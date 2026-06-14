<?php
/**
 * User Repository
 * Handles user authentication and profile operations
 */

namespace NamosaAPI\Repositories;

class UserRepository extends BaseRepository
{
    /**
     * Get user by ID
     */
    public function getById($userId)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.title,
                p.firstName,
                p.surname,
                p.preferredName,
                p.officialName,
                p.gender,
                p.username,
                p.email,
                p.emailAlternate,
                p.dob,
                p.phone1,
                p.phone2,
                p.phone3,
                p.phone4,
                p.address1,
                p.address2,
                p.status,
                p.dateStart,
                p.dateEnd,
                p.image_240 as photo,
                p.gibbonRoleIDPrimary,
                r.name as roleName,
                r.category as roleCategory
            FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON p.gibbonRoleIDPrimary = r.gibbonRoleID
            WHERE p.gibbonPersonID = :userId
            LIMIT 1
        ";
        
        $user = $this->fetchOne($sql, [':userId' => $userId]);
        
        if (!$user) {
            return null;
        }
        
        // Get additional role information
        $user['roles'] = $this->getUserRoles($userId);
        
        return $user;
    }
    
    /**
     * Get user by username
     */
    public function getByUsername($username)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.title,
                p.firstName,
                p.surname,
                p.preferredName,
                p.email,
                p.username,
                p.status,
                p.gibbonRoleIDPrimary,
                r.name as roleName,
                r.category as roleCategory
            FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON p.gibbonRoleIDPrimary = r.gibbonRoleID
            WHERE p.username = :username
            LIMIT 1
        ";
        
        return $this->fetchOne($sql, [':username' => $username]);
    }
    
    /**
     * Get all users with filtering
     */
    public function getAll($limit = 100, $offset = 0, $filters = [])
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.title,
                p.firstName,
                p.surname,
                p.preferredName,
                p.email,
                p.username,
                p.status,
                p.dateStart,
                p.image_240 as photo,
                r.name as roleName,
                r.category as roleCategory
            FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON p.gibbonRoleIDPrimary = r.gibbonRoleID
            WHERE 1=1
        ";
        
        $params = [];
        
        // Filter by role category
        if (!empty($filters['roleCategory'])) {
            $sql .= " AND r.category = :roleCategory";
            $params[':roleCategory'] = $filters['roleCategory'];
        }
        
        // Filter by status
        if (!empty($filters['status'])) {
            $sql .= " AND p.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        // Search by name
        if (!empty($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $sql .= " AND (p.firstName LIKE :search OR p.surname LIKE :search OR p.username LIKE :search)";
            $params[':search'] = $search;
        }
        
        $sql .= " ORDER BY p.surname ASC, p.firstName ASC LIMIT :limit OFFSET :offset";
        
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get user roles
     */
    public function getUserRoles($userId)
    {
        $sql = "
            SELECT 
                r.gibbonRoleID as id,
                r.name,
                r.category,
                r.description
            FROM gibbonRole r
            INNER JOIN gibbonPerson p ON FIND_IN_SET(r.gibbonRoleID, p.gibbonRoleIDAll)
            WHERE p.gibbonPersonID = :userId
        ";
        
        return $this->fetchAll($sql, [':userId' => $userId]);
    }
    
    /**
     * Authenticate user by username and password
     */
    public function authenticate($username, $password)
    {
        // Get user with password hash
        $user = $this->getByUsername($username);
        
        if (!$user) {
            return null;
        }
        
        // Get full user record with password
        $sql = "
            SELECT 
                gibbonPersonID,
                username,
                passwordStrong,
                passwordStrongSalt,
                status
            FROM gibbonPerson
            WHERE username = :username
            LIMIT 1
        ";
        
        $record = $this->fetchOne($sql, [':username' => $username]);
        
        if (!$record) {
            return null;
        }
        
        // Verify password
        $hashedPassword = hash('sha256', $password . $record['passwordStrongSalt']);
        
        if ($hashedPassword !== $record['passwordStrong']) {
            return null;
        }
        
        // Check if user is active
        if ($record['status'] !== 'Full') {
            return null;
        }
        
        return $user;
    }
    
    /**
     * Update user profile
     */
    public function updateProfile($userId, $data)
    {
        $allowedFields = [
            'firstName', 'surname', 'preferredName', 'email', 'phone1',
            'phone2', 'address1', 'address2'
        ];
        
        $updateData = [];
        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }
        
        if (empty($updateData)) {
            return false;
        }
        
        return $this->update(
            'Person',
            $updateData,
            'gibbonPersonID = :userId',
            [':userId' => $userId]
        );
    }
    
    /**
     * Get teachers (staff with teaching roles)
     */
    public function getTeachers($limit = 100, $offset = 0)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.title,
                p.firstName,
                p.surname,
                p.preferredName,
                p.email,
                p.phone1,
                p.image_240 as photo,
                p.status,
                GROUP_CONCAT(DISTINCT c.nameShort ORDER BY c.nameShort ASC SEPARATOR ', ') as classes
            FROM gibbonPerson p
            LEFT JOIN gibbonCourseClassTeacher ct ON p.gibbonPersonID = ct.gibbonPersonID
            LEFT JOIN gibbonCourseClass c ON ct.gibbonCourseClassID = c.gibbonCourseClassID
            WHERE p.gibbonRoleIDPrimary IN (
                SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Staff'
            )
            AND p.status = 'Full'
            GROUP BY p.gibbonPersonID
            ORDER BY p.surname ASC, p.firstName ASC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get user statistics
     */
    public function getUserStatistics()
    {
        $sql = "
            SELECT 
                r.category as roleCategory,
                COUNT(*) as count
            FROM gibbonPerson p
            INNER JOIN gibbonRole r ON p.gibbonRoleIDPrimary = r.gibbonRoleID
            WHERE p.status = 'Full'
            GROUP BY r.category
        ";
        
        $stats = $this->fetchAll($sql);
        
        // Convert to associative array
        $result = [];
        foreach ($stats as $stat) {
            $result[$stat['roleCategory']] = (int) $stat['count'];
        }
        
        return $result;
    }
}