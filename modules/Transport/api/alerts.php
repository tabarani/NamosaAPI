<?php
/**
 * Transport Safety Alerts API
 * Handles safety alerts and notifications
 */

// Database configuration
$configFile = dirname(__DIR__, 4) . '/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => 'Configuration file not found']);
    exit;
}

require_once $configFile;

try {
    $mysqli = new mysqli($databaseServer, $databaseUsername, $databasePassword, $databaseName, $databasePort ?? 3306);
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed');
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error']);
    exit;
}

$action = $_GET['action'] ?? 'list';

try {
    if ($action === 'unresolved' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        // Get unresolved safety alerts
        $severity = $_GET['severity'] ?? null;
        
        $sql = "SELECT a.*, r.name as routeName, p.firstName, p.surname
                FROM gibbonTransportAlert a
                LEFT JOIN gibbonTransportRoute r ON a.gibbonTransportRouteID = r.gibbonTransportRouteID
                LEFT JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
                WHERE a.resolved = 0";
        
        if ($severity) {
            $sql .= " AND a.severity = '" . $mysqli->real_escape_string($severity) . "'";
        }
        
        $sql .= " ORDER BY 
                CASE a.severity 
                    WHEN 'critical' THEN 1 
                    WHEN 'high' THEN 2 
                    WHEN 'medium' THEN 3 
                    ELSE 4 
                END, 
                a.timestampCreated DESC
                LIMIT 50";
        
        $result = $mysqli->query($sql);
        
        $alerts = [];
        while ($row = $result->fetch_assoc()) {
            $alerts[] = [
                'id' => $row['gibbonTransportAlertID'],
                'type' => $row['alertType'],
                'severity' => $row['severity'],
                'routeID' => $row['gibbonTransportRouteID'],
                'routeName' => $row['routeName'],
                'studentID' => $row['gibbonPersonID'],
                'studentName' => $row['firstName'] ? $row['firstName'] . ' ' . $row['surname'] : null,
                'message' => $row['message'],
                'createdAt' => $row['timestampCreated'],
                'smsSent' => (bool)$row['smsSent']
            ];
        }
        
        echo json_encode([
            'success' => true,
            'count' => count($alerts),
            'alerts' => $alerts
        ]);
        exit;
    }
    
    if ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        // Create new safety alert (triggered by system or manually)
        $input = json_decode(file_get_contents('php://input'), true);
        
        $required = ['alertType', 'severity', 'message'];
        foreach ($required as $field) {
            if (!isset($input[$field]) || empty($input[$field])) {
                http_response_code(400);
                echo json_encode(['error' => "Missing required field: $field"]);
                exit;
            }
        }
        
        // Prepare data
        $alertType = $mysqli->real_escape_string($input['alertType']);
        $severity = $mysqli->real_escape_string($input['severity']);
        $message = $mysqli->real_escape_string($input['message']);
        $routeID = isset($input['routeID']) ? (int)$input['routeID'] : 'NULL';
        $studentID = isset($input['studentID']) ? (int)$input['studentID'] : 'NULL';
        $smsRecipients = isset($input['smsRecipients']) ? json_encode($input['smsRecipients']) : 'NULL';
        
        $sql = "INSERT INTO gibbonTransportAlert 
                (alertType, severity, gibbonTransportRouteID, gibbonPersonID, message, smsRecipients, resolved)
                VALUES ('$alertType', '$severity', $routeID, $studentID, '$message', $smsRecipients, 0)";
        
        if ($mysqli->query($sql)) {
            $alertID = $mysqli->insert_id;
            
            // In production: Send SMS alerts here using SMSService
            if (isset($input['smsRecipients']) && is_array($input['smsRecipients'])) {
                error_log("ALERT $alertID: SMS to " . count($input['smsRecipients']) . " recipients");
                // $smsService->sendBulkSMS($input['smsRecipients'], $message);
            }
            
            echo json_encode([
                'success' => true,
                'alertID' => $alertID,
                'message' => 'Safety alert created successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => 'Database error', 'details' => $mysqli->error]);
        }
        
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