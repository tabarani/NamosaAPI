<?php
/**
 * Moodle Sync Service
 * 
 * Handles synchronization between Gibbon (Source of Truth) and Moodle.
 * Creates/updates users, courses, and enrollments in Moodle via Web Services.
 */

namespace Gibbon\Module\NamosaAPI\Moodle;

use PDO;

class MoodleSyncService
{
    private $pdo;
    private $moodleUrl;
    private $moodleToken;
    private $logger;

    public function __construct(PDO $pdo, string $moodleUrl, string $moodleToken)
    {
        $this->pdo = $pdo;
        $this->moodleUrl = rtrim($moodleUrl, '/');
        $this->moodleToken = $moodleToken;
        $this->logger = new SyncLogger($pdo);
    }

    /**
     * Sync a single user to Moodle
     * @param int $gibbonPersonID
     * @return array ['success' => bool, 'message' => string, 'moodleUserId' => int|null]
     */
    public function syncUser(int $gibbonPersonID): array
    {
        try {
            // 1. Fetch user data from Gibbon
            $userData = $this->getGibbonUserData($gibbonPersonID);
            
            if (!$userData) {
                return ['success' => false, 'message' => 'User not found in Gibbon'];
            }

            // 2. Check if user exists in Moodle (by idnumber = gibbonPersonID)
            $moodleUser = $this->findMoodleUserByIdnumber($gibbonPersonID);

            if ($moodleUser) {
                // 3a. Update existing user
                $result = $this->updateMoodleUser($moodleUser['id'], $userData);
                $this->logger->log('user_update', $gibbonPersonID, $moodleUser['id'], $result['success']);
                return $result;
            } else {
                // 3b. Create new user
                $result = $this->createMoodleUser($userData);
                if ($result['success']) {
                    $this->logger->log('user_create', $gibbonPersonID, $result['moodleUserId'], true);
                } else {
                    $this->logger->log('user_create', $gibbonPersonID, null, false, $result['message']);
                }
                return $result;
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Sync multiple users in batch
     */
    public function syncUsersBatch(array $gibbonPersonIDs): array
    {
        $results = ['total' => count($gibbonPersonIDs), 'success' => 0, 'failed' => 0, 'details' => []];
        
        foreach ($gibbonPersonIDs as $id) {
            $result = $this->syncUser($id);
            $results['details'][] = ['gibbonID' => $id, 'result' => $result];
            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
            }
        }
        
        return $results;
    }

    /**
     * Sync a course to Moodle
     */
    public function syncCourse(int $gibbonCourseID): array
    {
        try {
            $courseData = $this->getGibbonCourseData($gibbonCourseID);
            
            if (!$courseData) {
                return ['success' => false, 'message' => 'Course not found in Gibbon'];
            }

            // Map Gibbon course to Moodle category (simplified: use first category or create one)
            $categoryId = $this->getOrCreateMoodleCategory($courseData['department'] ?? 'Default');

            $moodleCourse = $this->findMoodleCourseByIdnumber($gibbonCourseID);

            if ($moodleCourse) {
                $result = $this->updateMoodleCourse($moodleCourse['id'], $courseData, $categoryId);
                $this->logger->log('course_update', $gibbonCourseID, $moodleCourse['id'], $result['success']);
                return $result;
            } else {
                $result = $this->createMoodleCourse($courseData, $categoryId);
                if ($result['success']) {
                    $this->logger->log('course_create', $gibbonCourseID, $result['moodleCourseId'], true);
                } else {
                    $this->logger->log('course_create', $gibbonCourseID, null, false, $result['message']);
                }
                return $result;
            }
        } catch (\Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }

    /**
     * Sync enrollments for a course
     */
    public function syncCourseEnrollments(int $gibbonCourseID): array
    {
        $enrollments = $this->getGibbonCourseEnrollments($gibbonCourseID);
        $moodleCourse = $this->findMoodleCourseByIdnumber($gibbonCourseID);

        if (!$moodleCourse) {
            return ['success' => false, 'message' => 'Course not found in Moodle'];
        }

        $results = ['enrolled' => 0, 'failed' => 0];

        foreach ($enrollments as $enrollment) {
            $moodleUser = $this->findMoodleUserByIdnumber($enrollment['gibbonPersonID']);
            
            if ($moodleUser) {
                $result = $this->enrolUserInCourse($moodleUser['id'], $moodleCourse['id'], 'student');
                if ($result['success']) {
                    $results['enrolled']++;
                } else {
                    $results['failed']++;
                }
            } else {
                // Optionally create user first
                $userResult = $this->syncUser($enrollment['gibbonPersonID']);
                if ($userResult['success']) {
                    $moodleUser = $this->findMoodleUserByIdnumber($enrollment['gibbonPersonID']);
                    $result = $this->enrolUserInCourse($moodleUser['id'], $moodleCourse['id'], 'student');
                    if ($result['success']) $results['enrolled']++;
                    else $results['failed']++;
                } else {
                    $results['failed']++;
                }
            }
        }

        $this->logger->log('enrollment_sync', $gibbonCourseID, $moodleCourse['id'], true, json_encode($results));
        return ['success' => true, 'results' => $results];
    }

    // --- Private Helper Methods ---

    private function getGibbonUserData(int $id): ?array
    {
        $sql = "SELECT p.gibbonPersonID, p.title, p.surname, p.preferredName, p.email, 
                       p.gender, p.dateOfBirth, p.status, r.name as roleName
                FROM gibbonPerson p
                LEFT JOIN gibbonRole r ON p.gibbonRoleIDAll = r.gibbonRoleID
                WHERE p.gibbonPersonID = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$data) return null;

        // Map to Moodle user format
        return [
            'idnumber' => (string)$id,
            'username' => $data['email'] ?? 'user_' . $id,
            'firstname' => $data['preferredName'],
            'lastname' => $data['surname'],
            'email' => $data['email'],
            'gender' => $data['gender'],
            'city' => '', // Add if available
            'country' => '', // Add if available
            'lang' => 'en',
            'description' => 'Role: ' . ($data['roleName'] ?? 'Student'),
            'auth' => 'oidc', // Important: link to OIDC auth
            'suspended' => ($data['status'] === 'Full' || $data['status'] === 'Staff') ? 0 : 1
        ];
    }

    private function getGibbonCourseData(int $id): ?array
    {
        $sql = "SELECT c.gibbonCourseID, c.name, c.nameShort, c.description, d.name as department
                FROM gibbonCourse c
                LEFT JOIN gibbonDepartment d ON c.gibbonDepartmentID = d.gibbonDepartmentID
                WHERE c.gibbonCourseID = :id";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $id]);
        $data = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$data) return null;

        return [
            'shortname' => $data['nameShort'],
            'fullname' => $data['name'],
            'summary' => $data['description'] ?? '',
            'idnumber' => (string)$id,
            'category' => $data['department'] ?? 'Default',
            'department' => $data['department']
        ];
    }

    private function getGibbonCourseEnrollments(int $courseID): array
    {
        $sql = "SELECT e.gibbonPersonID
                FROM gibbonCourseClassPerson cc
                JOIN gibbonCourseClass c ON cc.gibbonCourseClassID = c.gibbonCourseClassID
                JOIN gibbonCourse co ON c.gibbonCourseID = co.gibbonCourseID
                WHERE co.gibbonCourseID = :id AND cc.role = 'Student'";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['id' => $courseID]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function findMoodleUserByIdnumber(int $gibbonID): ?array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_user_get_users_by_field',
            'moodlewsrestformat' => 'json',
            'field' => 'idnumber',
            'values[0]' => (string)$gibbonID
        ]);

