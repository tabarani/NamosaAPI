<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

Transport Module - Settings
*/

$page->title = __('Transport Settings');
$page->breadcrumbs->add(__('Transport Settings'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/settings.php')) {
    $page->addError(__('Access denied'));
    return;
}

use Gibbon\Forms\Form;
use Gibbon\Tables\DataTable;

// Handle form submission
if (isset($_POST['smsSubmit'])) {
    $smsProvider = $_POST['smsProvider'] ?? 'infobip';
    $smsApiKey = $_POST['smsApiKey'] ?? '';
    $smsApiSecret = $_POST['smsApiSecret'] ?? '';
    $smsBaseUrl = $_POST['smsBaseUrl'] ?? '';
    $smsSenderID = $_POST['smsSenderID'] ?? '';
    $smsEnabled = isset($_POST['smsEnabled']) ? 1 : 0;
    
    // Update settings in database
    $settings = [
        'Transport_smsProvider' => $smsProvider,
        'Transport_smsApiKey' => $smsApiKey,
        'Transport_smsApiSecret' => $smsApiSecret,
        'Transport_smsBaseUrl' => $smsBaseUrl,
        'Transport_smsSenderID' => $smsSenderID,
        'Transport_smsEnabled' => $smsEnabled
    ];
    
    $success = true;
    foreach ($settings as $scope => $value) {
        $parts = explode('_', $scope, 2);
        $scopeName = $parts[0];
        $settingName = $parts[1] ?? $scope;
        
        // Check if setting exists
        $check = $connection2->prepare("SELECT COUNT(*) FROM gibbonSetting WHERE scope = ? AND name = ?");
        $check->execute([$scopeName, $settingName]);
        
        if ($check->fetchColumn() > 0) {
            $stmt = $connection2->prepare("UPDATE gibbonSetting SET value = ? WHERE scope = ? AND name = ?");
            $result = $stmt->execute([$value, $scopeName, $settingName]);
        } else {
            $stmt = $connection2->prepare("INSERT INTO gibbonSetting (scope, name, value) VALUES (?, ?, ?)");
            $result = $stmt->execute([$scopeName, $settingName, $value]);
        }
        $success = $success && $result;
    }
    
    if ($success) {
        echo '<div class="success">' . __('SMS settings saved successfully!') . '</div>';
    } else {
        echo '<div class="error">' . __('Failed to save SMS settings.') . '</div>';
    }
}

// Get current settings
function getTransportSetting($connection2, $name, $default = '') {
    $stmt = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Transport' AND name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

$currentSettings = [
    'smsProvider' => getTransportSetting($connection2, 'smsProvider', 'infobip'),
    'smsApiKey' => getTransportSetting($connection2, 'smsApiKey', ''),
    'smsApiSecret' => getTransportSetting($connection2, 'smsApiSecret', ''),
    'smsBaseUrl' => getTransportSetting($connection2, 'smsBaseUrl', 'https://api.infobip.com'),
    'smsSenderID' => getTransportSetting($connection2, 'smsSenderID', ''),
    'smsEnabled' => getTransportSetting($connection2, 'smsEnabled', '0')
];

echo '<h1>' . __('Transport Settings') . '</h1>';

// Settings Navigation Tabs
echo '<div style="display:flex;gap:10px;margin-bottom:30px;border-bottom:2px solid #ddd;padding-bottom:10px;">';
$tabs = [
    ['settings.php', __('SMS Configuration'), '📱', true],
    ['api_keys_manage.php', __('API Key Management'), '🔑', false],
    ['sms_broadcast.php', __('SMS Broadcast'), '📢', false]
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

?>

<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);max-width:900px;">
    <h2 style="margin-top:0;color:#333;border-bottom:2px solid #2196F3;padding-bottom:15px;display:flex;align-items:center;gap:10px;">
        <span style="font-size:28px;">📱</span>
        <?= __('SMS Gateway Configuration') ?>
    </h2>
    
    <div style="background:#e3f2fd;padding:15px;border-radius:8px;margin-bottom:25px;">
        <strong style="color:#1565c0;">ℹ️ <?= __('About SMS Integration') ?></strong>
        <p style="margin:10px 0 0 0;color:#555;">
            <?= __('Configure your SMS gateway to enable real-time notifications for transport events, emergency alerts, and parent communication. Supports Infobip, Twilio, and custom providers.') ?>
        </p>
    </div>

    <form method="post" action="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/settings.php">
        <input type="hidden" name="smsSubmit" value="1">
        
        <!-- SMS Enabled Toggle -->
        <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:25px;border:2px solid #ddd;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
                <div>
                    <h3 style="margin:0;color:#333;"><?= __('Enable SMS Notifications') ?></h3>
                    <p style="margin:5px 0 0 0;color:#666;font-size:14px;"><?= __('Toggle to enable or disable all SMS features') ?></p>
                </div>
                <label style="position:relative;display:inline-block;width:60px;height:34px;">
                    <input type="checkbox" name="smsEnabled" value="1" <?= $currentSettings['smsEnabled'] ? 'checked' : '' ?> 
                           style="opacity:0;width:0;height:0;">
                    <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?= $currentSettings['smsEnabled'] ? '#4CAF50' : '#ccc' ?>;transition:.4s;border-radius:34px;">
                        <span style="position:absolute;content:'';height:26px;width:26px;left:4px;bottom:4px;background:white;transition:.4s;border-radius:50%;transform:<?= $currentSettings['smsEnabled'] ? 'translateX(26px)' : 'none' ?>;"></span>
                    </span>
                </label>
            </div>
        </div>

        <!-- SMS Provider Selection -->
        <div style="margin-bottom:25px;">
            <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                <?= __('SMS Provider') ?> <span style="color:red;">*</span>
            </label>
            <select name="smsProvider" id="smsProvider" 
                    style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:16px;background:white;"
                    onchange="updateProviderFields()">
                <option value="infobip" <?= $currentSettings['smsProvider'] === 'infobip' ? 'selected' : '' ?>>Infobip</option>
                <option value="twilio" <?= $currentSettings['smsProvider'] === 'twilio' ? 'selected' : '' ?>>Twilio</option>
                <option value="africastalking" <?= $currentSettings['smsProvider'] === 'africastalking' ? 'selected' : '' ?>>Africa's Talking</option>
                <option value="nexmo" <?= $currentSettings['smsProvider'] === 'nexmo' ? 'selected' : '' ?>>Vonage (Nexmo)</option>
                <option value="custom" <?= $currentSettings['smsProvider'] === 'custom' ? 'selected' : '' ?>><?= __('Custom Provider') ?></option>
            </select>
        </div>

        <!-- API Credentials Section -->
        <div style="background:#fff3e0;padding:25px;border-radius:8px;border:2px solid #ff9800;margin-bottom:25px;">
            <h3 style="margin-top:0;color:#e65100;display:flex;align-items:center;gap:10px;">
                <span>🔐</span>
                <?= __('API Credentials') ?>
            </h3>
            <p style="color:#795548;font-size:14px;margin-bottom:20px;">
                <?= __('These credentials are encrypted and stored securely. Never share your API keys.') ?>
            </p>
            
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
                <!-- API Key -->
                <div>
                    <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                        <span id="apiKeyLabel"><?= __('API Key') ?></span> <span style="color:red;">*</span>
                    </label>
                    <div style="position:relative;">
                        <input type="password" name="smsApiKey" id="smsApiKey" 
                               value="<?= htmlspecialchars($currentSettings['smsApiKey']) ?>"
                               placeholder="<?= __('Enter your API Key') ?>"
                               style="width:100%;padding:12px 45px 12px 12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        <button type="button" onclick="togglePassword('smsApiKey')" 
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;">
                            👁️
                        </button>
                    </div>
                </div>
                
                <!-- API Secret -->
                <div>
                    <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                        <span id="apiSecretLabel"><?= __('API Secret') ?></span> <span style="color:red;">*</span>
                    </label>
                    <div style="position:relative;">
                        <input type="password" name="smsApiSecret" id="smsApiSecret" 
                               value="<?= htmlspecialchars($currentSettings['smsApiSecret']) ?>"
                               placeholder="<?= __('Enter your API Secret') ?>"
                               style="width:100%;padding:12px 45px 12px 12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
                        <button type="button" onclick="togglePassword('smsApiSecret')" 
                                style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;">
                            👁️
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Additional Settings -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:25px;">
            <!-- Base URL -->
            <div>
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('API Base URL') ?>
                </label>
                <input type="url" name="smsBaseUrl" id="smsBaseUrl" 
                       value="<?= htmlspecialchars($currentSettings['smsBaseUrl']) ?>"
                       placeholder="https://api.infobip.com"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
                <small style="color:#666;display:block;margin-top:5px;">
                    <?= __('The base URL for API requests. Usually auto-configured per provider.') ?>
                </small>
            </div>
            
            <!-- Sender ID -->
            <div>
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Sender ID / From Number') ?>
                </label>
                <input type="text" name="smsSenderID" id="smsSenderID" 
                       value="<?= htmlspecialchars($currentSettings['smsSenderID']) ?>"
                       placeholder="<?= __('e.g., SchoolBus or +1234567890') ?>"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
                <small style="color:#666;display:block;margin-top:5px;">
                    <?= __('The sender name or phone number that will appear on SMS messages.') ?>
                </small>
            </div>
        </div>

        <!-- Test Connection Section -->
        <div style="background:#e8f5e9;padding:20px;border-radius:8px;margin-bottom:25px;border:2px solid #4caf50;">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:15px;">
                <div>
                    <h4 style="margin:0;color:#2e7d32;"><?= __('Test SMS Connection') ?></h4>
                    <p style="margin:5px 0 0 0;color:#555;font-size:14px;">
                        <?= __('Send a test message to verify your configuration') ?>
                    </p>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <input type="tel" id="testPhone" placeholder="+243 XXX XXX XXX" 
                           style="padding:10px;border:2px solid #4caf50;border-radius:6px;width:180px;">
                    <button type="button" onclick="sendTestSMS()" 
                            style="padding:10px 20px;background:#4caf50;color:white;border:none;border-radius:6px;cursor:pointer;font-weight:bold;">
                        📤 <?= __('Send Test') ?>
                    </button>
                </div>
            </div>
            <div id="testResult" style="margin-top:15px;display:none;"></div>
        </div>

        <!-- Submit Button -->
        <div style="text-align:right;padding-top:20px;border-top:2px solid #eee;">
            <button type="submit" 
                    style="padding:15px 40px;background:linear-gradient(135deg,#2196F3,#1976D2);color:white;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;box-shadow:0 4px 15px rgba(33,150,243,0.4);">
                💾 <?= __('Save SMS Settings') ?>
            </button>
        </div>
    </form>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === 'password' ? 'text' : 'password';
}

function updateProviderFields() {
    const provider = document.getElementById('smsProvider').value;
    const baseUrlField = document.getElementById('smsBaseUrl');
    const apiKeyLabel = document.getElementById('apiKeyLabel');
    const apiSecretLabel = document.getElementById('apiSecretLabel');
    
    const configs = {
        'infobip': {
            baseUrl: 'https://api.infobip.com',
            keyLabel: 'API Key',
            secretLabel: 'API Secret'
        },
        'twilio': {
            baseUrl: 'https://api.twilio.com',
            keyLabel: 'Account SID',
            secretLabel: 'Auth Token'
        },
        'africastalking': {
            baseUrl: 'https://api.africastalking.com',
            keyLabel: 'API Key',
            secretLabel: 'Username'
        },
        'nexmo': {
            baseUrl: 'https://rest.nexmo.com',
            keyLabel: 'API Key',
            secretLabel: 'API Secret'
        },
        'custom': {
            baseUrl: '',
            keyLabel: 'API Key',
            secretLabel: 'API Secret'
        }
    };
    
    const config = configs[provider] || configs['custom'];
    baseUrlField.value = config.baseUrl;
    baseUrlField.placeholder = config.baseUrl || 'Enter custom API URL';
    apiKeyLabel.textContent = config.keyLabel;
    apiSecretLabel.textContent = config.secretLabel;
}

function sendTestSMS() {
    const phone = document.getElementById('testPhone').value;
    const resultDiv = document.getElementById('testResult');
    
    if (!phone) {
        resultDiv.innerHTML = '<div style="padding:10px;background:#ffebee;color:#c62828;border-radius:6px;">Please enter a phone number</div>';
        resultDiv.style.display = 'block';
        return;
    }
    
    resultDiv.innerHTML = '<div style="padding:10px;background:#e3f2fd;color:#1565c0;border-radius:6px;">Sending test message...</div>';
    resultDiv.style.display = 'block';
    
    // AJAX call to test endpoint
    fetch('<?= $_SESSION[$guid]['absoluteURL'] ?>/modules/Transport/api/sms_test.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({phone: phone})
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            resultDiv.innerHTML = '<div style="padding:10px;background:#e8f5e9;color:#2e7d32;border-radius:6px;">✅ Test message sent successfully!</div>';
        } else {
            resultDiv.innerHTML = '<div style="padding:10px;background:#ffebee;color:#c62828;border-radius:6px;">❌ ' + (data.error || 'Failed to send test message') + '</div>';
        }
    })
    .catch(error => {
        resultDiv.innerHTML = '<div style="padding:10px;background:#ffebee;color:#c62828;border-radius:6px;">❌ Connection error: ' + error.message + '</div>';
    });
}
</script>
