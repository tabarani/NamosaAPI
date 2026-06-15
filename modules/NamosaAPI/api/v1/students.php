<?php
/*
NamosaAPI v1 - Students Endpoint
GET /api/v1/students
*/

require_once __DIR__ . '/../../../gibbon.php';
require_once __DIR__ . '/../lib/AuthMiddleware.php';
require_once __DIR__ . '/config.php';

use Gibbon\Module\NamosaAPI\AuthMiddleware;
use Gibbon\Module\NamosaAPI\Config;

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// Only allow GET for now
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    // Initialize Config
    $configService = new Config($gibbon->session->get('connection2'));
    $config = $configService->get();

    if (!$configService->isConfigured()) {
        throw new \Exception('NamosaAPI is not configured. Please configure OIDC settings.');
    }

    // Authenticate Request
    $auth = new AuthMiddleware($gibbon->session->get('connection2'), $config);
    
    if (!$auth->authenticate()) {
        http_response_code(401);
        echo json_encode([
            'error' => 'Unauthorized',
            'message' => $auth->getError()
        ]);
        exit;
    }

    // Check Permission
    if (!$auth->hasPermission('students_read') && !$auth->hasRole('Admin')) {
        http_response_code(403);
        echo json_encode([
            'error' => 'Forbidden',
            'message' => 'You do not have permission to access students data'
        ]);
        exit;
    }

    // Get Query Parameters
    $limit = min((int)($_GET['limit'] ?? 50), 200); // Max 200
    $offset = (int)($_GET['offset'] ?? 0);
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? 'Full';

    // Build Query
    $data = [];
    $sql = "SELECT 
                p.gibbonPersonID,
                p.username,
                p.surname,
                p.preferredName,
                p.email,
                p.status,
                s.gibbonStudentEnrolmentID,
                s.status AS enrolmentStatus,
                y.name AS yearGroup,
                f.name AS familyName
            FROM gibbonPerson p
            JOIN gibbonStudentEnrolment s ON p.gibbonPersonID = s.gibbonPersonID
            JOIN gibbonYearGroup y ON s.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN gibbonFamilyMember fm ON p.gibbonPersonID = fm.gibbonPersonID
            LEFT JOIN gibbonFamily f ON fm.gibbonFamilyID = f.gibbonFamilyID
            WHERE p.status = :status";
    
    $data['status'] = $status;

    // Add search filter
    if (!empty($search)) {
        $sql .= " AND (p.surname LIKE :search OR p.preferredName LIKE :search OR p.email LIKE :search)";
        $data['search'] = '%' . $search . '%';
    }

    // Add ordering
    $sql .= " ORDER BY p.surname ASC, p.preferredName ASC";

    // Add pagination
    $sql .= " LIMIT :offset, :limit";
    $data['offset'] = $offset;
    $data['limit'] = $limit;

    $stmt = $gibbon->session->get('connection2')->execute($data, $sql);

    $students = [];
    if ($stmt->rowCount() > 0) {
        $students = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Get total count for pagination metadata
    $countSql = "SELECT COUNT(*) as total FROM gibbonPerson p
                 JOIN gibbonStudentEnrolment s ON p.gibbonPersonID = s.gibbonPersonID
                 WHERE p.status = :status";
    
    $countData = ['status' => $status];
    if (!empty($search)) {
        $countSql .= " AND (p.surname LIKE :search OR p.preferredName LIKE :search OR p.email LIKE :search)";
        $countData['search'] = '%' . $search . '%';
    }

    $countStmt = $gibbon->session->get('connection2')->execute($countData, $countSql);
    $total = $countStmt->fetch()['total'] ?? 0;

    // Return Response
    echo json_encode([
        'success' => true,
        'data' => $students,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ],
        'user' => [
            'id' => $auth->getUserContext()['gibbonPersonID'],
            'roles' => $auth->getUserContext()['roles']
        ]
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage()
    ]);
}
