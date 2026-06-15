<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Assign Students');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Student Assignments'), 'students_manage.php');
$page->breadcrumbs->add(__('Assign Students'));

// Load Select2 Assets
echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
echo '<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>';

if (!isActionAccessible($guid, $connection2, '/modules/Transport/students_manage_add.php')) {
    $page->addError(__('Access denied'));
    return;
}

$routeID = $_GET['route'] ?? $_POST['routeID'] ?? null;
if (!$routeID) {
    $page->addError(__('Route ID required'));
    return;
}

// --- PROCESS MULTIPLE SUBMISSIONS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $studentIDs = $_POST['studentIDs'] ?? [];
        $stopID = $_POST['gibbonTransportStopID'] ?? null;
        
        if (empty($studentIDs)) {
            throw new Exception('Please select at least one student.');
        }
        
        // Validate stop belongs to this route
        if (!empty($stopID)) {
            $stmtValidateStop = $connection2->prepare("
                SELECT gibbonTransportStopID FROM gibbonTransportStop 
                WHERE gibbonTransportStopID = :stopID AND gibbonTransportRouteID = :routeID AND active = 1
            ");
            $stmtValidateStop->execute(['stopID' => $stopID, 'routeID' => $routeID]);
            if (!$stmtValidateStop->fetch()) {
                throw new Exception('Selected stop does not belong to this route or is inactive.');
            }
        }

        $count = 0;
        $sql = "INSERT INTO gibbonTransportStudent (gibbonPersonID, gibbonTransportRouteID, gibbonTransportStopID, status, startDate, specialNeeds, comments) 
                VALUES (:studentID, :routeID, :stopID, :status, :startDate, :needs, :comm)";
        $stmtInsert = $connection2->prepare($sql);

        foreach ($studentIDs as $studentID) {
            // Check for duplicates before each insert
            $stmtCheck = $connection2->prepare("SELECT gibbonTransportStudentID FROM gibbonTransportStudent WHERE gibbonPersonID = :studentID AND gibbonTransportRouteID = :routeID");
            $stmtCheck->execute(['studentID' => $studentID, 'routeID' => $routeID]);
            
            if (!$stmtCheck->fetch()) {
                $stmtInsert->execute([
                    'studentID' => $studentID,
                    'routeID'   => $routeID,
                    'stopID'    => !empty($stopID) ? $stopID : null,
                    'status'    => $_POST['status'] ?? 'Active',
                    'startDate' => $_POST['startDate'] ?? date('Y-m-d'),
                    'needs'     => $_POST['specialNeeds'] ?? null,
                    'comm'      => $_POST['comments'] ?? null
                ]);
                $count++;
            }
        }
        
        $page->addSuccess(sprintf(__('%s students assigned successfully'), $count));
        header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage.php&route=' . $routeID);
        exit;
        
    } catch (Exception $e) {
        $page->addError('Failed to assign students: ' . $e->getMessage());
    }
}

// Get Route Info
$stmtRoute = $connection2->prepare("SELECT name FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :id");
$stmtRoute->execute(['id' => $routeID]);
$route = $stmtRoute->fetch();

