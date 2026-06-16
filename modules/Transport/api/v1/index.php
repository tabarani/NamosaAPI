<?php
/**
 * Transport Module RESTful API Handler
 *
 * Supports dual authentication:
 * 1. JWT Bearer Token (from IdentityProvider via NamosaAPI)
 * 2. Legacy API Key (for backward compatibility)
 *
 * Core endpoints:
 * - GET  /api/v1/routes
 * - GET  /api/v1/routes/{id}
 * - GET  /api/v1/students
 * - GET  /api/v1/stops
 * - GET  /api/v1/events
 * - POST /api/v1/events
 * - GET  /api/v1/alerts
 * - POST /api/v1/alerts
 *
 * Feature endpoints:
 * - GET  /api/v1/transport-status/child/{gibbonPersonID}
 * - GET  /api/v1/boarding/route/{routeID}
 * - POST /api/v1/boarding/events
 * - POST /api/v1/notifications
 * - GET  /api/v1/missing-alerts
 * - POST /api/v1/missing-alerts/run
 * - GET  /api/v1/supervisor/routes/{routeID}
 * - POST /api/v1/sync/offline-events
 * - POST /api/v1/tracking/locations
 * - GET  /api/v1/tracking/vehicles/{routeID}
 * - GET  /api/v1/eta/route/{routeID}
 * - GET  /api/v1/planning/routes
 * - GET|POST /api/v1/vehicles
 * - POST /api/v1/emergency
 * - GET|POST /api/v1/incidents
 * - GET|POST /api/v1/pickup-rules
 * - POST /api/v1/qr/resolve
 * - POST /api/v1/photos
 * - GET|POST /api/v1/billing
 * - GET  /api/v1/reports/{reportName}
 * - GET  /api/v1/audit-logs
 * - GET  /api/v1/integrations/oneroster/export
 * - GET  /api/v1/scenarios
 */

use Gibbon\Module\Core\JWTValidator;
use Gibbon\Module\Transport\RateLimiter;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once '../../../../gibbon.php';

while (ob_get_level() > 0) {
    ob_end_clean();
}

$apiKey = $_GET['api_key'] ?? $_POST['api_key'] ?? '';
$rateLimiter = new RateLimiter(null, $apiKey);

if (!$rateLimiter->allowRequest(null, $apiKey)) {
    $rateLimiter->sendRateLimitResponse();
    exit;
}

$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$authenticated = false;
$userData = ['gibbonPersonID' => null, 'roles' => [], 'authMethod' => null];

if (preg_match('/Bearer\s+(.*)/', $authHeader, $matches)) {
    $token = $matches[1];
    require_once __DIR__ . '/../../../Core/lib/JWTValidator.php';

    $jwksUrl = $session->get('idpJwksUrl') ?? '';
    $issuer = $session->get('idpIssuer') ?? '';
    $audience = $session->get('idpAudience') ?? 'namosa-api';

    if (!empty($jwksUrl)) {
        $validator = new JWTValidator($jwksUrl, $issuer, $audience);
        $payload = $validator->validate($token);

        if ($payload && isset($payload['sub'])) {
            $authenticated = true;
            $userData = [
                'gibbonPersonID' => (int)$payload['sub'],
                'roles' => [],
                'authMethod' => 'JWT'
            ];

            if (!$rateLimiter->allowRequest($userData['gibbonPersonID'], true)) {
                $rateLimiter->sendRateLimitResponse();
                exit;
            }
        }
    }
}

if (!$authenticated && !empty($apiKey)) {
    $sql = "SELECT gibbonPersonID FROM gibbonTransportAPIKey WHERE apiKey = :apiKey AND active = 1";
    $result = $database->execute($sql, ['apiKey' => $apiKey]);

    if ($result && $result->rowCount() > 0) {
        $row = $result->fetch();
        $authenticated = true;
        $userData = [
            'gibbonPersonID' => isset($row['gibbonPersonID']) ? (int)$row['gibbonPersonID'] : null,
            'roles' => [],
            'authMethod' => 'API_KEY'
        ];

        if (!$rateLimiter->allowRequest($apiKey, true)) {
            $rateLimiter->sendRateLimitResponse();
            exit;
        }
    }
}

if (!$authenticated) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'message' => 'Valid JWT token or API key required']);
    exit;
}

$requestMethod = $_SERVER['REQUEST_METHOD'];
$pathInfo = $_GET['path'] ?? '';
$parts = array_values(array_filter(explode('/', trim($pathInfo, '/'))));
$resource = $parts[0] ?? '';
$id = $parts[1] ?? null;

$permissionMap = [
    'routes' => 'transport_view',
    'students' => 'transport_view',
    'stops' => 'transport_view',
    'events' => 'transport_view',
    'alerts' => 'transport_manage',
    'transport-status' => 'transport_view',
    'boarding' => 'transport_manage',
    'notifications' => 'transport_manage',
    'missing-alerts' => 'transport_manage',
    'supervisor' => 'transport_view',
    'sync' => 'transport_manage',
    'tracking' => 'transport_view',
    'eta' => 'transport_view',
    'planning' => 'transport_manage',
    'vehicles' => 'transport_manage',
    'emergency' => 'transport_manage',
    'incidents' => 'transport_manage',
    'pickup-rules' => 'transport_manage',
    'qr' => 'transport_manage',
    'photos' => 'transport_manage',
    'billing' => 'transport_manage',
    'reports' => 'transport_view',
    'audit-logs' => 'transport_manage',
    'integrations' => 'transport_manage',
    'scenarios' => 'transport_view'
];

