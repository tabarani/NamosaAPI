<?php
/**
 * Namosa API - Main Entry Point
 * Handles all API requests for version 1
 */

// Enable error reporting for development (disable in production)
define('DEBUG', true);

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Set default timezone
date_default_timezone_set('UTC');

// Start output buffering
ob_start();

// CORS headers (handle preflight OPTIONS requests)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Bootstrap Gibbon if available
$gibbonBootstrapPath = __DIR__ . '/../../../../gibbon/lib/bootstrap.php';
if (file_exists($gibbonBootstrapPath)) {
    require_once $gibbonBootstrapPath;
}

// Autoloader
require_once __DIR__ . '/../../../vendor/autoload.php';

use NamosaAPI\Lib\Response;
use NamosaAPI\Middleware\AuthMiddleware;
use NamosaAPI\Config\Database;

// Get API settings from Gibbon or environment
function getApiSetting($name, $default = null)
{
    global $connection2;
    
    if (isset($connection2)) {
        try {
            $result = $connection2->query("
                SELECT value FROM gibbonSetting 
                WHERE scope='Namosa API' AND name='$name'
            ")->fetch();
            
            if ($result && !empty($result['value'])) {
                return $result['value'];
            }
        } catch (\Exception $e) {
            // Setting not found, use default
        }
    }
    
    return getenv('NAMOSA_API_' . strtoupper($name)) ?: $default;
}

// API Configuration
$apiEnabled = getApiSetting('api_enabled', 'Y') === 'Y';
if (!$apiEnabled) {
    Response::error('API is currently disabled', 503, 'SERVICE_UNAVAILABLE');
}

$jwtSecret = getApiSetting('jwt_secret', 'change-this-secret-key-in-production');
$tokenLifetime = (int) getApiSetting('token_lifetime', 3600);
$corsOrigins = getApiSetting('cors_origins', '*');

// Set CORS origin dynamically
if ($corsOrigins !== '*') {
    $allowedOrigins = array_map('trim', explode(',', $corsOrigins));
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    if (in_array($origin, $allowedOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
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
    // Authentication
    'POST /auth/token' => ['controller' => 'AuthController', 'method' => 'generateToken'],
    'POST /auth/validate' => ['controller' => 'AuthController', 'method' => 'validateToken'],
    'POST /auth/revoke' => ['controller' => 'AuthController', 'method' => 'revokeToken'],
    
    // Students
    'GET /students' => ['controller' => 'StudentController', 'method' => 'index', 'auth' => true],
    'GET /students/search' => ['controller' => 'StudentController', 'method' => 'search', 'auth' => true],
    'GET /students/([0-9]+)/?' => ['controller' => 'StudentController', 'method' => 'show', 'auth' => true, 'param' => 'id'],
    'GET /students/([0-9]+)/parents/?' => ['controller' => 'StudentController', 'method' => 'getParents', 'auth' => true, 'param' => 'id'],
    'GET /students/([0-9]+)/siblings/?' => ['controller' => 'StudentController', 'method' => 'getSiblings', 'auth' => true, 'param' => 'id'],
    
    // Add more routes here...
];

// Find matching route
$routeMatched = false;
$matchedRoute = null;
$routeParams = [];

foreach ($routes as $routePattern => $routeConfig) {
    $pattern = '/^' . str_replace('/', '\/', $routePattern) . '$/';
    $pattern = preg_replace('/\(([^\)]+)\)/', '($1)', $pattern);
    
    if (preg_match($pattern, "$requestMethod $path", $matches)) {
        $routeMatched = true;
        $matchedRoute = $routeConfig;
        
        // Extract parameters
        if (isset($routeConfig['param'])) {
            $paramName = $routeConfig['param'];
            $routeParams[$paramName] = $matches[1] ?? null;
        }
        
        break;
    }
}

// Route not found
if (!$routeMatched) {
    Response::error('Endpoint not found', 404, 'NOT_FOUND');
}

// Authentication check
if (isset($matchedRoute['auth']) && $matchedRoute['auth']) {
    $authMiddleware = new AuthMiddleware($jwtSecret, $tokenLifetime);
    
    $requiredScopes = $matchedRoute['scopes'] ?? [];
    $authMiddleware->handle($requiredScopes);
}

// Instantiate controller and call method
try {
    $controllerClass = "NamosaAPI\\Controllers\\" . $matchedRoute['controller'];
    
    if (!class_exists($controllerClass)) {
        Response::error('Controller not found', 500, 'INTERNAL_ERROR');
    }
    
    // Pass constructor arguments if needed
    if ($matchedRoute['controller'] === 'AuthController') {
        $controller = new $controllerClass($jwtSecret, $tokenLifetime);
    } else {
        $controller = new $controllerClass();
    }
    
    $method = $matchedRoute['method'];
    
    if (!method_exists($controller, $method)) {
        Response::error('Method not found', 500, 'INTERNAL_ERROR');
    }
    
    // Call controller method with parameters
    if (!empty($routeParams)) {
        call_user_func_array([$controller, $method], array_values($routeParams));
    } else {
        $controller->$method();
    }
    
} catch (\Exception $e) {
    error_log("API Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    
    if (DEBUG) {
        Response::error(
            'Internal server error',
            500,
            'INTERNAL_ERROR',
            $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine()
        );
    } else {
        Response::error('Internal server error', 500, 'INTERNAL_ERROR');
    }
}

// Cleanup
Database::getInstance()->close();