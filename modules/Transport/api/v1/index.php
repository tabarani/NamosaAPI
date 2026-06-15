<?php
/**
 * Transport Module RESTful API Handler
 * 
 * Supports dual authentication:
 * 1. JWT Bearer Token (from IdentityProvider via NamosaAPI)
 * 2. Legacy API Key (for backward compatibility)
 * 
 * Endpoints:
 * - GET /api/v1/routes - List all bus routes
 * - GET /api/v1/routes/{id} - Get route details
 * - GET /api/v1/students - Students assigned to transport
 * - GET /api/v1/stops - All bus stops
 * - GET /api/v1/events - Daily transport events
 * - POST /api/v1/events - Create event (check-in/out)
 * - GET /api/v1/alerts - Safety alerts
 * - POST /api/v1/alerts - Create alert
 */

use Gibbon\Module\NamosaAPI\Moodle\MoodleSyncService;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../../../gibbon.php';

// Disable HTML output for API
ob_end_clean();

// Authentication
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';

$authenticated = false;
$userData = null;

// Method 1: JWT Bearer Token
if (preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
    $token = $matches[1];
    
    // Load JWT Validator from NamosaAPI
    require_once __DIR__ . '/../../NamosaAPI/lib/JWTValidator.php';
    
    $jwksUrl = $session->get('idpJwksUrl') ?? '';
    $issuer = $session->get('idpIssuer') ?? '';
    $audience = $session->get('idpAudience') ?? 'namosa-api';
    
    if (!empty($jwksUrl)) {
        $validator = new \Gibbon\Module\NamosaAPI\JWTValidator($jwksUrl, $issuer, $audience);
        $payload = $validator->validateToken($token);
        
        if ($payload && isset($payload['sub'])) {
            $authenticated = true;
            $gibbonPersonID = $payload['sub']; // or custom claim
            
            // Fetch user data
            $data = [
                'gibbonPersonID' => $gibbonPersonID,
                'roles' => [] // Load roles if needed
            ];
            $userData = $data;
        }
    }
}

// Method 2: API Key (legacy)
if (!$authenticated && !empty($apiKey)) {
    $sql = "SELECT gibbonPersonID, role FROM gibbonTransportAPIKey 
            WHERE apiKey = :key AND active = 'Y'";
    $result = $database->execute($sql, ['key' => $apiKey]);
    
    if ($result->rowCount() > 0) {
        $row = $result->fetch();
        $authenticated = true;
        $userData = [
            'gibbonPersonID' => $row['gibbonPersonID'],
            'roles' => [$row['role']]
        ];
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Valid JWT token or API key required']);
    exit;
}

// Permission check (optional for certain endpoints)
$requiredPermission = null;
$endpoint = $_GET['endpoint'] ?? '';

// Route handling
$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_GET['path'] ?? '';
$parts = explode('/', trim($pathInfo, '/'));

$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;

// Define permission requirements
$permissionMap = [
    'routes' => 'transport_view',
    'students' => 'transport_view',
    'stops' => 'transport_view',
    'events' => 'transport_view',
    'alerts' => 'transport_manage'
];

if (isset($permissionMap[$resource])) {
    if (!$session->hasPermission($permissionMap[$resource])) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'message' => 'Insufficient permissions']);
        exit;
    }
}

// Process request
$response = null;

