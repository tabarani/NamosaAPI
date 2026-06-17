<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)
*/

$page->title = __('Transport Reports');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Reports'));

require_once __DIR__ . '/lib/TransportSchema.php';
transportEnsureCompatibilitySchema($connection2);

if (!isActionAccessible($guid, $connection2, '/modules/Transport/reports_routes.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Get report parameters
$reportType = $_GET['type'] ?? 'summary';
$dateFrom = $_GET['dateFrom'] ?? date('Y-m-01');
$dateTo = $_GET['dateTo'] ?? date('Y-m-t');
$routeID = $_GET['route'] ?? 'all';


function buildTransportReportRows(PDO $connection2, string $reportType, string $dateFrom, string $dateTo, string $routeID): array
{
    $routeFilter = '';
    if ($routeID !== 'all') {
        $routeFilter = ' AND e.gibbonTransportRouteID = ' . (int)$routeID;
    }

    if ($reportType === 'attendance') {
        return $connection2->query("\n            SELECT DATE(e.timestamp) AS reportDate,\n                   COUNT(DISTINCT CASE WHEN e.type = 'pickup' THEN e.gibbonPersonID END) AS pickups,\n                   COUNT(DISTINCT CASE WHEN e.type = 'dropoff' THEN e.gibbonPersonID END) AS dropoffs,\n                   COUNT(DISTINCT CASE WHEN e.status = 'Absent' THEN e.gibbonPersonID END) AS absent,\n                   COUNT(DISTINCT CASE WHEN e.emergencyFlag = 1 THEN e.gibbonTransportEventID END) AS emergencies\n            FROM gibbonTransportEvent e\n            WHERE DATE(e.timestamp) BETWEEN " . $connection2->quote($dateFrom) . " AND " . $connection2->quote($dateTo) . $routeFilter . "\n            GROUP BY DATE(e.timestamp)\n            ORDER BY reportDate DESC\n        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($reportType === 'safety') {
        $routeFilter = $routeID !== 'all' ? ' AND a.gibbonTransportRouteID = ' . (int)$routeID : '';
        return $connection2->query("\n            SELECT a.timestampCreated, a.alertType, a.severity, COALESCE(r.name, 'All Routes') AS routeName, a.message,\n                   CASE WHEN a.resolved = 1 THEN 'Resolved' ELSE 'Open' END AS status\n            FROM gibbonTransportAlert a\n            LEFT JOIN gibbonTransportRoute r ON a.gibbonTransportRouteID = r.gibbonTransportRouteID\n            WHERE DATE(a.timestampCreated) BETWEEN " . $connection2->quote($dateFrom) . " AND " . $connection2->quote($dateTo) . $routeFilter . "\n            ORDER BY a.timestampCreated DESC\n        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($reportType === 'utilization') {
        return $connection2->query("\n            SELECT r.name, r.nameShort, r.capacity, r.vehicleType,\n                   COUNT(ts.gibbonTransportStudentID) AS studentCount,\n                   ROUND(COUNT(ts.gibbonTransportStudentID) / NULLIF(r.capacity, 0) * 100, 1) AS utilization,\n                   COUNT(DISTINCT s.gibbonTransportStopID) AS stopCount\n            FROM gibbonTransportRoute r\n            LEFT JOIN gibbonTransportStudent ts ON r.gibbonTransportRouteID = ts.gibbonTransportRouteID AND ts.status = 'Active'\n            LEFT JOIN gibbonTransportStop s ON r.gibbonTransportRouteID = s.gibbonTransportRouteID AND s.active = 1\n            WHERE r.active = 1\n            GROUP BY r.gibbonTransportRouteID\n            ORDER BY utilization DESC\n        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    return [[
        'metric' => 'Active Routes',
        'value' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportRoute WHERE active = 1")->fetchColumn()
    ], [
        'metric' => 'Assigned Students',
        'value' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportStudent WHERE status = 'Active'")->fetchColumn()
    ], [
        'metric' => 'Active Stops',
        'value' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportStop WHERE active = 1")->fetchColumn()
    ], [
        'metric' => 'Events Today',
        'value' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportEvent WHERE DATE(timestamp) = CURDATE()")->fetchColumn()
    ]];
}

function outputTransportReportExport(array $rows, string $format, string $reportType): void
{
    $filename = 'transport-' . $reportType . '-' . date('Ymd-His');
    if ($format === 'pdf') {
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.html"');
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Transport Report</title></head><body>';
        echo '<h1>Transport ' . htmlspecialchars(ucfirst($reportType)) . ' Report</h1><table border="1" cellspacing="0" cellpadding="6">';
    } else {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . ($format === 'excel' ? '.xls' : '.csv') . '"');
    }

    if (empty($rows)) {
        echo $format === 'pdf' ? '<tr><td>No data</td></tr></table></body></html>' : "No data\n";
        exit;
    }

    $headers = array_keys($rows[0]);
    if ($format === 'pdf') {
        echo '<tr>';
        foreach ($headers as $header) {
            echo '<th>' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach ($headers as $header) {
                echo '<td>' . htmlspecialchars((string)($row[$header] ?? '')) . '</td>';
            }
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;
    }

    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, array_map(static fn($header) => $row[$header] ?? '', $headers));
    }
    fclose($out);
    exit;
}

if (isset($_GET['export'])) {
    $format = in_array($_GET['export'], ['csv', 'excel', 'pdf'], true) ? $_GET['export'] : 'csv';
    outputTransportReportExport(buildTransportReportRows($connection2, $reportType, $dateFrom, $dateTo, $routeID), $format, $reportType);
}

// Report type selector
echo '<div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:15px;margin:20px 0;">
        <h2>📊 ' . __('Transport Reports') . '</h2>
        <div style="display:flex;gap:10px;">
            <a href="?q=/modules/Transport/reports_routes.php&type=summary" style="padding:10px 20px;background:' . ($reportType === 'summary' ? '#2196F3' : '#f5f5f5') . ';color:' . ($reportType === 'summary' ? 'white' : '#333') . ';text-decoration:none;border-radius:6px;font-weight:bold;">' . __('Summary') . '</a>
            <a href="?q=/modules/Transport/reports_routes.php&type=attendance" style="padding:10px 20px;background:' . ($reportType === 'attendance' ? '#2196F3' : '#f5f5f5') . ';color:' . ($reportType === 'attendance' ? 'white' : '#333') . ';text-decoration:none;border-radius:6px;font-weight:bold;">' . __('Attendance') . '</a>
            <a href="?q=/modules/Transport/reports_routes.php&type=safety" style="padding:10px 20px;background:' . ($reportType === 'safety' ? '#2196F3' : '#f5f5f5') . ';color:' . ($reportType === 'safety' ? 'white' : '#333') . ';text-decoration:none;border-radius:6px;font-weight:bold;">' . __('Safety') . '</a>
            <a href="?q=/modules/Transport/reports_routes.php&type=utilization" style="padding:10px 20px;background:' . ($reportType === 'utilization' ? '#2196F3' : '#f5f5f5') . ';color:' . ($reportType === 'utilization' ? 'white' : '#333') . ';text-decoration:none;border-radius:6px;font-weight:bold;">' . __('Utilization') . '</a>
        </div>
      </div>';

// Date filter form
echo '<div style="background:#f9f9f9;padding:20px;border-radius:12px;margin-bottom:30px;">
        <form method="GET" style="display:flex;flex-wrap:wrap;gap:15px;align-items:end;">
            <input type="hidden" name="q" value="/modules/Transport/reports_routes.php">
            <input type="hidden" name="type" value="' . $reportType . '">
            <div>
                <label style="display:block;font-weight:bold;margin-bottom:5px;">' . __('From Date') . '</label>
                <input type="date" name="dateFrom" value="' . $dateFrom . '" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
            </div>
            <div>
                <label style="display:block;font-weight:bold;margin-bottom:5px;">' . __('To Date') . '</label>
                <input type="date" name="dateTo" value="' . $dateTo . '" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
            </div>
            <div>
                <label style="display:block;font-weight:bold;margin-bottom:5px;">' . __('Route') . '</label>
                <select name="route" style="padding:8px;border:1px solid #ddd;border-radius:4px;">
                    <option value="all" ' . ($routeID === 'all' ? 'selected' : '') . '>' . __('All Routes') . '</option>';

$routes = $connection2->query("SELECT gibbonTransportRouteID, name FROM gibbonTransportRoute WHERE active = 1 ORDER BY name")->fetchAll();
foreach ($routes as $route) {
    echo '<option value="' . $route['gibbonTransportRouteID'] . '" ' . ($routeID == $route['gibbonTransportRouteID'] ? 'selected' : '') . '>' . htmlspecialchars($route['name']) . '</option>';
}

echo '</select>
            </div>
            <button type="submit" style="padding:10px 20px;background:#2196F3;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">' . __('Generate Report') . '</button>
            <button type="button" onclick="window.print()" style="padding:10px 20px;background:#607D8B;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">' . __('Print') . '</button>
        </form>
      </div>';

// Summary Report
if ($reportType === 'summary') {
    echo '<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;color:#333;border-bottom:2px solid #2196F3;padding-bottom:15px;">' . __('Transport Summary Report') . '</h3>';
    
    // Key Metrics
    $metrics = [
        'total_routes' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportRoute WHERE active = 1")->fetchColumn(),
        'total_students' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportStudent WHERE status = 'Active'")->fetchColumn(),
        'total_stops' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportStop WHERE active = 1")->fetchColumn(),
        'events_today' => $connection2->query("SELECT COUNT(*) FROM gibbonTransportEvent WHERE DATE(timestamp) = CURDATE()")->fetchColumn()
    ];
    
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px;">
            <div style="background:#E3F2FD;padding:20px;border-radius:10px;text-align:center;border:2px solid #2196F3;">
                <div style="font-size:36px;font-weight:bold;color:#1976D2;">' . $metrics['total_routes'] . '</div>
                <div style="color:#1976D2;font-weight:bold;">' . __('Active Routes') . '</div>
            </div>
            <div style="background:#E8F5E9;padding:20px;border-radius:10px;text-align:center;border:2px solid #4CAF50;">
                <div style="font-size:36px;font-weight:bold;color:#388E3C;">' . $metrics['total_students'] . '</div>
                <div style="color:#388E3C;font-weight:bold;">' . __('Assigned Students') . '</div>
            </div>
            <div style="background:#FFF3E0;padding:20px;border-radius:10px;text-align:center;border:2px solid #FF9800;">
                <div style="font-size:36px;font-weight:bold;color:#EF6C00;">' . $metrics['total_stops'] . '</div>
                <div style="color:#EF6C00;font-weight:bold;">' . __('Active Stops') . '</div>
            </div>
            <div style="background:#F3E5F5;padding:20px;border-radius:10px;text-align:center;border:2px solid #9C27B0;">
                <div style="font-size:36px;font-weight:bold;color:#7B1FA2;">' . $metrics['events_today'] . '</div>
                <div style="color:#7B1FA2;font-weight:bold;">' . __('Events Today') . '</div>
            </div>
          </div>';
    
    // Route Utilization
    echo '<h4 style="color:#333;margin:30px 0 15px 0;">' . __('Route Utilization') . '</h4>';
    
    $stmt = $connection2->query("
        SELECT r.name, r.nameShort, r.capacity, COUNT(ts.gibbonTransportStudentID) as studentCount,
               ROUND(COUNT(ts.gibbonTransportStudentID) / r.capacity * 100, 1) as utilization
        FROM gibbonTransportRoute r
        LEFT JOIN gibbonTransportStudent ts ON r.gibbonTransportRouteID = ts.gibbonTransportRouteID AND ts.status = 'Active'
        WHERE r.active = 1
        GROUP BY r.gibbonTransportRouteID
        ORDER BY utilization DESC
    ");
    $utilization = $stmt->fetchAll();
    
    if ($utilization) {
        echo '<div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;background:white;">
                    <thead>
                        <tr style="background:#2196F3;color:white;">
                            <th style="padding:15px;text-align:left;">' . __('Route') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Capacity') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Students') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Utilization') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Status') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($utilization as $route) {
            $util = $route['utilization'];
            $color = $util > 90 ? '#f44336' : ($util > 70 ? '#FF9800' : '#4CAF50');
            $status = $util > 90 ? '⚠️ Over Capacity' : ($util > 70 ? '⚠️ High' : '✅ Normal');
            
            echo '<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px;"><strong>' . htmlspecialchars($route['name']) . '</strong><br><small style="color:#666;">' . htmlspecialchars($route['nameShort']) . '</small></td>
                    <td style="padding:15px;text-align:center;">' . $route['capacity'] . '</td>
                    <td style="padding:15px;text-align:center;">' . $route['studentCount'] . '</td>
                    <td style="padding:15px;text-align:center;"><span style="color:' . $color . ';font-weight:bold;font-size:18px;">' . $util . '%</span></td>
                    <td style="padding:15px;text-align:center;"><span style="color:' . $color . ';font-weight:bold;">' . $status . '</span></td>
                  </tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
    echo '</div>';
}

// Attendance Report
elseif ($reportType === 'attendance') {
    echo '<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;color:#333;border-bottom:2px solid #4CAF50;padding-bottom:15px;">' . __('Attendance Report') . '</h3>';
    
    $whereClause = "WHERE DATE(e.timestamp) BETWEEN '$dateFrom' AND '$dateTo'";
    if ($routeID !== 'all') {
        $whereClause .= " AND e.gibbonTransportRouteID = $routeID";
    }
    
    $stmt = $connection2->query("
        SELECT DATE(e.timestamp) as date, 
               COUNT(DISTINCT CASE WHEN e.type = 'pickup' THEN e.gibbonPersonID END) as pickups,
               COUNT(DISTINCT CASE WHEN e.type = 'dropoff' THEN e.gibbonPersonID END) as dropoffs,
               COUNT(DISTINCT CASE WHEN e.status = 'Absent' THEN e.gibbonPersonID END) as absent,
               COUNT(DISTINCT CASE WHEN e.emergencyFlag = 1 THEN e.gibbonTransportEventID END) as emergencies
        FROM gibbonTransportEvent e
        $whereClause
        GROUP BY DATE(e.timestamp)
        ORDER BY date DESC
        LIMIT 30
    ");
    $attendance = $stmt->fetchAll();
    
    if ($attendance) {
        echo '<div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;background:white;">
                    <thead>
                        <tr style="background:#4CAF50;color:white;">
                            <th style="padding:15px;text-align:left;">' . __('Date') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Pickups') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Dropoffs') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Absent') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Emergencies') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($attendance as $day) {
            echo '<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px;"><strong>' . date('M j, Y', strtotime($day['date'])) . '</strong></td>
                    <td style="padding:15px;text-align:center;color:#4CAF50;font-weight:bold;">' . $day['pickups'] . '</td>
                    <td style="padding:15px;text-align:center;color:#2196F3;font-weight:bold;">' . $day['dropoffs'] . '</td>
                    <td style="padding:15px;text-align:center;color:' . ($day['absent'] > 0 ? '#f44336' : '#999') . ';font-weight:bold;">' . $day['absent'] . '</td>
                    <td style="padding:15px;text-align:center;color:' . ($day['emergencies'] > 0 ? '#9C27B0' : '#999') . ';font-weight:bold;">' . $day['emergencies'] . '</td>
                  </tr>';
        }
        
        echo '</tbody></table></div>';
    } else {
        echo '<div style="text-align:center;padding:40px;color:#666;">
                <div style="font-size:48px;margin-bottom:15px;">📊</div>
                <h4>' . __('No attendance data found') . '</h4>
                <p>' . __('Try adjusting the date range or route filter') . '</p>
              </div>';
    }
    
    echo '</div>';
}

