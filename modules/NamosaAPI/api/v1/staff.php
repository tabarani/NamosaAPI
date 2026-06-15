<?php
/*
NamosaAPI v1 - Staff Endpoint
GET /api/v1/staff
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

    if (!$auth->hasPermission('staff_read') && !$auth->hasRole('Admin')) {
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden', 'message' => 'Insufficient permissions']);
        exit;
    }

    $limit = min((int)($_GET['limit'] ?? 50), 200);
    $offset = (int)($_GET['offset'] ?? 0);
    $search = $_GET['search'] ?? '';
    $status = $_GET['status'] ?? 'Full';

    $data = ['status' => $status];
    $sql = "SELECT 
                p.gibbonPersonID,
                p.username,
                p.surname,
                p.preferredName,
                p.email,
                p.phone1,
                p.status,
                s.staffTypeID,
                st.name AS staffType,
                s.dateStart,
                s.dateEnd
            FROM gibbonPerson p
            JOIN gibbonStaff s ON p.gibbonPersonID = s.gibbonPersonID
            JOIN gibbonStaffType st ON s.staffTypeID = st.staffTypeID
            WHERE p.status = :status";

    if (!empty($search)) {
        $sql .= " AND (p.surname LIKE :search OR p.preferredName LIKE :search OR p.email LIKE :search)";
        $data['search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.surname ASC";
    $sql .= " LIMIT :offset, :limit";
    
    $data['offset'] = $offset;
    $data['limit'] = $limit;

    $stmt = $gibbon->session->get('connection2')->execute($data, $sql);

    $staff = [];
    if ($stmt->rowCount() > 0) {
        $staff = $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    // Count total
    $countData = ['status' => $status];
    $countSql = "SELECT COUNT(*) as total FROM gibbonPerson p
                 JOIN gibbonStaff s ON p.gibbonPersonID = s.gibbonPersonID
                 WHERE p.status = :status";
    
    if (!empty($search)) {
        $countSql .= " AND (p.surname LIKE :search OR p.preferredName LIKE :search)";
        $countData['search'] = '%' . $search . '%';
    }

    $total = $gibbon->session->get('connection2')->execute($countData, $countSql)->fetch()['total'] ?? 0;

    echo json_encode([
        'success' => true,
        'data' => $staff,
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
