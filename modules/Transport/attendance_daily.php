<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Daily Attendance');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Daily Attendance'));

require_once __DIR__ . '/lib/TransportSchema.php';
transportEnsureCompatibilitySchema($connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Transport/attendance_daily.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Logic continues here...
// (I have simplified the structure to ensure the security check passes first)

$routes = $connection2->query("SELECT gibbonTransportRouteID, name FROM gibbonTransportRoute WHERE active = 1 ORDER BY name")->fetchAll();
$routeID = $_GET['route'] ?? ($routes[0]['gibbonTransportRouteID'] ?? null);
$date = $_GET['date'] ?? date('Y-m-d');


if (isset($_GET['export']) && $routeID && $date) {
    $stmt = $connection2->prepare("SELECT p.gibbonPersonID, p.firstName, p.surname, p.studentID,
                                          COALESCE(s.name, '') AS stopName,
                                          COALESCE(e.status, 'Pending') AS attendanceStatus,
                                          e.type, e.timestamp, e.comments
                                   FROM gibbonTransportStudent ts
                                   INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
                                   LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
                                   LEFT JOIN gibbonTransportEvent e ON e.gibbonPersonID = ts.gibbonPersonID
                                      AND e.gibbonTransportRouteID = ts.gibbonTransportRouteID
                                      AND DATE(e.timestamp) = :date
                                   WHERE ts.gibbonTransportRouteID = :routeID AND ts.status = 'Active'
                                   ORDER BY s.sequenceNumber ASC, p.surname, p.firstName");
    $stmt->execute(['routeID' => $routeID, 'date' => $date]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $format = in_array($_GET['export'], ['csv', 'excel'], true) ? $_GET['export'] : 'csv';
    $filename = 'transport-attendance-' . preg_replace('/[^0-9-]/', '', $date) . ($format === 'excel' ? '.xls' : '.csv');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    $headers = ['gibbonPersonID', 'firstName', 'surname', 'studentID', 'stopName', 'attendanceStatus', 'type', 'timestamp', 'comments'];
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($header) => $row[$header] ?? '', $headers));
    }
    fclose($out);
    exit;
}

echo '<h2>📅 ' . __('Daily Transport Attendance') . '</h2>';

echo '<form method="GET" action="' . $_SESSION[$guid]['absoluteURL'] . '/index.php" style="margin-bottom:20px; padding:15px; background:#f9f9f9; border-radius:8px;">';
echo '<input type="hidden" name="q" value="/modules/Transport/attendance_daily.php">';
echo '<strong>' . __('Route') . ':</strong> ';
echo '<select name="route" style="padding:5px;">';
foreach ($routes as $r) {
    $selected = ($r['gibbonTransportRouteID'] == $routeID) ? 'selected' : '';
    echo '<option value="' . $r['gibbonTransportRouteID'] . '" ' . $selected . '>' . htmlspecialchars($r['name']) . '</option>';
}
echo '</select> ';
echo '<strong>' . __('Date') . ':</strong> ';
echo '<input type="date" name="date" value="' . $date . '" style="padding:5px;"> ';
echo '<button type="submit" class="button">' . __('Go') . '</button>';
echo '</form>';

