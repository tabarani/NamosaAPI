<?php
/**
 * Integration Dashboard - Main UI for Moodle Sync Management
 * 
 * Features:
 * - View sync status and statistics
 * - Manual sync for users, courses, enrollments
 * - Batch sync operations
 * - Schedule configuration (cron-based)
 * - Sync logs viewer
 */

use Gibbon\Forms\Form;
use Gibbon\Services\Format;
use Gibbon\Module\NamosaAPI\Moodle\MoodleSyncService;
use Gibbon\Module\NamosaAPI\Moodle\SyncLogger;

require_once '../../gibbon.php';

// Check permissions
$session->checkPermission('Moodle Sync_manage', true);

// Get module connection info
$moodleUrl = $session->get('moodleUrl') ?? '';
$moodleToken = $session->get('moodleToken') ?? '';

if (empty($moodleUrl) || empty($moodleToken)) {
    echo $page->renderAlert('Moodle integration not configured. Please set up Moodle URL and Token in settings.', 'error');
    exit;
}

// Initialize services
$pdo = $database->getConnection();
$syncService = new MoodleSyncService($pdo, $moodleUrl, $moodleToken);
$logger = new SyncLogger($pdo);

// Handle actions
$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'syncUser' && isset($_POST['gibbonPersonID'])) {
    $result = $syncService->syncUser((int)$_POST['gibbonPersonID']);
    echo $page->renderAlert(
        $result['success'] ? 'User synced successfully!' : 'Sync failed: ' . $result['message'],
        $result['success'] ? 'success' : 'error'
    );
}

if ($action === 'syncCourse' && isset($_POST['gibbonCourseID'])) {
    $result = $syncService->syncCourse((int)$_POST['gibbonCourseID']);
    echo $page->renderAlert(
        $result['success'] ? 'Course synced successfully!' : 'Sync failed: ' . $result['message'],
        $result['success'] ? 'success' : 'error'
    );
}

if ($action === 'syncEnrollments' && isset($_POST['gibbonCourseID'])) {
    $result = $syncService->syncCourseEnrollments((int)$_POST['gibbonCourseID']);
    echo $page->renderAlert(
        "Enrollment sync complete: {$result['results']['enrolled']} enrolled, {$result['results']['failed']} failed",
        'success'
    );
}

if ($action === 'batchSyncUsers' && isset($_POST['personIDs'])) {
    $ids = array_map('intval', explode(',', $_POST['personIDs']));
    $result = $syncService->syncUsersBatch($ids);
    echo $page->renderAlert(
        "Batch sync complete: {$result['success']} successful, {$result['failed']} failed out of {$result['total']}",
        'success'
    );
}

// Display Dashboard
echo "<h1>Moodle Integration Dashboard</h1>";

// Statistics Panel
echo "<div class='row'>";
echo "<div class='column half'>";
echo "<h3>Sync Statistics</h3>";
$stats = $logger->getSyncStats();
if (!empty($stats)) {
    echo "<table class='smallInts'>";
    echo "<tr><th>Action</th><th>Total</th><th>Success</th><th>Failed</th></tr>";
    foreach ($stats as $stat) {
        echo "<tr>";
        echo "<td>" . ucfirst(str_replace('_', ' ', $stat['action_type'])) . "</td>";
        echo "<td>{$stat['total']}</td>";
        echo "<td style='color:green'>{$stat['successful']}</td>";
        echo "<td style='color:red'>{$stat['failed']}</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No sync operations recorded yet.</p>";
}
echo "</div>";

echo "<div class='column half'>";
echo "<h3>Quick Actions</h3>";
echo "<div style='display:flex;gap:10px;flex-wrap:wrap;'>";
echo "<a href='" . $session->get('absoluteURL') . "/modules/NamosaAPI/pages/moodle_sync_users.php' class='button'>Sync Users</a>";
echo "<a href='" . $session->get('absoluteURL') . "/modules/NamosaAPI/pages/moodle_sync_courses.php' class='button'>Sync Courses</a>";
echo "<a href='" . $session->get('absoluteURL') . "/modules/NamosaAPI/pages/moodle_batch_sync.php' class='button'>Batch Sync</a>";
echo "<a href='" . $session->get('absoluteURL') . "/modules/NamosaAPI/pages/moodle_schedules.php' class='button'>Manage Schedules</a>";
echo "</div>";
echo "</div>";
echo "</div>";

// Recent Logs
echo "<h3>Recent Sync Activity</h3>";
$logs = $logger->getRecentLogs(20);
if (!empty($logs)) {
    echo "<table class='colorRows'>";
    echo "<tr>";
    echo "<th>Time</th>";
    echo "<th>Action</th>";
    echo "<th>Gibbon ID</th>";
    echo "<th>Moodle ID</th>";
    echo "<th>Status</th>";
    echo "<th>Message</th>";
    echo "</tr>";
    
    foreach ($logs as $log) {
        echo "<tr>";
        echo "<td>" . Format::dateTime($log['timestamp']) . "</td>";
        echo "<td>" . ucfirst(str_replace('_', ' ', $log['action_type'])) . "</td>";
        echo "<td>{$log['gibbon_id']}</td>";
        echo "<td>" . ($log['moodle_id'] ?? '-') . "</td>";
        echo "<td>" . ($log['success'] ? '<span style="color:green">✓ Success</span>' : '<span style="color:red">✗ Failed</span>') . "</td>";
        echo "<td>" . substr($log['message'] ?? '', 0, 50) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
} else {
    echo "<p>No recent activity.</p>";
}

// Configuration Status
echo "<h3>Configuration Status</h3>";
echo "<table class='smallInts'>";
echo "<tr><th>Setting</th><th>Value</th><th>Status</th></tr>";
echo "<tr><td>Moodle URL</td><td>" . htmlspecialchars($moodleUrl) . "</td><td>✓</td></tr>";
echo "<tr><td>Moodle Token</td><td>" . (strlen($moodleToken) > 10 ? substr($moodleToken, 0, 10) . '...' : 'Not set') . "</td><td>" . (empty($moodleToken) ? '✗' : '✓') . "</td></tr>";
echo "</table>";

echo "<p style='margin-top:20px'><a href='" . $session->get('absoluteURL') . "/index.php?q=/modules/NamosaAPI/settings.php'>Configure Settings</a></p>";
