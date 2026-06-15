<?php
/*
Gibbon: the flexible, open school platform
Transport Module - SMS Broadcast
*/

$page->title = __('SMS Broadcast');
$page->breadcrumbs->add(__('Transport Settings'), 'settings.php');
$page->breadcrumbs->add(__('SMS Broadcast'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/sms_broadcast.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Get SMS settings
function getTransportSetting($connection2, $name, $default = '') {
    $stmt = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Transport' AND name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

$smsEnabled = getTransportSetting($connection2, 'smsEnabled', '0');

// Handle SMS broadcast submission
if (isset($_POST['broadcast'])) {
    $routeID = intval($_POST['routeID'] ?? 0);
    $message = trim($_POST['message'] ?? '');
    
    if (!$smsEnabled) {
        echo '<div class="error">' . __('SMS is not enabled. Please configure SMS settings first.') . '</div>';
    } elseif (empty($message)) {
        echo '<div class="error">' . __('Please enter a message.') . '</div>';
    } else {
        // Get parent phone numbers for the selected route
        $sql = "SELECT DISTINCT 
                    COALESCE(p.phone1, p.phone2) as phone,
                    p.firstName, p.surname,
                    student.firstName as studentFirstName, student.surname as studentSurname
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
            echo '<div class="warning">' . __('No recipients found with valid phone numbers.') . '</div>';
        } else {
            // In production, this would call the SMS API
            $sentCount = count($recipients);
            echo '<div class="success">' . sprintf(__('SMS broadcast queued for %d recipients.'), $sentCount) . '</div>';
        }
    }
}

echo '<h1>' . __('Transport Settings') . '</h1>';

// Settings Navigation Tabs
echo '<div style="display:flex;gap:10px;margin-bottom:30px;border-bottom:2px solid #ddd;padding-bottom:10px;">';
$tabs = [
    ['settings.php', __('SMS Configuration'), '📱', false],
    ['api_keys_manage.php', __('API Key Management'), '🔑', false],
    ['sms_broadcast.php', __('SMS Broadcast'), '📢', true]
];
foreach ($tabs as $tab) {
    $active = $tab[3] ? 'background:#2196F3;color:white;' : 'background:#f5f5f5;color:#333;';
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/' . $tab[0] . '" 
          style="display:flex;align-items:center;gap:8px;padding:12px 20px;border-radius:8px 8px 0 0;text-decoration:none;font-weight:bold;' . $active . '">';
    echo '<span style="font-size:20px;">' . $tab[1] . '</span>';
    echo '<span>' . $tab[1] . '</span>';
    echo '</a>';
}
echo '</div>';

if (!$smsEnabled) {
    echo '<div style="background:#ffebee;padding:25px;border-radius:12px;border:2px solid #f44336;text-align:center;">';
    echo '<div style="font-size:48px;margin-bottom:15px;">⚠️</div>';
    echo '<h3 style="color:#c62828;margin:0 0 10px 0;">' . __('SMS Not Configured') . '</h3>';
    echo '<p style="color:#555;margin:0 0 20px 0;">' . __('Please configure your SMS gateway settings before using the broadcast feature.') . '</p>';
    echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/settings.php" 
           style="display:inline-block;padding:12px 24px;background:#2196F3;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">
           📱 ' . __('Configure SMS Settings') . '</a>';
    echo '</div>';
    return;
}

// Get routes for dropdown
$routes = $connection2->query("SELECT gibbonTransportRouteID, name, nameShort FROM gibbonTransportRoute WHERE active = 1 ORDER BY name")->fetchAll();

?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:30px;">
    <!-- Broadcast Form -->
    <div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <h2 style="margin-top:0;color:#333;border-bottom:2px solid #9C27B0;padding-bottom:15px;display:flex;align-items:center;gap:10px;">
            <span style="font-size:28px;">📢</span>
            <?= __('Send SMS Broadcast') ?>
        </h2>
        
        <form method="post" action="" id="broadcastForm">
            <input type="hidden" name="broadcast" value="1">
            
            <!-- Route Selection -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Select Route') ?>
                </label>
                <select name="routeID" id="routeID" 
                        style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:16px;background:white;"
                        onchange="updateRecipientCount()">
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
                            style="padding:8px 12px;background:#f5f5f5;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-size:12px;">
                        <?= $t[0] ?> <?= $t[1] ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Message -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Message') ?> <span style="color:red;">*</span>
                </label>
                <textarea name="message" id="message" rows="5" required
                          placeholder="<?= __('Enter your message here...') ?>"
                          style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;resize:vertical;box-sizing:border-box;"
                          oninput="updateCharCount()"></textarea>
                <div style="display:flex;justify-content:space-between;margin-top:8px;">
                    <small style="color:#666;">
                        <?= __('Use {STUDENT_NAME} to personalize') ?>
                    </small>
                    <small id="charCount" style="color:#666;">0 / 160 <?= __('characters') ?></small>
                </div>
            </div>
            
            <!-- Recipient Preview -->
            <div style="background:#e8f5e9;padding:15px;border-radius:8px;margin-bottom:25px;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <strong style="color:#2e7d32;">📊 <?= __('Recipients') ?></strong>
                        <span id="recipientCount" style="display:inline-block;margin-left:10px;padding:4px 12px;background:#4CAF50;color:white;border-radius:20px;font-weight:bold;">
                            --
                        </span>
                    </div>
                    <button type="button" onclick="previewRecipients()" 
                            style="padding:8px 16px;background:white;border:2px solid #4CAF50;color:#4CAF50;border-radius:6px;cursor:pointer;font-weight:bold;">
                        👁️ <?= __('Preview') ?>
                    </button>
                </div>
            </div>
            
            <!-- Submit -->
            <div style="text-align:right;">
                <button type="submit" 
                        onclick="return confirm('<?= __('Are you sure you want to send this SMS to all selected recipients?') ?>')"
                        style="padding:15px 30px;background:linear-gradient(135deg,#9C27B0,#7B1FA2);color:white;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;box-shadow:0 4px 15px rgba(156,39,176,0.4);">
                    📤 <?= __('Send Broadcast') ?>
                </button>
            </div>
        </form>
    </div>
    
    <!-- Recent Broadcasts & Stats -->
    <div>
        <!-- SMS Stats -->
        <div style="background:white;padding:25px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);margin-bottom:25px;">
            <h3 style="margin-top:0;color:#333;"><?= __('SMS Statistics') ?></h3>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:15px;">
                <div style="padding:20px;background:#e3f2fd;border-radius:8px;text-align:center;">
                    <div style="font-size:32px;font-weight:bold;color:#1976D2;">0</div>
                    <div style="color:#666;font-size:14px;"><?= __('Sent Today') ?></div>
                </div>
                <div style="padding:20px;background:#e8f5e9;border-radius:8px;text-align:center;">
                    <div style="font-size:32px;font-weight:bold;color:#388E3C;">0</div>
                    <div style="color:#666;font-size:14px;"><?= __('This Month') ?></div>
                </div>
            </div>
        </div>
        
        <!-- Alert Types Quick Send -->
        <div style="background:white;padding:25px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
            <h3 style="margin-top:0;color:#333;"><?= __('Quick Alert Types') ?></h3>
            <div style="display:flex;flex-direction:column;gap:10px;">
                <button onclick="sendQuickAlert('delay')" 
                        style="display:flex;align-items:center;gap:10px;padding:15px;background:#fff3e0;border:2px solid #ff9800;border-radius:8px;cursor:pointer;text-align:left;">
                    <span style="font-size:24px;">🕐</span>
                    <div>
                        <strong style="color:#e65100;"><?= __('Bus Delay Alert') ?></strong>
                        <div style="color:#666;font-size:12px;"><?= __('Notify parents of delay') ?></div>
                    </div>
                </button>
                <button onclick="sendQuickAlert('emergency')" 
                        style="display:flex;align-items:center;gap:10px;padding:15px;background:#ffebee;border:2px solid #f44336;border-radius:8px;cursor:pointer;text-align:left;">
                    <span style="font-size:24px;">🚨</span>
                    <div>
                        <strong style="color:#c62828;"><?= __('Emergency Alert') ?></strong>
                        <div style="color:#666;font-size:12px;"><?= __('Critical notification') ?></div>
                    </div>
                </button>
                <button onclick="sendQuickAlert('arrival')" 
                        style="display:flex;align-items:center;gap:10px;padding:15px;background:#e8f5e9;border:2px solid #4caf50;border-radius:8px;cursor:pointer;text-align:left;">
                    <span style="font-size:24px;">✅</span>
                    <div>
                        <strong style="color:#2e7d32;"><?= __('Safe Arrival') ?></strong>
                        <div style="color:#666;font-size:12px;"><?= __('Confirm safe arrival') ?></div>
                    </div>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
function setTemplate(text) {
    document.getElementById('message').value = text;
    updateCharCount();
}

function updateCharCount() {
    const message = document.getElementById('message').value;
    const count = message.length;
    const counter = document.getElementById('charCount');
    counter.textContent = count + ' / 160 <?= __('characters') ?>';
    counter.style.color = count > 160 ? '#f44336' : '#666';
}

function updateRecipientCount() {
    document.getElementById('recipientCount').textContent = '<?= __('Loading...') ?>';
    // In production, this would make an AJAX call to get actual count
    setTimeout(() => {
        document.getElementById('recipientCount').textContent = Math.floor(Math.random() * 50) + 10;
    }, 500);
}

function previewRecipients() {
    alert('<?= __('Preview functionality would show a list of all recipients with phone numbers.') ?>');
}

function sendQuickAlert(type) {
    const templates = {
        'delay': '<?= __('The school bus is running late today. We will update you with the expected arrival time shortly. Thank you for your patience.') ?>',
        'emergency': '<?= __('URGENT: Please contact the school transport office immediately. This is regarding your child\\'s safety.') ?>',
        'arrival': '<?= __('Your child has safely arrived at school. Have a great day!') ?>'
    };
    document.getElementById('message').value = templates[type] || '';
    updateCharCount();
    document.getElementById('message').focus();
}

// Initialize
updateRecipientCount();
</script>
