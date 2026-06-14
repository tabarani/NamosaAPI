<?php
/**
 * Transportation Repository
 * Handles all transportation-related database operations
 * Bus routes, stops, boarding events, drivers
 * 
 * Schema: namosa_transport_* tables (custom for Namosa safety system)
 */

namespace NamosaAPI\Repositories;

use NamosaAPI\Lib\Response;

class TransportationRepository extends BaseRepository
{
    /**
     * Get all active bus routes
     */
    public function getAllRoutes($activeOnly = true)
    {
        $sql = "
            SELECT 
                r.routeID as id,
                r.routeName,
                r.routeCode,
                r.vehicleNumber,
                r.vehicleType,
                r.capacity,
                r.active,
                r.comments,
                r.driverID,
                r.driverPhone,
                d.firstName as driverFirstName,
                d.surname as driverSurname,
                d.phoneNumber as driverPhoneNumber,
                d.email as driverEmail,
                COUNT(DISTINCT s.assignmentID) as studentCount
            FROM namosa_transport_routes r
            LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
            LEFT JOIN namosa_transport_students s ON r.routeID = s.routeID AND s.status = 'Active'
            WHERE 1=1
        ";
        
        if ($activeOnly) {
            $sql .= " AND r.active = 'Y'";
        }
        
        $sql .= " GROUP BY r.routeID ORDER BY r.routeName ASC";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Get single route by ID
     */
    public function getRouteById($routeId)
    {
        $sql = "
            SELECT 
                r.routeID as id,
                r.routeName,
                r.routeCode,
                r.vehicleNumber,
                r.vehicleType,
                r.capacity,
                r.active,
                r.comments,
                r.driverID,
                r.driverPhone,
                d.firstName as driverFirstName,
                d.surname as driverSurname,
                d.phoneNumber as driverPhoneNumber,
                d.email as driverEmail
            FROM namosa_transport_routes r
            LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
            WHERE r.routeID = :routeId
            LIMIT 1
        ";
        
        $route = $this->fetchOne($sql, [':routeId' => $routeId]);
        
        if (!$route) {
            return null;
        }
        
        // Add stops and students
        $route['stops'] = $this->getRouteStops($routeId);
        $route['students'] = $this->getStudentsOnRoute($routeId);
        
        return $route;
    }
    
    /**
     * Get all stops for a route (ordered by sequence)
     */
    public function getRouteStops($routeId)
    {
        $sql = "
            SELECT 
                s.stopID as id,
                s.stopName,
                s.stopCode,
                s.sequenceNumber,
                s.latitude,
                s.longitude,
                s.address,
                s.landmark,
                s.estimatedArrivalTime,
                s.comments,
                s.active
            FROM namosa_transport_stops s
            WHERE s.routeID = :routeId
            AND s.active = 'Y'
            ORDER BY s.sequenceNumber ASC
        ";
        
        return $this->fetchAll($sql, [':routeId' => $routeId]);
    }
    
    /**
     * Get all students assigned to a route
     */
    public function getStudentsOnRoute($routeId)
    {
        $sql = "
            SELECT 
                s.assignmentID,
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.preferredName,
                p.dob,
                p.gender,
                p.email,
                p.address1 as address,
                p.phone1 as phoneNumber,
                p.image_240 as photo,
                p.studentID as studentNumber,
                s.status as assignmentStatus,
                s.startDate,
                s.endDate,
                s.specialNeeds,
                s.comments as assignmentComments,
                y.nameShort as yearGroupName,
                c.nameShort as className,
                ss.stopName as assignedStop,
                sss.pickupTime,
                sss.dropoffTime
            FROM namosa_transport_students s
            INNER JOIN gibbonPerson p ON s.studentID = p.gibbonPersonID
            LEFT JOIN gibbonYearGroup y ON p.gibbonYearGroupID = y.gibbonYearGroupID
            LEFT JOIN gibbonFormGroup fg ON p.gibbonFormGroupID = fg.gibbonFormGroupID
            LEFT JOIN gibbonCourseClass c ON fg.gibbonCourseClassID = c.gibbonCourseClassID
            LEFT JOIN namosa_transport_student_stops sss ON s.assignmentID = sss.assignmentID AND sss.stopType IN ('pickup', 'both')
            LEFT JOIN namosa_transport_stops ss ON sss.stopID = ss.stopID
            WHERE s.routeID = :routeId
            AND s.status = 'Active'
            AND p.status = 'Full'
            ORDER BY ss.sequenceNumber ASC, p.surname ASC
        ";
        
        $students = $this->fetchAll($sql, [':routeId' => $routeId]);
        
        // Enrich with parents
        foreach ($students as &$student) {
            $student['parents'] = $this->getParentsForStudent($student['id']);
        }
        
        return $students;
    }
    
    /**
     * Get route assignment for a specific student
     */
    public function getStudentRouteAssignment($studentId)
    {
        $sql = "
            SELECT 
                s.assignmentID,
                r.routeID as routeId,
                r.routeName,
                r.routeCode,
                r.vehicleNumber,
                r.driverID,
                r.driverPhone,
                d.firstName as driverFirstName,
                d.surname as driverSurname,
                d.phoneNumber as driverPhoneNumber,
                ss.stopID,
                ss.stopName,
                ss.sequenceNumber,
                sss.pickupTime,
                sss.dropoffTime,
                ss.latitude,
                ss.longitude,
                ss.address,
                ss.landmark
            FROM namosa_transport_students s
            INNER JOIN namosa_transport_routes r ON s.routeID = r.routeID
            LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
            LEFT JOIN namosa_transport_student_stops sss ON s.assignmentID = sss.assignmentID AND sss.stopType IN ('pickup', 'both')
            LEFT JOIN namosa_transport_stops ss ON sss.stopID = ss.stopID
            WHERE s.studentID = :studentId
            AND s.status = 'Active'
            AND r.active = 'Y'
            LIMIT 1
        ";
        
        return $this->fetchOne($sql, [':studentId' => $studentId]);
    }
    
    /**
     * Get today's boarding events (all routes)
     */
    public function getTodaysBoardingEvents($date = null)
    {
        $date = $date ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                e.eventID as id,
                e.routeID as routeId,
                r.routeName,
                r.routeCode,
                r.vehicleNumber,
                e.studentID as studentId,
                p.firstName,
                p.surname,
                p.preferredName,
                p.image_240 as studentPhoto,
                e.eventType,
                e.eventStatus,
                e.eventTime,
                e.recordedTime,
                e.recordedBy,
                rec.firstName as recorderFirstName,
                rec.surname as recorderSurname,
                e.latitude,
                e.longitude,
                e.stopID,
                st.stopName,
                e.photoUrl,
                e.photoVerified,
                e.comments,
                e.emergencyFlag,
                e.emergencyNotes
            FROM namosa_transport_events e
            INNER JOIN namosa_transport_routes r ON e.routeID = r.routeID
            INNER JOIN gibbonPerson p ON e.studentID = p.gibbonPersonID
            LEFT JOIN gibbonPerson rec ON e.recordedBy = rec.gibbonPersonID
            LEFT JOIN namosa_transport_stops st ON e.stopID = st.stopID
            WHERE DATE(e.eventTime) = :date
            ORDER BY e.eventTime DESC, e.routeID ASC
        ";
        
        return $this->fetchAll($sql, [':date' => $date]);
    }
    
    /**
     * Get boarding events for a specific student (history)
     */
    public function getStudentBoardingHistory($studentId, $limit = 30, $offset = 0)
    {
        $sql = "
            SELECT 
                e.eventID as id,
                e.routeID as routeId,
                r.routeName,
                r.routeCode,
                r.vehicleNumber,
                e.eventType,
                e.eventStatus,
                e.eventTime,
                e.recordedTime,
                e.latitude,
                e.longitude,
                e.stopID,
                st.stopName,
                e.photoUrl,
                e.photoVerified,
                e.comments,
                e.emergencyFlag,
                e.emergencyNotes
            FROM namosa_transport_events e
            INNER JOIN namosa_transport_routes r ON e.routeID = r.routeID
            LEFT JOIN namosa_transport_stops st ON e.stopID = st.stopID
            WHERE e.studentID = :studentId
            ORDER BY e.eventTime DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':studentId', $studentId);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get boarding events for a specific route on a date
     */
    public function getRouteBoardingEvents($routeId, $date = null)
    {
        $date = $date ?? date('Y-m-d');
        
        $sql = "
            SELECT 
                e.eventID as id,
                e.studentID as studentId,
                p.firstName,
                p.surname,
                p.preferredName,
                p.image_240 as studentPhoto,
                p.studentID as studentNumber,
                e.eventType,
                e.eventStatus,
                e.eventTime,
                e.recordedTime,
                e.recordedBy,
                rec.firstName as recorderFirstName,
                rec.surname as recorderSurname,
                e.latitude,
                e.longitude,
                e.stopID,
                st.stopName,
                e.photoUrl,
                e.photoVerified,
                e.comments,
                e.emergencyFlag,
                e.emergencyNotes
            FROM namosa_transport_events e
            INNER JOIN gibbonPerson p ON e.studentID = p.gibbonPersonID
            LEFT JOIN gibbonPerson rec ON e.recordedBy = rec.gibbonPersonID
            LEFT JOIN namosa_transport_stops st ON e.stopID = st.stopID
            WHERE e.routeID = :routeId
            AND DATE(e.eventTime) = :date
            ORDER BY e.eventTime ASC
        ";
        
        return $this->fetchAll($sql, [
            ':routeId' => $routeId,
            ':date' => $date
        ]);
    }
    
    /**
     * Record a boarding event (pickup or dropoff)
     * CRITICAL SAFETY ENDPOINT
     */
    public function recordBoardingEvent($data)
    {
        $this->beginTransaction();
        
        try {
            // Required fields
            $required = ['routeID', 'studentID', 'eventType', 'recordedBy'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    throw new \Exception("Missing required field: $field");
                }
            }
            
            // Validate event type
            $eventType = strtolower($data['eventType']);
            if (!in_array($eventType, ['pickup', 'dropoff'])) {
                throw new \Exception("Event type must be 'pickup' or 'dropoff'");
            }
            
            // Check if student is assigned to this route
            $assignment = $this->getStudentRouteAssignment($data['studentID']);
            
            if (!$assignment) {
                throw new \Exception("Student is not assigned to this route");
            }
            
            // Determine expected stop based on event type
            $expectedStopID = null;
            if ($assignment && isset($assignment['stopID'])) {
                $expectedStopID = $assignment['stopID'];
            }
            
            // Prepare event data
            $eventData = [
                'routeID' => $data['routeID'],
                'studentID' => $data['studentID'],
                'eventType' => $eventType,
                'eventStatus' => $data['eventStatus'] ?? 'OnTime',
                'eventTime' => $data['eventTime'] ?? date('Y-m-d H:i:s'),
                'recordedTime' => date('Y-m-d H:i:s'),
                'recordedBy' => $data['recordedBy'],
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'stopID' => $data['stopID'] ?? $expectedStopID,
                'photoUrl' => $data['photoUrl'] ?? null,
                'photoVerified' => $data['photoVerified'] ?? 'Pending',
                'comments' => $data['comments'] ?? null,
                'emergencyFlag' => $data['emergencyFlag'] ?? 'N',
                'emergencyNotes' => $data['emergencyNotes'] ?? null,
                'syncStatus' => 'synced' // Mark as synced since we're recording directly
            ];
            
            // Insert event
            $eventId = $this->insertTransportEvent($eventData);
            
            // Log the action in audit log
            $this->logAuditAction(
                'boarding_recorded',
                'transport_event',
                $eventId,
                $data['recordedBy'],
                null,
                json_encode($eventData)
            );
            
            $this->commit();
            
            // Return full event with student details
            $sql = "
                SELECT 
                    e.eventID as id,
                    e.routeID as routeId,
                    r.routeName,
                    r.routeCode,
                    r.vehicleNumber,
                    e.studentID as studentId,
                    p.firstName,
                    p.surname,
                    p.preferredName,
                    p.image_240 as studentPhoto,
                    e.eventType,
                    e.eventStatus,
                    e.eventTime,
                    e.recordedTime,
                    e.latitude,
                    e.longitude,
                    e.stopID,
                    st.stopName,
                    e.photoUrl,
                    e.photoVerified,
                    e.comments,
                    e.emergencyFlag,
                    e.emergencyNotes
                FROM namosa_transport_events e
                INNER JOIN namosa_transport_routes r ON e.routeID = r.routeID
                INNER JOIN gibbonPerson p ON e.studentID = p.gibbonPersonID
                LEFT JOIN namosa_transport_stops st ON e.stopID = st.stopID
                WHERE e.eventID = :eventId
            ";
            
            $event = $this->fetchOne($sql, [':eventId' => $eventId]);
            
            return $event;
            
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }
    
    /**
     * Insert transport event (helper method)
     */
    private function insertTransportEvent($data)
    {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);
        
        $sql = "INSERT INTO namosa_transport_events ($fields) VALUES ($placeholders)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get students who haven't boarded yet (for a route on a date)
     * CRITICAL SAFETY ENDPOINT
     */
    public function getMissingBoardingStudents($routeId, $date = null, $eventType = 'pickup')
    {
        $date = $date ?? date('Y-m-d');
        
        $sql = "
            -- Students assigned to route who haven't boarded
            SELECT 
                s.assignmentID,
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.preferredName,
                p.dob,
                p.gender,
                p.email,
                p.address1 as address,
                p.phone1 as phoneNumber,
                p.image_240 as photo,
                p.studentID as studentNumber,
                sss.pickupTime,
                sss.dropoffTime,
                ss.stopName,
                ss.sequenceNumber,
                ss.latitude,
                ss.longitude,
                ss.address as stopAddress,
                ss.landmark,
                'Expected' as boardingStatus,
                CASE 
                    WHEN sss.pickupTime IS NOT NULL AND CURTIME() > DATE_ADD(sss.pickupTime, INTERVAL 15 MINUTE) THEN 'Late'
                    ELSE 'OnTime'
                END as expectedStatus
            FROM namosa_transport_students s
            INNER JOIN gibbonPerson p ON s.studentID = p.gibbonPersonID
            LEFT JOIN namosa_transport_student_stops sss ON s.assignmentID = sss.assignmentID 
                AND sss.stopType IN (:eventType, 'both')
            LEFT JOIN namosa_transport_stops ss ON sss.stopID = ss.stopID
            LEFT JOIN (
                SELECT studentID, eventType 
                FROM namosa_transport_events 
                WHERE routeID = :routeId 
                AND DATE(eventTime) = :date
                AND eventType = :eventType
            ) e ON s.studentID = e.studentID AND e.eventType = :eventType
            WHERE s.routeID = :routeId
            AND s.status = 'Active'
            AND p.status = 'Full'
            AND e.studentID IS NULL  -- Not yet boarded
            ORDER BY ss.sequenceNumber ASC, p.surname ASC
        ";
        
        return $this->fetchAll($sql, [
            ':routeId' => $routeId,
            ':date' => $date,
            ':eventType' => $eventType
        ]);
    }
    
    /**
     * Get route statistics (for dashboard)
     */
    public function getRouteStatistics($routeId, $days = 30)
    {
        $sinceDate = date('Y-m-d', strtotime("-{$days} days"));
        
        $sql = "
            SELECT 
                COUNT(DISTINCT CASE WHEN eventType = 'pickup' THEN studentID END) as totalPickups,
                COUNT(DISTINCT CASE WHEN eventType = 'dropoff' THEN studentID END) as totalDropoffs,
                COUNT(DISTINCT CASE WHEN eventStatus = 'Late' THEN eventID END) as lateEvents,
                COUNT(DISTINCT CASE WHEN eventStatus = 'Absent' THEN eventID END) as absentEvents,
                COUNT(DISTINCT CASE WHEN emergencyFlag = 'Y' THEN eventID END) as emergencyEvents,
                DATE(eventTime) as eventDate
            FROM namosa_transport_events
            WHERE routeID = :routeId
            AND eventTime >= :sinceDate
            GROUP BY DATE(eventTime)
            ORDER BY eventDate DESC
        ";
        
        $dailyStats = $this->fetchAll($sql, [
            ':routeId' => $routeId,
            ':sinceDate' => $sinceDate
        ]);
        
        // Calculate totals
        $totals = [
            'totalPickups' => 0,
            'totalDropoffs' => 0,
            'lateEvents' => 0,
            'absentEvents' => 0,
            'emergencyEvents' => 0,
            'days' => count($dailyStats)
        ];
        
        foreach ($dailyStats as $stat) {
            $totals['totalPickups'] += $stat['totalPickups'];
            $totals['totalDropoffs'] += $stat['totalDropoffs'];
            $totals['lateEvents'] += $stat['lateEvents'];
            $totals['absentEvents'] += $stat['absentEvents'];
            $totals['emergencyEvents'] += $stat['emergencyEvents'];
        }
        
        return [
            'totals' => $totals,
            'daily' => $dailyStats
        ];
    }
    
    /**
     * Get driver information
     */
    public function getDriverById($driverId)
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.phoneNumber as phone1,
                p.phone2,
                p.phone3,
                p.phone4,
                p.email,
                p.address1,
                p.status,
                p.dateStart,
                COUNT(DISTINCT r.routeID) as assignedRoutes,
                GROUP_CONCAT(DISTINCT r.routeName ORDER BY r.routeName ASC SEPARATOR ', ') as routes
            FROM gibbonPerson p
            LEFT JOIN namosa_transport_routes r ON p.gibbonPersonID = r.driverID
            WHERE p.gibbonPersonID = :driverId
            GROUP BY p.gibbonPersonID
            LIMIT 1
        ";
        
        return $this->fetchOne($sql, [':driverId' => $driverId]);
    }
    