if (isset($permissionMap[$resource]) && method_exists($session, 'hasPermission') && !$session->hasPermission($permissionMap[$resource])) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden', 'message' => 'Insufficient permissions']);
    exit;
}

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
        case 'transport-status':
            $response = handleTransportStatus($database, $requestMethod, $parts);
            break;
        case 'boarding':
            $response = handleBoarding($database, $requestMethod, $parts, $userData['gibbonPersonID']);
            break;
        case 'notifications':
            $response = handleNotifications($database, $requestMethod, $userData['gibbonPersonID']);
            break;
        case 'missing-alerts':
            $response = handleMissingAlerts($database, $requestMethod, $parts, $userData['gibbonPersonID']);
            break;
        case 'supervisor':
            $response = handleSupervisor($database, $requestMethod, $parts, $userData['gibbonPersonID']);
            break;
        case 'sync':
            $response = handleOfflineSync($database, $requestMethod, $parts, $userData['gibbonPersonID']);
            break;
        case 'tracking':
            $response = handleTracking($database, $requestMethod, $parts, $userData['gibbonPersonID']);
            break;
        case 'eta':
            $response = handleEta($database, $requestMethod, $parts);
            break;
        case 'planning':
            $response = handlePlanning($database, $requestMethod, $parts);
            break;
        case 'vehicles':
            $response = handleVehicles($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
        case 'emergency':
            $response = handleEmergency($database, $requestMethod, $userData['gibbonPersonID']);
            break;
        case 'incidents':
            $response = handleIncidents($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
        case 'pickup-rules':
            $response = handlePickupRules($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
        case 'qr':
            $response = handleQr($database, $requestMethod, $parts);
            break;
        case 'photos':
            $response = handlePhotos($database, $requestMethod, $userData['gibbonPersonID']);
            break;
        case 'billing':
            $response = handleBilling($database, $requestMethod, $id, $userData['gibbonPersonID']);
            break;
        case 'reports':
            $response = handleReports($database, $requestMethod, $id);
            break;
        case 'audit-logs':
            $response = handleAuditLogs($database, $requestMethod);
            break;
        case 'integrations':
            $response = handleIntegrations($database, $requestMethod, $parts);
            break;
        case 'scenarios':
            $response = handleScenarios($requestMethod);
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

function getInput(): array
{
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    return is_array($input) ? $input : [];
}

function pdo($database): \PDO
{
    return $database->getConnection();
}

function tableExists($database, string $table): bool
{
    $stmt = pdo($database)->prepare('SHOW TABLES LIKE :tableName');
    $stmt->execute(['tableName' => $table]);
    return $stmt->rowCount() > 0;
}

function migrationRequired(string $table): array
{
    return [
        'data' => [],
        'requiresMigration' => true,
        'message' => "Run modules/Transport/sql/migrate_v1.2_to_v1.3.sql to create {$table}."
    ];
}

function pagination(): array
{
    return [
        'limit' => min(max((int)($_GET['limit'] ?? 50), 1), 200),
        'offset' => max((int)($_GET['offset'] ?? 0), 0)
    ];
}

function successCreated($database, string $message): array
{
    return ['success' => true, 'message' => $message, 'id' => pdo($database)->lastInsertId()];
}

function methodNotAllowed(): array
{
    http_response_code(405);
    return ['error' => 'Method not allowed'];
}

function handleRoutes($database, string $method, ?string $id): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    if ($id) {
        $stmt = pdo($database)->prepare('SELECT * FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :id');
        $stmt->execute(['id' => $id]);
        $route = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $route ?: ['error' => 'Route not found'];
    }

    $page = pagination();
    $stmt = pdo($database)->prepare('SELECT * FROM gibbonTransportRoute ORDER BY name LIMIT :limit OFFSET :offset');
    $stmt->bindValue(':limit', $page['limit'], \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $page['offset'], \PDO::PARAM_INT);
    $stmt->execute();

    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'pagination' => $page];
}

function handleStudents($database, string $method): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    $page = pagination();
    $search = $_GET['search'] ?? '';
    $stmt = pdo($database)->prepare(
        "SELECT p.gibbonPersonID, p.surname, p.preferredName, p.email,
                r.name AS routeName, s.name AS stopName, ts.status, ts.specialNeeds
         FROM gibbonPerson p
         JOIN gibbonTransportStudent ts ON p.gibbonPersonID = ts.gibbonPersonID
         LEFT JOIN gibbonTransportRoute r ON ts.gibbonTransportRouteID = r.gibbonTransportRouteID
         LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
         WHERE p.status = 'Full'
         AND (p.surname LIKE :search OR p.preferredName LIKE :search OR p.email LIKE :search)
         ORDER BY p.surname
         LIMIT :limit OFFSET :offset"
    );
    $stmt->bindValue(':search', '%' . $search . '%', \PDO::PARAM_STR);
    $stmt->bindValue(':limit', $page['limit'], \PDO::PARAM_INT);
    $stmt->bindValue(':offset', $page['offset'], \PDO::PARAM_INT);
    $stmt->execute();

    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'pagination' => $page + ['search' => $search]];
}

