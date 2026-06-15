<?php
/*
Gibbon OIDC - Permission Service
Loads user roles and permissions from Gibbon database
*/

namespace Gibbon\Module\Gibbon_OIDC;

class PermissionService
{
    private $pdo;
    private $gibbonPersonID;

    public function __construct($pdo, $gibbonPersonID)
    {
        $this->pdo = $pdo;
        $this->gibbonPersonID = $gibbonPersonID;
    }

    /**
     * Load all permissions for a user
     * @return array ['roles' => [...], 'permissions' => [...]]
     */
    public function loadPermissions()
    {
        if (!$this->gibbonPersonID) {
            return ['roles' => [], 'permissions' => []];
        }

        $data = ['gibbonPersonID' => $this->gibbonPersonID];
        $sql = "SELECT 
                    r.name AS roleName, 
                    r.gibbonRoleIDAll AS roleID,
                    p.name AS permissionName,
                    p.actionName AS actionName
                FROM gibbonRole r
                LEFT JOIN gibbonPermission p ON FIND_IN_SET(r.gibbonRoleID, p.gibbonRoleID)
                JOIN gibbonPerson p2 ON FIND_IN_SET(r.gibbonRoleID, p2.gibbonRoleIDAll)
                WHERE p2.gibbonPersonID = :gibbonPersonID
                AND r.active = 'Y'
                ORDER BY r.sequenceNumber";

        $stmt = $this->pdo->execute($data, $sql);
        
        $roles = [];
        $permissions = [];

        if ($stmt->rowCount() > 0) {
            while ($row = $stmt->fetch()) {
                if (!in_array($row['roleName'], $roles)) {
                    $roles[] = $row['roleName'];
                }
                if ($row['permissionName'] && !in_array($row['permissionName'], $permissions)) {
                    $permissions[] = $row['permissionName'];
                }
            }
        }

        // Fallback: Check direct role assignment in gibbonPerson
        if (empty($roles)) {
            $data = ['gibbonPersonID' => $this->gibbonPersonID];
            $sql = "SELECT r.name AS roleName
                    FROM gibbonRole r
                    JOIN gibbonPerson p ON p.gibbonRoleID = r.gibbonRoleID
                    WHERE p.gibbonPersonID = :gibbonPersonID";
            
            $stmt = $this->pdo->execute($data, $sql);
            if ($stmt->rowCount() > 0) {
                while ($row = $stmt->fetch()) {
                    $roles[] = $row['roleName'];
                }
            }
        }

        return [
            'roles' => array_unique($roles),
            'permissions' => array_unique($permissions)
        ];
    }

    /**
     * Check if user has specific permission
     * @param string $permissionName e.g., 'students_read'
     * @return bool
     */
    public function hasPermission($permissionName)
    {
        $perms = $this->loadPermissions();
        return in_array($permissionName, $perms['permissions']);
    }

    /**
     * Check if user has specific role
     * @param string $roleName e.g., 'Admin'
     * @return bool
     */
    public function hasRole($roleName)
    {
        $perms = $this->loadPermissions();
        return in_array($roleName, $perms['roles']);
    }
}