try {
    switch ($resource) {
        case 'routes':
            $response = handleRoutes($database, $requestMethod, $id);
            break;
            
        case 'students':
            $response = handleStudents($database, $requestMethod);
            break;
            
        case 'stops':
            $response = handleStops($database, $requestMethod);
            break;
            
        case 'events':
            $response = handleEvents($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
            
        case 'alerts':
            $response = handleAlerts($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
            
        default:
            http_response_code(404);
            $response = ['error' => 'Not Found', 'message' => 'Unknown resource: ' . $resource];
    }
} catch (\Exception $e) {
    http_response_code(500);
    $response = ['error' => 'Internal Server Error', 'message' => $e->getMessage()];
}

echo json_encode($response);

// --- Handler Functions ---

function handleRoutes($database, string $method, ?string $id): array
{
    if ($method === 'GET') {
        if ($id) {
            // Get single route
            $sql = "SELECT * FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :id";
            $data = $database->execute($sql, ['id' => $id]);
            return $data->rowCount() > 0 ? $data->fetch() : ['error' => 'Route not found'];
        } else {
            // List all routes with pagination
            $limit = (int)($_GET['limit'] ?? 50);
            $offset = (int)($_GET['offset'] ?? 0);
            
            $sql = "SELECT * FROM gibbonTransportRoute ORDER BY name LIMIT :limit OFFSET :offset";
            $stmt = $database->getConnection()->prepare($sql);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            return [
                'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
                'pagination' => ['limit' => $limit, 'offset' => $offset]
            ];
        }
    }
    
    return ['error' => 'Method not allowed'];
}

function handleStudents($database, string $method): array
{
    if ($method === 'GET') {
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        $search = $_GET['search'] ?? '';
        
        $sql = "SELECT p.gibbonPersonID, p.surname, p.preferredName, p.email, 
                       r.name as routeName, s.stopName
                FROM gibbonPerson p
                JOIN gibbonTransportStudent ts ON p.gibbonPersonID = ts.gibbonPersonID
                LEFT JOIN gibbonTransportRoute r ON ts.gibbonTransportRouteID = r.gibbonTransportRouteID
                LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
                WHERE p.status = 'Full'
                AND (p.surname LIKE :search OR p.preferredName LIKE :search OR p.email LIKE :search)
                ORDER BY p.surname
                LIMIT :limit OFFSET :offset";
        
        $stmt = $database->getConnection()->prepare($sql);
        $stmt->bindValue(':search', '%' . $search . '%', \PDO::PARAM_STR);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return [
            'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC),
            'pagination' => ['limit' => $limit, 'offset' => $offset, 'search' => $search]
        ];
    }
    
    return ['error' => 'Method not allowed'];
}

function handleStops($database, string $method): array
{
    if ($method === 'GET') {
        $sql = "SELECT * FROM gibbonTransportStop ORDER BY sequenceNumber";
        $data = $database->execute($sql);
        return ['data' => $data->fetchAll(\PDO::FETCH_ASSOC)];
    }
    
    return ['error' => 'Method not allowed'];
}

function handleEvents($database, string $method, ?string $id, int $userId): array
{
    if ($method === 'GET') {
        // Get today's events
        $today = date('Y-m-d');
        $sql = "SELECT e.*, p.surname, p.preferredName, r.name as routeName
                FROM gibbonTransportEvent e
                JOIN gibbonPerson p ON e.gibbonPersonID = p.gibbonPersonID
                LEFT JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
                WHERE DATE(e.timestamp) = :today
                ORDER BY e.timestamp DESC";
        
        $data = $database->execute($sql, ['today' => $today]);
        return ['data' => $data->fetchAll(\PDO::FETCH_ASSOC)];
    }
    
    if ($method === 'POST') {
        // Create check-in/out event
        $input = json_decode(file_get_contents('php://input'), true);
        
        $gibbonPersonID = $input['gibbonPersonID'] ?? null;
        $routeID = $input['gibbonTransportRouteID'] ?? null;
        $type = $input['type'] ?? 'check_in'; // check_in, check_out, absent
        $notes = $input['notes'] ?? '';
        
        if (!$gibbonPersonID) {
            return ['error' => 'Missing gibbonPersonID'];
        }
        
        $sql = "INSERT INTO gibbonTransportEvent 
                (gibbonPersonID, gibbonTransportRouteID, type, timestamp, notes, gibbonPersonIDCreator)
                VALUES (:person, :route, :type, NOW(), :notes, :creator)";
        
        $result = $database->execute($sql, [
            'person' => $gibbonPersonID,
            'route' => $routeID,
            'type' => $type,
            'notes' => $notes,
            'creator' => $userId
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Event recorded', 'id' => $database->lastInsertID()];
        }
        
        return ['error' => 'Failed to create event'];
    }
    
    return ['error' => 'Method not allowed'];
}

function handleAlerts($database, string $method, ?string $id, int $userId): array
{
    if ($method === 'GET') {
        $sql = "SELECT a.*, p.surname as creatorSurname 
                FROM gibbonTransportAlert a
                JOIN gibbonPerson p ON a.gibbonPersonIDCreator = p.gibbonPersonID
                ORDER BY a.timestamp DESC
                LIMIT 50";
        
        $data = $database->execute($sql);
        return ['data' => $data->fetchAll(\PDO::FETCH_ASSOC)];
    }
    
    if ($method === 'POST') {
        // Create safety alert
        $input = json_decode(file_get_contents('php://input'), true);
        
        $gibbonPersonID = $input['gibbonPersonID'] ?? null;
        $routeID = $input['gibbonTransportRouteID'] ?? null;
        $severity = $input['severity'] ?? 'medium'; // low, medium, high
        $message = $input['message'] ?? '';
        
        if (!$gibbonPersonID || !$message) {
            return ['error' => 'Missing required fields'];
        }
        
        $sql = "INSERT INTO gibbonTransportAlert 
                (gibbonPersonID, gibbonTransportRouteID, severity, message, timestamp, gibbonPersonIDCreator)
                VALUES (:person, :route, :severity, :message, NOW(), :creator)";
        
        $result = $database->execute($sql, [
            'person' => $gibbonPersonID,
            'route' => $routeID,
            'severity' => $severity,
            'message' => $message,
            'creator' => $userId
        ]);
        
        if ($result) {
            return ['success' => true, 'message' => 'Alert created', 'id' => $database->lastInsertID()];
        }
        
        return ['error' => 'Failed to create alert'];
    }
    
    return ['error' => 'Method not allowed'];
}
