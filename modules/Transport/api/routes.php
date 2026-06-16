<?php
/**
 * Transport Routes API
 * Handles route-related API endpoints for mobile app
 */

// Database configuration (read from Gibbon config)
$configFile = dirname(__DIR__, 4) . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

require_once $configFile;

// Create database connection
try {
    $mysqli = new mysqli($databaseServer, $databaseUsername, $databasePassword, $databaseName, $databasePort ?? 3306);
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error', 'details' => $e->getMessage()]);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Pagination parameters
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 20))); // Default 20, max 100
        $offset = ($page - 1) * $perPage;
        
        // Get total count for pagination metadata
        $countStmt = $mysqli->prepare("
            SELECT COUNT(*) as total
            FROM gibbonTransportRoute r
            WHERE r.active = 1
        ");
        $countStmt->execute();
        $totalResult = $countStmt->get_result();
        $total = (int)$totalResult->fetch_assoc()['total'];
        $totalPages = ceil($total / $perPage);
        
        // Get paginated routes with basic info
        $stmt = $mysqli->prepare("
            SELECT r.gibbonTransportRouteID, r.name, r.nameShort, r.routeType, 
                   r.vehicleNumber, r.vehicleType, r.capacity, r.driverID, r.driverPhone,
                   r.supervisorEnabled, r.gibbonPersonIDSupervisor, r.active,
                   d.firstName as driverFirstName, d.surname as driverSurname,
                   s.firstName as supervisorFirstName, s.surname as supervisorSurname
            FROM gibbonTransportRoute r
            LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
            LEFT JOIN gibbonPerson s ON r.gibbonPersonIDSupervisor = s.gibbonPersonID
            WHERE r.active = 1
            ORDER BY r.name
            LIMIT ? OFFSET ?
        ");
        
        $stmt->bind_param('ii', $perPage, $offset);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $routes = [];
        while ($row = $result->fetch_assoc()) {
            $routes[] = [
                'id' => (int)$row['gibbonTransportRouteID'],
                'name' => $row['name'],
                'nameShort' => $row['nameShort'],
                'routeType' => $row['routeType'],
                'vehicleNumber' => $row['vehicleNumber'],
                'vehicleType' => $row['vehicleType'],
                'capacity' => (int)$row['capacity'],
                'driverID' => $row['driverID'] ? (int)$row['driverID'] : null,
                'driverName' => $row['driverFirstName'] ? $row['driverFirstName'] . ' ' . $row['driverSurname'] : null,
                'driverPhone' => $row['driverPhone'],
                'supervisorEnabled' => $row['supervisorEnabled'] === 'Y',
                'supervisorID' => $row['gibbonPersonIDSupervisor'] ? (int)$row['gibbonPersonIDSupervisor'] : null,
                'supervisorName' => $row['supervisorFirstName'] ? $row['supervisorFirstName'] . ' ' . $row['supervisorSurname'] : null,
                'active' => (bool)$row['active']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'data' => $routes,
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'total_pages' => $totalPages
            ]
        ]);
        exit;
    }
    
    if ($action === 'students' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get students assigned to a specific route
        $routeID = $_GET['id'] ?? null;
        $date = $_GET['date'] ?? date('Y-m-d');
        
        if (!$routeID) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing route ID']);
            exit;
        }
        
        // Get students with their stop information
        $stmt = $mysqli->prepare("
            SELECT ts.gibbonTransportStudentID, ts.gibbonPersonID, ts.gibbonTransportStopID, ts.status,
                   p.firstName, p.surname, p.studentID, p.image_240, p.phone1, p.phone2,
                   s.name as stopName, s.sequenceNumber, s.estimatedArrivalTime, s.address,
                   e.gibbonTransportEventID, e.type as eventType, e.status as eventStatus, 
                   e.timestamp as eventTimestamp, e.comments as eventComments
            FROM gibbonTransportStudent ts
            INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
            LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
            LEFT JOIN gibbonTransportEvent e ON ts.gibbonPersonID = e.gibbonPersonID 
                AND ts.gibbonTransportRouteID = e.gibbonTransportRouteID 
                AND DATE(e.timestamp) = ?
            WHERE ts.gibbonTransportRouteID = ? AND ts.status = 'Active'
            ORDER BY s.sequenceNumber ASC, p.surname, p.firstName
        ");
        
        $stmt->bind_param('si', $date, $routeID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = [
                'id' => (int)$row['gibbonTransportStudentID'],
                'studentID' => (int)$row['gibbonPersonID'],
                'studentNumber' => $row['studentID'],
                'name' => $row['firstName'] . ' ' . $row['surname'],
                'photoUrl' => $row['image_240'] ? $_SERVER['HTTP_HOST'] . '/' . $row['image_240'] : null,
                'phone1' => $row['phone1'],
                'phone2' => $row['phone2'],
                'stopID' => $row['gibbonTransportStopID'] ? (int)$row['gibbonTransportStopID'] : null,
                'stopName' => $row['stopName'],
                'stopSequence' => $row['sequenceNumber'] ? (int)$row['sequenceNumber'] : null,
                'estimatedArrivalTime' => $row['estimatedArrivalTime'],
                'stopAddress' => $row['address'],
                'status' => $row['status'],
                'eventID' => $row['gibbonTransportEventID'] ? (int)$row['gibbonTransportEventID'] : null,
                'eventType' => $row['eventType'],
                'eventStatus' => $row['eventStatus'],
                'eventTimestamp' => $row['eventTimestamp'],
                'eventComments' => $row['eventComments']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'routeID' => (int)$routeID,
            'date' => $date,
            'count' => count($students),
            'students' => $students
        ]);
        exit;
    }
    
    if ($action === 'stops' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get stops for a specific route
        $routeID = $_GET['id'] ?? null;
        
        if (!$routeID) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing route ID']);
            exit;
        }
        
        $stmt = $mysqli->prepare("
            SELECT gibbonTransportStopID, name, sequenceNumber, latitude, longitude,
                   address, landmark, estimatedArrivalTime, comments, active
            FROM gibbonTransportStop 
            WHERE gibbonTransportRouteID = ? AND active = 1
            ORDER BY sequenceNumber ASC
        ");
        
        $stmt->bind_param('i', $routeID);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $stops = [];
        while ($row = $result->fetch_assoc()) {
            $stops[] = [
                'id' => (int)$row['gibbonTransportStopID'],
                'name' => $row['name'],
                'sequenceNumber' => (int)$row['sequenceNumber'],
                'latitude' => $row['latitude'] ? (float)$row['latitude'] : null,
                'longitude' => $row['longitude'] ? (float)$row['longitude'] : null,
                'address' => $row['address'],
                'landmark' => $row['landmark'],
                'estimatedArrivalTime' => $row['estimatedArrivalTime'],
                'comments' => $row['comments'],
                'active' => (bool)$row['active']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'routeID' => (int)$routeID,
            'count' => count($stops),
            'stops' => $stops
        ]);
        exit;
    }
    
    if ($action === 'details' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get detailed route information including stops and students
        $routeID = $_GET['id'] ?? null;
        $date = $_GET['date'] ?? date('Y-m-d');
        
        if (!$routeID) {
            http_response_code(400);
            echo json_encode(['error' => 'Missing route ID']);
            exit;
        }
        
        // Get route details
        $stmt = $mysqli->prepare("
            SELECT r.*, d.firstName as driverFirstName, d.surname as driverSurname,
                   s.firstName as supervisorFirstName, s.surname as supervisorSurname
            FROM gibbonTransportRoute r
            LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
            LEFT JOIN gibbonPerson s ON r.gibbonPersonIDSupervisor = s.gibbonPersonID
            WHERE r.gibbonTransportRouteID = ?
        ");
        
        $stmt->bind_param('i', $routeID);
        $stmt->execute();
        $routeResult = $stmt->get_result();
        $route = $routeResult->fetch_assoc();
        
        if (!$route) {
            http_response_code(404);
            echo json_encode(['error' => 'Route not found']);
            exit;
        }
        
        // Get stops
        $stmt = $mysqli->prepare("
            SELECT gibbonTransportStopID, name, sequenceNumber, latitude, longitude,
                   address, landmark, estimatedArrivalTime
            FROM gibbonTransportStop 
            WHERE gibbonTransportRouteID = ? AND active = 1
            ORDER BY sequenceNumber ASC
        ");
        
        $stmt->bind_param('i', $routeID);
        $stmt->execute();
        $stopsResult = $stmt->get_result();
        
        $stops = [];
        while ($stop = $stopsResult->fetch_assoc()) {
            $stops[] = [
                'id' => (int)$stop['gibbonTransportStopID'],
                'name' => $stop['name'],
                'sequenceNumber' => (int)$stop['sequenceNumber'],
                'latitude' => $stop['latitude'] ? (float)$stop['latitude'] : null,
                'longitude' => $stop['longitude'] ? (float)$stop['longitude'] : null,
                'address' => $stop['address'],
                'landmark' => $stop['landmark'],
                'estimatedArrivalTime' => $stop['estimatedArrivalTime']
            ];
        }
        
        // Get students with attendance for the date
        $stmt = $mysqli->prepare("
            SELECT ts.gibbonPersonID, p.firstName, p.surname, p.studentID,
                   s.gibbonTransportStopID, s.name as stopName, s.sequenceNumber,
                   e.status as eventStatus, e.timestamp as eventTimestamp
            FROM gibbonTransportStudent ts
            INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
            LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
            LEFT JOIN gibbonTransportEvent e ON ts.gibbonPersonID = e.gibbonPersonID 
                AND ts.gibbonTransportRouteID = e.gibbonTransportRouteID 
                AND DATE(e.timestamp) = ?
            WHERE ts.gibbonTransportRouteID = ? AND ts.status = 'Active'
            ORDER BY s.sequenceNumber ASC, p.surname, p.firstName
        ");
        
        $stmt->bind_param('si', $date, $routeID);
        $stmt->execute();
        $studentsResult = $stmt->get_result();
        
        $students = [];
        while ($student = $studentsResult->fetch_assoc()) {
            $students[] = [
                'studentID' => (int)$student['gibbonPersonID'],
                'name' => $student['firstName'] . ' ' . $student['surname'],
                'studentNumber' => $student['studentID'],
                'stopID' => $student['gibbonTransportStopID'] ? (int)$student['gibbonTransportStopID'] : null,
                'stopName' => $student['stopName'],
                'stopSequence' => $student['sequenceNumber'] ? (int)$student['sequenceNumber'] : null,
                'eventStatus' => $student['eventStatus'],
                'eventTimestamp' => $student['eventTimestamp']
            ];
        }
        
        // Calculate statistics
        $totalStudents = count($students);
        $presentCount = count(array_filter($students, function($s) { return $s['eventStatus'] === 'Verified'; }));
        $absentCount = count(array_filter($students, function($s) { return $s['eventStatus'] === 'Absent'; }));
        $pendingCount = $totalStudents - $presentCount - $absentCount;
        
        echo json_encode([
            'success' => true,
            'route' => [
                'id' => (int)$route['gibbonTransportRouteID'],
                'name' => $route['name'],
                'nameShort' => $route['nameShort'],
                'routeType' => $route['routeType'],
                'vehicleNumber' => $route['vehicleNumber'],
                'vehicleType' => $route['vehicleType'],
                'capacity' => (int)$route['capacity'],
                'driverName' => $route['driverFirstName'] ? $route['driverFirstName'] . ' ' . $route['driverSurname'] : null,
                'driverPhone' => $route['driverPhone'],
                'supervisorName' => $route['supervisorFirstName'] ? $route['supervisorFirstName'] . ' ' . $route['supervisorSurname'] : null,
                'active' => (bool)$route['active']
            ],
            'date' => $date,
            'statistics' => [
                'totalStudents' => $totalStudents,
                'present' => $presentCount,
                'absent' => $absentCount,
                'pending' => $pendingCount
            ],
            'stops' => $stops,
            'students' => $students
        ]);
        exit;
    }
    
    // Default response for invalid action
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or method']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
} finally {
    $mysqli->close();
}