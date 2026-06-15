<?php
/*
Gibbon: the flexible, open school platform
AJAX Staff Search - Returns active staff members for Select2
*/

// Gibbon bootstrap
$_POST['address'] = '/modules/Transport/ajax/staffSearch.php';

// Include core
include '../../../gibbon.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Security check
if (!isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage.php') &&
    !isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage_add.php') &&
    !isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage_edit.php')) {
    echo json_encode(['results' => [], 'error' => 'Access denied']);
    exit;
}

// Get search term from Select2
$term = $_GET['q'] ?? $_GET['term'] ?? '';
$term = trim($term);

// Minimum 2 characters for search
if (strlen($term) < 2) {
    echo json_encode(['results' => []]);
    exit;
}

try {
    // Query for active staff members
    $sql = "SELECT 
                p.gibbonPersonID AS id,
                CONCAT(p.preferredName, ' ', p.surname) AS text,
                p.surname,
                p.preferredName,
                s.jobTitle
            FROM gibbonPerson p
            INNER JOIN gibbonStaff s ON p.gibbonPersonID = s.gibbonPersonID
            WHERE p.status = 'Full'
            AND (
                p.preferredName LIKE :term
                OR p.surname LIKE :term
                OR p.firstName LIKE :term
                OR CONCAT(p.preferredName, ' ', p.surname) LIKE :term
                OR CONCAT(p.firstName, ' ', p.surname) LIKE :term
            )
            ORDER BY p.surname, p.preferredName
            LIMIT 20";
    
    $stmt = $connection2->prepare($sql);
    $stmt->execute(['term' => '%' . $term . '%']);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format for Select2
    $formatted = [];
    foreach ($results as $row) {
        $displayText = $row['text'];
        if (!empty($row['jobTitle'])) {
            $displayText .= ' (' . $row['jobTitle'] . ')';
        }
        $formatted[] = [
            'id' => $row['id'],
            'text' => $displayText
        ];
    }
    
    echo json_encode(['results' => $formatted]);
    
} catch (Exception $e) {
    echo json_encode(['results' => [], 'error' => 'Database error']);
}
