<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Student Assignments');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Student Assignments'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/students_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Handle student removal
if (isset($_GET['remove'])) {
    $studentAssignmentID = $_GET['remove'];
    
    $stmt = $connection2->prepare("
        SELECT s.firstName, s.surname, r.name as routeName
        FROM gibbonTransportStudent st
        INNER JOIN gibbonPerson s ON st.gibbonPersonID = s.gibbonPersonID
        INNER JOIN gibbonTransportRoute r ON st.gibbonTransportRouteID = r.gibbonTransportRouteID
        WHERE st.gibbonTransportStudentID = :id
    ");
    $stmt->execute(['id' => $studentAssignmentID]);
    $student = $stmt->fetch();
    
    if ($student) {
        if (isset($_GET['confirm'])) {
            try {
                $stmtDel = $connection2->prepare("DELETE FROM gibbonTransportStudent WHERE gibbonTransportStudentID = :id");
                $stmtDel->execute(['id' => $studentAssignmentID]);
                
                $page->addSuccess(__('Student removed from route successfully'));
                header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage.php&route=' . ($_GET['route'] ?? ''));
                exit;
            } catch (Exception $e) {
                $page->addError('Failed to remove student: ' . $e->getMessage());
            }
        }
    }
}

// Get routes
$routes = $connection2->query("SELECT gibbonTransportRouteID, name FROM gibbonTransportRoute WHERE active = 1 ORDER BY name")->fetchAll();
$routeID = $_GET['route'] ?? ($routes[0]['gibbonTransportRouteID'] ?? null);

echo '<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0;">';
echo '<h2>👥 ' . __('Student Transport Assignments') . '</h2>';

if ($routeID) {
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage_add.php&route=' . $routeID . '" class="button" style="background:#4CAF50;color:white;text-decoration:none;padding:10px 20px;border-radius:4px;">+ ' . __('Assign Student') . '</a>';
}

echo '</div>';

// Route selector
echo '<div style="margin:20px 0;">';
echo '<form method="GET" action="' . $_SESSION[$guid]['absoluteURL'] . '/index.php" style="display:inline;">';
echo '<input type="hidden" name="q" value="/modules/Transport/students_manage.php">';
echo '<label><strong>' . __('Route') . ':</strong></label>';
echo '<select name="route" onchange="this.form.submit()" style="padding:8px;margin-left:10px;">';
foreach ($routes as $route) {
    $selected = ($route['gibbonTransportRouteID'] == $routeID) ? 'selected' : '';
    echo '<option value="' . $route['gibbonTransportRouteID'] . '" ' . $selected . '>' . htmlspecialchars($route['name']) . '</option>';
}
echo '</select>';
echo '</form>';
echo '</div>';

if (!$routeID) {
    echo '<div class="message">' . __('No active routes found.') . '</div>';
    return;
}

// FIXED: Using gibbonSchoolYearID instead of gibbonAcademicYearID

$stmt = $connection2->prepare("
    SELECT ts.*, p.firstName, p.surname, p.studentID, y.nameShort as yearGroup
    FROM gibbonTransportStudent ts
    INNER JOIN gibbonPerson p ON ts.gibbonPersonID = p.gibbonPersonID
    LEFT JOIN gibbonStudentEnrolment e ON p.gibbonPersonID = e.gibbonPersonID 
        AND e.gibbonSchoolYearID = :schoolYearID
    LEFT JOIN gibbonYearGroup y ON e.gibbonYearGroupID = y.gibbonYearGroupID
    WHERE ts.gibbonTransportRouteID = :routeID
    AND p.status = 'Full'
    ORDER BY p.surname, p.firstName
");

// Use gibbonSchoolYearID which is the standard session key in most Gibbon versions
$schoolYearID = $_SESSION[$guid]['gibbonSchoolYearID'] ?? null;

$stmt->execute([
    'routeID' => $routeID,
    'schoolYearID' => $schoolYearID
]);
$students = $stmt->fetchAll();

echo '<h3 style="margin:30px 0 15px 0;">' . __('Assigned Students') . ' (' . count($students) . ')</h3>';

if ($students) {
    echo '<table class="smallIntBorder" style="width:100%;">';
    echo '<tr class="head"><th>' . __('Student ID') . '</th><th>' . __('Name') . '</th><th>' . __('Year Group') . '</th><th>' . __('Status') . '</th><th>' . __('Assigned') . '</th><th>' . __('Actions') . '</th></tr>';
    
    foreach ($students as $student) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($student['studentID']) . '</td>';
        echo '<td><strong>' . htmlspecialchars($student['firstName'] . ' ' . $student['surname']) . '</strong></td>';
        echo '<td>' . htmlspecialchars($student['yearGroup'] ?? '-') . '</td>';
        echo '<td>' . ($student['status'] === 'Active' ? '<span style="color:#4CAF50;font-weight:bold;">✓ ' . __('Active') . '</span>' : __($student['status'])) . '</td>';
        echo '<td>' . date('M j, Y', strtotime($student['timestampCreated'])) . '</td>';
        echo '<td><a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage.php&remove=' . $student['gibbonTransportStudentID'] . '&confirm=1&route=' . $routeID . '" style="color:#f44336;text-decoration:none;" onclick="return confirm(\'' . __('Are you sure you want to remove this student from the route?') . '\');">🗑️ ' . __('Remove') . '</a></td>';
        echo '</tr>';
    }
    
    echo '</table>';
} else {
    echo '<div class="message">' . __('No students assigned to this route yet.') . '</div>';
}