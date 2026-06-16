<?php
/**
 * Transport Module - API Handler
 * 
 * Handles RESTful API requests for transportation data
 * Supports dual authentication: JWT (NamosaAPI) and legacy API Key
 */

class TransportAPIHandler
{
    private $pdo;
    private $config;
    
    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->loadConfig();
    }
    
    private function loadConfig()
    {
        // Load from Gibbon settings
        $data = ['scope' => 'Transport'];
        $sql = "SELECT name, value FROM gibbonSetting WHERE scope = :scope";
        $stmt = $this->pdo->execute($data, $sql);
        
        $this->config = [
            'api_enabled' => false,
            'jwt_auth_enabled' => true,
            'api_key_auth_enabled' => true,
            'jwks_url' => '',
            'issuer' => '',
            'audience' => 'namosa-api',
            'valid_api_keys' => []
        ];
        
        if ($stmt->rowCount() > 0) {
            while ($row = $stmt->fetch()) {
                switch ($row['name']) {
                    case 'apiEnabled':
                        $this->config['api_enabled'] = $row['value'] === 'Y';
                        break;
                    case 'idpURL':
                        $baseUrl = rtrim($row['value'], '/');
                        $this->config['jwks_url'] = $baseUrl . '/.well-known/jwks.json';
                        $this->config['issuer'] = $baseUrl;
                        break;
                    case 'apiKeys':
                        $this->config['valid_api_keys'] = array_filter(explode(',', $row['value']));
                        break;
                }
            }
        }
    }
    
    /**
     * Authenticate request using JWT or API Key
     */
    public function authenticate()
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
        
        // Try JWT first
        if (!empty($authHeader) && strpos($authHeader, 'Bearer ') === 0) {
            return $this->authenticateJWT(substr($authHeader, 7));
        }
        
        // Try API Key
        if (!empty($apiKey) && $this->config['api_key_auth_enabled']) {
            return $this->authenticateAPIKey($apiKey);
        }
        
        return ['authenticated' => false, 'error' => 'Missing authentication'];
    }
    
    private function authenticateJWT($token)
    {
        if (!$this->config['jwt_auth_enabled'] || empty($this->config['jwks_url'])) {
            return ['authenticated' => false, 'error' => 'JWT auth not configured'];
        }
        
        require_once __DIR__ . '/../../Core/lib/JWTValidator.php';
        
        $jwtValidator = new \Gibbon\Module\Core\JWTValidator(
            $this->config['jwks_url'],
            $this->config['issuer'],
            $this->config['audience']
        );
        
        $payload = $jwtValidator->validate($token);
        
        if (!$payload) {
            return ['authenticated' => false, 'error' => 'Invalid token'];
        }
        
        $userIdClaim = 'sub';
        $gibbonPersonID = $payload[$userIdClaim] ?? null;
        
        if (!$gibbonPersonID) {
            return ['authenticated' => false, 'error' => 'User ID not found'];
        }
        
        // Verify user exists
        $data = ['gibbonPersonID' => $gibbonPersonID];
        $sql = "SELECT gibbonPersonID, surname, preferredName, email FROM gibbonPerson WHERE gibbonPersonID = :gibbonPersonID";
        $stmt = $this->pdo->execute($data, $sql);
        
        if ($stmt->rowCount() === 0) {
            return ['authenticated' => false, 'error' => 'User not found'];
        }
        
        $user = $stmt->fetch();
        
        return [
            'authenticated' => true,
            'gibbonPersonID' => $gibbonPersonID,
            'user' => $user,
            'authMethod' => 'JWT'
        ];
    }
    
    private function authenticateAPIKey($apiKey)
    {
        if (!in_array($apiKey, $this->config['valid_api_keys'])) {
            return ['authenticated' => false, 'error' => 'Invalid API key'];
        }
        
        return [
            'authenticated' => true,
            'gibbonPersonID' => null,
            'user' => null,
            'authMethod' => 'API_KEY'
        ];
    }
    
    /**
     * Check permission for user
     */
    public function checkPermission($gibbonPersonID, $permission)
    {
        if (!$gibbonPersonID) {
            // API keys have full access by default
            return true;
        }
        
        require_once __DIR__ . '/../../NamosaAPI/lib/PermissionService.php';
        $permissionService = new PermissionService($this->pdo);
        $userPermissions = $permissionService->loadPermissions($gibbonPersonID);
        
        return $permissionService->hasPermission($userPermissions, $permission);
    }
    
    /**
     * Send JSON response
     */
    public function respond($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        
        echo json_encode($data);
        exit;
    }
    
    /**
     * Get pagination params
     */
    public function getPaginationParams()
    {
        $limit = min((int)($_GET['limit'] ?? 50), 200);
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $page = max(1, (int)($_GET['page'] ?? 1));
        
        if (isset($_GET['page'])) {
            $offset = ($page - 1) * $limit;
        }
        
        return ['limit' => $limit, 'offset' => $offset, 'page' => $page];
    }
}
