<?php
/*
Gibbon: the flexible, open school platform
Transport Module - SMS History Viewer
View and audit all SMS messages sent through the Transport module
*/

$page->title = __('SMS History');
$page->breadcrumbs->add(__('Transport Settings'), 'settings.php');
$page->breadcrumbs->add(__('SMS History'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/sms_broadcast.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Load SMS service
require_once __DIR__ . '/lib/TransportSMS.php';
$smsService = new TransportSMS($connection2, $guid);

// Get filters
$status = $_GET['status'] ?? 'all';
$routeID = intval($_GET['routeID'] ?? 0);
$daysBack = intval($_GET['days'] ?? 30);

// Build filters array
$filters = [];
if ($status !== 'all') {
    $filters['status'] = $status;
}
if ($routeID > 0) {
    $filters['routeID'] = $routeID;
}

// Get SMS history with pagination
$page_num = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 50;
$offset = ($page_num - 1) * $limit;

$allHistory = $smsService->getHistory(1000, $filters);
$filteredHistory = array_filter($allHistory, function($sms) use ($daysBack) {
    $created = strtotime($sms['timestampCreated']);
    $cutoff = strtotime("-$daysBack days");
    return $created >= $cutoff;
});

$totalRecords = count($filteredHistory);
$paginatedHistory = array_slice($filteredHistory, $offset, $limit);

// Get routes for filter dropdown
$routes = $connection2->query("
    SELECT gibbonTransportRouteID, name 
    FROM gibbonTransportRoute 
    WHERE active = 1 
    ORDER BY name
")->fetchAll();

?>

<h1 style="display:flex;align-items:center;gap:10px;">
    <span style="font-size:36px;">📋</span>
    <?= __('SMS History') ?>
</h1>

<!-- Filters -->
<div style="background:white;padding:20px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);margin-bottom:30px;display:flex;gap:20px;flex-wrap:wrap;align-items:center;">
    <div style="flex:1;min-width:200px;">
        <form method="get" style="display:flex;gap:15px;flex-wrap:wrap;align-items:center;">
            <!-- Status Filter -->
            <div style="display:flex;gap:8px;align-items:center;">
                <label style="font-weight:bold;color:#333;font-size:14px;"><?= __('Status:') ?></label>
                <select name="status" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>✓ Sent</option>
                    <option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>✗ Failed</option>
                    <option value="pending" <?= $status === 'pending' ? 'selected' : '' ?>>⏳ Pending</option>
                </select>
            </div>
            
            <!-- Route Filter -->
            <div style="display:flex;gap:8px;align-items:center;">
                <label style="font-weight:bold;color:#333;font-size:14px;"><?= __('Route:') ?></label>
                <select name="routeID" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                    <option value="0">All Routes</option>
                    <?php foreach ($routes as $route): ?>
                    <option value="<?= $route['gibbonTransportRouteID'] ?>" <?= $routeID === $route['gibbonTransportRouteID'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($route['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Time Filter -->
            <div style="display:flex;gap:8px;align-items:center;">
                <label style="font-weight:bold;color:#333;font-size:14px;"><?= __('Days:') ?></label>
                <select name="days" style="padding:8px 12px;border:1px solid #ddd;border-radius:6px;">
                    <option value="1" <?= $daysBack === 1 ? 'selected' : '' ?>>Last 24 Hours</option>
                    <option value="7" <?= $daysBack === 7 ? 'selected' : '' ?>>Last 7 Days</option>
                    <option value="30" <?= $daysBack === 30 ? 'selected' : '' ?>>Last 30 Days</option>
                    <option value="90" <?= $daysBack === 90 ? 'selected' : '' ?>>Last 90 Days</option>
                </select>
            </div>
            
            <!-- Submit -->
            <button type="submit" style="padding:8px 20px;background:#2196F3;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">
                🔍 Filter
            </button>
        </form>
    </div>
    
    <!-- Stats -->
    <div style="display:flex;gap:15px;text-align:center;">
        <div>
            <div style="font-size:24px;font-weight:bold;color:#4CAF50;">
                <?= count(array_filter($paginatedHistory, fn($s) => $s['status'] === 'sent')) ?>
            </div>
            <div style="font-size:12px;color:#666;">Sent</div>
        </div>
        <div>
            <div style="font-size:24px;font-weight:bold;color:#f44336;">
                <?= count(array_filter($paginatedHistory, fn($s) => $s['status'] === 'failed')) ?>
            </div>
            <div style="font-size:12px;color:#666;">Failed</div>
        </div>
        <div>
            <div style="font-size:24px;font-weight:bold;color:#2196F3;">
                <?= $totalRecords ?>
            </div>
            <div style="font-size:12px;color:#666;">Total</div>
        </div>
    </div>
</div>

<!-- SMS Table -->
<div style="background:white;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.1);overflow:hidden;">
    <?php if (empty($paginatedHistory)): ?>
        <div style="padding:40px;text-align:center;color:#999;">
            <div style="font-size:48px;margin-bottom:15px;">📭</div>
            <p><?= __('No SMS history found.') ?></p>
        </div>
    <?php else: ?>
        <table style="width:100%;border-collapse:collapse;">
            <thead>
                <tr style="background:#f5f5f5;border-bottom:2px solid #ddd;">
                    <th style="padding:15px;text-align:left;font-weight:bold;color:#333;">Date/Time</th>
                    <th style="padding:15px;text-align:left;font-weight:bold;color:#333;">Message</th>
                    <th style="padding:15px;text-align:center;font-weight:bold;color:#333;">Recipients</th>
                    <th style="padding:15px;text-align:center;font-weight:bold;color:#333;">Status</th>
                    <th style="padding:15px;text-align:center;font-weight:bold;color:#333;">Route</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($paginatedHistory as $sms): ?>
                <tr style="border-bottom:1px solid #eee;transition:background 0.2s;" onmouseover="this.style.background='#f9f9f9'" onmouseout="this.style.background='white'">
                    <td style="padding:15px;color:#666;font-size:13px;white-space:nowrap;">
                        <?= date('M d, H:i', strtotime($sms['timestampCreated'])) ?>
                    </td>
                    <td style="padding:15px;color:#333;">
                        <div style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                            <?= htmlspecialchars($sms['message']) ?>
                        </div>
                        <div style="font-size:11px;color:#999;margin-top:3px;">
                            ID: <?= htmlspecialchars(substr($sms['messageID'], 0, 20)) ?>...
                        </div>
                    </td>
                    <td style="padding:15px;text-align:center;color:#666;font-size:13px;">
                        <?= count(explode(',', $sms['recipients'])) ?>
                    </td>
                    <td style="padding:15px;text-align:center;">
                        <?php
                        $statusColors = [
                            'sent' => ['#4CAF50', '✓ Sent'],
                            'failed' => ['#f44336', '✗ Failed'],
                            'pending' => ['#FFC107', '⏳ Pending']
                        ];
                        $color = $statusColors[$sms['status']][0] ?? '#999';
                        $label = $statusColors[$sms['status']][1] ?? $sms['status'];
                        ?>
                        <span style="background:<?= $color ?>;color:white;padding:4px 8px;border-radius:4px;font-size:12px;font-weight:bold;">
                            <?= $label ?>
                        </span>
                    </td>
                    <td style="padding:15px;text-align:center;color:#666;font-size:13px;">
                        <?php if (!empty($sms['routeID'])): ?>
                            <?php
                            $routeStmt = $connection2->prepare("SELECT name FROM gibbonTransportRoute WHERE gibbonTransportRouteID = ?");
                            $routeStmt->bind_param('i', $sms['routeID']);
                            $routeStmt->execute();
                            $routeResult = $routeStmt->get_result()->fetch_assoc();
                            echo htmlspecialchars($routeResult['name'] ?? 'Route #' . $sms['routeID']);
                            ?>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Pagination -->
<?php if ($totalRecords > $limit): ?>
<div style="margin-top:30px;text-align:center;display:flex;justify-content:center;gap:10px;flex-wrap:wrap;">
    <?php
    $totalPages = ceil($totalRecords / $limit);
    $urlParams = http_build_query(['status' => $status, 'routeID' => $routeID, 'days' => $daysBack]);
    
    for ($i = 1; $i <= $totalPages; $i++):
        $active = $i === $page_num ? 'background:#2196F3;color:white;' : 'background:#f5f5f5;color:#333;';
    ?>
    <a href="?page=<?= $i ?>&<?= $urlParams ?>" 
       style="<?= $active ?>padding:8px 12px;border-radius:6px;text-decoration:none;font-weight:bold;border:1px solid #ddd;">
        <?= $i ?>
    </a>
    <?php endfor; ?>
</div>
<?php endif; ?>
