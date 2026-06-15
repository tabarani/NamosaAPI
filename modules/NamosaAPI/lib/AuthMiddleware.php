<?php
/*
NamosaAPI - Authentication Middleware
Validates JWT from Authorization header and loads user context
*/

namespace Gibbon\Module\NamosaAPI;

use Gibbon\Module\Gibbon_OIDC\JWTValidator;
use Gibbon\Module\Gibbon_OIDC\PermissionService;

class AuthMiddleware
{
    private $pdo;
    private $config;
    private $userContext = null;
    private $error = null;

    public function __construct($pdo, $config)
    {
        $this->pdo = $pdo;
        $this->config = $config; // ['jwks_url', 'issuer', 'audience', 'user_id_claim']
    }

    /**
     * Process request: Validate token and load user
     * @return bool True if authenticated, false otherwise
     */
    public function authenticate()
    {
        // Get Authorization Header
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (empty($authHeader)) {
            // Try alternative header for some servers
            $authHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
            $this->error = 'Missing or invalid Authorization header';
            return false;
        }

        $token = substr($authHeader, 7); // Remove "Bearer "

        // Initialize Validator
        $validator = new JWTValidator(
            $this->config['jwks_url'],
            $this->config['issuer'],
            $this->config['audience']
        );

        // Validate Token
        $payload = $validator->validate($token);

        if (!$payload) {
            $this->error = 'Invalid or expired token';
            return false;
        }

        // Extract User ID from claim
        $claimName = $this->config['user_id_claim'] ?? 'sub';
        $gibbonPersonID = $payload[$claimName] ?? null;

        // Fallback: Check for custom claim if sub is not numeric
        if (!$gibbonPersonID && isset($payload['gibbon_person_id'])) {
            $gibbonPersonID = $payload['gibbon_person_id'];
        }

        if (!$gibbonPersonID || !is_numeric($gibbonPersonID)) {
            $this->error = 'User ID not found in token';
            return false;
        }

        // Verify user exists in Gibbon
        $user = $this->loadUser($gibbonPersonID);
        if (!$user) {
            $this->error = 'User not found in Gibbon';
            return false;
        }

        // Load Permissions
        $permService = new PermissionService($this->pdo, $gibbonPersonID);
        $permissions = $permService->loadPermissions();

        // Set Context
        $this->userContext = [
            'gibbonPersonID' => $gibbonPersonID,
            'username' => $user['username'],
            'fullName' => $user['fullName'],
            'email' => $user['email'],
            'roles' => $permissions['roles'],
            'permissions' => $permissions['permissions'],
            'tokenPayload' => $payload
        ];

        return true;
    }

    /**
     * Load user from Gibbon database
     */
    private function loadUser($gibbonPersonID)
    {
        $data = ['gibbonPersonID' => $gibbonPersonID];
        $sql = "SELECT gibbonPersonID, username, surname, preferredName, email, status
                FROM gibbonPerson
                WHERE gibbonPersonID = :gibbonPersonID
                AND status = 'Full'";

        $stmt = $this->pdo->execute($data, $sql);
        
        if ($stmt->rowCount() == 0) {
            return null;
        }

        $user = $stmt->fetch();
        return [
            'gibbonPersonID' => $user['gibbonPersonID'],
            'username' => $user['username'],
            'fullName' => $user['surname'] . ', ' . $user['preferredName'],
            'email' => $user['email'],
            'status' => $user['status']
        ];
    }

    /**
     * Get authenticated user context
     */
    public function getUserContext()
    {
        return $this->userContext;
    }

    /**
     * Get error message
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Check if user has permission
     */
    public function hasPermission($permissionName)
    {
        if (!$this->userContext) {
            return false;
        }
        return in_array($permissionName, $this->userContext['permissions']);
    }

    /**
     * Check if user has role
     */
    public function hasRole($roleName)
    {
        if (!$this->userContext) {
            return false;
        }
        return in_array($roleName, $this->userContext['roles']);
    }
}
