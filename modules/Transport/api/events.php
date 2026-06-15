<?php
/**
 * Transport Events API
 * Handles boarding/dropoff events from mobile app
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
    if ($action === 'today' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get today's events for all routes
        $date = $_GET['date'] ?? date('Y-m-d');
        $routeID = $_GET['routeID'] ?? null;
        
        $sql = "SELECT e.*, p.firstName, p.surname, p.studentID, r.name as routeName
                FROM gibbonTransportEvent e
                INNER JOIN gibbonPerson p ON e.gibbonPersonID = p.gibbonPersonID
                INNER JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
                WHERE DATE(e.timestamp) = ?
                AND e.status = 'Verified'";
        
        $params = [$date];
        $types = 's';
        
        if ($routeID) {
            $sql .= " AND e.gibbonTransportRouteID = ?";
            $params[] = $routeID;
            $types .= 'i';
        }
        
        $sql .= " ORDER BY e.timestamp DESC LIMIT 100";
        
        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $events = [];
        while ($row = $result->fetch_assoc()) {
            $events[] = [
                'id' => $row['gibbonTransportEventID'],
                'routeID' => $row['gibbonTransportRouteID'],
                'routeName' => $row['routeName'],
                'studentID' => $row['gibbonPersonID'],
                'studentName' => $row['firstName'] . ' ' . $row['surname'],
                'studentNumber' => $row['studentID'],
                'eventType' => $row['type'],
                'timestamp' => $row['timestamp'],
                'status' => $row['status'],
                'latitude' => $row['latitude'] ? (float)$row['latitude'] : null,
                'longitude' => $row['longitude'] ? (float)$row['longitude'] : null,
                'photoUrl' => $row['photoUrl'],
                'emergencyFlag' => (bool)$row['emergencyFlag']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'date' => $date,
            'count' => count($events),
            'events' => $events
        ]);
        exit;
    }
    
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Record new boarding event (from mobile app)
        $input = json_decode(file_get_contents('php://input'), true);
        
        // Validate required fields
        $required = ['routeID', 'studentID', 'eventType', 'recordedBy'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        // Validate event type
        if (!in_array($input['eventType'], ['pickup', 'dropoff'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid event type. Must be "pickup" or "dropoff"']);
            exit;
        }
        
        // Prepare and validate data
        $routeID = (int)$input['routeID'];
        $studentID = (int)$input['studentID'];
        $eventType = in_array($input['eventType'], ['pickup', 'dropoff']) ? $input['eventType'] : 'pickup';
        $recordedBy = (int)$input['recordedBy'];
        $latitude = isset($input['latitude']) && is_numeric($input['latitude']) ? (float)$input['latitude'] : null;
        $longitude = isset($input['longitude']) && is_numeric($input['longitude']) ? (float)$input['longitude'] : null;
        $photoUrl = isset($input['photoUrl']) ? substr($input['photoUrl'], 0, 255) : null;
        $emergencyFlag = isset($input['emergencyFlag']) && $input['emergencyFlag'] ? 1 : 0;
        $emergencyNotes = isset($input['emergencyNotes']) ? substr($input['emergencyNotes'], 0, 1000) : null;
        $comments = isset($input['comments']) ? substr($input['comments'], 0, 1000) : null;
        $timestamp = isset($input['timestamp']) && strtotime($input['timestamp']) ? $input['timestamp'] : date('Y-m-d H:i:s');
        
        // Use prepared statement with proper NULL handling
        $sql = "INSERT INTO gibbonTransportEvent 
                (gibbonTransportRouteID, gibbonPersonID, type, timestamp, status, gibbonPersonIDRecorder, 
                 latitude, longitude, photoUrl, photoVerified, emergencyFlag, emergencyNotes, comments, syncStatus)
                VALUES (?, ?, ?, ?, 'Verified', ?, ?, ?, ?, 1, ?, ?, ?, 'synced')";
        
        $stmt = $mysqli->prepare($sql);
        
        // Bind parameters with proper types
        // latitude and longitude can be NULL, so we need to handle them specially
        if ($latitude !== null && $longitude !== null) {
            $stmt->bind_param('iissiddsiss', 
                $routeID, 
                $studentID, 
                $eventType, 
                $timestamp, 
                $recordedBy,
                $latitude,
                $longitude,
                $photoUrl,
                $emergencyFlag,
                $emergencyNotes,
                $comments
            );
        } elseif ($latitude !== null) {
            $stmt->bind_param('iissidssiss', 
                $routeID, 
                $studentID, 
                $eventType, 
                $timestamp, 
                $recordedBy,
                $latitude,
                $photoUrl,
                $emergencyFlag,
                $emergencyNotes,
                $comments
            );
        } elseif ($longitude !== null) {
            $stmt->bind_param('iissidssiss', 
                $routeID, 
                $studentID, 
                $eventType, 
                $timestamp, 
                $recordedBy,
                $longitude,
                $photoUrl,
                $emergencyFlag,
                $emergencyNotes,
                $comments
            );
        } else {
            $stmt->bind_param('iississsiss', 
                $routeID, 
                $studentID, 
                $eventType, 
                $timestamp, 
                $recordedBy,
                $photoUrl,
                $emergencyFlag,
                $emergencyNotes,
                $comments
            );
        }
        
        if ($stmt->execute()) {
            $eventId = $mysqli->insert_id;
            
            // Trigger SMS alert if emergency flag is set
            if ($emergencyFlag) {
                // TODO: Implement SMS service integration
                error_log("EMERGENCY ALERT: Student $studentID on route $routeID at " . date('Y-m-d H:i:s'));
            }
            
            echo json_encode([
                'success' => true,
                'eventId' => $eventId,
                'message' => 'Boarding event recorded successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error', 'details' => $stmt->error]);
        }
        
        $stmt->close();
        exit;
    }
    
    // Default response
    http_response_code(400);
    echo json_encode(['error' => 'Invalid action or method']);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
} finally {
    $mysqli->close();
}