    /**
     * Get all drivers
     */
    public function getAllDrivers()
    {
        $sql = "
            SELECT 
                p.gibbonPersonID as id,
                p.firstName,
                p.surname,
                p.phoneNumber as phone1,
                p.email,
                COUNT(DISTINCT r.routeID) as routeCount,
                GROUP_CONCAT(DISTINCT r.routeName ORDER BY r.routeName ASC SEPARATOR ', ') as routes
            FROM gibbonPerson p
            INNER JOIN namosa_transport_routes r ON p.gibbonPersonID = r.driverID
            WHERE r.active = 'Y'
            GROUP BY p.gibbonPersonID
            ORDER BY p.surname ASC
        ";
        
        return $this->fetchAll($sql);
    }
    
    /**
     * Create a new route
     */
    public function createRoute($data)
    {
        $required = ['routeName', 'routeCode', 'vehicleNumber'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \Exception("Missing required field: $field");
            }
        }
        
        $routeData = [
            'routeName' => $data['routeName'],
            'routeCode' => $data['routeCode'],
            'vehicleNumber' => $data['vehicleNumber'],
            'vehicleType' => $data['vehicleType'] ?? 'Bus',
            'capacity' => $data['capacity'] ?? 50,
            'driverID' => $data['driverID'] ?? null,
            'driverPhone' => $data['driverPhone'] ?? null,
            'active' => $data['active'] ?? 'Y',
            'comments' => $data['comments'] ?? null
        ];
        
