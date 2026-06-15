<?php
/*
Transport Module API v1 - Enhanced with JWT/OIDC Support
Supports both legacy API Key and new JWT authentication
*/

namespace Gibbon\Module\Transport\API;

use Gibbon\Module\NamosaAPI\AuthMiddleware;
use Gibbon\Module\NamosaAPI\Config as NamosaConfig;

class TransportAPIHandler
{
    private $pdo;
    private $authMiddleware = null;
    private $user = null;
    private $isJWTAuth = false;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Authenticate request using JWT or fallback to API Key
     */
    public function authenticate()
    {
        // Try JWT first (from Authorization header)
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        
        if (!empty($authHeader) && strpos($authHeader, 'Bearer ') === 0) {
            return $this->authenticateWithJWT();
        }

        // Fallback to API Key
        return $this->authenticateWithAPIKey();
    }

    /**
     * JWT Authentication via NamosaAPI
     */
    private function authenticateWithJWT()
    {
        try {
            $configService = new NamosaConfig($this->pdo);
            $config = $configService->get();

            if (!$configService->isConfigured()) {
                return ['success' => false, 'error' => 'JWT not configured'];
            }

            $this->authMiddleware = new AuthMiddleware($this->pdo, $config);
            
            if (!$this->authMiddleware->authenticate()) {
                return ['success' => false, 'error' => $this->authMiddleware->getError()];
            }

            $this->user = $this->authMiddleware->getUserContext();
            $this->isJWTAuth = true;

            return ['success' => true];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Legacy API Key Authentication
     */
    private function authenticateWithAPIKey()
    {
        $apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;

        if (!$apiKey) {
            return ['success' => false, 'error' => 'Missing API key'];
        }

        try {
            $data = ['apiKey' => $apiKey];
            $sql = "SELECT apiKeyID, name, active, gibbonPersonID 
                    FROM gibbonTransportAPIKey 
                    WHERE apiKey = :apiKey AND active = 1";

            $stmt = $this->pdo->execute($data, $sql);

            if ($stmt->rowCount() === 0) {
                return ['success' => false, 'error' => 'Invalid API key'];
            }

            $keyData = $stmt->fetch();

            // Load user if person ID is linked
            if ($keyData['gibbonPersonID']) {
                $this->user = [
                    'gibbonPersonID' => $keyData['gibbonPersonID'],
                    'roles' => ['API User'],
                    'permissions' => ['transport_read']
                ];
            }

            $this->isJWTAuth = false;

            return ['success' => true];

        } catch (\Exception $e) {
            return ['success' => false, 'error' => 'Authentication failed'];
        }
    }

    /**
     * Check permission
     */
    public function hasPermission($permission)
    {
        if (!$this->user) {
            return false;
        }

        // Admin always has access
        if (in_array('Admin', $this->user['roles'] ?? [])) {
            return true;
        }

        return in_array($permission, $this->user['permissions'] ?? []);
    }

    /**
     * Get current user
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * Is authenticated via JWT
     */
    public function isJWT()
    {
        return $this->isJWTAuth;
    }
}