function handleStops($database, string $method): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    $routeId = $_GET['routeID'] ?? null;
    if ($routeId) {
        $stmt = pdo($database)->prepare('SELECT * FROM gibbonTransportStop WHERE gibbonTransportRouteID = :routeID ORDER BY sequenceNumber');
        $stmt->execute(['routeID' => $routeId]);
    } else {
        $stmt = pdo($database)->query('SELECT * FROM gibbonTransportStop ORDER BY gibbonTransportRouteID, sequenceNumber');
    }

    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
}

function handleEvents($database, string $method, ?string $id, ?int $userId): array
{
    if ($method === 'GET') {
        $date = $_GET['date'] ?? date('Y-m-d');
        $stmt = pdo($database)->prepare(
            "SELECT e.*, p.surname, p.preferredName, r.name AS routeName, s.name AS stopName
             FROM gibbonTransportEvent e
             JOIN gibbonPerson p ON e.gibbonPersonID = p.gibbonPersonID
             LEFT JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
             LEFT JOIN gibbonTransportStop s ON e.gibbonTransportStopID = s.gibbonTransportStopID
             WHERE DATE(e.timestamp) = :eventDate
             ORDER BY e.timestamp DESC"
        );
        $stmt->execute(['eventDate' => $date]);
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'date' => $date];
    }

    if ($method === 'POST') {
        return createTransportEvent($database, getInput(), $userId, 'Event recorded');
    }

    return methodNotAllowed();
}

function createTransportEvent($database, array $input, ?int $userId, string $message): array
{
    $personId = $input['gibbonPersonID'] ?? null;
    $routeId = $input['gibbonTransportRouteID'] ?? null;
    $stopId = $input['gibbonTransportStopID'] ?? null;
    $type = $input['type'] ?? 'pickup';
    $status = $input['status'] ?? 'Verified';

    if (!$personId || !$routeId || !in_array($type, ['pickup', 'dropoff'], true)) {
        http_response_code(422);
        return ['error' => 'Missing or invalid gibbonPersonID, gibbonTransportRouteID, or type'];
    }

    $stmt = pdo($database)->prepare(
        "INSERT INTO gibbonTransportEvent
         (gibbonPersonID, gibbonTransportRouteID, gibbonTransportStopID, type, timestamp, status,
          gibbonPersonIDRecorder, latitude, longitude, photoUrl, comments, emergencyFlag, emergencyNotes, syncStatus, syncTimestamp)
         VALUES
         (:person, :route, :stop, :type, COALESCE(:eventTime, NOW()), :status,
          :recorder, :latitude, :longitude, :photoUrl, :comments, :emergencyFlag, :emergencyNotes, :syncStatus, NOW())"
    );
    $stmt->execute([
        'person' => $personId,
        'route' => $routeId,
        'stop' => $stopId,
        'type' => $type,
        'eventTime' => $input['timestamp'] ?? null,
        'status' => $status,
        'recorder' => $userId,
        'latitude' => $input['latitude'] ?? null,
        'longitude' => $input['longitude'] ?? null,
        'photoUrl' => $input['photoUrl'] ?? null,
        'comments' => $input['comments'] ?? ($input['notes'] ?? null),
        'emergencyFlag' => !empty($input['emergencyFlag']) ? 1 : 0,
        'emergencyNotes' => $input['emergencyNotes'] ?? null,
        'syncStatus' => $input['syncStatus'] ?? 'synced'
    ]);

    createAuditLog($database, $userId, 'transport_event.create', 'gibbonTransportEvent', pdo($database)->lastInsertId(), $input);
    return successCreated($database, $message);
}

function handleAlerts($database, string $method, ?string $id, ?int $userId): array
{
    if ($method === 'GET') {
        $stmt = pdo($database)->query('SELECT * FROM gibbonTransportAlert ORDER BY timestampCreated DESC LIMIT 50');
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST') {
        $input = getInput();
        return createAlert($database, $input, $userId, $input['message'] ?? 'Transport alert');
    }

    return methodNotAllowed();
}

function createAlert($database, array $input, ?int $userId, string $defaultMessage): array
{
    $message = $input['message'] ?? $defaultMessage;
    if (!$message) {
        http_response_code(422);
        return ['error' => 'Missing message'];
    }

    $stmt = pdo($database)->prepare(
        "INSERT INTO gibbonTransportAlert
         (alertType, severity, gibbonTransportRouteID, gibbonPersonID, message, smsSent, smsRecipients, resolved, resolvedBy, resolvedAt, resolvedNotes)
         VALUES (:alertType, :severity, :routeID, :personID, :message, :smsSent, :smsRecipients, 0, NULL, NULL, NULL)"
    );
    $stmt->execute([
        'alertType' => $input['alertType'] ?? 'custom',
        'severity' => $input['severity'] ?? 'medium',
        'routeID' => $input['gibbonTransportRouteID'] ?? null,
        'personID' => $input['gibbonPersonID'] ?? null,
        'message' => $message,
        'smsSent' => !empty($input['smsSent']) ? 1 : 0,
        'smsRecipients' => isset($input['smsRecipients']) ? json_encode($input['smsRecipients']) : null
    ]);

    createAuditLog($database, $userId, 'transport_alert.create', 'gibbonTransportAlert', pdo($database)->lastInsertId(), $input);
    return successCreated($database, 'Alert created');
}

