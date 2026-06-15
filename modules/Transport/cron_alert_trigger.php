<?php
/**
 * Transport Alert Trigger Cron Job
 * Run this script every 5-10 minutes via cron: */5 * * * * /usr/bin/php /path/to/cron_alert_trigger.php
 * 
 * This script:
 * - Checks for missing student boardings
 * - Checks for late arrivals
 * - Automatically triggers alerts
 * - Sends SMS notifications to parents
 */

// Require Gibbon bootstrap
require_once dirname(__DIR__, 3) . '/gibbon.php';

// Check if running from CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo 'Access denied. This script must run from CLI.';
    exit;
}

// Simple logging
function logCron($message) {
    $logFile = dirname(__DIR__, 3) . '/logs/transport_alerts_cron.log';
    $timestamp = date('Y-m-d H:i:s');
    error_log("[$timestamp] $message\n", 3, $logFile);
    echo "[$timestamp] $message\n";
}

try {
    logCron('=== Alert Trigger Cron Started ===');
    
    // Load alert system
    require_once __DIR__ . '/lib/TransportAlerts.php';
    require_once __DIR__ . '/lib/TransportSMS.php';
    
    // Initialize systems
    $alertSystem = new TransportAlerts($connection2, null);
    $smsService = new TransportSMS($connection2, null);
    
    // Check for missing boardings
    logCron('Checking for missing boardings...');
    $missingCount = $alertSystem->checkMissingBoardings();
    logCron("Missing boarding alerts created: $missingCount");
    
    // Check for late arrivals
    logCron('Checking for late arrivals...');
    $lateCount = $alertSystem->checkLateArrivals(15); // 15 minute threshold
    logCron("Late arrival alerts created: $lateCount");
    
    // Summary
    $totalAlerts = $missingCount + $lateCount;
    logCron("=== Alert Trigger Cron Completed ($totalAlerts alerts) ===\n");
    
} catch (Exception $e) {
    logCron('ERROR: ' . $e->getMessage());
    exit(1);
}

exit(0);
?>
