<?php
/**
 * NamosaAPI v1 - Courses Endpoint
 * GET /api/v1/courses
 * 
 * Requires: courses_read permission
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

// Initialize Rate Limiter (check before authentication)
require_once __DIR__ . '/../../lib/RateLimiter.php';
$rateLimiter = new \Gibbon\Module\NamosaAPI\RateLimiter();

// Check rate limit using IP initially (will upgrade after auth)
if (!$rateLimiter->allowRequest(null, false)) {
    $rateLimiter->sendRateLimitResponse();
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

    // Re-check rate limit with authenticated status (higher limit for authenticated users)
    if (!$rateLimiter->allowRequest($gibbonPersonID, true)) {
        $rateLimiter->sendRateLimitResponse();
        exit;
    }

    // Load permissions
    require_once __DIR__ . '/../lib/PermissionService.php';
    $permissionService = new PermissionService($connection2);
    $userPermissions = $permissionService->loadPermissions($gibbonPersonID);
    $userRoles = $permissionService->loadRoles($gibbonPersonID);

    // Check permission
    if (!$permissionService->hasPermission($userPermissions, 'courses_read')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Insufficient permissions']);
        exit;
    }

    // Parse parameters
    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $search = trim($_GET['search'] ?? '');
    $schoolYear = $_GET['schoolYear'] ?? null;
    $department = $_GET['department'] ?? null;

    // Get current school year if not specified
    if (!$schoolYear) {
        $data = ['status' => 'Current'];
        $sql = "SELECT gibbonSchoolYearID FROM gibbonSchoolYear WHERE status = :status";
        $stmt = $connection2->execute($data, $sql);
        $schoolYear = $stmt->fetchColumn();
    }

    // Build query
    $whereConditions = ['ccp.gibbonSchoolYearID = :schoolYear'];
    $params = ['schoolYear' => $schoolYear];

    if ($search) {
        $whereConditions[] = "(c.nameShort LIKE :search OR c.name LIKE :search)";
        $params['search'] = '%' . $search . '%';
    }

    if ($department) {
        $whereConditions[] = "c.gibbonDepartmentID = :department";
        $params['department'] = $department;
    }

    $whereClause = 'WHERE ' . implode(' AND ', $whereConditions);

    // Count total
    $countSql = "SELECT COUNT(DISTINCT c.gibbonCourseID) as total 
                 FROM gibbonCourse c 
                 JOIN gibbonCourseClass cc ON c.gibbonCourseID = cc.gibbonCourseID
                 JOIN gibbonCourseClassPerson ccp ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
                 $whereClause";
    
    $countStmt = $connection2->execute($params, $countSql);
    $total = $countStmt->fetch()['total'] ?? 0;

    // Get courses
    $selectSql = "
        SELECT DISTINCT
            c.gibbonCourseID,
            c.nameShort as courseCode,
            c.name as courseName,
            c.description,
            c.gibbonDepartmentID,
            gd.name as departmentName,
            cpr.gibbonSchoolYearID,
            sy.name as schoolYearName,
            COUNT(DISTINCT cc.gibbonCourseClassID) as classCount,
            COUNT(DISTINCT ccp.gibbonPersonID) as studentCount
        FROM gibbonCourse c
        JOIN gibbonCourseClass cc ON c.gibbonCourseID = cc.gibbonCourseID
        JOIN gibbonCourseClassPerson ccp ON cc.gibbonCourseClassID = ccp.gibbonCourseClassID
        JOIN gibbonDepartment gd ON c.gibbonDepartmentID = gd.gibbonDepartmentID
        JOIN gibbonSchoolYear sy ON cpr.gibbonSchoolYearID = sy.gibbonSchoolYearID
        JOIN gibbonCoursePrerequisite cpr ON c.gibbonCourseID = cpr.gibbonCourseID
        $whereClause
        GROUP BY c.gibbonCourseID
        ORDER BY c.nameShort
        LIMIT :limit OFFSET :offset
    ";

    $selectStmt = $connection2->prepare($selectSql);
    foreach ($params as $key => $value) {
        $selectStmt->bindValue(':' . $key, $value);
    }
    $selectStmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $selectStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $selectStmt->execute();

    $courses = $selectStmt->fetchAll(PDO::FETCH_ASSOC);

    // Response
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'data' => $courses,
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
    error_log('NamosaAPI Courses Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Internal server error']);
}
