<?php
/*
Gibbon: the flexible, open school platform
AJAX Get Stops by Route - Returns stops for a specific route
*/

// Gibbon bootstrap
$_POST['address'] = '/modules/Transport/ajax/getStopsByRoute.php';

// Include core
include '../../../gibbon.php';

// Set JSON header
header('Content-Type: application/json; charset=utf-8');

// Security check
if (!isActionAccessible($guid, $connection2, '/modules/Transport/students_manage.php') &&
    !isActionAccessible($guid, $connection2, '/modules/Transport/students_manage_add.php')) {
    echo json_encode(['success' => false, 'error' => 'Access denied', 'stops' => []]);
    exit;
}

// Get route ID
$routeID = $_GET['routeID'] ?? $_POST['routeID'] ?? null;

if (!$routeID || !is_numeric($routeID)) {
    echo json_encode(['success' => false, 'error' => 'Invalid route ID', 'stops' => []]);
    exit;
}

try {
    // Query stops for the route
    $sql = "SELECT 
                gibbonTransportStopID AS id,
                name,
                sequenceNumber,
                estimatedArrivalTime
            FROM gibbonTransportStop
            WHERE gibbonTransportRouteID = :routeID
            AND active = 1
            ORDER BY sequenceNumber ASC";
    
    $stmt = $connection2->prepare($sql);
    $stmt->execute(['routeID' => $routeID]);
    $stops = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format for dropdown
    $formatted = [];
    foreach ($stops as $stop) {
        $text = $stop['name'];
        if (!empty($stop['estimatedArrivalTime'])) {
            $text .= ' (' . date('H:i', strtotime($stop['estimatedArrivalTime'])) . ')';
        }
        $formatted[] = [
            'id' => $stop['id'],
            'text' => $text,
            'sequence' => $stop['sequenceNumber']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'stops' => $formatted,
        'count' => count($formatted)
    ]);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error', 'stops' => []]);
}