// Get Stops for this Route
$stmtStops = $connection2->prepare("
    SELECT gibbonTransportStopID, name, sequenceNumber, estimatedArrivalTime 
    FROM gibbonTransportStop 
    WHERE gibbonTransportRouteID = :routeID AND active = 1 
    ORDER BY sequenceNumber ASC
");
$stmtStops->execute(['routeID' => $routeID]);
$stops = $stmtStops->fetchAll();

// Get Unassigned Students
$schoolYearID = $_SESSION[$guid]['gibbonSchoolYearID'] ?? null;
$stmtUnassigned = $connection2->prepare("
    SELECT p.gibbonPersonID, p.firstName, p.surname, p.studentID, y.nameShort as yearGroup
    FROM gibbonPerson p
    LEFT JOIN gibbonStudentEnrolment e ON p.gibbonPersonID = e.gibbonPersonID AND e.gibbonSchoolYearID = :schoolYearID
    LEFT JOIN gibbonYearGroup y ON e.gibbonYearGroupID = y.gibbonYearGroupID
    WHERE p.status = 'Full'
    AND p.gibbonRoleIDPrimary IN (SELECT gibbonRoleID FROM gibbonRole WHERE category = 'Student')
    AND p.gibbonPersonID NOT IN (SELECT gibbonPersonID FROM gibbonTransportStudent WHERE gibbonTransportRouteID = :routeID)
    ORDER BY p.surname, p.firstName
");
$stmtUnassigned->execute(['schoolYearID' => $schoolYearID, 'routeID' => $routeID]);
$unassignedStudents = $stmtUnassigned->fetchAll();

echo '<h2>👥 ' . __('Bulk Assign Students to: ') . htmlspecialchars($route['name']) . '</h2>';

echo '<form method="POST" action="" id="assignForm">';
echo '<input type="hidden" name="routeID" value="' . $routeID . '">';
echo '<table class="smallIntBorder" style="width:100%;max-width:850px;">';

// MULTI-SELECT DROPDOWN FOR STUDENTS
echo '<tr><td style="width:30%;"><strong>' . __('Select Students *') . '</strong><br><small>' . __('Search and select multiple students') . '</small></td><td>';
echo '<select name="studentIDs[]" id="studentSearch" multiple="multiple" required style="width:100%;">';
foreach ($unassignedStudents as $student) {
    $label = $student['surname'] . ', ' . $student['firstName'] . ' (' . $student['studentID'] . ')';
    if (!empty($student['yearGroup'])) $label .= " - " . $student['yearGroup'];
    echo '<option value="' . $student['gibbonPersonID'] . '">' . htmlspecialchars($label) . '</option>';
}
echo '</select></td></tr>';

// ===== STOP SELECTION =====
echo '<tr><td colspan="2" style="background:#e3f2fd;padding:10px;"><strong>📍 ' . __('Pickup Stop Assignment') . '</strong></td></tr>';
echo '<tr><td><strong>' . __('Select Stop') . '</strong><br><small>' . __('Assign students to a pickup/dropoff stop') . '</small></td><td>';
echo '<select name="gibbonTransportStopID" id="stopSelect" style="width:100%;padding:8px;">';
echo '<option value="">' . __('-- Select a stop --') . '</option>';
if (empty($stops)) {
    echo '<option value="" disabled>' . __('No stops defined for this route') . '</option>';
} else {
    foreach ($stops as $stop) {
        $stopLabel = $stop['sequenceNumber'] . '. ' . $stop['name'];
        if (!empty($stop['estimatedArrivalTime'])) {
            $stopLabel .= ' (' . date('H:i', strtotime($stop['estimatedArrivalTime'])) . ')';
        }
        echo '<option value="' . $stop['gibbonTransportStopID'] . '">' . htmlspecialchars($stopLabel) . '</option>';
    }
}
echo '</select>';
if (empty($stops)) {
    echo '<br><small style="color:#ff9800;">⚠️ ' . __('Please add stops to this route first.') . ' <a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage_add.php&route=' . $routeID . '">' . __('Add Stop') . '</a></small>';
}
echo '</td></tr>';
// ===== END STOP SELECTION =====

echo '<tr><td><strong>' . __('Common Start Date') . '</strong></td><td><input type="date" name="startDate" value="' . date('Y-m-d') . '" style="width:100%;padding:8px;"></td></tr>';
echo '<tr><td><strong>' . __('Status') . '</strong></td><td><label><input type="checkbox" name="status" value="Active" checked> ' . __('Active') . '</label></td></tr>';
echo '<tr><td><strong>' . __('Special Needs') . '</strong><br><small>' . __('Optional notes about special requirements') . '</small></td><td><textarea name="specialNeeds" style="width:100%;padding:8px;height:60px;"></textarea></td></tr>';
echo '<tr><td><strong>' . __('Comments') . '</strong></td><td><textarea name="comments" style="width:100%;padding:8px;height:60px;"></textarea></td></tr>';
echo '<tr><td colspan="2" style="text-align:center;padding:20px;"><button type="submit" class="button" style="background:#4CAF50;color:white;padding:12px 30px;font-size:16px;">✅ ' . __('Assign All Selected') . '</button> <a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage.php&route=' . $routeID . '" style="background:#999;color:white;padding:12px 30px;margin-left:10px;text-decoration:none;border-radius:4px;">' . __('Cancel') . '</a></td></tr>';
echo '</table>';
echo '</form>';

?>
<script>
$(document).ready(function() {
    // Initialize Select2 for student multi-select
    $('#studentSearch').select2({
        placeholder: "<?php echo __('Type student names here...'); ?>",
        allowClear: true,
        closeOnSelect: false,
        width: '100%'
    });
    
    // Initialize Select2 for stop dropdown
    $('#stopSelect').select2({
        placeholder: "<?php echo __('Select a pickup stop...'); ?>",
        allowClear: true,
        width: '100%'
    });
});
</script>

<style>
/* Make the multi-select tags look better */
.select2-container--default .select2-selection--multiple {
    border: 1px solid #ccc;
    min-height: 45px;
    padding: 5px;
}
.select2-container--default .select2-selection--multiple .select2-selection__choice {
    background-color: #e4e4e4;
    border: 1px solid #aaa;
    border-radius: 4px;
    padding: 2px 8px;
    margin-top: 5px;
}
.select2-container--default .select2-selection--single {
    height: 38px;
    padding: 5px;
    border: 1px solid #ccc;
}
</style>
