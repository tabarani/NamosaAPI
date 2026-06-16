<?php
/**
 * Transport Module - Students on Routes Endpoint
 * GET /transport/api/v1/students
 * 
 * Returns students assigned to bus routes with their route details
 * Requires: transport_read permission or valid API key
 */

// Bootstrap Gibbon
require_once __DIR__ . '/../../../gibbon.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit;
}

// Only allow GET method
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Initialize Rate Limiter
require_once __DIR__ . '/../lib/RateLimiter.php';

// Get API key from request
$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';

// Check rate limit before authentication
$rateLimiter = new \Gibbon\Module\Transport\RateLimiter(null, $apiKey);

if (!$rateLimiter->allowRequest(null, $apiKey)) {
    $rateLimiter->sendRateLimitResponse();
    exit;
}

try {
    // Initialize API handler
    require_once __DIR__ . '/../lib/TransportAPIHandler.php';
    $apiHandler = new TransportAPIHandler($connection2);
    
    // Authenticate
    $authResult = $apiHandler->authenticate();
    
    if (!$authResult['authenticated']) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'error' => $authResult['error']
        ]);
        exit;
    }
    
    // Re-check rate limit with authenticated status (higher limit for authenticated users)
    if (!$rateLimiter->allowRequest($authResult['gibbonPersonID'], $apiKey)) {
        $rateLimiter->sendRateLimitResponse();
        exit;
    }
    
    // Check permission (skip for API keys)
    if ($authResult['authMethod'] === 'JWT') {
        if (!$apiHandler->checkPermission($authResult['gibbonPersonID'], 'transport_read')) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Insufficient permissions. Required: transport_read'
            ]);
            exit;
        }
    }
    
    // Get pagination params
    $pagination = $apiHandler->getPaginationParams();
    $limit = $pagination['limit'];
    $offset = $pagination['offset'];
    
    // Parse filters
    $routeID = $_GET['routeID'] ?? null;
    $stopID = $_GET['stopID'] ?? null;
    $studentID = $_GET['studentID'] ?? null;
    $status = $_GET['status'] ?? 'Y';
    $search = trim($_GET['search'] ?? '');
    
    // Build query
    $whereConditions = ['tsr.status = :status'];
    $params = ['status' => $status];
    
    if ($routeID) {
        $whereConditions[] = 'tr.gibbonTransportRouteID = :routeID';
        $params['routeID'] = $routeID;
    }
    
    if ($stopID) {
        $whereConditions[] = 'ts.gibbonTransportStopID = :stopID';
        $params['stopID'] = $stopID;
    }
    
    if ($studentID) {
        $whereConditions[] = 'gp.gibbonPersonID = :studentID';
        $params['studentID'] = $studentID;
    }
    
    if ($search) {
        $whereConditions[] = '(gp.surname LIKE :search OR gp.preferredName LIKE :search)';
        $params['search'] = '%' . $search . '%';
    }
    
    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);
    
    // Count total
    $countSql = "
        SELECT COUNT(*) as total
        FROM gibbonTransportStudent tsr
        JOIN gibbonPerson gp ON tsr.gibbonPersonID = gp.gibbonPersonID
        LEFT JOIN gibbonTransportRoute tr ON tsr.gibbonTransportRouteID = tr.gibbonTransportRouteID
        LEFT JOIN gibbonTransportStop ts ON tsr.gibbonTransportStopID = ts.gibbonTransportStopID
        $whereClause
    ";
    
    $countStmt = $connection2->execute($params, $countSql);
    $total = $countStmt->fetch()['total'] ?? 0;
    
    // Get students on routes
    $selectSql = "
        SELECT 
            gp.gibbonPersonID,
            gp.surname,
            gp.preferredName,
            gp.email,
            gp.phone1 as studentPhone,
            gp.dateOfBirth,
            gp.gender,
            gp.address,
            tr.gibbonTransportRouteID,
            tr.name as routeName,
            tr.description as routeDescription,
            tr.vehicleCapacity,
            ts.gibbonTransportStopID,
            ts.name as stopName,
            ts.address as stopAddress,
            ts.sequenceNumber as stopSequence,
            tsr.pickupTime,
            tsr.dropoffTime,
            tsr.status,
            tsr.comment
        FROM gibbonTransportStudent tsr
        JOIN gibbonPerson gp ON tsr.gibbonPersonID = gp.gibbonPersonID
        LEFT JOIN gibbonTransportRoute tr ON tsr.gibbonTransportRouteID = tr.gibbonTransportRouteID
        LEFT JOIN gibbonTransportStop ts ON tsr.gibbonTransportStopID = ts.gibbonTransportStopID
        $whereClause
        ORDER BY tr.name, ts.sequenceNumber, gp.surname
        LIMIT :limit OFFSET :offset
    ";
    
    $selectStmt = $connection2->prepare($selectSql);
    foreach ($params as $key => $value) {
        $selectStmt->bindValue(':' . $key, $value);
    }
    $selectStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->execute();
    
    $students = $selectStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Response
    $apiHandler->respond([
        'success' => true,
        'data' => $students,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ],
        'meta' => [
            'requestedBy' => $authResult['gibbonPersonID'],
            'authMethod' => $authResult['authMethod'],
            'timestamp' => date('c')
        ]
    ]);
    
} catch (Exception $e) {
    error_log('Transport API Students Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);
}
