<?php
/*
Gibbon: the flexible, open school platform
Transport Module - SMS Broadcast (Updated with TransportSMS Service)
Send SMS notifications to parents of students on transport routes
*/

$page->title = __('SMS Broadcast');
$page->breadcrumbs->add(__('Transport Settings'), 'settings.php');
$page->breadcrumbs->add(__('SMS Broadcast'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/sms_broadcast.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Load SMS service
require_once __DIR__ . '/lib/TransportSMS.php';
$smsService = new TransportSMS($connection2, $guid);

// Handle SMS broadcast submission
if (isset($_POST['broadcast'])) {
    $routeID = intval($_POST['routeID'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if (!$smsService->isEnabled()) {
        $page->addError(__('SMS is not enabled. Please configure SMS settings first.'));
    } elseif (empty($message)) {
        $page->addError(__('Please enter a message.'));
    } elseif (strlen($message) > 160) {
        $page->addError(__('Message exceeds 160 character limit.'));
    } else {
        // Get parent phone numbers for the selected route
        $sql = "SELECT DISTINCT 
                    COALESCE(p.phone1, p.phone2) as phone,
                    p.firstName, p.surname,
                    student.firstName as studentFirstName, student.surname as studentSurname,
                    ts.gibbonTransportStudentID
                FROM gibbonTransportStudent ts
                INNER JOIN gibbonPerson student ON ts.gibbonPersonID = student.gibbonPersonID
                INNER JOIN gibbonFamilyChild fc ON student.gibbonPersonID = fc.gibbonPersonID
                INNER JOIN gibbonFamilyAdult fa ON fc.gibbonFamilyID = fa.gibbonFamilyID
                INNER JOIN gibbonPerson p ON fa.gibbonPersonID = p.gibbonPersonID
                WHERE ts.status = 'Active' 
                AND (p.phone1 IS NOT NULL OR p.phone2 IS NOT NULL)";
        
        if ($routeID > 0) {
            $sql .= " AND ts.gibbonTransportRouteID = " . $routeID;
        }
        
        $stmt = $connection2->query($sql);
        $recipients = $stmt->fetchAll();
        
        if (empty($recipients)) {
            $page->addWarning(__('No recipients found with valid phone numbers.'));
        } else {
            // Send SMS to each recipient
            $phones = array_unique(array_column($recipients, 'phone'));
            $result = $smsService->sendSMS($phones, $message, ['routeID' => $routeID]);
            
            if ($result['success']) {
                $page->addSuccess(sprintf(__('SMS successfully sent to %d recipients. Message ID: %s'), 
                    count($phones), $result['messageID']));
            } else {
                $page->addError(__('Failed to send SMS: ') . $result['error']);
            }
        }
    }
}

// Get routes for dropdown
$routes = $connection2->query("
    SELECT gibbonTransportRouteID, name, nameShort 
    FROM gibbonTransportRoute 
    WHERE active = 1 
    ORDER BY name
")->fetchAll();

// Get recent SMS history
$recentSMS = $smsService->getHistory(10);

?>

<h1 style="display:flex;align-items:center;gap:10px;">
    <span style="font-size:36px;">📱</span>
    <?= __('SMS Broadcast') ?>
</h1>

<!-- Navigation Tabs -->
<div style="display:flex;gap:10px;margin-bottom:30px;border-bottom:2px solid #ddd;padding-bottom:10px;flex-wrap:wrap;">
    <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/settings.php" 
       style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:bold;background:#f5f5f5;color:#333;">
        📱 <?= __('SMS Configuration') ?>
    </a>
    <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/sms_broadcast.php" 
       style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:bold;background:#2196F3;color:white;">
        📢 <?= __('SMS Broadcast') ?>
    </a>
    <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/sms_history.php" 
       style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:bold;background:#f5f5f5;color:#333;">
        📋 <?= __('SMS History') ?>
    </a>
</div>

<?php if (!$smsService->isEnabled()): ?>
    <div style="background:#ffebee;padding:25px;border-radius:12px;border:2px solid #f44336;text-align:center;margin-bottom:30px;">
        <div style="font-size:48px;margin-bottom:15px;">⚠️</div>
        <h3 style="color:#c62828;margin:0 0 10px 0;"><?= __('SMS Not Configured') ?></h3>
        <p style="color:#555;margin:0 0 20px 0;">
            <?= __('Please configure your SMS gateway settings before using the broadcast feature.') ?>
        </p>
        <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/settings.php" 
           style="display:inline-block;padding:12px 24px;background:#2196F3;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">
           ⚙️ <?= __('Configure SMS Settings') ?>
        </a>
    </div>
<?php else: ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;margin-bottom:30px;">
    
    <!-- Broadcast Form -->
    <div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <h2 style="margin-top:0;color:#333;border-bottom:2px solid #9C27B0;padding-bottom:15px;">
            📢 <?= __('Send SMS Broadcast') ?>
        </h2>
        
        <form method="post" action="">
            <input type="hidden" name="broadcast" value="1">
            
            <!-- Route Selection -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Select Route') ?>
                </label>
                <select name="routeID" id="routeID" 
                        style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:16px;background:white;">
                    <option value="0"><?= __('All Routes') ?></option>
                    <?php foreach ($routes as $route): ?>
                    <option value="<?= $route['gibbonTransportRouteID'] ?>">
                        <?= htmlspecialchars($route['name']) ?> (<?= htmlspecialchars($route['nameShort']) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <!-- Message Templates -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Quick Templates') ?>
                </label>
                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                    <?php
                    $templates = [
                        ['🚌', __('Delay'), __('The school bus is running approximately {X} minutes late today. We apologize for the inconvenience.')],
                        ['🎉', __('Early Dismissal'), __('Students will be dismissed early today at {TIME}. Please arrange for pickup.')],
                        ['⛔', __('Cancellation'), __('Due to {REASON}, bus service is cancelled today. Please arrange alternative transport.')],
                        ['📅', __('Schedule Change'), __('Please note the bus schedule has changed. New pickup time: {TIME}.')],
                        ['🚨', __('Emergency'), __('URGENT: Please contact the school immediately regarding your child\'s transport.')]
                    ];
                    foreach ($templates as $t):
                    ?>
                    <button type="button" onclick="setTemplate('<?= addslashes($t[2]) ?>')"
                            style="padding:8px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:12px;transition:all 0.2s;"
                            onmouseover="this.style.background='#e0e0e0'" onmouseout="this.style.background='#f5f5f5'">
                        <?= $t[0] ?> <?= $t[1] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Message -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Message') ?> <span style="color:#999;">(Max 160 characters)</span>
                </label>
                <textarea name="message" id="messageBox"
                          style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;resize:vertical;min-height:100px;font-family:Arial,sans-serif;"
                          placeholder="<?= __('Type your SMS message here...') ?>" required></textarea>
                <div style="margin-top:8px;color:#999;font-size:12px;">
                    <?= __('Characters: ') ?><span id="charCount">0</span>/160
                </div>
            </div>
            
            <!-- Preview -->
            <div style="margin-bottom:25px;background:#f5f5f5;padding:15px;border-radius:8px;">
                <div style="font-weight:bold;color:#666;margin-bottom:10px;font-size:12px;">📱 <?= __('Preview') ?></div>
                <div id="messagePreview" style="background:white;padding:10px;border-radius:4px;color:#333;font-size:13px;min-height:40px;"></div>
            </div>
            
            <!-- Submit -->
            <button type="submit" 
                    style="width:100%;padding:15px;background:#2196F3;color:white;border:none;border-radius:8px;font-weight:bold;font-size:16px;cursor:pointer;transition:all 0.2s;"
                    onmouseover="this.style.background='#1976D2'" onmouseout="this.style.background='#2196F3'">
                📤 <?= __('Send SMS') ?>
            </button>
        </form>
    </div>
    
    <!-- Info & History -->
    <div>
        <!-- Info Box -->
        <div style="background:white;padding:20px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);margin-bottom:20px;">
            <h3 style="margin:0 0 15px 0;color:#333;border-bottom:2px solid #2196F3;padding-bottom:10px;">
                ℹ️ <?= __('SMS Information') ?>
            </h3>
            <div style="color:#666;line-height:1.8;font-size:13px;">
                <div style="margin-bottom:10px;">
                    <strong><?= __('Message Limit:') ?></strong> 160 characters per SMS
                </div>
                <div style="margin-bottom:10px;">
                    <strong><?= __('Cost:') ?></strong> <?= __('Check your SMS provider for rates') ?>
                </div>
                <div style="margin-bottom:10px;">
                    <strong><?= __('Delivery Time:') ?></strong> <?= __('Usually delivered within seconds') ?>
                </div>
                <div style="background:#e8f5e9;padding:10px;border-radius:6px;border-left:4px solid #4CAF50;margin-top:15px;color:#2e7d32;">
                    <strong>✓ <?= __('Tips:') ?></strong>
                    <ul style="margin:5px 0;padding-left:20px;">
                        <li><?= __('Keep messages short and clear') ?></li>
                        <li><?= __('Use templates for consistency') ?></li>
                        <li><?= __('Always include emergency contact info when relevant') ?></li>
                    </ul>
                </div>
            </div>
        </div>
        
        <!-- Recent SMS -->
        <div style="background:white;padding:20px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
            <h3 style="margin:0 0 15px 0;color:#333;border-bottom:2px solid #2196F3;padding-bottom:10px;">
                📋 <?= __('Recent SMS') ?>
            </h3>
            <?php if (!empty($recentSMS)): ?>
                <div style="font-size:12px;">
                    <?php foreach (array_slice($recentSMS, 0, 5) as $sms): ?>
                    <div style="background:#f5f5f5;padding:10px;border-radius:6px;margin-bottom:8px;border-left:4px solid <?= $sms['status'] === 'sent' ? '#4CAF50' : '#f44336' ?>;">
                        <div style="font-weight:bold;color:#333;"><?= htmlspecialchars(substr($sms['message'], 0, 50)) ?>...</div>
                        <div style="color:#999;margin-top:3px;font-size:11px;">
                            <?= date('M d, H:i', strtotime($sms['timestampCreated'])) ?>
                            | <?= ucfirst($sms['status']) ?>
                            | <?= count(explode(',', $sms['recipients'])) ?> recipients
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/sms_history.php" 
                   style="display:inline-block;margin-top:15px;color:#2196F3;text-decoration:none;font-weight:bold;font-size:13px;">
                   <?= __('View All History') ?> →
                </a>
            <?php else: ?>
                <p style="color:#999;text-align:center;padding:20px;margin:0;">
                    <?= __('No SMS sent yet') ?>
                </p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>

<script>
function setTemplate(text) {
    document.getElementById('messageBox').value = text;
    updateMessagePreview();
}

document.getElementById('messageBox').addEventListener('input', updateMessagePreview);

function updateMessagePreview() {
    const text = document.getElementById('messageBox').value;
    const charCount = document.getElementById('charCount');
    const preview = document.getElementById('messagePreview');
    
    charCount.textContent = text.length;
    preview.textContent = text || '<?= __('Your message will appear here...') ?>';
    
    if (text.length > 160) {
        charCount.parentElement.style.color = '#f44336';
    } else {
        charCount.parentElement.style.color = '#999';
    }
}
</script>