function handleTransportStatus($database, string $method, array $parts): array
{
    if ($method !== 'GET' || ($parts[1] ?? '') !== 'child' || empty($parts[2])) {
        return methodNotAllowed();
    }

    $personId = (int)$parts[2];
    $stmt = pdo($database)->prepare(
        "SELECT ts.*, r.name AS routeName, r.vehicleNumber, s.name AS stopName, s.estimatedArrivalTime
         FROM gibbonTransportStudent ts
         LEFT JOIN gibbonTransportRoute r ON ts.gibbonTransportRouteID = r.gibbonTransportRouteID
         LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
         WHERE ts.gibbonPersonID = :personID AND ts.status = 'Active'
         LIMIT 1"
    );
    $stmt->execute(['personID' => $personId]);
    $assignment = $stmt->fetch(\PDO::FETCH_ASSOC);

    $events = pdo($database)->prepare(
        "SELECT * FROM gibbonTransportEvent WHERE gibbonPersonID = :personID AND DATE(timestamp) = CURDATE() ORDER BY timestamp DESC LIMIT 5"
    );
    $events->execute(['personID' => $personId]);

    $alerts = pdo($database)->prepare(
        "SELECT * FROM gibbonTransportAlert WHERE gibbonPersonID = :personID AND resolved = 0 ORDER BY timestampCreated DESC LIMIT 3"
    );
    $alerts->execute(['personID' => $personId]);

    return [
        'studentID' => $personId,
        'assignment' => $assignment,
        'latestEvents' => $events->fetchAll(\PDO::FETCH_ASSOC),
        'activeAlerts' => $alerts->fetchAll(\PDO::FETCH_ASSOC)
    ];
}

function handleBoarding($database, string $method, array $parts, ?int $userId): array
{
    if ($method === 'GET' && ($parts[1] ?? '') === 'route' && !empty($parts[2])) {
        $stmt = pdo($database)->prepare(
            "SELECT ts.gibbonTransportStudentID, ts.gibbonPersonID, p.surname, p.preferredName, ts.status,
                    s.name AS stopName, s.sequenceNumber,
                    latest.status AS latestEventStatus, latest.type AS latestEventType, latest.timestamp AS latestEventTime
             FROM gibbonTransportStudent ts
             JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
             LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
             LEFT JOIN (
                SELECT e1.* FROM gibbonTransportEvent e1
                INNER JOIN (
                    SELECT gibbonPersonID, MAX(timestamp) AS maxTimestamp
                    FROM gibbonTransportEvent
                    WHERE DATE(timestamp) = CURDATE()
                    GROUP BY gibbonPersonID
                ) e2 ON e1.gibbonPersonID = e2.gibbonPersonID AND e1.timestamp = e2.maxTimestamp
             ) latest ON latest.gibbonPersonID = ts.gibbonPersonID
             WHERE ts.gibbonTransportRouteID = :routeID AND ts.status = 'Active'
             ORDER BY s.sequenceNumber, p.surname"
        );
        $stmt->execute(['routeID' => (int)$parts[2]]);
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST' && ($parts[1] ?? '') === 'events') {
        return createTransportEvent($database, getInput(), $userId, 'Boarding event recorded');
    }

    return methodNotAllowed();
}

function handleNotifications($database, string $method, ?int $userId): array
{
    if ($method !== 'POST') {
        return methodNotAllowed();
    }

    $input = getInput();
    $recipients = $input['recipients'] ?? [];
    $message = $input['message'] ?? '';

    if (!$message || empty($recipients)) {
        http_response_code(422);
        return ['error' => 'Missing recipients or message'];
    }

    $messageId = $input['messageID'] ?? uniqid('transport_', true);
    $stmt = pdo($database)->prepare(
        "INSERT INTO gibbonTransportSMSHistory
         (messageID, recipients, message, status, gibbonTransportRouteID, gibbonPersonID, gibbonTransportAlertID, createdBy)
         VALUES (:messageID, :recipients, :message, :status, :routeID, :personID, :alertID, :createdBy)"
    );
    $stmt->execute([
        'messageID' => $messageId,
        'recipients' => json_encode($recipients),
        'message' => $message,
        'status' => $input['status'] ?? 'pending',
        'routeID' => $input['gibbonTransportRouteID'] ?? null,
        'personID' => $input['gibbonPersonID'] ?? null,
        'alertID' => $input['gibbonTransportAlertID'] ?? null,
        'createdBy' => $userId
    ]);

    createAuditLog($database, $userId, 'notification.queue', 'gibbonTransportSMSHistory', pdo($database)->lastInsertId(), $input);
    return successCreated($database, 'Notification queued');
}