        $response = $this->callMoodleApi($params);
        
        if (!empty($response) && !isset($response['exception'])) {
            return $response[0] ?? null;
        }
        return null;
    }

    private function createMoodleUser(array $userData): array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_user_create_users',
            'moodlewsrestformat' => 'json',
            'users[0][username]' => $userData['username'],
            'users[0][password]' => bin2hex(random_bytes(16)), // Random password, OIDC handles login
            'users[0][firstname]' => $userData['firstname'],
            'users[0][lastname]' => $userData['lastname'],
            'users[0][email]' => $userData['email'],
            'users[0][idnumber]' => $userData['idnumber'],
            'users[0][lang]' => $userData['lang'],
            'users[0][description]' => $userData['description'],
            'users[0][auth]' => $userData['auth'],
            'users[0][suspended]' => $userData['suspended'],
        ]);

        if ($userData['gender']) $params .= '&users[0][gender]=' . $userData['gender'];

        $response = $this->callMoodleApi($params, 'POST');

        if (!empty($response) && isset($response[0]['id'])) {
            return ['success' => true, 'moodleUserId' => $response[0]['id'], 'message' => 'User created'];
        }
        
        return ['success' => false, 'message' => $response['message'] ?? 'Unknown error'];
    }

    private function updateMoodleUser(int $moodleId, array $userData): array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_user_update_users',
            'moodlewsrestformat' => 'json',
            'users[0][id]' => $moodleId,
            'users[0][firstname]' => $userData['firstname'],
            'users[0][lastname]' => $userData['lastname'],
            'users[0][email]' => $userData['email'],
            'users[0][suspended]' => $userData['suspended'],
            'users[0][description]' => $userData['description'],
        ]);

        $response = $this->callMoodleApi($params, 'POST');

        if ($response === true || (is_array($response) && empty($response))) {
            return ['success' => true, 'message' => 'User updated'];
        }
        
        return ['success' => false, 'message' => $response['message'] ?? 'Unknown error'];
    }

    private function findMoodleCourseByIdnumber(int $gibbonID): ?array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_course_get_courses_by_field',
            'moodlewsrestformat' => 'json',
            'field' => 'idnumber',
            'value' => (string)$gibbonID
        ]);

        $response = $this->callMoodleApi($params);
        
        if (!empty($response['courses']) && !isset($response['exception'])) {
            return $response['courses'][0] ?? null;
        }
        return null;
    }

    private function getOrCreateMoodleCategory(string $name): int
    {
        // Simplified: Try to find existing category, else return default (1)
        // In production, implement core_course_create_categories
        return 1; 
    }

    private function createMoodleCourse(array $courseData, int $categoryId): array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_course_create_courses',
            'moodlewsrestformat' => 'json',
            'courses[0][shortname]' => $courseData['shortname'],
            'courses[0][fullname]' => $courseData['fullname'],
            'courses[0][summary]' => $courseData['summary'],
            'courses[0][idnumber]' => $courseData['idnumber'],
            'courses[0][categoryid]' => $categoryId,
            'courses[0][visible]' => 1,
        ]);

        $response = $this->callMoodleApi($params, 'POST');

        if (!empty($response) && isset($response[0]['id'])) {
            return ['success' => true, 'moodleCourseId' => $response[0]['id'], 'message' => 'Course created'];
        }
        
        return ['success' => false, 'message' => $response['message'] ?? 'Unknown error'];
    }

    private function updateMoodleCourse(int $moodleId, array $courseData, int $categoryId): array
    {
        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'core_course_update_courses',
            'moodlewsrestformat' => 'json',
            'courses[0][id]' => $moodleId,
            'courses[0][shortname]' => $courseData['shortname'],
            'courses[0][fullname]' => $courseData['fullname'],
            'courses[0][summary]' => $courseData['summary'],
            'courses[0][categoryid]' => $categoryId,
        ]);

        $response = $this->callMoodleApi($params, 'POST');

        if ($response === true || (is_array($response) && empty($response))) {
            return ['success' => true, 'message' => 'Course updated'];
        }
        
        return ['success' => false, 'message' => $response['message'] ?? 'Unknown error'];
    }

    private function enrolUserInCourse(int $userId, int $courseId, string $role = 'student'): array
    {
        $roleId = ($role === 'student') ? 5 : 4; // Standard Moodle role IDs

        $params = http_build_query([
            'wstoken' => $this->moodleToken,
            'wsfunction' => 'enrol_manual_enrol_users',
            'moodlewsrestformat' => 'json',
            'enrolments[0][roleid]' => $roleId,
            'enrolments[0][userid]' => $userId,
            'enrolments[0][courseid]' => $courseId,
        ]);

        $response = $this->callMoodleApi($params, 'POST');

        if ($response === true) {
            return ['success' => true, 'message' => 'Enrolled'];
        }
        
        return ['success' => false, 'message' => $response['message'] ?? 'Unknown error'];
    }

    private function callMoodleApi(string $params, string $method = 'GET'): mixed
    {
        $url = $this->moodleUrl . '/webservice/rest/server.php?' . $params;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Configure for prod
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception("Moodle API HTTP Error: $httpCode");
        }

        return json_decode($response, true);
    }
}
