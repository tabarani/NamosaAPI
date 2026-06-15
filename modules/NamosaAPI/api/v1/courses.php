<?php
/*
NamosaAPI v1 - Courses Endpoint
GET /api/v1/courses
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

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

try {
    $configService = new Config($gibbon->session->get('connection2'));
    $config = $configService->get();

    if (!$configService->isConfigured()) {
        throw new \Exception('NamosaAPI is not configured.');
    }

    $auth = new AuthMiddleware($gibbon->session->get('connection2'), $config);
    
    if (!$auth->authenticate()) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'message' => $auth->getError()]);
        exit;
    }

    if (!$auth->hasPermission('courses_read') && !$auth->hasRole('Admin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'message' => 'Insufficient permissions']);
        exit;
    }

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $search = $_GET['search'] ?? '';
    $schoolYear = $_GET['schoolYear'] ?? $gibbon->session->get('gibbonSchoolYearID');

    $data = ['schoolYear' => $schoolYear];
    $sql = "SELECT 
                c.gibbonCourseID,
                c.name AS courseName,
                c.description,
                c.prerequisites,
                sy.name AS schoolYear,
                COUNT(cc.gibbonCourseClassID) AS classCount
            FROM gibbonCourse c
            JOIN gibbonSchoolYear sy ON c.gibbonSchoolYearID = sy.gibbonSchoolYearID
            LEFT JOIN gibbonCourseClass cc ON c.gibbonCourseID = cc.gibbonCourseID
            WHERE c.gibbonSchoolYearID = :schoolYear";

    if (!empty($search)) {
        $sql .= " AND (c.name LIKE :search OR c.description LIKE :search)";
        $data['search'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY c.gibbonCourseID";
    $sql .= " ORDER BY c.name ASC";
    $sql .= " LIMIT :offset, :limit";
    
    $data['offset'] = $offset;
    $data['limit'] = $limit;

    $stmt = $gibbon->session->get('connection2')->execute($data, $sql);

    $courses = [];
    if ($stmt->rowCount() > 0) {
        $courses = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Count total
    $countData = ['schoolYear' => $schoolYear];
    $countSql = "SELECT COUNT(DISTINCT c.gibbonCourseID) as total 
                 FROM gibbonCourse c
                 WHERE c.gibbonSchoolYearID = :schoolYear";
    
    if (!empty($search)) {
        $countSql .= " AND (c.name LIKE :search OR c.description LIKE :search)";
        $countData['search'] = '%' . $search . '%';
    }

    $total = $gibbon->session->get('connection2')->execute($countData, $countSql)->fetch()['total'] ?? 0;

    echo json_encode([
        'success' => true,
        'data' => $courses,
        'meta' => [
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'hasMore' => ($offset + $limit) < $total
        ]
    ]);

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Internal Server Error', 'message' => $e->getMessage()]);
}
