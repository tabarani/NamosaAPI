<?php
/**
 * NamosaAPI - Permission Service
 * 
 * Loads user permissions from Gibbon database based on person ID
 */

class PermissionService
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load all permissions for a given Gibbon Person ID
     * 
     * @param int $gibbonPersonID
     * @return array Array of permission names
     */
    public function loadPermissions($gibbonPersonID)
    {
        if (!$gibbonPersonID) {
            return [];
        }

        try {
            $sql = "
                SELECT DISTINCT p.nameShort as permission
                FROM gibbonPermission p
                JOIN gibbonRole r ON p.idRole = r.idRole
                JOIN gibbonPersonRole pr ON r.idRole = pr.idRole
                WHERE pr.idPerson = :gibbonPersonID
                AND pr.idRole IN (
                    SELECT idRole FROM gibbonRole WHERE active = 'Y'
                )
                ORDER BY p.nameShort
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['gibbonPersonID' => $gibbonPersonID]);
            
            $permissions = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            return $permissions ?: [];

        } catch (Exception $e) {
            error_log('PermissionService Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get user roles for a given Gibbon Person ID
     * 
     * @param int $gibbonPersonID
     * @return array Array of role data
     */
    public function loadRoles($gibbonPersonID)
    {
        if (!$gibbonPersonID) {
            return [];
        }

        try {
            $sql = "
                SELECT r.idRole, r.name, r.nameShort, r.type
                FROM gibbonRole r
                JOIN gibbonPersonRole pr ON r.idRole = pr.idRole
                WHERE pr.idPerson = :gibbonPersonID
                AND r.active = 'Y'
                ORDER BY r.sequenceNumber
            ";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['gibbonPersonID' => $gibbonPersonID]);
            
            return $stmt->fetchAll(PDO::FETCH_ASSOC);

        } catch (Exception $e) {
            error_log('PermissionService Error: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Check if user has a specific permission
     * 
     * @param array $userPermissions
     * @param string $permissionName
     * @return bool
     */
    public function hasPermission($userPermissions, $permissionName)
    {
        // Allow wildcard permissions (e.g., students_* matches students_read)
        $parts = explode('_', $permissionName);
        
        foreach ($userPermissions as $perm) {
            if ($perm === $permissionName) {
                return true;
            }
            
            // Check wildcard match
            if (strpos($perm, '*') !== false) {
                $pattern = '/^' . str_replace('*', '.*', $perm) . '$/';
                if (preg_match($pattern, $permissionName)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get system role (highest priority role)
     * 
     * @param array $roles
     * @return string|null
     */
    public function getSystemRole($roles)
    {
        $priority = ['Administrator', 'Staff', 'Parent', 'Student'];
        
        foreach ($priority as $roleType) {
            foreach ($roles as $role) {
                if ($role['name'] === $roleType || $role['nameShort'] === $roleType) {
                    return $role['nameShort'];
                }
            }
        }
        
        return null;
    }
}