        $routeId = $this->insert('namosa_transport_routes', $routeData);
        
        // Log audit
        $this->logAuditAction(
            'route_created',
            'transport_route',
            $routeId,
            $data['createdBy'] ?? null,
            null,
            json_encode($routeData)
        );
        
        return $routeId;
    }
    
    /**
     * Create a stop for a route
     */
    public function createStop($data)
    {
        $required = ['routeID', 'stopName', 'sequenceNumber'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \Exception("Missing required field: $field");
            }
        }
        
        $stopData = [
            'routeID' => $data['routeID'],
            'stopName' => $data['stopName'],
            'stopCode' => $data['stopCode'] ?? null,
            'sequenceNumber' => $data['sequenceNumber'],
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'address' => $data['address'] ?? null,
            'landmark' => $data['landmark'] ?? null,
            'estimatedArrivalTime' => $data['estimatedArrivalTime'] ?? null,
            'comments' => $data['comments'] ?? null,
            'active' => $data['active'] ?? 'Y'
        ];
        
        $stopId = $this->insert('namosa_transport_stops', $stopData);
        
        return $stopId;
    }
    
    /**
     * Assign student to route
     */
    public function assignStudentToRoute($data)
    {
        $required = ['studentID', 'routeID'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \Exception("Missing required field: $field");
            }
        }
        
        // Check if already assigned
        $existing = $this->fetchOne("
            SELECT assignmentID FROM namosa_transport_students 
            WHERE studentID = :studentID AND routeID = :routeID AND status IN ('Active', 'Pending')
        ", [
            ':studentID' => $data['studentID'],
            ':routeID' => $data['routeID']
        ]);
        
        if ($existing) {
            throw new \Exception("Student is already assigned to this route");
        }
        
        $assignmentData = [
            'studentID' => $data['studentID'],
            'routeID' => $data['routeID'],
            'academicYearID' => $data['academicYearID'] ?? null,
            'status' => $data['status'] ?? 'Active',
            'startDate' => $data['startDate'] ?? date('Y-m-d'),
            'endDate' => $data['endDate'] ?? null,
            'emergencyContactOverride' => $data['emergencyContactOverride'] ?? null,
            'specialNeeds' => $data['specialNeeds'] ?? null,
            'comments' => $data['comments'] ?? null
        ];
        
        $assignmentId = $this->insert('namosa_transport_students', $assignmentData);
        
        // Log audit
        $this->logAuditAction(
            'student_assigned_to_route',
            'transport_student',
            $assignmentId,
            $data['assignedBy'] ?? null,
            null,
            json_encode($assignmentData)
        );
        
        return $assignmentId;
    }
    
    /**
     * Helper: Get parents for student
     */
    private function getParentsForStudent($studentId)
    {
        $sql = "
            SELECT 
                pa.gibbonPersonID as id,
                pa.firstName,
                pa.surname,
                pa.email,
                pa.phone1 as phone,
                pa.phone2,
                fa.relationship,
                fa.contactPriority
            FROM gibbonFamilyChild fc
            INNER JOIN gibbonFamilyAdult fa ON fc.gibbonFamilyID = fa.gibbonFamilyID
            INNER JOIN gibbonPerson pa ON fa.gibbonPersonID = pa.gibbonPersonID
            WHERE fc.gibbonPersonIDStudent = :studentId
            ORDER BY fa.contactPriority ASC
        ";
        
        return $this->fetchAll($sql, [':studentId' => $studentId]);
    }
    
    /**
     * Log audit action
     */
    private function logAuditAction($actionType, $entityType, $entityID, $userID, $oldValues, $newValues)
    {
        $auditData = [
            'actionType' => $actionType,
            'entityType' => $entityType,
            'entityID' => $entityID,
            'userID' => $userID,
            'ipAddress' => $_SERVER['REMOTE_ADDR'] ?? null,
            'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'oldValues' => $oldValues,
            'newValues' => $newValues,
            'success' => 'Y'
        ];
        
        $this->insert('namosa_transport_audit_log', $auditData);
    }
    
    /**
     * Get safety alerts
     */
    public function getSafetyAlerts($limit = 50, $resolved = false)
    {
        $sql = "
            SELECT 
                a.alertID as id,
                a.alertType,
                a.severity,
                a.routeID,
                r.routeName,
                a.studentID,
                s.firstName as studentFirstName,
                s.surname as studentSurname,
                a.driverID,
                d.firstName as driverFirstName,
                d.surname as driverSurname,
                a.message,
                a.smsSent,
                a.emailSent,
                a.resolved,
                a.resolvedAt,
                a.resolvedBy,
                a.createdAt,
                a.updatedAt
            FROM namosa_transport_alerts a
            LEFT JOIN namosa_transport_routes r ON a.routeID = r.routeID
            LEFT JOIN gibbonPerson s ON a.studentID = s.gibbonPersonID
            LEFT JOIN gibbonPerson d ON a.driverID = d.gibbonPersonID
            WHERE 1=1
        ";
        
        if (!$resolved) {
            $sql .= " AND a.resolved = 'N'";
        }
        
        $sql .= " ORDER BY a.createdAt DESC LIMIT :limit";
        
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    /**
     * Create safety alert
     */
    public function createSafetyAlert($data)
    {
        $required = ['alertType', 'severity', 'message'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                throw new \Exception("Missing required field: $field");
            }
        }
        
        $alertData = [
            'alertType' => $data['alertType'],
            'severity' => $data['severity'],
            'routeID' => $data['routeID'] ?? null,
            'studentID' => $data['studentID'] ?? null,
            'driverID' => $data['driverID'] ?? null,
            'message' => $data['message'],
            'smsSent' => $data['smsSent'] ?? 'N',
            'smsRecipients' => $data['smsRecipients'] ?? null,
            'smsResponse' => $data['smsResponse'] ?? null,
            'emailSent' => $data['emailSent'] ?? 'N',
            'resolved' => 'N',
            'resolvedBy' => null,
            'resolvedAt' => null,
            'resolvedNotes' => null
        ];
        
        $alertId = $this->insert('namosa_transport_alerts', $alertData);
        
        return $alertId;
    }
}