<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)
*/

$page->title = __('Transport Dashboard');
$page->breadcrumbs->add(__('Transport Dashboard'));

require_once __DIR__ . '/lib/TransportSchema.php';
transportEnsureCompatibilitySchema($connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Transport/index.php')) {
    $page->addError(__('Access denied'));
    return;
}

echo '<h1>🚌 ' . __('Transport Management') . '</h1>';

// Quick stats
$stats = [
    'routes' => $connection2->query("SELECT COUNT(*) as count FROM gibbonTransportRoute WHERE active = 1")->fetch()['count'] ?? 0,
    'students' => $connection2->query("SELECT COUNT(*) as count FROM gibbonTransportStudent WHERE status = 'Active'")->fetch()['count'] ?? 0,
    'stops' => $connection2->query("SELECT COUNT(*) as count FROM gibbonTransportStop WHERE active = 1")->fetch()['count'] ?? 0,
    'today_events' => $connection2->query("SELECT COUNT(*) as count FROM gibbonTransportEvent WHERE DATE(timestamp) = CURDATE()")->fetch()['count'] ?? 0
];

echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:20px;margin:30px 0;">';
foreach ($stats as $label => $count) {
    $titles = [
        'routes' => __('Active Routes'),
        'students' => __('Students Assigned'),
        'stops' => __('Active Stops'),
        'today_events' => __("Today's Events")
    ];
    $colors = [
        'routes' => '#2196F3',
        'students' => '#4CAF50',
        'stops' => '#FF9800',
        'today_events' => '#9C27B0'
    ];
    echo '<div style="background:white;padding:20px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);border-left:4px solid ' . $colors[$label] . ';">';
    echo '<h3 style="margin:0 0 10px 0;color:#666;font-size:14px;text-transform:uppercase;">' . $titles[$label] . '</h3>';
    echo '<div style="font-size:36px;font-weight:bold;color:' . $colors[$label] . ';">' . $count . '</div>';
    echo '</div>';
}
echo '</div>';

// Quick links
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;margin:30px 0;">';

$links = [
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/routes_manage.php', __('Manage Routes'), '📋', '#2196F3'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/stops_manage.php', __('Manage Stops'), '📍', '#FF9800'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/students_manage.php', __('Student Assignments'), '👥', '#4CAF50'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/attendance_daily.php', __('Daily Attendance'), '✅', '#9C27B0'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/reports_routes.php', __('Reports'), '📊', '#607D8B'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/sms_broadcast.php', __('SMS Broadcast'), '📢', '#E91E63'],
    [$_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/settings.php', __('Settings'), '⚙️', '#795548']
];

foreach ($links as $link) {
    echo '<a href="' . $link[0] . '" style="display:block;background:' . $link[3] . ';color:white;padding:20px;text-decoration:none;border-radius:8px;text-align:center;box-shadow:0 2px 8px rgba(0,0,0,0.2);transition:transform 0.2s;">';
    echo '<div style="font-size:48px;margin-bottom:10px;">' . $link[2] . '</div>';
    echo '<div style="font-size:16px;font-weight:bold;">' . $link[1] . '</div>';
    echo '</a>';
}

echo '</div>';


// Feature launch panel
$featureCards = [
    ['🧭', __('Parent Status API'), __('GET /transport-status/child/{id}'), '#1976D2'],
    ['✅', __('Boarding API'), __('GET /boarding/route/{routeID} + POST /boarding/events'), '#388E3C'],
    ['📍', __('Vehicle Tracking API'), __('POST /tracking/locations'), '#7B1FA2'],
    ['🚨', __('Emergency API'), __('POST /emergency'), '#C62828'],
    ['🧾', __('Billing API'), __('GET|POST /billing'), '#EF6C00'],
    ['📘', __('Scenario Catalogue'), __('GET /scenarios'), '#455A64']
];

echo '<h2 style="margin-top:40px;">' . __('New Transport Features') . '</h2>';
echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:15px;margin:20px 0 35px 0;">';
foreach ($featureCards as $card) {
    echo '<div style="background:white;border-left:4px solid ' . $card[3] . ';padding:18px;border-radius:8px;box-shadow:0 2px 8px rgba(0,0,0,0.1);">';
    echo '<div style="font-size:30px;margin-bottom:8px;">' . $card[0] . '</div>';
    echo '<strong style="display:block;color:' . $card[3] . ';margin-bottom:6px;">' . $card[1] . '</strong>';
    echo '<code style="font-size:12px;white-space:normal;word-break:break-word;">' . htmlspecialchars($card[2]) . '</code>';
    echo '</div>';
}
echo '</div>';

// Recent events
echo '<h2 style="margin-top:40px;">' . __('Recent Boarding Events') . '</h2>';
$stmt = $connection2->query("
    SELECT e.*, p.firstName, p.surname, r.name as routeName
    FROM gibbonTransportEvent e
    INNER JOIN gibbonPerson p ON e.gibbonPersonID = p.gibbonPersonID
    INNER JOIN gibbonTransportRoute r ON e.gibbonTransportRouteID = r.gibbonTransportRouteID
    WHERE DATE(e.timestamp) >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
    ORDER BY e.timestamp DESC
    LIMIT 10
");
$events = $stmt->fetchAll();

if ($events) {
    echo '<table class="smallIntBorder" style="width:100%;">';
    echo '<tr class="head"><th>' . __('Student') . '</th><th>' . __('Route') . '</th><th>' . __('Type') . '</th><th>' . __('Time') . '</th><th>' . __('Status') . '</th></tr>';
    foreach ($events as $event) {
        $statusColor = $event['status'] === 'Verified' ? '#4CAF50' : '#FF9800';
        echo '<tr>';
        echo '<td>' . htmlspecialchars($event['firstName'] . ' ' . $event['surname']) . '</td>';
        echo '<td>' . htmlspecialchars($event['routeName']) . '</td>';
        echo '<td>' . __($event['type']) . '</td>';
        echo '<td>' . date('M j, H:i', strtotime($event['timestamp'])) . '</td>';
        echo '<td><span style="color:' . $statusColor . ';font-weight:bold;">' . __($event['status']) . '</span></td>';
        echo '</tr>';
    }
    echo '</table>';
} else {
    echo '<div class="message">' . __('No recent events') . '</div>';
}