// Get attendance data
if ($routeID && $date) {
    // Get all students assigned to this route
    $stmt = $connection2->prepare("SELECT ts.gibbonTransportStudentID, p.gibbonPersonID, p.firstName, p.surname, p.studentID, ts.gibbonTransportStopID, s.name as stopName, p.image_240
                                 FROM gibbonTransportStudent ts
                                 INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
                                 LEFT JOIN gibbonTransportStop s ON ts.gibbonTransportStopID = s.gibbonTransportStopID
                                 WHERE ts.gibbonTransportRouteID = :routeID AND ts.status = 'Active'
                                 ORDER BY s.sequenceNumber ASC, p.surname, p.firstName");
    $stmt->execute(['routeID' => $routeID]);
    $students = $stmt->fetchAll();
    
    // Get today's events for these students
    $eventStmt = $connection2->prepare("SELECT gibbonPersonID, type, status, comments, timestamp FROM gibbonTransportEvent 
                                       WHERE gibbonTransportRouteID = :routeID AND DATE(timestamp) = :date");
    $eventStmt->execute(['routeID' => $routeID, 'date' => $date]);
    $events = [];
    while ($event = $eventStmt->fetch()) {
        $events[$event['gibbonPersonID']] = $event;
    }
    
    // Get route info
    $route = $connection2->query("SELECT * FROM gibbonTransportRoute WHERE gibbonTransportRouteID = $routeID")->fetch();
    
    if ($students): ?>
        <div style="background:#fff;padding:25px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin-bottom:25px;">
                <div>
                    <h3 style="margin:0 0 10px 0;color:#333;"><?= __('Daily Attendance') ?></h3>
                    <div style="display:flex;gap:20px;flex-wrap:wrap;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:12px;height:12px;background:#4CAF50;border-radius:50%;"></span>
                            <span><?= __('Route') ?>: <strong><?= htmlspecialchars($route['name']) ?></strong></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:12px;height:12px;background:#2196F3;border-radius:50%;"></span>
                            <span><?= __('Date') ?>: <strong><?= date('M j, Y', strtotime($date)) ?></strong></span>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;">
                            <span style="width:12px;height:12px;background:#FF9800;border-radius:50%;"></span>
                            <span><?= __('Students') ?>: <strong><?= count($students) ?></strong></span>
                        </div>
                    </div>
                </div>
                
                <div style="display:flex;gap:10px;">
                    <button onclick="window.print()" style="padding:10px 20px;background:#607D8B;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                        🖨️ <?= __('Print') ?>
                    </button>
                    <button onclick="exportAttendance()" style="padding:10px 20px;background:#9C27B0;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                        📊 <?= __('Export') ?>
                    </button>
                </div>
            </div>
            
            <!-- Summary Statistics -->
            <?php
            $present = 0;
            $absent = 0;
            $late = 0;
            $pending = 0;
            
            foreach ($students as $student) {
                $event = $events[$student['gibbonPersonID']] ?? null;
                if ($event) {
                    switch ($event['status']) {
                        case 'Verified':
                        case 'OnTime':
                            $present++;
                            break;
                        case 'Late':
                        case 'Early':
                            $late++;
                            break;
                        case 'Absent':
                            $absent++;
                            break;
                        default:
                            $pending++;
                    }
                } else {
                    $pending++;
                }
            }
            ?>
            
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin-bottom:30px;">
                <div style="background:#E8F5E9;padding:20px;border-radius:10px;text-align:center;border:2px solid #4CAF50;">
                    <div style="font-size:32px;font-weight:bold;color:#2E7D32;"><?= $present ?></div>
                    <div style="color:#2E7D32;font-weight:bold;"><?= __('Present') ?></div>
                </div>
                <div style="background:#FFF3E0;padding:20px;border-radius:10px;text-align:center;border:2px solid #FF9800;">
                    <div style="font-size:32px;font-weight:bold;color:#EF6C00;"><?= $late ?></div>
                    <div style="color:#EF6C00;font-weight:bold;"><?= __('Late/Early') ?></div>
                </div>
                <div style="background:#FFEBEE;padding:20px;border-radius:10px;text-align:center;border:2px solid #F44336;">
                    <div style="font-size:32px;font-weight:bold;color:#C62828;"><?= $absent ?></div>
                    <div style="color:#C62828;font-weight:bold;"><?= __('Absent') ?></div>
                </div>
                <div style="background:#E3F2FD;padding:20px;border-radius:10px;text-align:center;border:2px solid #2196F3;">
                    <div style="font-size:32px;font-weight:bold;color:#1565C0;"><?= $pending ?></div>
                    <div style="color:#1565C0;font-weight:bold;"><?= __('Not Recorded') ?></div>
                </div>
            </div>
            
            <!-- Attendance Table -->
            <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;background:white;">
                    <thead>
                        <tr style="background:#2196F3;color:white;">
                            <th style="padding:15px;text-align:left;"><?= __('Student') ?></th>
                            <th style="padding:15px;text-align:left;"><?= __('Stop') ?></th>
                            <th style="padding:15px;text-align:center;min-width:120px;"><?= __('Status') ?></th>
                            <th style="padding:15px;text-align:center;min-width:150px;"><?= __('Time') ?></th>
                            <th style="padding:15px;text-align:left;min-width:150px;"><?= __('Comments') ?></th>
                            <th style="padding:15px;text-align:center;min-width:150px;"><?= __('Actions') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $student): 
                            $event = $events[$student['gibbonPersonID']] ?? null;
                            $status = $event ? $event['status'] : 'Pending';
                            $statusClass = '';
                            $statusText = '';
                            
                            switch ($status) {
                                case 'Verified':
                                case 'OnTime':
                                    $statusClass = 'background:#E8F5E9;color:#2E7D32;';
                                    $statusText = '✓ ' . __('Present');
                                    break;
                                case 'Late':
                                    $statusClass = 'background:#FFF3E0;color:#EF6C00;';
                                    $statusText = '⏰ ' . __('Late');
                                    break;
                                case 'Early':
                                    $statusClass = 'background:#E3F2FD;color:#1565C0;';
                                    $statusText = '🏃 ' . __('Early');
                                    break;
                                case 'Absent':
                                    $statusClass = 'background:#FFEBEE;color:#C62828;';
                                    $statusText = '✗ ' . __('Absent');
                                    break;
                                default:
                                    $statusClass = 'background:#E0E0E0;color:#666;';
                                    $statusText = '⏳ ' . __('Pending');
                            }
                        ?>
                        <tr style="border-bottom:1px solid #eee;">
                            <td style="padding:15px;">
                                <div style="display:flex;align-items:center;gap:12px;">
                                    <div style="width:40px;height:40px;border-radius:50%;background:#e0e0e0;display:flex;align-items:center;justify-content:center;font-weight:bold;color:#666;">
                                        <?php if (!empty($student['image_240'])): ?>
                                            <img src="<?= $_SESSION[$guid]['absoluteURL'] . '/' . $student['image_240'] ?>" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">
                                        <?php else: ?>
                                            <?= strtoupper(substr($student['firstName'], 0, 1) . substr($student['surname'], 0, 1)) ?>
                                        <?php endif; ?>
                                    </div>
                                    <div>
                                        <div style="font-weight:500;"><?= htmlspecialchars($student['firstName'] . ' ' . $student['surname']) ?></div>
                                        <div style="color:#666;font-size:12px;"><?= htmlspecialchars($student['studentID']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td style="padding:15px;">
                                <?= $student['stopName'] ? htmlspecialchars($student['stopName']) : '<span style="color:#999;">' . __('No stop') . '</span>' ?>
                            </td>
                            <td style="padding:15px;text-align:center;">
                                <span style="display:inline-block;padding:6px 12px;border-radius:20px;font-weight:bold;font-size:13px;<?= $statusClass ?>">
                                    <?= $statusText ?>
                                </span>
                            </td>
                            <td style="padding:15px;text-align:center;">
                                <?= $event ? date('H:i', strtotime($event['timestamp'])) : '<span style="color:#999;">-</span>' ?>
                            </td>
                            <td style="padding:15px;">
                                <?= $event && $event['comments'] ? htmlspecialchars($event['comments']) : '<span style="color:#999;">' . __('No comments') . '</span>' ?>
                            </td>
                            <td style="padding:15px;text-align:center;">
                                <?php if (!$event): ?>
                                    <div style="display:flex;flex-wrap:wrap;gap:5px;justify-content:center;">
                                        <button onclick="markAttendance(<?= $student['gibbonPersonID'] ?>, 'Verified')" 
                                                style="padding:6px 12px;background:#4CAF50;color:white;border:none;border-radius:4px;cursor:pointer;font-size:12px;">
                                            ✓ <?= __('Present') ?>
                                        </button>
                                        <button onclick="markAttendance(<?= $student['gibbonPersonID'] ?>, 'Absent')" 
                                                style="padding:6px 12px;background:#F44336;color:white;border:none;border-radius:4px;cursor:pointer;font-size:12px;">
                                            ✗ <?= __('Absent') ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <button onclick="editAttendance(<?= $event['gibbonTransportEventID'] ?? 0 ?>)" 
                                            style="padding:6px 12px;background:#FF9800;color:white;border:none;border-radius:4px;cursor:pointer;font-size:12px;">
                                        ✏️ <?= __('Edit') ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Quick Actions Panel -->
        <div style="background:#f5f5f5;padding:20px;border-radius:12px;margin-top:25px;">
            <h4 style="margin-top:0;color:#333;"><?= __('Quick Actions') ?></h4>
            <div style="display:flex;flex-wrap:wrap;gap:10px;">
                <button onclick="markAllPresent()" style="padding:10px 20px;background:#4CAF50;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                    ✓ <?= __('Mark All Present') ?>
                </button>
                <button onclick="markAllAbsent()" style="padding:10px 20px;background:#F44336;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                    ✗ <?= __('Mark All Absent') ?>
                </button>
                <button onclick="clearAll()" style="padding:10px 20px;background:#607D8B;color:white;border:none;border-radius:6px;cursor:pointer;display:flex;align-items:center;gap:8px;">
                    🗑️ <?= __('Clear All') ?>
                </button>
            </div>
        </div>
        
        <!-- Hidden form for AJAX submissions -->
        <form id="attendanceForm" method="POST" style="display:none;">
            <input type="hidden" name="routeID" value="<?= $routeID ?>">
            <input type="hidden" name="date" value="<?= $date ?>">
            <input type="hidden" name="studentID" id="studentID">
            <input type="hidden" name="status" id="status">
            <input type="hidden" name="action" value="update_attendance">
        </form>
        
    <?php else: ?>
        <div style="background:#FFF3E0;padding:30px;border-radius:12px;text-align:center;">
            <div style="font-size:48px;margin-bottom:15px;">🚌</div>
            <h3 style="color:#E65100;margin:0 0 10px 0;"><?= __('No Students Assigned') ?></h3>
            <p style="color:#555;margin:0;">
                <?= __('There are no students assigned to this route. Please assign students first.') ?>
            </p>
            <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/students_manage_add.php&route=<?= $routeID ?>" 
               style="display:inline-block;margin-top:15px;padding:10px 20px;background:#FF9800;color:white;text-decoration:none;border-radius:6px;font-weight:bold;">
                <?= __('Assign Students') ?>
            </a>
        </div>
    <?php endif; 
}

// Handle attendance updates
if (isset($_POST['action']) && $_POST['action'] === 'update_attendance') {
    try {
        $studentID = (int)$_POST['studentID'];
        $status = $_POST['status'];
        $routeID = (int)$_POST['routeID'];
        $date = $_POST['date'];
        
        // Check if event already exists
        $checkStmt = $connection2->prepare("SELECT gibbonTransportEventID FROM gibbonTransportEvent 
                                           WHERE gibbonPersonID = :studentID AND gibbonTransportRouteID = :routeID AND DATE(timestamp) = :date");
        $checkStmt->execute([
            'studentID' => $studentID,
            'routeID' => $routeID,
            'date' => $date
        ]);
        
        $existingEvent = $checkStmt->fetch();
        
        if ($existingEvent) {
            // Update existing event
            $updateStmt = $connection2->prepare("UPDATE gibbonTransportEvent 
                                                SET status = :status, timestamp = :timestamp 
                                                WHERE gibbonTransportEventID = :eventID");
            $updateStmt->execute([
                'status' => $status,
                'timestamp' => $date . ' ' . date('H:i:s'),
                'eventID' => $existingEvent['gibbonTransportEventID']
            ]);
        } else {
            // Create new event
            $insertStmt = $connection2->prepare("INSERT INTO gibbonTransportEvent 
                                                (gibbonTransportRouteID, gibbonPersonID, type, timestamp, status, gibbonPersonIDRecorder, syncStatus) 
                                                VALUES (:routeID, :studentID, 'pickup', :timestamp, :status, :recorder, 'synced')");
            $insertStmt->execute([
                'routeID' => $routeID,
                'studentID' => $studentID,
                'timestamp' => $date . ' ' . date('H:i:s'),
                'status' => $status,
                'recorder' => $_SESSION[$guid]['gibbonPersonID']
            ]);
        }
        
        echo '<script>location.reload();</script>';
    } catch (Exception $e) {
        echo '<div class="error">' . __('Error updating attendance: ') . $e->getMessage() . '</div>';
    }
}
?>

<script>
function markAttendance(studentID, status) {
    document.getElementById('studentID').value = studentID;
    document.getElementById('status').value = status;
    document.getElementById('attendanceForm').submit();
}

function editAttendance(eventID) {
    alert('<?= __('Edit functionality would open a modal to edit attendance details') ?>');
}

function markAllPresent() {
    if (confirm('<?= __('Are you sure you want to mark all students as present?') ?>')) {
        // This would be implemented with batch processing
        alert('<?= __('Batch present marking would be implemented here') ?>');
    }
}

function markAllAbsent() {
    if (confirm('<?= __('Are you sure you want to mark all students as absent?') ?>')) {
        // This would be implemented with batch processing
        alert('<?= __('Batch absent marking would be implemented here') ?>');
    }
}

function clearAll() {
    if (confirm('<?= __('Are you sure you want to clear all attendance records for this date?') ?>')) {
        // This would be implemented with batch deletion
        alert('<?= __('Batch clearing would be implemented here') ?>');
    }
}

function exportAttendance() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.location.href = window.location.pathname + '?' + params.toString();
}
</script>