// Safety Report
elseif ($reportType === 'safety') {
    echo '<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;color:#333;border-bottom:2px solid #f44336;padding-bottom:15px;">' . __('Safety Report') . '</h3>';
    
    // Recent alerts
    $stmt = $connection2->query("
        SELECT a.*, r.name as routeName, p.firstName, p.surname
        FROM gibbonTransportAlert a
        LEFT JOIN gibbonTransportRoute r ON a.gibbonTransportRouteID = r.gibbonTransportRouteID
        LEFT JOIN gibbonPerson p ON a.gibbonPersonID = p.gibbonPersonID
        WHERE a.timestampCreated BETWEEN '$dateFrom' AND '$dateTo'
        ORDER BY a.timestampCreated DESC
        LIMIT 50
    ");
    $alerts = $stmt->fetchAll();
    
    if ($alerts) {
        echo '<h4 style="color:#333;margin:20px 0;">' . __('Recent Safety Alerts') . ' (' . count($alerts) . ')</h4>
              <div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;background:white;">
                    <thead>
                        <tr style="background:#f44336;color:white;">
                            <th style="padding:15px;text-align:left;">' . __('Date') . '</th>
                            <th style="padding:15px;text-align:left;">' . __('Type') . '</th>
                            <th style="padding:15px;text-align:left;">' . __('Severity') . '</th>
                            <th style="padding:15px;text-align:left;">' . __('Route') . '</th>
                            <th style="padding:15px;text-align:left;">' . __('Message') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Status') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($alerts as $alert) {
            $severityColors = [
                'low' => '#4CAF50',
                'medium' => '#FF9800',
                'high' => '#f44336',
                'critical' => '#9C27B0'
            ];
            
            echo '<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px;">' . date('M j, H:i', strtotime($alert['timestampCreated'])) . '</td>
                    <td style="padding:15px;text-transform:capitalize;">' . str_replace('_', ' ', $alert['alertType']) . '</td>
                    <td style="padding:15px;">
                        <span style="color:' . $severityColors[$alert['severity']] . ';font-weight:bold;text-transform:capitalize;">
                            ' . $alert['severity'] . '
                        </span>
                    </td>
                    <td style="padding:15px;">' . ($alert['routeName'] ?? 'All Routes') . '</td>
                    <td style="padding:15px;">' . htmlspecialchars(substr($alert['message'], 0, 100)) . (strlen($alert['message']) > 100 ? '...' : '') . '</td>
                    <td style="padding:15px;text-align:center;">
                        <span style="padding:4px 10px;border-radius:12px;font-size:12px;font-weight:bold;background:' . ($alert['resolved'] ? '#E8F5E9' : '#FFF3E0') . ';color:' . ($alert['resolved'] ? '#2E7D32' : '#EF6C00') . ';">
                            ' . ($alert['resolved'] ? '✓ Resolved' : '⏳ Open') . '
                        </span>
                    </td>
                  </tr>';
        }
        
        echo '</tbody></table></div>';
    } else {
        echo '<div style="text-align:center;padding:40px;color:#666;">
                <div style="font-size:48px;margin-bottom:15px;">✅</div>
                <h4>' . __('No safety alerts during this period') . '</h4>
                <p>' . __('Great! No safety incidents reported.') . '</p>
              </div>';
    }
    
    echo '</div>';
}

