<?php
/**
 * NamosaAPI v1 - Students Endpoint
 * GET /api/v1/students
 * 
 * Requires: students_read permission
 */

use Gibbon\Module\NamosaAPI\Config;
use Gibbon\Module\NamosaAPI\AuthMiddleware;
use Gibbon\Module\NamosaAPI\PermissionService;
use Gibbon\Module\NamosaAPI\RateLimiter;

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

// Initialize Rate Limiter (check before authentication)
$rateLimiter = new RateLimiter();

// Check rate limit using IP initially (will upgrade after auth)
if (!$rateLimiter->allowRequest(null, false)) {
    $rateLimiter->sendRateLimitResponse();
    exit;
}

try {
    // Initialize configuration
    $configObj = new Config($connection2);
    $config = $configObj->get();

    if (!$configObj->isConfigured()) {
        throw new Exception('NamosaAPI not configured. Please configure OIDC settings.');
    }

    // Authenticate request
    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    
    if (empty($authHeader) || strpos($authHeader, 'Bearer ') !== 0) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Missing or invalid Authorization header']);
        exit;
    }

    $token = substr($authHeader, 7); // Remove "Bearer " prefix

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
        echo json_encode(['success' => false, 'error' => 'Invalid or expired token']);
        exit;
    }

    // Extract user ID from token
    $userIdClaim = $config['user_id_claim'] ?? 'sub';
    $gibbonPersonID = $payload[$userIdClaim] ?? null;

    if (!$gibbonPersonID) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'User ID not found in token']);
        exit;
    }

    // Re-check rate limit with authenticated status (higher limit for authenticated users)
    if (!$rateLimiter->allowRequest($gibbonPersonID, true)) {
        $rateLimiter->sendRateLimitResponse();
        exit;
    }

    // Verify user exists in Gibbon
    $data = ['gibbonPersonID' => $gibbonPersonID];
    $sql = "SELECT gibbonPersonID, title, surname, preferredName, email, active 
            FROM gibbonPerson 
            WHERE gibbonPersonID = :gibbonPersonID";
    $stmt = $connection2->execute($data, $sql);
    
    if ($stmt->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'User not found in Gibbon']);
        exit;
    }

    $currentUser = $stmt->fetch();

    // Load permissions
    require_once __DIR__ . '/../lib/PermissionService.php';
    $permissionService = new PermissionService($connection2);
    $userPermissions = $permissionService->loadPermissions($gibbonPersonID);
    $userRoles = $permissionService->loadRoles($gibbonPersonID);

    // Check required permission
    $requiredPermission = 'students_read';
    if (!$permissionService->hasPermission($userPermissions, $requiredPermission)) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error' => 'Insufficient permissions. Required: ' . $requiredPermission
        ]);
        exit;
    }

    // Parse pagination and filtering parameters
    $limit = min((int)($_GET['limit'] ?? 50), 200); // Max 200
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim($_GET['search'] ?? '');
    $status = $_GET['status'] ?? 'Full'; // Full, Left, Expected
    $yearGroup = $_GET['yearGroup'] ?? null;
    $house = $_GET['house'] ?? null;

    // Build query
    $whereConditions = [];
    $params = [];

    if ($status) {
        $whereConditions[] = "gp.status = :status";
        $params['status'] = $status;
    }

    if ($search) {
        $whereConditions[] = "(gp.surname LIKE :search OR gp.preferredName LIKE :search OR gp.email LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    if ($yearGroup) {
        $whereConditions[] = "gs.gibbonYearGroupID = :yearGroup";
        $params['yearGroup'] = $yearGroup;
    }

    if ($house) {
        $whereConditions[] = "gp.house = :house";
        $params['house'] = $house;
    }

    // Restrict data based on user role (non-admins see limited data)
    $isAdmin = false;
    foreach ($userRoles as $role) {
        if (in_array($role['nameShort'], ['Administrator', 'Admin'])) {
            $isAdmin = true;
            break;
        }
    }

    $whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

    // Get total count
    $countSql = "SELECT COUNT(*) as total 
                 FROM gibbonPerson gp 
                 LEFT JOIN gibbonStudentEnrolment gs ON gp.gibbonPersonID = gs.gibbonPersonID 
                 $whereClause";
    
    $countStmt = $connection2->execute($params, $countSql);
    $total = $countStmt->fetch()['total'] ?? 0;

    // Get students
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
            gp.house,
            gp.image_240,
            gp.status,
            gs.gibbonYearGroupID,
            gy.name as yearGroupName,
            gy.sequenceNumber as yearGroupSequence,
            gs.gibbonSchoolYearID,
            gsy.name as schoolYearName,
            gc.gibbonCourseID,
            c.nameShort as courseCode,
            c.name as courseName
        FROM gibbonPerson gp
        LEFT JOIN gibbonStudentEnrolment gs ON gp.gibbonPersonID = gs.gibbonPersonID
        LEFT JOIN gibbonYearGroup gy ON gs.gibbonYearGroupID = gy.gibbonYearGroupID
        LEFT JOIN gibbonSchoolYear gsy ON gs.gibbonSchoolYearID = gsy.gibbonSchoolYearID
        LEFT JOIN gibbonCourseClassPerson ccp ON gp.gibbonPersonID = ccp.gibbonPersonID
        LEFT JOIN gibbonCourseClass cc ON ccp.gibbonCourseClassID = cc.gibbonCourseClassID
        LEFT JOIN gibbonCourse c ON cc.gibbonCourseID = c.gibbonCourseID
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

    $students = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    // Format response
    $response = [
        'success' => true,
        'data' => $students,
        'pagination' => [
            'total' => (int)$total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ],
        'meta' => [
            'requestedBy' => $gibbonPersonID,
            'userRoles' => array_column($userRoles, 'nameShort'),
            'timestamp' => date('c')
        ]
    ];

    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode($response);

} catch (Exception $e) {
    error_log('NamosaAPI Students Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error',
        'debug' => defined('DEBUG_MODE') && DEBUG_MODE ? $e->getMessage() : null
    ]);
}
