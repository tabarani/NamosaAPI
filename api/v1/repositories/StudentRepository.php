<?php
/**
 * Student Repository
 * Handles all student-related database operations
 */

namespace NamosaAPI\Repositories;

class StudentRepository extends BaseRepository
{
    /**
     * Get all students with pagination and filtering
     */
    public function getAll($limit = 100, $offset = 0, $filters = [])
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.preferredName,
                p.dob,
                p.gender,
                p.email,
                p.address,
                p.phoneNumber,
                p.dateStart,
                p.dateEnd,
                y.nameShort as yearGroupName,
                c.nameShort as className,
                fg.name as formGroupName,
                p.status
            FROM {$this->tablePrefix}Person p
            LEFT JOIN {$this->tablePrefix}YearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN {$this->tablePrefix}FormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            LEFT JOIN {$this->tablePrefix}CourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE p.status = 'Full'
            AND p.gibbonRoleIDPrimary IN (
                SELECT gibbonRoleID FROM {$this->tablePrefix}Role WHERE category = 'Student'
            )
        ";
        
        $params = [];
        
        // Apply filters
        if (!empty($filters['yearGroup'])) {
            $sql .= " AND y.nameShort = :yearGroup";
            $params[':yearGroup'] = $filters['yearGroup'];
        }
        
        if (!empty($filters['class'])) {
            $sql .= " AND c.nameShort = :class";
            $params[':class'] = $filters['class'];
        }
        
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $sql .= " AND (p.firstName LIKE :search OR p.surname LIKE :search)";
            $params[':search'] = $search;
        }
        
        // Count total before pagination
        $countSql = "SELECT COUNT(*) as total FROM ({$sql}) as subquery";
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetch()['total'];
        
        // Add pagination
        $sql .= " ORDER BY p.surname, p.firstName LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        $students = $stmt->fetchAll();
        
        // Enrich with parents and photo
        foreach ($students as &$student) {
            $student['parents'] = $this->getParentsForStudent($student['id']);
            $student['photoUrl'] = $this->getPhotoUrl($student['id']);
        }
        
        return [
            'data' => $students,
            'total' => $total
        ];
    }
    
    /**
     * Get single student by ID
     */
    public function getById($studentId)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.preferredName,
                p.dob,
                p.gender,
                p.email,
                p.address,
                p.phoneNumber,
                p.dateStart,
                p.dateEnd,
                y.nameShort as yearGroupName,
                c.nameShort as className,
                fg.name as formGroupName,
                p.status
            FROM {$this->tablePrefix}Person p
            LEFT JOIN {$this->tablePrefix}YearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN {$this->tablePrefix}FormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            LEFT JOIN {$this->tablePrefix}CourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE p.gibbonPersonID = :id
            AND p.status = 'Full'
            AND p.gibbonRoleIDPrimary IN (
                SELECT gibbonRoleID FROM {$this->tablePrefix}Role WHERE category = 'Student'
            )
            LIMIT 1
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':id' => $studentId]);
        $student = $stmt->fetch();
        
        if (!$student) {
            return null;
        }
        
        // Enrich with additional data
        $student['parents'] = $this->getParentsForStudent($studentId);
        $student['siblings'] = $this->getSiblings($studentId);
        $student['photoUrl'] = $this->getPhotoUrl($studentId);
        $student['emergencyContacts'] = $this->getEmergencyContacts($studentId);
        
        return $student;
    }
    
    /**
     * Get parents for a student
     */
    private function getParentsForStudent($studentId)
    {
        $sql = "
            SELECT 
                pa.gibbonPersonID as id,
                pa.firstName,
                pa.surname,
                pa.email,
                pa.phoneNumber,
                pa.phoneOther,
                fa.relationship,
                fa.contactPriority
            FROM {$this->tablePrefix}FamilyChild fc
            INNER JOIN {$this->tablePrefix}FamilyAdult fa ON fc.gibbonFamilyID = fa.gibbonFamilyID
            INNER JOIN {$this->tablePrefix}Person pa ON fa.gibbonPersonID = pa.gibbonPersonID
            WHERE fc.gibbonPersonIDStudent = :studentId
            ORDER BY fa.contactPriority ASC
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':studentId' => $studentId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get siblings for a student
     */
    private function getSiblings($studentId)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.dob,
                y.nameShort as yearGroupName
            FROM {$this->tablePrefix}FamilyChild fc1
            INNER JOIN {$this->tablePrefix}FamilyChild fc2 ON fc1.gibbonFamilyID = fc2.gibbonFamilyID
            INNER JOIN {$this->tablePrefix}Person p ON fc2.gibbonPersonIDStudent = p.gibbonPersonID
            LEFT JOIN {$this->tablePrefix}YearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            WHERE fc1.gibbonPersonIDStudent = :studentId
            AND fc2.gibbonPersonIDStudent != :studentId
            AND p.status = 'Full'
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':studentId' => $studentId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get emergency contacts for a student
     */
    private function getEmergencyContacts($studentId)
    {
        $sql = "
            SELECT 
                contact1Name as name1,
                contact1Relationship as relationship1,
                contact1Number as number1,
                contact2Name as name2,
                contact2Relationship as relationship2,
                contact2Number as number2
            FROM {$this->tablePrefix}Student
            WHERE gibbonPersonID = :studentId
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':studentId' => $studentId]);
        $result = $stmt->fetch();
        
        if (!$result) {
            return [];
        }
        
        $contacts = [];
        if (!empty($result['name1'])) {
            $contacts[] = [
                'name' => $result['name1'],
                'relationship' => $result['relationship1'],
                'number' => $result['number1']
            ];
        }
        if (!empty($result['name2'])) {
            $contacts[] = [
                'name' => $result['name2'],
                'relationship' => $result['relationship2'],
                'number' => $result['number2']
            ];
        }
        
        return $contacts;
    }
    
    /**
     * Get photo URL for student
     */
    private function getPhotoUrl($studentId)
    {
        // Check if photo exists
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/students/';
        $photoPath = $uploadDir . $studentId . '.jpg';
        
        if (file_exists($photoPath)) {
            return '/uploads/students/' . $studentId . '.jpg';
        }
        
        return null;
    }
    
    /**
     * Search students by name
     */
    public function search($query, $limit = 20)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.preferredName,
                y.nameShort as yearGroupName,
                c.nameShort as className
            FROM {$this->tablePrefix}Person p
            LEFT JOIN {$this->tablePrefix}YearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN {$this->tablePrefix}FormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            LEFT JOIN {$this->tablePrefix}CourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE p.status = 'Full'
            AND p.gibbonRoleIDPrimary IN (
                SELECT gibbonRoleID FROM {$this->tablePrefix}Role WHERE category = 'Student'
            )
            AND (p.firstName LIKE :query OR p.surname LIKE :query OR p.preferredName LIKE :query)
            ORDER BY p.surname, p.firstName
            LIMIT :limit
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':query', '%' . $query . '%');
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
}