<?php
/*
Transport Module API v1 - Students on Routes Endpoint
GET /api/v1/students
*/

require_once __DIR__ . '/../../../config.php';
require_once __DIR__ . '/../lib/TransportAPIHandler.php';

use Gibbon\Module\Transport\API\TransportAPIHandler;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Connect to database
try {
    $pdo = new PDO(
        "mysql:host={$databaseServer};port={$databasePort ?? 3306};dbname={$databaseName}",
        $databaseUsername,
        $databasePassword,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Authenticate
$apiHandler = new TransportAPIHandler($pdo);
$authResult = $apiHandler->authenticate();

if (!$authResult['success']) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => $authResult['error']
    ]);
    exit;
}

// Check permission
if (!$apiHandler->hasPermission('transport_read')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'error' => 'Insufficient permissions'
    ]);
    exit;
}

// Get parameters
$routeID = $_GET['routeID'] ?? null;
$date = $_GET['date'] ?? date('Y-m-d');
$status = $_GET['status'] ?? 'Full';

try {
    $data = ['status' => $status];
    
    $sql = "SELECT 
                p.gibbonPersonID,
                p.surname,
                p.preferredName,
                p.email,
                p.phone1 AS parentPhone,
                ts.gibbonTransportStudentID,
                ts.pickupArea,
                ts.dropoffArea,
                ts.seatNumber,
                tr.name AS routeName,
                tr.vehicleNumber
            FROM gibbonPerson p
            JOIN gibbonTransportStudent ts ON p.gibbonPersonID = ts.gibbonPersonID
            JOIN gibbonTransportRoute tr ON ts.gibbonTransportRouteID = tr.gibbonTransportRouteID
            WHERE p.status = :status";

    if ($routeID) {
        $sql .= " AND ts.gibbonTransportRouteID = :routeID";
        $data['routeID'] = $routeID;
    }

    // Filter by today's attendance if date specified
    if ($date) {
        $sql .= " AND ts.date <= :date AND (ts.dateEnd IS NULL OR ts.dateEnd >= :date)";
        $data['date'] = $date;
    }

    $sql .= " ORDER BY tr.name, p.surname";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($data);
    
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $students,
        'meta' => [
            'total' => count($students),
            'routeID' => $routeID,
            'date' => $date
        ],
        'auth' => [
            'method' => $apiHandler->isJWT() ? 'JWT' : 'API Key',
            'user' => $apiHandler->getUser() ? $apiHandler->getUser()['gibbonPersonID'] : null
        ]
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'message' => $e->getMessage()
    ]);
}
