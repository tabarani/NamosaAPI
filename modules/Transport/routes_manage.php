<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)
*/

$page->title = __('Manage Routes');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Manage Routes'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/routes_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Handle delete confirmation
if (isset($_GET['delete'])) {
    $routeID = $_GET['delete'];
    
    // Get route info for confirmation message
    $route = $connection2->query("SELECT name FROM gibbonTransportRoute WHERE gibbonTransportRouteID = :id", ['id' => $routeID])->fetch();
    
    if ($route) {
        if (isset($_GET['confirm'])) {
            try {
                // Delete all related records (cascading should handle this but let's be explicit)
                $connection2->delete('gibbonTransportEvent', ['gibbonTransportRouteID' => $routeID]);
                $connection2->delete('gibbonTransportStop', ['gibbonTransportRouteID' => $routeID]);
                $connection2->delete('gibbonTransportStudent', ['gibbonTransportRouteID' => $routeID]);
                
                // Finally delete the route
                $connection2->delete('gibbonTransportRoute', ['gibbonTransportRouteID' => $routeID]);
                
                $page->addSuccess(sprintf(__('Route "%s" deleted successfully with all associated stops and student assignments.'), htmlspecialchars($route['name'])));
                // Redirect to remove delete parameter
                header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php');
                exit;
            } catch (Exception $e) {
                $page->addError('Failed to delete route: ' . $e->getMessage());
            }
        } else {
            echo '<div class="warning" style="padding:20px;margin:20px 0;">';
            echo '<h3>⚠️ ' . __('Confirm Delete') . '</h3>';
            echo '<p>' . sprintf(__('Are you sure you want to delete the route "%s"? This will also delete all associated stops and student assignments.'), htmlspecialchars($route['name'])) . '</p>';
            echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php&delete=' . $routeID . '&confirm=1" class="button" style="background:#f44336;color:white;margin-right:10px;">' . __('Yes, Delete') . '</a>';
            echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php" class="button">' . __('Cancel') . '</a>';
            echo '</div>';
        }
    } else {
        echo '<div class="error">' . __('Route not found.') . '</div>';
    }
}

// List routes
$stmt = $connection2->query("
    SELECT r.*, d.firstName as driverFirstName, d.surname as driverSurname
    FROM gibbonTransportRoute r
    LEFT JOIN gibbonPerson d ON r.driverID = d.gibbonPersonID
    ORDER BY r.name
");
$routes = $stmt->fetchAll();

echo '<div style="display:flex;justify-content:space-between;align-items:center;margin:20px 0;">';
echo '<h2>🚌 ' . __('Transport Routes') . '</h2>';
echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage_add.php" class="button" style="background:#4CAF50;color:white;text-decoration:none;padding:10px 20px;border-radius:4px;">+ ' . __('Add Route') . '</a>';
echo '</div>';

if ($routes) {
    echo '<table class="smallIntBorder" style="width:100%;">';
    echo '<tr class="head">';
    echo '<th style="width:30%;">' . __('Route Name') . '</th>';
    echo '<th style="width:15%;">' . __('Vehicle') . '</th>';
    echo '<th style="width:20%;">' . __('Driver') . '</th>';
    echo '<th style="width:10%;">' . __('Capacity') . '</th>';
    echo '<th style="width:10%;">' . __('Status') . '</th>';
    echo '<th style="width:15%;">' . __('Actions') . '</th>';
    echo '</tr>';
    
    foreach ($routes as $route) {
        echo '<tr>';
        echo '<td><strong>' . htmlspecialchars($route['name']) . '</strong><br><small>' . htmlspecialchars($route['nameShort']) . '</small></td>';
        echo '<td>' . htmlspecialchars($route['vehicleNumber']) . '<br><small>' . htmlspecialchars($route['vehicleType']) . '</small></td>';
        echo '<td>' . ($route['driverFirstName'] ? htmlspecialchars($route['driverFirstName'] . ' ' . $route['driverSurname']) : '<span style="color:#999;">' . __('Not assigned') . '</span>') . '</td>';
        echo '<td>' . $route['capacity'] . ' ' . __('students') . '</td>';
        echo '<td>' . ($route['active'] ? '<span style="color:#4CAF50;font-weight:bold;">✓ ' . __('Active') . '</span>' : '<span style="color:#999;">' . __('Inactive') . '</span>') . '</td>';
        echo '<td>';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage_edit.php&id=' . $route['gibbonTransportRouteID'] . '" style="color:#2196F3;margin-right:10px;text-decoration:none;">✏️ ' . __('Edit') . '</a>';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php&delete=' . $route['gibbonTransportRouteID'] . '" style="color:#f44336;text-decoration:none;">🗑️ ' . __('Delete') . '</a>';
        echo '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
} else {
    echo '<div class="message" style="margin:30px 0;text-align:center;">';
    echo '<h3>' . __('No routes created yet') . '</h3>';
    echo '<p>' . __('Click the "+ Add Route" button above to create your first transport route.') . '</p>';
    echo '</div>';
}