<?php
/**
 * Transport Module API - v1
 * Standalone endpoint (no Gibbon bootstrap required)
 * For mobile app integration
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Load Gibbon configuration for database access
$configFile = dirname(__DIR__, 4) . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found', 'code' => 'CONFIG_ERROR']);
    exit;
}

require_once $configFile;

// Initialize Rate Limiter
require_once __DIR__ . '/../lib/RateLimiter.php';

// Get API key from header
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? null;

// Check rate limit before authentication
$rateLimiter = new \Gibbon\Module\Transport\RateLimiter(null, $apiKey);

if (!$rateLimiter->allowRequest(null, $apiKey)) {
    $rateLimiter->sendRateLimitResponse();
    exit;
}

if (!$apiKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Missing API key', 'code' => 'MISSING_API_KEY']);
    exit;
}

// Validate API key against database
try {
    $mysqli = new mysqli($databaseServer, $databaseUsername, $databasePassword, $databaseName, $databasePort ?? 3306);
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed');
    }
    
    // Check if API key exists and is active
    $stmt = $mysqli->prepare("SELECT apiKeyID, name, active FROM gibbonTransportAPIKey WHERE apiKey = ? AND active = 1");
    $stmt->bind_param('s', $apiKey);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid API key', 'code' => 'INVALID_API_KEY']);
        $stmt->close();
        $mysqli->close();
        exit;
    }
    
    $apiKeyData = $result->fetch_assoc();
    $stmt->close();
    $mysqli->close();
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Authentication error', 'code' => 'AUTH_ERROR']);
    exit;
}

// Parse request
$requestMethod = $_SERVER['REQUEST_METHOD'];
$requestUri = $_SERVER['REQUEST_URI'];
$scriptName = $_SERVER['SCRIPT_NAME'];

// Extract path (remove script name and query string)
$path = str_replace($scriptName, '', $requestUri);
$path = strtok($path, '?');
$path = '/' . trim($path, '/');

// Route mapping
$routes = [
    'GET /events/today' => 'events.php?action=today',
    'POST /events' => 'events.php?action=create',
    'GET /alerts/unresolved' => 'alerts.php?action=unresolved',
    'POST /alerts' => 'alerts.php?action=create',
    'GET /routes' => 'routes.php?action=list',
    'GET /routes/{id}/students' => 'routes.php?action=students',
    'GET /health' => function() {
        echo json_encode([
            'status' => 'operational',
            'module' => 'Transport',
            'version' => '1.0.0',
            'timestamp' => date('c'),
            'endpoints' => [
                'events' => '/api/v1/events',
                'alerts' => '/api/v1/alerts',
                'routes' => '/api/v1/routes'
            ]
        ]);
        exit;
    }
];

// Find matching route
$routeMatched = false;
$responseHandler = null;

foreach ($routes as $pattern => $handler) {
    // Convert pattern to regex
    $regex = '/^' . preg_quote($pattern, '/') . '$/';
    $regex = str_replace(['\/{id}\/', '\/{id}'], ['\/([0-9]+)\/', '\/([0-9]+)'], $regex);
    
    if (preg_match($regex, "$requestMethod $path", $matches)) {
        $routeMatched = true;
        
        // Extract parameters
        $params = [];
        if (strpos($pattern, '{id}') !== false && count($matches) > 1) {
            $params['id'] = $matches[1];
        }
        
        // Set query parameters for handler
        if (is_string($handler)) {
            $_GET['action'] = explode('?', $handler)[0];
            if (!empty($params)) {
                foreach ($params as $key => $value) {
                    $_GET[$key] = $value;
                }
            }
            $responseHandler = $handler;
        } else {
            $handler(); // Direct callable
        }
        
        break;
    }
}

if (!$routeMatched) {
    http_response_code(404);
    echo json_encode(['error' => 'Endpoint not found', 'code' => 'NOT_FOUND']);
    exit;
}

if (is_string($responseHandler)) {
    // Include handler file
    $handlerFile = __DIR__ . '/' . explode('?', $responseHandler)[0];
    
    if (!file_exists($handlerFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Handler file not found', 'code' => 'HANDLER_NOT_FOUND']);
        exit;
    }
    
    include $handlerFile;
    exit;
}