function handleMissingAlerts($database, string $method, array $parts, ?int $userId): array
{
    if ($method === 'GET') {
        $stmt = pdo($database)->query("SELECT * FROM gibbonTransportAlert WHERE alertType = 'missing_boarding' AND resolved = 0 ORDER BY timestampCreated DESC");
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST' && ($parts[1] ?? '') === 'run') {
        $routeId = getInput()['gibbonTransportRouteID'] ?? ($_GET['routeID'] ?? null);
        $where = $routeId ? 'AND ts.gibbonTransportRouteID = :routeID' : '';
        $stmt = pdo($database)->prepare(
            "SELECT ts.gibbonPersonID, ts.gibbonTransportRouteID
             FROM gibbonTransportStudent ts
             WHERE ts.status = 'Active' {$where}
             AND NOT EXISTS (
                SELECT 1 FROM gibbonTransportEvent e
                WHERE e.gibbonPersonID = ts.gibbonPersonID
                AND e.gibbonTransportRouteID = ts.gibbonTransportRouteID
                AND DATE(e.timestamp) = CURDATE()
             )"
        );
        $routeId ? $stmt->execute(['routeID' => $routeId]) : $stmt->execute();
        $missing = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($missing as $row) {
            createAlert($database, [
                'alertType' => 'missing_boarding',
                'severity' => 'high',
                'gibbonTransportRouteID' => $row['gibbonTransportRouteID'],
                'gibbonPersonID' => $row['gibbonPersonID'],
                'message' => 'Expected transport event has not been recorded for this student today.'
            ], $userId, 'Missing boarding/dropoff event');
        }

        return ['success' => true, 'alertsCreated' => count($missing)];
    }

    return methodNotAllowed();
}

function handleSupervisor($database, string $method, array $parts, ?int $userId): array
{
    if ($method !== 'GET' || ($parts[1] ?? '') !== 'routes' || empty($parts[2])) {
        return methodNotAllowed();
    }

    $routeId = (int)$parts[2];
    $routeStmt = pdo($database)->prepare('SELECT * FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :routeID');
    $routeStmt->execute(['routeID' => $routeId]);

    return [
        'route' => $routeStmt->fetch(\PDO::FETCH_ASSOC),
        'checklist' => handleBoarding($database, 'GET', ['boarding', 'route', $routeId], $userId)['data'],
        'mode' => 'supervisor-mobile'
    ];
}

function handleOfflineSync($database, string $method, array $parts, ?int $userId): array
{
    if ($method !== 'POST' || ($parts[1] ?? '') !== 'offline-events') {
        return methodNotAllowed();
    }

    $input = getInput();
    $events = $input['events'] ?? [];
    $created = [];

    foreach ($events as $event) {
        $event['syncStatus'] = 'synced';
        $created[] = createTransportEvent($database, $event, $userId, 'Offline event synced');
    }

    return ['success' => true, 'syncedCount' => count($created), 'results' => $created];
}

function handleTracking($database, string $method, array $parts, ?int $userId): array
{
    if (!tableExists($database, 'gibbonTransportVehicleLocation')) {
        return migrationRequired('gibbonTransportVehicleLocation');
    }

    if ($method === 'POST' && ($parts[1] ?? '') === 'locations') {
        $input = getInput();
        $stmt = pdo($database)->prepare(
            "INSERT INTO gibbonTransportVehicleLocation
             (gibbonTransportRouteID, vehicleNumber, latitude, longitude, speedKph, bearing, accuracyMeters, recordedBy, source, recordedAt)
             VALUES (:routeID, :vehicleNumber, :latitude, :longitude, :speedKph, :bearing, :accuracyMeters, :recordedBy, :source, COALESCE(:recordedAt, NOW()))"
        );
        $stmt->execute([
            'routeID' => $input['gibbonTransportRouteID'] ?? null,
            'vehicleNumber' => $input['vehicleNumber'] ?? null,
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null,
            'speedKph' => $input['speedKph'] ?? null,
            'bearing' => $input['bearing'] ?? null,
            'accuracyMeters' => $input['accuracyMeters'] ?? null,
            'recordedBy' => $userId,
            'source' => $input['source'] ?? 'mobile',
            'recordedAt' => $input['recordedAt'] ?? null
        ]);
        return successCreated($database, 'Vehicle location recorded');
    }

    if ($method === 'GET' && ($parts[1] ?? '') === 'vehicles' && !empty($parts[2])) {
        $stmt = pdo($database)->prepare(
            'SELECT * FROM gibbonTransportVehicleLocation WHERE gibbonTransportRouteID = :routeID ORDER BY recordedAt DESC LIMIT 1'
        );
        $stmt->execute(['routeID' => (int)$parts[2]]);
        return ['data' => $stmt->fetch(\PDO::FETCH_ASSOC) ?: null];
    }

    return methodNotAllowed();
}

function handleEta($database, string $method, array $parts): array
{
    if ($method !== 'GET' || ($parts[1] ?? '') !== 'route' || empty($parts[2])) {
        return methodNotAllowed();
    }

    $routeId = (int)$parts[2];
    $stmt = pdo($database)->prepare(
        "SELECT s.*, latest.timestamp AS lastEventTime
         FROM gibbonTransportStop s
         LEFT JOIN (
            SELECT gibbonTransportStopID, MAX(timestamp) AS timestamp
            FROM gibbonTransportEvent
            WHERE gibbonTransportRouteID = :routeIDToday AND DATE(timestamp) = CURDATE()
            GROUP BY gibbonTransportStopID
         ) latest ON latest.gibbonTransportStopID = s.gibbonTransportStopID
         WHERE s.gibbonTransportRouteID = :routeID
         ORDER BY s.sequenceNumber"
    );
    $stmt->execute(['routeIDToday' => $routeId, 'routeID' => $routeId]);

    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC), 'strategy' => 'scheduled-stop-time-with-latest-event-adjustment'];
}

