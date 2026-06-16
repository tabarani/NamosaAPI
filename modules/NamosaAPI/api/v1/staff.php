<?php
/**
 * NamosaAPI v1 - Staff Endpoint
 * GET /api/v1/staff
 * 
 * Requires: staff_read permission
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

try {
    // Initialize configuration
    require_once __DIR__ . '/config.php';
    $configObj = new Gibbon\Module\NamosaAPI\Config($connection2);
    $config = $configObj->get();

    if (!$configObj->isConfigured()) {
        throw new Exception('NamosaAPI not configured');
    }

    // Authenticate request
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing Authorization header']);
        exit;
    }

    $token = substr($authHeader, 7);

    // Validate JWT token using consolidated Core module validator
    require_once __DIR__ . '/../../Core/lib/JWTValidator.php';
    $jwtValidator = new \Gibbon\Module\Core\JWTValidator(
        $config['jwks_url'],
        $config['issuer'],
        $config['audience']
    );

    $payload = $jwtValidator->validate($token);
    
    if (!$payload) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Invalid token']);
        exit;
    }

    // Extract user ID
    $userIdClaim = $config['user_id_claim'] ?? 'sub';
    $gibbonPersonID = $payload[$userIdClaim] ?? null;

    if (!$gibbonPersonID) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User ID not found']);
        exit;
    }

    // Load permissions
    require_once __DIR__ . '/../lib/PermissionService.php';
    $permissionService = new PermissionService($connection2);
    $userPermissions = $permissionService->loadPermissions($gibbonPersonID);
    $userRoles = $permissionService->loadRoles($gibbonPersonID);

    // Check permission
    if (!$permissionService->hasPermission($userPermissions, 'staff_read')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    // Parse parameters
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'Full';
    $type = $_GET['type'] ?? 'Staff';

    // Build query
    $whereConditions = ['gp.type = :type', 'gp.status = :status'];
    $params = ['type' => $type, 'status' => $status];

    if ($search) {
        $whereConditions[] = "(gp.surname LIKE :search OR gp.preferredName LIKE :search OR gp.email LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    // Count total
    $countSql = "SELECT COUNT(*) as total FROM gibbonPerson gp $whereClause";
    $countStmt = $connection2->execute($params, $countSql);
    $total = $countStmt->fetch()['total'] ?? 0;

    // Get staff
    $selectSql = "
        SELECT 
            gp.gibbonPersonID,
            gp.title,
            gp.surname,
            gp.preferredName,
            gp.email,
            gp.phone1,
            gp.phone2,
            gp.address,
            gp.city,
            gp.country,
            gp.postcode,
            gp.gender,
            gp.dateOfBirth,
            gp.image_240,
            gp.status,
            gp.type,
            gp.employmentType,
            gp.jobTitle
        FROM gibbonPerson gp
        $whereClause
        ORDER BY gp.surname, gp.preferredName
        LIMIT :limit OFFSET :offset
    ";

    $selectStmt = $connection2->prepare($selectSql);
    foreach ($params as $key => $value) {
        $selectStmt->bindValue(':' . $key, $value);
    }
    $selectStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->execute();

    $staff = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    // Response
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $staff,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ],
        'meta' => [
            'requestedBy' => $gibbonPersonID,
            'timestamp' => date('c')
        ]
    ]);

} catch (Exception $e) {
    error_log('NamosaAPI Staff Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
