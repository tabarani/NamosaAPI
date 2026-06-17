<?php
/*
Gibbon: the flexible, open school platform
*/

$page->title = __('Manage Stops');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Manage Stops'));

require_once __DIR__ . '/lib/TransportSchema.php';
transportEnsureCompatibilitySchema($connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Transport/stops_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Get routes
$stmt = $connection2->query("SELECT gibbonTransportRouteID, name FROM gibbonTransportRoute WHERE active = 1 ORDER BY name");
$routes = ($stmt) ? $stmt->fetchAll() : [];

// Define routeID
$routeID = $_GET['route'] ?? ($routes[0]['gibbonTransportRouteID'] ?? null);

echo '<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0;">';
echo '<h2>📍 ' . __('Transport Stops') . '</h2>';

if ($routeID) {
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage_add.php&route=' . $routeID . '" class="button" style="background:#4CAF50;color:white;text-decoration:none;padding:10px 20px;border-radius:4px;">➕ ' . __('Add New Stop') . '</a>';
}
echo '</div>';

// Route Selector
echo '<div class="grid" style="margin-bottom:20px;">';
echo '<div class="column shadow-area" style="padding:15px;background:#f9f9f9;border-radius:8px;">';
echo '<form method="GET" action="' . $_SESSION[$guid]['absoluteURL'] . '/index.php">';
echo '<input type="hidden" name="q" value="/modules/Transport/stops_manage.php">';
echo '<strong>' . __('Select Route') . ': </strong>';
echo '<select name="route" onchange="this.form.submit()" style="padding:8px;min-width:250px;margin-left:10px;">';

if (empty($routes)) {
    echo '<option value="">' . __('No routes found') . '</option>';
} else {
    foreach ($routes as $r) {
        $selected = ($r['gibbonTransportRouteID'] == $routeID) ? 'selected' : '';
        echo '<option value="' . $r['gibbonTransportRouteID'] . '" ' . $selected . '>' . htmlspecialchars($r['name']) . '</option>';
    }
}
echo '</select>';
echo '</form>';
echo '</div>';
echo '</div>';

if (!$routeID) {
    echo '<div class="warning">' . __('Please create a route first.') . '</div>';
    return;
}

// Fetch stops with student counts
$stmt = $connection2->prepare("
    SELECT 
        s.*,
        COUNT(ts.gibbonTransportStudentID) AS studentCount
    FROM gibbonTransportStop s
    LEFT JOIN gibbonTransportStudent ts ON s.gibbonTransportStopID = ts.gibbonTransportStopID 
        AND ts.status = 'Active'
    WHERE s.gibbonTransportRouteID = :routeID 
    GROUP BY s.gibbonTransportStopID
    ORDER BY s.sequenceNumber ASC
");
$stmt->execute(['routeID' => $routeID]);
$stops = $stmt->fetchAll();

// Show summary stats
$totalStops = count($stops);
$totalStudentsAtStops = array_sum(array_column($stops, 'studentCount'));
echo '<div style="margin-bottom:15px;padding:10px;background:#e3f2fd;border-radius:4px;">';
echo '<strong>' . __('Route Summary:') . '</strong> ';
echo $totalStops . ' ' . __('stops') . ' | ';
echo $totalStudentsAtStops . ' ' . __('students assigned to stops');
echo '</div>';

if (empty($stops)) {
    echo '<div class="information">' . __('No stops defined for this route yet.') . '</div>';
} else {
    echo '<table class="smallIntBorder" style="width:100%;">';
    echo '<tr class="head">';
    echo '<th style="width:8%;">' . __('Order') . '</th>';
    echo '<th style="width:25%;">' . __('Stop Name') . '</th>';
    echo '<th style="width:20%;">' . __('Location') . '</th>';
    echo '<th style="width:12%;">' . __('Pickup Time') . '</th>';
    echo '<th style="width:12%;">' . __('Students') . '</th>';
    echo '<th style="width:10%;">' . __('Status') . '</th>';
    echo '<th style="width:13%;">' . __('Actions') . '</th>';
    echo '</tr>';
    
    foreach ($stops as $stop) {
        $rowClass = $stop['active'] ? '' : 'style="background:#f5f5f5;color:#999;"';
        echo '<tr ' . $rowClass . '>';
        echo '<td style="text-align:center;"><strong>' . $stop['sequenceNumber'] . '</strong></td>';
        echo '<td><strong>' . htmlspecialchars($stop['name']) . '</strong>';
        if (!empty($stop['landmark'])) {
            echo '<br><small style="color:#666;">' . htmlspecialchars($stop['landmark']) . '</small>';
        }
        echo '</td>';
        echo '<td>';
        if (!empty($stop['address'])) {
            echo htmlspecialchars(substr($stop['address'], 0, 50)) . (strlen($stop['address']) > 50 ? '...' : '');
        } else {
            echo '<span style="color:#999;">' . __('No address') . '</span>';
        }
        if (!empty($stop['latitude']) && !empty($stop['longitude'])) {
            echo '<br><small style="color:#2196F3;">📍 ' . number_format($stop['latitude'], 5) . ', ' . number_format($stop['longitude'], 5) . '</small>';
        }
        echo '</td>';
        echo '<td style="text-align:center;">';
        if (!empty($stop['estimatedArrivalTime'])) {
            echo '<strong>' . date('H:i', strtotime($stop['estimatedArrivalTime'])) . '</strong>';
        } else {
            echo '-';
        }
        echo '</td>';
        
        // Student count with color coding
        $studentCount = (int)$stop['studentCount'];
        $countColor = $studentCount > 0 ? '#4CAF50' : '#999';
        echo '<td style="text-align:center;">';
        echo '<span style="background:' . $countColor . ';color:white;padding:3px 10px;border-radius:12px;font-weight:bold;">' . $studentCount . '</span>';
        echo '</td>';
        
        echo '<td style="text-align:center;">';
        if ($stop['active']) {
            echo '<span style="color:#4CAF50;">✓ ' . __('Active') . '</span>';
        } else {
            echo '<span style="color:#999;">' . __('Inactive') . '</span>';
        }
        echo '</td>';
        echo '<td style="text-align:center;">';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage_edit.php&id=' . $stop['gibbonTransportStopID'] . '" style="color:#2196F3;margin-right:8px;text-decoration:none;" title="' . __('Edit') . '">✏️</a>';
        
        // Only show delete if no students assigned
        if ($studentCount == 0) {
            echo '<a href="#" onclick="confirmDelete(' . $stop['gibbonTransportStopID'] . ')" style="color:#f44336;text-decoration:none;" title="' . __('Delete') . '">🗑️</a>';
        } else {
            echo '<span style="color:#ccc;cursor:not-allowed;" title="' . __('Cannot delete: students assigned') . '">🗑️</span>';
        }
        echo '</td>';
        echo '</tr>';
    }
    echo '</table>';
}

// Legend
echo '<div style="margin-top:20px;padding:10px;background:#fff3cd;border-radius:4px;font-size:12px;">';
echo '<strong>' . __('Note:') . '</strong> ' . __('Stops with assigned students cannot be deleted. Reassign students first.');
echo '</div>';
?>

<script>
function confirmDelete(stopID) {
    if (confirm('<?php echo __("Are you sure you want to delete this stop?"); ?>')) {
        window.location.href = '<?php echo $_SESSION[$guid]['absoluteURL']; ?>/index.php?q=/modules/Transport/stops_manage.php&action=delete&id=' + stopID + '&route=<?php echo $routeID; ?>';
    }
}
</script>