function handlePlanning($database, string $method, array $parts): array
{
    if ($method !== 'GET' || ($parts[1] ?? '') !== 'routes') {
        return methodNotAllowed();
    }

    $stmt = pdo($database)->query(
        "SELECT r.gibbonTransportRouteID, r.name, r.capacity, COUNT(ts.gibbonTransportStudentID) AS assignedStudents,
                ROUND((COUNT(ts.gibbonTransportStudentID) / NULLIF(r.capacity, 0)) * 100, 2) AS capacityUsedPercent
         FROM gibbonTransportRoute r
         LEFT JOIN gibbonTransportStudent ts ON r.gibbonTransportRouteID = ts.gibbonTransportRouteID AND ts.status = 'Active'
         GROUP BY r.gibbonTransportRouteID, r.name, r.capacity
         ORDER BY capacityUsedPercent DESC"
    );
    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
}

function handleVehicles($database, string $method, ?string $id, ?int $userId): array
{
    if (!tableExists($database, 'gibbonTransportVehicle')) {
        return migrationRequired('gibbonTransportVehicle');
    }

    if ($method === 'GET') {
        $stmt = pdo($database)->query('SELECT * FROM gibbonTransportVehicle ORDER BY vehicleNumber');
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST') {
        $input = getInput();
        $stmt = pdo($database)->prepare(
            "INSERT INTO gibbonTransportVehicle
             (vehicleNumber, vehicleType, capacity, active, insuranceExpiry, licenseExpiry, maintenanceDueDate, notes, createdBy)
             VALUES (:vehicleNumber, :vehicleType, :capacity, :active, :insuranceExpiry, :licenseExpiry, :maintenanceDueDate, :notes, :createdBy)"
        );
        $stmt->execute([
            'vehicleNumber' => $input['vehicleNumber'] ?? '',
            'vehicleType' => $input['vehicleType'] ?? 'Bus',
            'capacity' => $input['capacity'] ?? null,
            'active' => !empty($input['active']) ? 1 : 0,
            'insuranceExpiry' => $input['insuranceExpiry'] ?? null,
            'licenseExpiry' => $input['licenseExpiry'] ?? null,
            'maintenanceDueDate' => $input['maintenanceDueDate'] ?? null,
            'notes' => $input['notes'] ?? null,
            'createdBy' => $userId
        ]);
        return successCreated($database, 'Vehicle created');
    }

    return methodNotAllowed();
}

function handleEmergency($database, string $method, ?int $userId): array
{
    if ($method !== 'POST') {
        return methodNotAllowed();
    }

    $input = getInput();
    $input['alertType'] = 'emergency';
    $input['severity'] = 'critical';
    $result = createAlert($database, $input, $userId, $input['message'] ?? 'Emergency reported from transport route.');

    if (!empty($input['gibbonPersonID']) && !empty($input['gibbonTransportRouteID'])) {
        createTransportEvent($database, [
            'gibbonPersonID' => $input['gibbonPersonID'],
            'gibbonTransportRouteID' => $input['gibbonTransportRouteID'],
            'gibbonTransportStopID' => $input['gibbonTransportStopID'] ?? null,
            'type' => $input['type'] ?? 'pickup',
            'status' => 'Verified',
            'emergencyFlag' => true,
            'emergencyNotes' => $input['message'] ?? 'Emergency reported',
            'latitude' => $input['latitude'] ?? null,
            'longitude' => $input['longitude'] ?? null
        ], $userId, 'Emergency event recorded');
    }

    return $result + ['emergencyMode' => true];
}

function handleIncidents($database, string $method, ?string $id, ?int $userId): array
{
    if (!tableExists($database, 'gibbonTransportIncident')) {
        return migrationRequired('gibbonTransportIncident');
    }

    if ($method === 'GET') {
        $stmt = pdo($database)->query('SELECT * FROM gibbonTransportIncident ORDER BY occurredAt DESC LIMIT 100');
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST') {
        $input = getInput();
        $stmt = pdo($database)->prepare(
            "INSERT INTO gibbonTransportIncident
             (incidentType, severity, gibbonTransportRouteID, gibbonPersonID, description, followUpRequired, status, reportedBy, occurredAt)
             VALUES (:incidentType, :severity, :routeID, :personID, :description, :followUpRequired, :status, :reportedBy, COALESCE(:occurredAt, NOW()))"
        );
        $stmt->execute([
            'incidentType' => $input['incidentType'] ?? 'other',
            'severity' => $input['severity'] ?? 'medium',
            'routeID' => $input['gibbonTransportRouteID'] ?? null,
            'personID' => $input['gibbonPersonID'] ?? null,
            'description' => $input['description'] ?? '',
            'followUpRequired' => !empty($input['followUpRequired']) ? 1 : 0,
            'status' => $input['status'] ?? 'open',
            'reportedBy' => $userId,
            'occurredAt' => $input['occurredAt'] ?? null
        ]);
        return successCreated($database, 'Incident recorded');
    }

    return methodNotAllowed();
}

