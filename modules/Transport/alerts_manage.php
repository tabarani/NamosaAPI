<?php
/*
Gibbon: the flexible, open school platform
Transport Module - Transport Alerts Management
Manage and resolve safety alerts
*/

$page->title = __('Transport Alerts');
$page->breadcrumbs->add(__('Transport'), 'index.php');
$page->breadcrumbs->add(__('Safety Alerts'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/alerts_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Load alert system
require_once __DIR__ . '/lib/TransportAlerts.php';
require_once __DIR__ . '/lib/TransportSMS.php';

$alertSystem = new TransportAlerts($connection2, $guid);
$smsService = new TransportSMS($connection2, $guid);

// Handle alert resolution
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'resolve_alert') {
    $alertID = (int)$_POST['alertID'];
    $notes = trim($_POST['notes'] ?? '');
    
    if ($alertSystem->resolveAlert($alertID, $notes, $_SESSION[$guid]['gibbonPersonID'])) {
        $page->addSuccess(__('Alert resolved successfully.'));
    } else {
        $page->addError(__('Failed to resolve alert.'));
    }
}

// Get alerts
$filterSeverity = $_GET['severity'] ?? null;
$filterStatus = $_GET['status'] ?? 'unresolved';

if ($filterStatus === 'critical') {
    $alerts = $alertSystem->getCriticalAlerts();
} else {
    $alerts = $alertSystem->getUnresolvedAlerts(100);
}

if ($filterSeverity) {
    $alerts = array_filter($alerts, function($alert) use ($filterSeverity) {
        return $alert['severity'] === $filterSeverity;
    });
}

?>

<style>
.alerts-container { max-width: 1200px; margin: 0 auto; }
.alert-filters { 
    background: white; 
    padding: 20px; 
    border-radius: 8px; 
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    gap: 20px;
    align-items: center;
    flex-wrap: wrap;
}
.filter-group {
    display: flex;
    gap: 10px;
    align-items: center;
}
.alert-badge {
    display: inline-block;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
}
.severity-critical {
    background: #c62828;
    color: white;
}
.severity-high {
    background: #f57c00;
    color: white;
}
.severity-medium {
    background: #fbc02d;
    color: #333;
}
.severity-low {
    background: #388e3c;
    color: white;
}
.alert-card {
    background: white;
    border-left: 5px solid #333;
    padding: 20px;
    margin-bottom: 15px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 20px;
}
.alert-card.critical {
    border-left-color: #c62828;
    background: #ffebee;
}
.alert-card.high {
    border-left-color: #f57c00;
    background: #fff3e0;
}
.alert-card.medium {
    border-left-color: #fbc02d;
    background: #fffde7;
}
.alert-card.low {
    border-left-color: #388e3c;
    background: #e8f5e9;
}
.alert-icon {
    font-size: 32px;
    min-width: 50px;
    text-align: center;
}
.alert-content {
    flex: 1;
}
.alert-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}
.alert-type {
    font-weight: bold;
    font-size: 16px;
    display: flex;
    gap: 10px;
    align-items: center;
}
.alert-message {
    color: #333;
    margin-bottom: 10px;
    line-height: 1.6;
}
.alert-meta {
    display: flex;
    gap: 20px;
    font-size: 13px;
    color: #666;
    flex-wrap: wrap;
}
.alert-actions {
    display: flex;
    gap: 10px;
    flex-direction: column;
    min-width: 150px;
}
.btn-resolve {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 10px 15px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    transition: all 0.2s;
}
.btn-resolve:hover {
    background: #388E3C;
}
.no-alerts {
    background: white;
    padding: 40px;
    text-align: center;
    border-radius: 8px;
    color: #666;
}
.alert-icon-map {
    width: 100%;
    height: 300px;
    background: #f5f5f5;
    border-radius: 8px;
    margin-top: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #999;
}
.stats-bar {
    background: white;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.stat-box {
    text-align: center;
    padding: 15px;
    background: #f5f5f5;
    border-radius: 6px;
}
.stat-number {
    font-size: 28px;
    font-weight: bold;
    color: #2196F3;
}
.stat-label {
    font-size: 12px;
    color: #666;
    margin-top: 5px;
}
.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}
.modal.active {
    display: flex;
}
.modal-content {
    background: white;
    padding: 30px;
    border-radius: 8px;
    max-width: 500px;
    width: 90%;
    max-height: 80vh;
    overflow-y: auto;
}
.modal-header {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 20px;
    border-bottom: 2px solid #eee;
    padding-bottom: 10px;
}
.form-group {
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    font-weight: bold;
    margin-bottom: 5px;
    font-size: 14px;
}
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 6px;
    font-family: Arial, sans-serif;
    resize: vertical;
    min-height: 80px;
}
.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    border-top: 1px solid #eee;
    padding-top: 15px;
}
.btn-primary {
    background: #2196F3;
    color: white;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-primary:hover {
    background: #1976D2;
}
.btn-secondary {
    background: #e0e0e0;
    color: #333;
    border: none;
    padding: 10px 20px;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.2s;
}
.btn-secondary:hover {
    background: #d0d0d0;
}
</style>

<div class="alerts-container">
    <h1 style="display:flex;align-items:center;gap:10px;">
        <span style="font-size:36px;">🚨</span>
        <?= __('Safety Alerts Management') ?>
    </h1>
    
    <!-- Statistics -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="stat-number" style="color:#c62828;"><?= count(array_filter($alerts, fn($a) => $a['severity'] === 'critical')) ?></div>
            <div class="stat-label"><?= __('Critical') ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:#f57c00;"><?= count(array_filter($alerts, fn($a) => $a['severity'] === 'high')) ?></div>
            <div class="stat-label"><?= __('High') ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:#fbc02d;"><?= count(array_filter($alerts, fn($a) => $a['severity'] === 'medium')) ?></div>
            <div class="stat-label"><?= __('Medium') ?></div>
        </div>
        <div class="stat-box">
            <div class="stat-number" style="color:#388e3c;"><?= count(array_filter($alerts, fn($a) => $a['severity'] === 'low')) ?></div>
            <div class="stat-label"><?= __('Low') ?></div>
        </div>
    </div>
    
    <!-- Filters -->
    <div class="alert-filters">
        <div class="filter-group">
            <label style="font-weight:bold;font-size:14px;"><?= __('Filter by Severity:') ?></label>
            <a href="?status=unresolved" style="text-decoration:none;color:#2196F3;font-weight:bold;">
                <?= __('All') ?>
            </a>
            |
            <a href="?severity=critical&status=unresolved" style="text-decoration:none;color:#c62828;font-weight:bold;">
                <?= __('Critical') ?>
            </a>
            |
            <a href="?severity=high&status=unresolved" style="text-decoration:none;color:#f57c00;font-weight:bold;">
                <?= __('High') ?>
            </a>
            |
            <a href="?severity=medium&status=unresolved" style="text-decoration:none;color:#fbc02d;font-weight:bold;">
                <?= __('Medium') ?>
            </a>
        </div>
    </div>
    
    <!-- Alerts List -->
    <div id="alertsList">
        <?php if (empty($alerts)): ?>
            <div class="no-alerts">
                <div style="font-size:48px;margin-bottom:15px;">✅</div>
                <h2><?= __('No Active Alerts') ?></h2>
                <p><?= __('All systems are operating normally!') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($alerts as $alert): ?>
            <div class="alert-card <?= $alert['severity'] ?>">
                <div class="alert-icon">
                    <?php
                    $icons = [
                        'missing_boarding' => '🚨',
                        'route_deviation' => '📍',
                        'late_arrival' => '⏰',
                        'emergency' => '🆘',
                        'custom' => '⚠️'
                    ];
                    echo $icons[$alert['alertType']] ?? '⚠️';
                    ?>
                </div>
                <div class="alert-content">
                    <div class="alert-header">
                        <div class="alert-type">
                            <span><?= __(ucfirst(str_replace('_', ' ', $alert['alertType']))) ?></span>
                            <span class="alert-badge severity-<?= $alert['severity'] ?>">
                                <?= strtoupper($alert['severity']) ?>
                            </span>
                        </div>
                        <div style="font-size:12px;color:#999;">
                            <?= date('M d, H:i', strtotime($alert['timestampCreated'])) ?>
                        </div>
                    </div>
                    
                    <div class="alert-message">
                        <?= htmlspecialchars($alert['message']) ?>
                    </div>
                    
                    <div class="alert-meta">
                        <?php if ($alert['routeName']): ?>
                        <div><strong>🚌 <?= __('Route:') ?></strong> <?= htmlspecialchars($alert['routeName']) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($alert['studentName']): ?>
                        <div><strong>👤 <?= __('Student:') ?></strong> <?= htmlspecialchars($alert['studentName']) ?></div>
                        <?php endif; ?>
                        
                        <?php if ($alert['smsSent']): ?>
                        <div><strong>📱 <?= __('SMS Sent:') ?></strong> ✓</div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="alert-actions">
                    <button class="btn-resolve" onclick="openResolveModal(<?= $alert['gibbonTransportAlertID'] ?>, '<?= htmlspecialchars($alert['message']) ?>')">
                        ✓ <?= __('Resolve') ?>
                    </button>
                    <button class="btn-secondary" onclick="viewAlertDetails(<?= $alert['gibbonTransportAlertID'] ?>)" style="background:#e3f2fd;color:#1976D2;">
                        📋 <?= __('Details') ?>
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Resolve Modal -->
<div id="resolveModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <?= __('Resolve Alert') ?>
            <span style="float:right;cursor:pointer;font-size:20px;color:#999;" onclick="closeModal()">✕</span>
        </div>
        
        <form method="post" onsubmit="submitResolveForm(event)">
            <input type="hidden" name="action" value="resolve_alert">
            <input type="hidden" id="alertIDInput" name="alertID" value="">
            
            <div class="form-group">
                <label><?= __('Alert Message') ?></label>
                <div id="alertMessageDisplay" style="background:#f5f5f5;padding:10px;border-radius:6px;color:#333;"></div>
            </div>
            
            <div class="form-group">
                <label><?= __('Resolution Notes') ?></label>
                <textarea name="notes" placeholder="<?= __('Describe how this alert was resolved...') ?>" required></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn-secondary" onclick="closeModal()">
                    <?= __('Cancel') ?>
                </button>
                <button type="submit" class="btn-primary">
                    <?= __('Resolve Alert') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function openResolveModal(alertID, message) {
    document.getElementById('alertIDInput').value = alertID;
    document.getElementById('alertMessageDisplay').textContent = message;
    document.getElementById('resolveModal').classList.add('active');
}

function closeModal() {
    document.getElementById('resolveModal').classList.remove('active');
}

function submitResolveForm(event) {
    event.preventDefault();
    document.querySelector('form').submit();
}

function viewAlertDetails(alertID) {
    // TODO: Implement detailed view modal
    alert('Details view coming soon');
}

// Close modal on background click
document.getElementById('resolveModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>
