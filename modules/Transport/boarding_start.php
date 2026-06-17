<?php
/*
Gibbon: the flexible, open school platform
Transport Module - Boarding Registration
Allows supervisor/driver to manually register student pickups/dropoffs at each stop
*/

$page->title = __('Boarding Registration');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Boarding Registration'));

require_once __DIR__ . '/lib/TransportSchema.php';
transportEnsureCompatibilitySchema($connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Transport/boarding_start.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Get current user info
$currentUserID = $_SESSION[$guid]['gibbonPersonID'];

// Check if user is driver or supervisor of any route
$stmtRouteAccess = $connection2->prepare("
    SELECT r.gibbonTransportRouteID, r.name, r.routeType, r.vehicleNumber,
           CASE 
               WHEN r.driverID = :userID THEN 'driver'
               WHEN r.gibbonPersonIDSupervisor = :userID THEN 'supervisor'
               ELSE 'admin'
           END as userRole
    FROM gibbonTransportRoute r
    WHERE r.active = 1 
    AND (r.driverID = :userID OR r.gibbonPersonIDSupervisor = :userID OR :isAdmin = 1)
    ORDER BY r.name
");

// Check if user is admin
$isAdmin = false;
$stmtAdmin = $connection2->prepare("SELECT gibbonRoleID FROM gibbonRole WHERE gibbonRoleID = :roleID AND category = 'Staff'");
$stmtAdmin->execute(['roleID' => $_SESSION[$guid]['gibbonRoleIDPrimary']]);
if ($stmtAdmin->fetch()) {
    // Check for admin permission
    $isAdmin = isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage.php');
}

$stmtRouteAccess->execute(['userID' => $currentUserID, 'isAdmin' => $isAdmin ? 1 : 0]);
$accessibleRoutes = $stmtRouteAccess->fetchAll();

if (empty($accessibleRoutes) && !$isAdmin) {
    $page->addError(__('You are not assigned as a driver or supervisor for any route.'));
    return;
}

// If admin, get all routes
if ($isAdmin && empty($accessibleRoutes)) {
    $accessibleRoutes = $connection2->query("
        SELECT gibbonTransportRouteID, name, routeType, vehicleNumber, 'admin' as userRole
        FROM gibbonTransportRoute WHERE active = 1 ORDER BY name
    ")->fetchAll();
}

$selectedRouteID = $_GET['route'] ?? $_POST['routeID'] ?? ($accessibleRoutes[0]['gibbonTransportRouteID'] ?? null);
$selectedDate = $_GET['date'] ?? date('Y-m-d');

// Get selected route info
$selectedRoute = null;
foreach ($accessibleRoutes as $r) {
    if ($r['gibbonTransportRouteID'] == $selectedRouteID) {
        $selectedRoute = $r;
        break;
    }
}

// Process boarding registration
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'register_boarding') {
            $studentID = (int)$_POST['studentID'];
            $stopID = !empty($_POST['stopID']) ? (int)$_POST['stopID'] : null;
            $routeID = (int)$_POST['routeID'];
            $routeType = $_POST['routeType'] ?? 'both';
            $comments = $_POST['comments'] ?? null;
            
            // Determine event type based on route type
            if ($routeType === 'to_school') {
                $eventType = 'pickup';
            } elseif ($routeType === 'from_school') {
                $eventType = 'dropoff';
            } else {
                $eventType = $_POST['eventType'] ?? 'pickup';
            }
            
            // Check if already registered today
            $stmtCheck = $connection2->prepare("
                SELECT gibbonTransportEventID FROM gibbonTransportEvent 
                WHERE gibbonPersonID = :studentID 
                AND gibbonTransportRouteID = :routeID 
                AND DATE(timestamp) = :date
                AND type = :type
            ");
            $stmtCheck->execute([
                'studentID' => $studentID,
                'routeID' => $routeID,
                'date' => $selectedDate,
                'type' => $eventType
            ]);
            
            if ($stmtCheck->fetch()) {
                $page->addWarning(__('This student has already been registered for this route today.'));
            } else {
                // Insert boarding event
                $stmtInsert = $connection2->prepare("
                    INSERT INTO gibbonTransportEvent 
                    (gibbonTransportRouteID, gibbonPersonID, type, timestamp, status, gibbonPersonIDRecorder, comments, syncStatus)
                    VALUES (:routeID, :studentID, :type, :timestamp, 'Verified', :recorder, :comments, 'synced')
                ");
                $stmtInsert->execute([
                    'routeID' => $routeID,
                    'studentID' => $studentID,
                    'type' => $eventType,
                    'timestamp' => $selectedDate . ' ' . date('H:i:s'),
                    'recorder' => $currentUserID,
                    'comments' => $comments
                ]);
                
                $page->addSuccess(__('Student boarding registered successfully!'));
            }
        } elseif ($_POST['action'] === 'mark_absent') {
            $studentID = (int)$_POST['studentID'];
            $routeID = (int)$_POST['routeID'];
            $routeType = $_POST['routeType'] ?? 'both';
            
            $eventType = ($routeType === 'to_school') ? 'pickup' : (($routeType === 'from_school') ? 'dropoff' : 'pickup');
            
            $stmtAbsent = $connection2->prepare("
                INSERT INTO gibbonTransportEvent 
                (gibbonTransportRouteID, gibbonPersonID, type, timestamp, status, gibbonPersonIDRecorder, comments, syncStatus)
                VALUES (:routeID, :studentID, :type, :timestamp, 'Absent', :recorder, 'Marked absent by staff', 'synced')
            ");
            $stmtAbsent->execute([
                'routeID' => $routeID,
                'studentID' => $studentID,
                'type' => $eventType,
                'timestamp' => $selectedDate . ' ' . date('H:i:s'),
                'recorder' => $currentUserID
            ]);
            
            $page->addSuccess(__('Student marked as absent.'));
        }
    } catch (Exception $e) {
        $page->addError(__('Error: ') . $e->getMessage());
    }
}

// Get route type label
function getRouteTypeLabel($type) {
    switch ($type) {
        case 'to_school': return '🌅 To School (Pickups)';
        case 'from_school': return '🌆 From School (Dropoffs)';
        default: return '🔄 Bidirectional';
    }
}

function getRouteTypeBadge($type) {
    switch ($type) {
        case 'to_school': return '<span style="background:#4CAF50;color:white;padding:3px 8px;border-radius:4px;font-size:12px;">PICKUP</span>';
        case 'from_school': return '<span style="background:#2196F3;color:white;padding:3px 8px;border-radius:4px;font-size:12px;">DROPOFF</span>';
        default: return '<span style="background:#FF9800;color:white;padding:3px 8px;border-radius:4px;font-size:12px;">BOTH</span>';
    }
}

?>

<style>
.boarding-container { max-width: 1000px; margin: 0 auto; }
.route-selector { background: #f5f5f5; padding: 15px; border-radius: 8px; margin-bottom: 20px; }
.stop-section { background: #fff; border: 1px solid #ddd; border-radius: 8px; margin-bottom: 15px; overflow: hidden; }
.stop-header { background: #1976D2; color: white; padding: 12px 15px; font-weight: bold; display: flex; justify-content: space-between; align-items: center; }
.stop-header .badge { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 12px; font-size: 12px; }
.student-list { padding: 0; margin: 0; }
.student-item { display: flex; align-items: center; justify-content: space-between; padding: 12px 15px; border-bottom: 1px solid #eee; }
.student-item:last-child { border-bottom: none; }
.student-item:hover { background: #f9f9f9; }
.student-info { display: flex; align-items: center; gap: 12px; }
.student-photo { width: 40px; height: 40px; border-radius: 50%; background: #e0e0e0; display: flex; align-items: center; justify-content: center; font-weight: bold; color: #666; }
.student-name { font-weight: 500; }
.student-id { color: #666; font-size: 12px; }
.action-buttons { display: flex; gap: 8px; }
.btn { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; transition: all 0.2s; }
.btn-board { background: #4CAF50; color: white; }
.btn-board:hover { background: #388E3C; }
.btn-absent { background: #FF5722; color: white; }
.btn-absent:hover { background: #E64A19; }
.btn-done { background: #9E9E9E; color: white; cursor: default; }
.status-badge { padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: bold; }
.status-boarded { background: #C8E6C9; color: #2E7D32; }
.status-absent { background: #FFCDD2; color: #C62828; }
.summary-bar { background: #263238; color: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
.summary-stat { text-align: center; }
.summary-stat .number { font-size: 28px; font-weight: bold; }
.summary-stat .label { font-size: 12px; opacity: 0.8; }
.no-stop { background: #FFF3E0; border-left: 4px solid #FF9800; }
</style>

<div class="boarding-container">
    <h2>🚌 <?php echo __('Boarding Registration'); ?></h2>
    
    <!-- Route & Date Selector -->
    <div class="route-selector">
        <form method="GET" action="<?php echo $_SESSION[$guid]['absoluteURL']; ?>/index.php" style="display:flex; gap:15px; align-items:center; flex-wrap:wrap;">
            <input type="hidden" name="q" value="/modules/Transport/boarding_start.php">
            
            <div>
                <strong><?php echo __('Route'); ?>:</strong>
                <select name="route" style="padding:8px; min-width:200px;" onchange="this.form.submit()">
                    <?php foreach ($accessibleRoutes as $r): ?>
                        <option value="<?php echo $r['gibbonTransportRouteID']; ?>" <?php echo ($r['gibbonTransportRouteID'] == $selectedRouteID) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($r['name'] . ' (' . $r['vehicleNumber'] . ')'); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <strong><?php echo __('Date'); ?>:</strong>
                <input type="date" name="date" value="<?php echo $selectedDate; ?>" style="padding:8px;" onchange="this.form.submit()">
            </div>
            
            <?php if ($selectedRoute): ?>
                <div>
                    <strong><?php echo __('Type'); ?>:</strong>
                    <?php echo getRouteTypeBadge($selectedRoute['routeType'] ?? 'both'); ?>
                </div>
                
                <div>
                    <strong><?php echo __('Your Role'); ?>:</strong>
                    <span style="background:#673AB7;color:white;padding:4px 10px;border-radius:4px;font-size:12px;">
                        <?php echo strtoupper($selectedRoute['userRole']); ?>
                    </span>
                </div>
            <?php endif; ?>
        </form>
    </div>

<?php if ($selectedRoute): 
    // Get stops for this route
    $stmtStops = $connection2->prepare("
        SELECT gibbonTransportStopID, name, sequenceNumber, estimatedArrivalTime, address
        FROM gibbonTransportStop 
        WHERE gibbonTransportRouteID = :routeID AND active = 1 
        ORDER BY sequenceNumber ASC
    ");
    $stmtStops->execute(['routeID' => $selectedRouteID]);
    $stops = $stmtStops->fetchAll();
    
    // Get students assigned to this route with their stop info
    $stmtStudents = $connection2->prepare("
        SELECT ts.gibbonTransportStudentID, ts.gibbonTransportStopID, ts.specialNeeds,
               p.gibbonPersonID, p.firstName, p.surname, p.studentID, p.image_240,
               s.name as stopName, s.sequenceNumber
        FROM gibbonTransportStudent ts
        INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
        LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
        WHERE ts.gibbonTransportRouteID = :routeID AND ts.status = 'Active'
        ORDER BY s.sequenceNumber ASC, p.surname, p.firstName
    ");
    $stmtStudents->execute(['routeID' => $selectedRouteID]);
    $students = $stmtStudents->fetchAll();
    
    // Get today's events for this route
    $stmtEvents = $connection2->prepare("
        SELECT gibbonPersonID, type, status 
        FROM gibbonTransportEvent 
        WHERE gibbonTransportRouteID = :routeID AND DATE(timestamp) = :date
    ");
    $stmtEvents->execute(['routeID' => $selectedRouteID, 'date' => $selectedDate]);
    $todayEvents = [];
    while ($event = $stmtEvents->fetch()) {
        $todayEvents[$event['gibbonPersonID']] = $event;
    }
    
    // Group students by stop
    $studentsByStop = [];
    $studentsNoStop = [];
    foreach ($students as $student) {
        if ($student['gibbonTransportStopID']) {
            $studentsByStop[$student['gibbonTransportStopID']][] = $student;
        } else {
            $studentsNoStop[] = $student;
        }
    }
    
    // Calculate summary
    $totalStudents = count($students);
    $boardedCount = 0;
    $absentCount = 0;
    foreach ($todayEvents as $e) {
        if ($e['status'] === 'Verified') $boardedCount++;
        if ($e['status'] === 'Absent') $absentCount++;
    }
    $pendingCount = $totalStudents - $boardedCount - $absentCount;
    
    $routeType = $selectedRoute['routeType'] ?? 'both';
?>

    <!-- Summary Bar -->
    <div class="summary-bar">
        <div class="summary-stat">
            <div class="number"><?php echo $totalStudents; ?></div>
            <div class="label"><?php echo __('Total Students'); ?></div>
        </div>
        <div class="summary-stat">
            <div class="number" style="color:#4CAF50;"><?php echo $boardedCount; ?></div>
            <div class="label"><?php echo ($routeType === 'to_school') ? __('Picked Up') : (($routeType === 'from_school') ? __('Dropped Off') : __('Boarded')); ?></div>
        </div>
        <div class="summary-stat">
            <div class="number" style="color:#FF5722;"><?php echo $absentCount; ?></div>
            <div class="label"><?php echo __('Absent'); ?></div>
        </div>
        <div class="summary-stat">
            <div class="number" style="color:#FFC107;"><?php echo $pendingCount; ?></div>
            <div class="label"><?php echo __('Pending'); ?></div>
        </div>
    </div>

    <?php if (empty($students)): ?>
        <div style="background:#FFF3E0;padding:20px;border-radius:8px;text-align:center;">
            <p>⚠️ <?php echo __('No students assigned to this route yet.'); ?></p>
            <a href="<?php echo $_SESSION[$guid]['absoluteURL']; ?>/index.php?q=/modules/Transport/students_manage_add.php&route=<?php echo $selectedRouteID; ?>" class="btn btn-board">
                <?php echo __('Assign Students'); ?>
            </a>
        </div>
    <?php else: ?>
    
    <!-- Students without assigned stop -->
    <?php if (!empty($studentsNoStop)): ?>
        <div class="stop-section no-stop">
            <div class="stop-header" style="background:#FF9800;">
                <span>⚠️ <?php echo __('No Stop Assigned'); ?></span>
                <span class="badge"><?php echo count($studentsNoStop); ?> <?php echo __('students'); ?></span>
            </div>
            <div class="student-list">
                <?php foreach ($studentsNoStop as $student): 
                    $hasEvent = isset($todayEvents[$student['gibbonPersonID']]);
                    $eventStatus = $hasEvent ? $todayEvents[$student['gibbonPersonID']]['status'] : null;
                ?>
                    <div class="student-item">
                        <div class="student-info">
                            <div class="student-photo">
                                <?php if (!empty($student['image_240'])): ?>
                                    <img src="<?php echo $_SESSION[$guid]['absoluteURL'] . '/' . $student['image_240']; ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['firstName'], 0, 1) . substr($student['surname'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="student-name"><?php echo htmlspecialchars($student['firstName'] . ' ' . $student['surname']); ?></div>
                                <div class="student-id"><?php echo htmlspecialchars($student['studentID']); ?></div>
                                <?php if ($student['specialNeeds']): ?>
                                    <div style="color:#E91E63;font-size:11px;">⚠️ <?php echo htmlspecialchars(substr($student['specialNeeds'], 0, 50)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <?php if ($eventStatus === 'Verified'): ?>
                                <span class="status-badge status-boarded">✓ <?php echo ($routeType === 'to_school') ? __('PICKED UP') : __('BOARDED'); ?></span>
                            <?php elseif ($eventStatus === 'Absent'): ?>
                                <span class="status-badge status-absent">✗ <?php echo __('ABSENT'); ?></span>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="register_boarding">
                                    <input type="hidden" name="routeID" value="<?php echo $selectedRouteID; ?>">
                                    <input type="hidden" name="routeType" value="<?php echo $routeType; ?>">
                                    <input type="hidden" name="studentID" value="<?php echo $student['gibbonPersonID']; ?>">
                                    <button type="submit" class="btn btn-board">✓ <?php echo ($routeType === 'to_school') ? __('Pickup') : __('Board'); ?></button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="mark_absent">
                                    <input type="hidden" name="routeID" value="<?php echo $selectedRouteID; ?>">
                                    <input type="hidden" name="routeType" value="<?php echo $routeType; ?>">
                                    <input type="hidden" name="studentID" value="<?php echo $student['gibbonPersonID']; ?>">
                                    <button type="submit" class="btn btn-absent">✗ <?php echo __('Absent'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
    
    <!-- Students grouped by stop -->
    <?php foreach ($stops as $stop): 
        $stopStudents = $studentsByStop[$stop['gibbonTransportStopID']] ?? [];
        if (empty($stopStudents)) continue;
    ?>
        <div class="stop-section">
            <div class="stop-header">
                <span>
                    📍 <?php echo $stop['sequenceNumber']; ?>. <?php echo htmlspecialchars($stop['name']); ?>
                    <?php if ($stop['estimatedArrivalTime']): ?>
                        <small style="opacity:0.8;margin-left:10px;">⏰ <?php echo date('H:i', strtotime($stop['estimatedArrivalTime'])); ?></small>
                    <?php endif; ?>
                </span>
                <span class="badge"><?php echo count($stopStudents); ?> <?php echo __('students'); ?></span>
            </div>
            <div class="student-list">
                <?php foreach ($stopStudents as $student): 
                    $hasEvent = isset($todayEvents[$student['gibbonPersonID']]);
                    $eventStatus = $hasEvent ? $todayEvents[$student['gibbonPersonID']]['status'] : null;
                ?>
                    <div class="student-item">
                        <div class="student-info">
                            <div class="student-photo">
                                <?php if (!empty($student['image_240'])): ?>
                                    <img src="<?php echo $_SESSION[$guid]['absoluteURL'] . '/' . $student['image_240']; ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                                <?php else: ?>
                                    <?php echo strtoupper(substr($student['firstName'], 0, 1) . substr($student['surname'], 0, 1)); ?>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="student-name"><?php echo htmlspecialchars($student['firstName'] . ' ' . $student['surname']); ?></div>
                                <div class="student-id"><?php echo htmlspecialchars($student['studentID']); ?></div>
                                <?php if ($student['specialNeeds']): ?>
                                    <div style="color:#E91E63;font-size:11px;">⚠️ <?php echo htmlspecialchars(substr($student['specialNeeds'], 0, 50)); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="action-buttons">
                            <?php if ($eventStatus === 'Verified'): ?>
                                <span class="status-badge status-boarded">✓ <?php echo ($routeType === 'to_school') ? __('PICKED UP') : __('BOARDED'); ?></span>
                            <?php elseif ($eventStatus === 'Absent'): ?>
                                <span class="status-badge status-absent">✗ <?php echo __('ABSENT'); ?></span>
                            <?php else: ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="register_boarding">
                                    <input type="hidden" name="routeID" value="<?php echo $selectedRouteID; ?>">
                                    <input type="hidden" name="routeType" value="<?php echo $routeType; ?>">
                                    <input type="hidden" name="studentID" value="<?php echo $student['gibbonPersonID']; ?>">
                                    <input type="hidden" name="stopID" value="<?php echo $stop['gibbonTransportStopID']; ?>">
                                    <button type="submit" class="btn btn-board">✓ <?php echo ($routeType === 'to_school') ? __('Pickup') : __('Board'); ?></button>
                                </form>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="action" value="mark_absent">
                                    <input type="hidden" name="routeID" value="<?php echo $selectedRouteID; ?>">
                                    <input type="hidden" name="routeType" value="<?php echo $routeType; ?>">
                                    <input type="hidden" name="studentID" value="<?php echo $student['gibbonPersonID']; ?>">
                                    <button type="submit" class="btn btn-absent">✗ <?php echo __('Absent'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endforeach; ?>
    
    <?php endif; ?>

<?php else: ?>
    <div style="background:#FFEBEE;padding:20px;border-radius:8px;text-align:center;">
        <p>❌ <?php echo __('Please select a route to begin boarding registration.'); ?></p>
    </div>
<?php endif; ?>

</div>