function handlePickupRules($database, string $method, ?string $id, ?int $userId): array
{
    if (!tableExists($database, 'gibbonTransportPickupRule')) {
        return migrationRequired('gibbonTransportPickupRule');
    }

    if ($method === 'GET') {
        $personId = $_GET['gibbonPersonID'] ?? null;
        $sql = 'SELECT * FROM gibbonTransportPickupRule' . ($personId ? ' WHERE gibbonPersonID = :personID' : '') . ' ORDER BY priority DESC, authorisedName';
        $stmt = pdo($database)->prepare($sql);
        $personId ? $stmt->execute(['personID' => $personId]) : $stmt->execute();
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST') {
        $input = getInput();
        $stmt = pdo($database)->prepare(
            "INSERT INTO gibbonTransportPickupRule
             (gibbonPersonID, authorisedName, relationship, phone, ruleType, notes, priority, createdBy)
             VALUES (:personID, :authorisedName, :relationship, :phone, :ruleType, :notes, :priority, :createdBy)"
        );
        $stmt->execute([
            'personID' => $input['gibbonPersonID'] ?? null,
            'authorisedName' => $input['authorisedName'] ?? '',
            'relationship' => $input['relationship'] ?? null,
            'phone' => $input['phone'] ?? null,
            'ruleType' => $input['ruleType'] ?? 'authorised',
            'notes' => $input['notes'] ?? null,
            'priority' => $input['priority'] ?? 0,
            'createdBy' => $userId
        ]);
        return successCreated($database, 'Pickup rule created');
    }

    return methodNotAllowed();
}

function handleQr($database, string $method, array $parts): array
{
    if ($method !== 'POST' || ($parts[1] ?? '') !== 'resolve') {
        return methodNotAllowed();
    }

    $input = getInput();
    $token = $input['token'] ?? '';
    if (!$token) {
        http_response_code(422);
        return ['error' => 'Missing token'];
    }

    $stmt = pdo($database)->prepare(
        "SELECT ts.*, p.surname, p.preferredName, r.name AS routeName, s.name AS stopName
         FROM gibbonTransportStudent ts
         JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
         LEFT JOIN gibbonTransportRoute r ON ts.gibbonTransportRouteID = r.gibbonTransportRouteID
         LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
         WHERE SHA2(CONCAT('transport:', ts.gibbonPersonID, ':', ts.gibbonTransportRouteID), 256) = :token
         LIMIT 1"
    );
    $stmt->execute(['token' => $token]);
    return ['data' => $stmt->fetch(\PDO::FETCH_ASSOC) ?: null];
}

function handlePhotos($database, string $method, ?int $userId): array
{
    if ($method !== 'POST') {
        return methodNotAllowed();
    }

    $input = getInput();
    if (empty($input['gibbonTransportEventID']) || empty($input['photoUrl'])) {
        http_response_code(422);
        return ['error' => 'Missing gibbonTransportEventID or photoUrl'];
    }

    $stmt = pdo($database)->prepare(
        "INSERT INTO gibbonTransportPhoto
         (gibbonTransportEventID, photoUrl, photoType, fileSize, uploadedBy, verified, notes)
         VALUES (:eventID, :photoUrl, :photoType, :fileSize, :uploadedBy, :verified, :notes)"
    );
    $stmt->execute([
        'eventID' => $input['gibbonTransportEventID'],
        'photoUrl' => $input['photoUrl'],
        'photoType' => $input['photoType'] ?? 'boarding_event',
        'fileSize' => $input['fileSize'] ?? 0,
        'uploadedBy' => $userId,
        'verified' => !empty($input['verified']) ? 1 : 0,
        'notes' => $input['notes'] ?? null
    ]);
    return successCreated($database, 'Photo attached');
}

function handleBilling($database, string $method, ?string $id, ?int $userId): array
{
    if (!tableExists($database, 'gibbonTransportBilling')) {
        return migrationRequired('gibbonTransportBilling');
    }

    if ($method === 'GET') {
        $stmt = pdo($database)->query('SELECT * FROM gibbonTransportBilling ORDER BY billingPeriod DESC, gibbonPersonID');
        return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
    }

    if ($method === 'POST') {
        $input = getInput();
        $stmt = pdo($database)->prepare(
            "INSERT INTO gibbonTransportBilling
             (gibbonPersonID, gibbonTransportRouteID, billingPeriod, amount, discountAmount, status, notes, createdBy)
             VALUES (:personID, :routeID, :billingPeriod, :amount, :discountAmount, :status, :notes, :createdBy)"
        );
        $stmt->execute([
            'personID' => $input['gibbonPersonID'] ?? null,
            'routeID' => $input['gibbonTransportRouteID'] ?? null,
            'billingPeriod' => $input['billingPeriod'] ?? date('Y-m'),
            'amount' => $input['amount'] ?? 0,
            'discountAmount' => $input['discountAmount'] ?? 0,
            'status' => $input['status'] ?? 'pending',
            'notes' => $input['notes'] ?? null,
            'createdBy' => $userId
        ]);
        return successCreated($database, 'Billing record created');
    }

    return methodNotAllowed();
}

function handleReports($database, string $method, ?string $reportName): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    switch ($reportName) {
        case 'capacity':
            return handlePlanning($database, 'GET', ['planning', 'routes']);
        case 'late-events':
            $stmt = pdo($database)->query("SELECT * FROM gibbonTransportEvent WHERE status IN ('Late', 'Early') ORDER BY timestamp DESC LIMIT 100");
            return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        case 'alerts':
            $stmt = pdo($database)->query('SELECT alertType, severity, COUNT(*) AS count FROM gibbonTransportAlert GROUP BY alertType, severity');
            return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
        default:
            return ['reports' => ['capacity', 'late-events', 'alerts']];
    }
}