// Utilization Report
elseif ($reportType === 'utilization') {
    echo '<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 2px 10px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;color:#333;border-bottom:2px solid #FF9800;padding-bottom:15px;">' . __('Route Utilization Report') . '</h3>';
    
    $stmt = $connection2->query("
        SELECT r.name, r.nameShort, r.capacity, r.vehicleType,
               COUNT(ts.gibbonTransportStudentID) as studentCount,
               ROUND(COUNT(ts.gibbonTransportStudentID) / r.capacity * 100, 1) as utilization,
               COUNT(DISTINCT s.gibbonTransportStopID) as stopCount
        FROM gibbonTransportRoute r
        LEFT JOIN gibbonTransportStudent ts ON r.gibbonTransportRouteID = ts.gibbonTransportRouteID AND ts.status = 'Active'
        LEFT JOIN gibbonTransportStop s ON r.gibbonTransportRouteID = s.gibbonTransportRouteID AND s.active = 1
        WHERE r.active = 1
        GROUP BY r.gibbonTransportRouteID
        ORDER BY utilization DESC
    ");
    $routes = $stmt->fetchAll();
    
    if ($routes) {
        echo '<div style="overflow-x:auto;">
                <table style="width:100%;border-collapse:collapse;background:white;">
                    <thead>
                        <tr style="background:#FF9800;color:white;">
                            <th style="padding:15px;text-align:left;">' . __('Route') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Vehicle') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Capacity') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Students') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Stops') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Utilization') . '</th>
                            <th style="padding:15px;text-align:center;">' . __('Efficiency') . '</th>
                        </tr>
                    </thead>
                    <tbody>';
        
        foreach ($routes as $route) {
            $util = $route['utilization'];
            $color = $util > 90 ? '#f44336' : ($util > 70 ? '#FF9800' : '#4CAF50');
            $efficiency = $route['stopCount'] > 0 ? round($route['studentCount'] / $route['stopCount'], 1) : 0;
            
            echo '<tr style="border-bottom:1px solid #eee;">
                    <td style="padding:15px;">
                        <strong>' . htmlspecialchars($route['name']) . '</strong>
                        <br><small style="color:#666;">' . htmlspecialchars($route['nameShort']) . '</small>
                    </td>
                    <td style="padding:15px;text-align:center;">' . htmlspecialchars($route['vehicleType']) . '</td>
                    <td style="padding:15px;text-align:center;">' . $route['capacity'] . '</td>
                    <td style="padding:15px;text-align:center;font-weight:bold;">' . $route['studentCount'] . '</td>
                    <td style="padding:15px;text-align:center;">' . $route['stopCount'] . '</td>
                    <td style="padding:15px;text-align:center;">
                        <span style="color:' . $color . ';font-weight:bold;font-size:18px;">' . $util . '%</span>
                    </td>
                    <td style="padding:15px;text-align:center;">
                        <span style="background:#e3f2fd;color:#1976d2;padding:4px 10px;border-radius:12px;font-weight:bold;">
                            ' . $efficiency . ' ' . __('per stop') . '
                        </span>
                    </td>
                  </tr>';
        }
        
        echo '</tbody></table></div>';
    }
    
    echo '</div>';
}

// Export functionality
echo '<div style="text-align:center;margin-top:30px;padding:20px;background:#f5f5f5;border-radius:12px;">
        <h4 style="margin:0 0 15px 0;color:#333;">' . __('Export Options') . '</h4>
        <div style="display:flex;justify-content:center;gap:15px;flex-wrap:wrap;">
            <button onclick="exportReport(\'pdf\')" style="padding:12px 25px;background:#f44336;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;display:flex;align-items:center;gap:8px;">
                📄 ' . __('Export to PDF') . '
            </button>
            <button onclick="exportReport(\'csv\')" style="padding:12px 25px;background:#4CAF50;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;display:flex;align-items:center;gap:8px;">
                📊 ' . __('Export to CSV') . '
            </button>
            <button onclick="exportReport(\'excel\')" style="padding:12px 25px;background:#2196F3;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;display:flex;align-items:center;gap:8px;">
                📈 ' . __('Export to Excel') . '
            </button>
        </div>
      </div>';

?>

<script>
function exportReport(format) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', format);
    const url = window.location.pathname + '?' + params.toString();
    
    window.location.href = url;
}
</script>