<?php
/**
 * Transport Alert System
 * Handles automatic and manual alert triggering with escalation
 */

class TransportAlerts {
    
    private $connection;
    private $guid;
    private $smsService;
    
    const ALERT_MISSING_BOARDING = 'missing_boarding';
    const ALERT_ROUTE_DEVIATION = 'route_deviation';
    const ALERT_LATE_ARRIVAL = 'late_arrival';
    const ALERT_EMERGENCY = 'emergency';
    const ALERT_CUSTOM = 'custom';
    
    const SEVERITY_LOW = 'low';
    const SEVERITY_MEDIUM = 'medium';
    const SEVERITY_HIGH = 'high';
    const SEVERITY_CRITICAL = 'critical';
    
    /**
     * Constructor
     */
    public function __construct($connection, $guid, $smsService = null) {
        $this->connection = $connection;
        $this->guid = $guid;
        $this->smsService = $smsService;
    }
    
    /**
     * Create alert
     */
    public function createAlert($alertType, $severity, $message, $metadata = []) {
        try {
            $stmt = $this->connection->prepare("
                INSERT INTO gibbonTransportAlert 
                (alertType, severity, gibbonTransportRouteID, gibbonPersonID, message, resolved)
                VALUES (?, ?, ?, ?, ?, 0)
            ");
            
            $routeID = $metadata['routeID'] ?? null;
            $studentID = $metadata['studentID'] ?? null;
            
            $stmt->bind_param('sssii', $alertType, $severity, $routeID, $studentID, $message);
            $stmt->execute();
            
            $alertID = $this->connection->insert_id;
            
            // Send SMS if configured
            if (!empty($metadata['smsRecipients']) && $this->smsService) {
                $smsResult = $this->smsService->sendSMS(
                    $metadata['smsRecipients'],
                    $message,
                    ['alertID' => $alertID]
                );
                
                // Update SMS status
                if ($smsResult['success']) {
                    $updateStmt = $this->connection->prepare("
                        UPDATE gibbonTransportAlert 
                        SET smsSent = 1, smsRecipients = ? 
                        WHERE gibbonTransportAlertID = ?
                    ");
                    $recipients = is_array($metadata['smsRecipients']) 
                        ? json_encode($metadata['smsRecipients']) 
                        : $metadata['smsRecipients'];
                    $updateStmt->bind_param('si', $recipients, $alertID);
                    $updateStmt->execute();
                }
            }
            
            return $alertID;
        } catch (Exception $e) {
            error_log('Alert creation failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Check for missing student boardings
     * Run periodically (every 5-10 minutes)
     */
    public function checkMissingBoardings() {
        try {
            // Get routes that should be active now
            $stmt = $this->connection->prepare("
                SELECT DISTINCT r.gibbonTransportRouteID, r.name, r.routeType
                FROM gibbonTransportRoute r
                INNER JOIN gibbonTransportStop s ON r.gibbonTransportRouteID = s.gibbonTransportRouteID
                WHERE r.active = 1
                AND s.estimatedArrivalTime IS NOT NULL
                AND TIME(NOW()) >= DATE_SUB(s.estimatedArrivalTime, INTERVAL 15 MINUTE)
                AND TIME(NOW()) <= DATE_ADD(s.estimatedArrivalTime, INTERVAL 15 MINUTE)
            ");
            $stmt->execute();
            $routes = $stmt->get_result()->fetchAll();
            
            $missingAlerts = 0;
            
            foreach ($routes as $route) {
                $routeID = $route['gibbonTransportRouteID'];
                $routeType = $route['routeType'];
                
                // Determine event type
                $eventType = ($routeType === 'to_school') ? 'pickup' : (($routeType === 'from_school') ? 'dropoff' : 'pickup');
                
                // Find students who should have been boarded but weren't
                $missingStmt = $this->connection->prepare("
                    SELECT ts.gibbonPersonID, ts.gibbonTransportStudentID, p.firstName, p.surname
                    FROM gibbonTransportStudent ts
                    INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
                    WHERE ts.gibbonTransportRouteID = ?
                    AND ts.status = 'Active'
                    AND NOT EXISTS (
                        SELECT 1 FROM gibbonTransportEvent e
                        WHERE e.gibbonPersonID = ts.gibbonPersonID
                        AND e.gibbonTransportRouteID = ?
                        AND DATE(e.timestamp) = CURDATE()
                        AND e.type = ?
                    )
                    AND NOT EXISTS (
                        SELECT 1 FROM gibbonTransportAlert a
                        WHERE a.gibbonPersonID = ts.gibbonPersonID
                        AND a.gibbonTransportRouteID = ?
                        AND a.alertType = ?
                        AND a.resolved = 0
                        AND DATE(a.timestampCreated) = CURDATE()
                    )
                ");
                
                $missingStmt->bind_param('iisss', $routeID, $routeID, $eventType, $routeID, self::ALERT_MISSING_BOARDING);
                $missingStmt->execute();
                $missingStudents = $missingStmt->get_result()->fetchAll();
                
                foreach ($missingStudents as $student) {
                    $studentID = $student['gibbonPersonID'];
                    $studentName = $student['firstName'] . ' ' . $student['surname'];
                    
                    // Get parent phone numbers
                    $parentStmt = $this->connection->prepare("
                        SELECT DISTINCT COALESCE(p.phone1, p.phone2) as phone
                        FROM gibbonFamilyChild fc
                        INNER JOIN gibbonFamilyAdult fa ON fc.gibbonFamilyID = fa.gibbonFamilyID
                        INNER JOIN gibbonPerson p ON fa.gibbonPersonID = p.gibbonPersonID
                        WHERE fc.gibbonPersonID = ?
                        AND (p.phone1 IS NOT NULL OR p.phone2 IS NOT NULL)
                    ");
                    $parentStmt->bind_param('i', $studentID);
                    $parentStmt->execute();
                    $parents = $parentStmt->get_result()->fetchAll();
                    
                    if (!empty($parents)) {
                        $phones = array_column($parents, 'phone');
                        
                        $message = "🚨 ALERT: {$studentName} did not board the " . 
                                  $route['name'] . " route. Please contact the school immediately.";
                        
                        $alertID = $this->createAlert(
                            self::ALERT_MISSING_BOARDING,
                            self::SEVERITY_HIGH,
                            $message,
                            [
                                'routeID' => $routeID,
                                'studentID' => $studentID,
                                'smsRecipients' => $phones
                            ]
                        );
                        
                        if ($alertID) {
                            $missingAlerts++;
                        }
                    }
                }
            }
            
            return $missingAlerts;
        } catch (Exception $e) {
            error_log('Missing boarding check failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Check for late arrivals
     */
    public function checkLateArrivals($lateThreshold = 15) { // minutes
        try {
            $lateThresholdTime = date('Y-m-d H:i:s', strtotime("-$lateThreshold minutes"));
            
            $stmt = $this->connection->prepare("
                SELECT DISTINCT e.gibbonPersonID, e.gibbonTransportRouteID, 
                       p.firstName, p.surname, r.name as routeName,
                       s.estimatedArrivalTime
                FROM gibbonTransportEvent e
                INNER JOIN gibbonPerson p ON e.gibbonPersonID = p.gibbonPersonID
                INNER JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
                LEFT JOIN gibbonTransportStop s ON e.gibbonTransportStopID = s.gibbonTransportStopID
                WHERE DATE(e.timestamp) = CURDATE()
                AND e.status = 'Late'
                AND NOT EXISTS (
                    SELECT 1 FROM gibbonTransportAlert a
                    WHERE a.gibbonPersonID = e.gibbonPersonID
                    AND a.gibbonTransportRouteID = e.gibbonTransportRouteID
                    AND a.alertType = ?
                    AND a.resolved = 0
                    AND DATE(a.timestampCreated) = CURDATE()
                )
            ");
            
            $stmt->bind_param('s', self::ALERT_LATE_ARRIVAL);
            $stmt->execute();
            $lateEvents = $stmt->get_result()->fetchAll();
            
            $lateAlerts = 0;
            
            foreach ($lateEvents as $event) {
                $studentID = $event['gibbonPersonID'];
                $routeID = $event['gibbonTransportRouteID'];
                
                // Get parent phone numbers
                $parentStmt = $this->connection->prepare("
                    SELECT DISTINCT COALESCE(p.phone1, p.phone2) as phone
                    FROM gibbonFamilyChild fc
                    INNER JOIN gibbonFamilyAdult fa ON fc.gibbonFamilyID = fa.gibbonFamilyID
                    INNER JOIN gibbonPerson p ON fa.gibbonPersonID = p.gibbonPersonID
                    WHERE fc.gibbonPersonID = ?
                    AND (p.phone1 IS NOT NULL OR p.phone2 IS NOT NULL)
                ");
                $parentStmt->bind_param('i', $studentID);
                $parentStmt->execute();
                $parents = $parentStmt->get_result()->fetchAll();
                
                if (!empty($parents)) {
                    $phones = array_column($parents, 'phone');
                    
                    $message = "⏰ ALERT: {$event['firstName']} {$event['surname']} is running late on the " . 
                              $event['routeName'] . " route. ETA: {$event['estimatedArrivalTime']}";
                    
                    $alertID = $this->createAlert(
                        self::ALERT_LATE_ARRIVAL,
                        self::SEVERITY_MEDIUM,
                        $message,
                        [
                            'routeID' => $routeID,
                            'studentID' => $studentID,
                            'smsRecipients' => $phones
                        ]
                    );
                    
                    if ($alertID) {
                        $lateAlerts++;
                    }
                }
            }
            
            return $lateAlerts;
        } catch (Exception $e) {
            error_log('Late arrival check failed: ' . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Resolve alert
     */
    public function resolveAlert($alertID, $notes = '', $resolvedBy = null) {
        try {
            if (!$resolvedBy) {
                $resolvedBy = $_SESSION[$this->guid]['gibbonPersonID'] ?? null;
            }
            
            $stmt = $this->connection->prepare("
                UPDATE gibbonTransportAlert 
                SET resolved = 1, resolvedBy = ?, resolvedNotes = ?, resolvedAt = NOW()
                WHERE gibbonTransportAlertID = ?
            ");
            
            $stmt->bind_param('isi', $resolvedBy, $notes, $alertID);
            return $stmt->execute();
        } catch (Exception $e) {
            error_log('Alert resolution failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Get unresolved alerts
     */
    public function getUnresolvedAlerts($limit = 50) {
        try {
            $stmt = $this->connection->prepare("
                SELECT a.*, r.name as routeName, p.firstName, p.surname
                FROM gibbonTransportAlert a
                LEFT JOIN gibbonTransportRoute r ON a.gibbonTransportRouteID = r.gibbonTransportRouteID
                LEFT JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
                WHERE a.resolved = 0
                ORDER BY 
                    CASE a.severity 
                        WHEN 'critical' THEN 1 
                        WHEN 'high' THEN 2 
                        WHEN 'medium' THEN 3 
                        ELSE 4 
                    END,
                    a.timestampCreated DESC
                LIMIT ?
            ");
            
            $stmt->bind_param('i', $limit);
            $stmt->execute();
            return $stmt->get_result()->fetchAll();
        } catch (Exception $e) {
            error_log('Unresolved alerts fetch failed: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get critical alerts
     */
    public function getCriticalAlerts() {
        try {
            $stmt = $this->connection->prepare("
                SELECT a.*, r.name as routeName, p.firstName, p.surname
                FROM gibbonTransportAlert a
                LEFT JOIN gibbonTransportRoute r ON a.gibbonTransportRouteID = r.gibbonTransportRouteID
                LEFT JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
                WHERE a.resolved = 0
                AND a.severity = 'critical'
                ORDER BY a.timestampCreated DESC
            ");
            
            $stmt->execute();
            return $stmt->get_result()->fetchAll();
        } catch (Exception $e) {
            error_log('Critical alerts fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}
?>