function handleAuditLogs($database, string $method): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    if (!tableExists($database, 'gibbonTransportAuditLog')) {
        return migrationRequired('gibbonTransportAuditLog');
    }

    $stmt = pdo($database)->query('SELECT * FROM gibbonTransportAuditLog ORDER BY timestampCreated DESC LIMIT 100');
    return ['data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
}

function handleIntegrations($database, string $method, array $parts): array
{
    if ($method !== 'GET' || ($parts[1] ?? '') !== 'oneroster' || ($parts[2] ?? '') !== 'export') {
        return methodNotAllowed();
    }

    $stmt = pdo($database)->query(
        "SELECT p.gibbonPersonID AS sourcedId, p.preferredName AS givenName, p.surname AS familyName,
                p.email, ts.gibbonTransportRouteID, ts.gibbonTransportStopID, ts.status
         FROM gibbonTransportStudent ts
         JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID"
    );

    return ['format' => 'oneroster-inspired-json', 'data' => $stmt->fetchAll(\PDO::FETCH_ASSOC)];
}

function handleScenarios(string $method): array
{
    if ($method !== 'GET') {
        return methodNotAllowed();
    }

    return [
        'scenarios' => [
            ['feature' => 'Parent status', 'endpoint' => 'GET /transport-status/child/{gibbonPersonID}', 'scenario' => 'Parent checks whether their child boarded or was dropped off today.'],
            ['feature' => 'Boarding confirmation', 'endpoint' => 'POST /boarding/events', 'scenario' => 'Supervisor records pickup/dropoff from a mobile checklist.'],
            ['feature' => 'Notifications', 'endpoint' => 'POST /notifications', 'scenario' => 'System queues SMS/email after a boarding event or route delay.'],
            ['feature' => 'Missing event alerts', 'endpoint' => 'POST /missing-alerts/run', 'scenario' => 'Cron job detects assigned students with no event for today.'],
            ['feature' => 'Supervisor mobile mode', 'endpoint' => 'GET /supervisor/routes/{routeID}', 'scenario' => 'Supervisor loads route-specific student checklist.'],
            ['feature' => 'Offline sync', 'endpoint' => 'POST /sync/offline-events', 'scenario' => 'Mobile device uploads queued events after connectivity returns.'],
            ['feature' => 'Real-time tracking', 'endpoint' => 'POST /tracking/locations', 'scenario' => 'Mobile app or GPS hardware posts vehicle coordinates.'],
            ['feature' => 'ETA', 'endpoint' => 'GET /eta/route/{routeID}', 'scenario' => 'Parent/admin sees estimated stop arrivals using scheduled times and latest events.'],
            ['feature' => 'Route planning', 'endpoint' => 'GET /planning/routes', 'scenario' => 'Admin reviews capacity utilization and overload risk.'],
            ['feature' => 'Vehicles', 'endpoint' => 'GET|POST /vehicles', 'scenario' => 'Admin manages fleet, insurance, license, and maintenance metadata.'],
            ['feature' => 'Emergency mode', 'endpoint' => 'POST /emergency', 'scenario' => 'Supervisor raises a critical alert with optional event/location evidence.'],
            ['feature' => 'Incidents', 'endpoint' => 'GET|POST /incidents', 'scenario' => 'Staff records breakdown, behavior, medical, or unauthorized pickup incidents.'],
            ['feature' => 'Pickup rules', 'endpoint' => 'GET|POST /pickup-rules', 'scenario' => 'Staff manages authorised and blocked pickup contacts.'],
            ['feature' => 'QR boarding', 'endpoint' => 'POST /qr/resolve', 'scenario' => 'Supervisor scans student card and resolves assignment for quick boarding.'],
            ['feature' => 'Photo verification', 'endpoint' => 'POST /photos', 'scenario' => 'Supervisor attaches photo evidence to a transport event.'],
            ['feature' => 'Billing', 'endpoint' => 'GET|POST /billing', 'scenario' => 'Finance tracks monthly or termly transport charges.'],
            ['feature' => 'Reports', 'endpoint' => 'GET /reports/{reportName}', 'scenario' => 'Admin reviews capacity, late events, and alert summaries.'],
            ['feature' => 'Audit logs', 'endpoint' => 'GET /audit-logs', 'scenario' => 'Admin reviews sensitive transport data changes.'],
            ['feature' => 'OneRoster export', 'endpoint' => 'GET /integrations/oneroster/export', 'scenario' => 'External systems consume roster-aligned transport assignments.']
        ]
    ];
}

function createAuditLog($database, ?int $userId, string $action, string $entityType, $entityId, array $payload): void
{
    if (!tableExists($database, 'gibbonTransportAuditLog')) {
        return;
    }

    $stmt = pdo($database)->prepare(
        "INSERT INTO gibbonTransportAuditLog (gibbonPersonID, action, entityType, entityID, payloadJson, ipAddress)
         VALUES (:personID, :action, :entityType, :entityID, :payloadJson, :ipAddress)"
    );
    $stmt->execute([
        'personID' => $userId,
        'action' => $action,
        'entityType' => $entityType,
        'entityID' => (string)$entityId,
        'payloadJson' => json_encode($payload),
        'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null
    ]);
}
