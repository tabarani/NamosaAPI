<?php
/*
Gibbon: the flexible, open school platform
Transport Module - Add API Key
*/

$page->title = __('Generate New API Key');
$page->breadcrumbs->add(__('Transport Settings'), 'settings.php');
$page->breadcrumbs->add(__('API Key Management'), 'api_keys_manage.php');
$page->breadcrumbs->add(__('Generate New API Key'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/api_keys_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Handle form submission
if (isset($_POST['submit'])) {
    $name = trim($_POST['name'] ?? '');
    
    if (empty($name)) {
        echo '<div class="error">' . __('Please provide a name for the API key.') . '</div>';
    } else {
        // Generate secure API key
        $apiKey = 'tk_' . bin2hex(random_bytes(32));
        $createdBy = $_SESSION[$guid]['gibbonPersonID'] ?? null;
        
        $stmt = $connection2->prepare("
            INSERT INTO gibbonTransportAPIKey (name, apiKey, active, createdBy) 
            VALUES (?, ?, 1, ?)
        ");
        
        if ($stmt->execute([$name, $apiKey, $createdBy])) {
            ?>
            <div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);max-width:700px;margin:20px auto;">
                <div style="text-align:center;margin-bottom:30px;">
                    <div style="font-size:64px;margin-bottom:15px;">✅</div>
                    <h2 style="color:#4CAF50;margin:0;"><?= __('API Key Generated Successfully!') ?></h2>
                </div>
                
                <div style="background:#fff3e0;padding:20px;border-radius:8px;border:2px solid #ff9800;margin-bottom:25px;">
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
                        <span style="font-size:24px;">⚠️</span>
                        <strong style="color:#e65100;"><?= __('Important: Copy this key now!') ?></strong>
                    </div>
                    <p style="margin:0;color:#795548;font-size:14px;">
                        <?= __('This is the only time you will see the full API key. Please copy it and store it securely.') ?>
                    </p>
                </div>
                
                <div style="margin-bottom:25px;">
                    <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                        <?= __('Key Name') ?>
                    </label>
                    <div style="padding:12px;background:#f5f5f5;border-radius:8px;font-weight:bold;">
                        <?= htmlspecialchars($name) ?>
                    </div>
                </div>
                
                <div style="margin-bottom:25px;">
                    <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                        <?= __('API Key') ?>
                    </label>
                    <div style="display:flex;gap:10px;align-items:center;">
                        <code id="generatedKey" style="flex:1;padding:15px;background:#263238;color:#aed581;border-radius:8px;font-family:monospace;font-size:14px;word-break:break-all;">
                            <?= htmlspecialchars($apiKey) ?>
                        </code>
                        <button onclick="copyGeneratedKey()" 
                                style="padding:15px 20px;background:#4CAF50;color:white;border:none;border-radius:8px;cursor:pointer;font-weight:bold;white-space:nowrap;">
                            📋 <?= __('Copy') ?>
                        </button>
                    </div>
                </div>
                
                <div style="background:#e8f5e9;padding:15px;border-radius:8px;margin-bottom:25px;">
                    <strong style="color:#2e7d32;">💡 <?= __('Usage Example') ?></strong>
                    <div style="background:#263238;color:#aed581;padding:15px;border-radius:6px;margin-top:10px;font-family:monospace;overflow-x:auto;">
                        <div style="color:#81d4fa;"># <?= __('Include this header in API requests') ?></div>
                        <div>Authorization: Bearer <span style="color:#ffcc80;"><?= htmlspecialchars($apiKey) ?></span></div>
                    </div>
                </div>
                
                <div style="text-align:center;">
                    <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/api_keys_manage.php" 
                       style="display:inline-block;padding:12px 30px;background:#2196F3;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">
                        ← <?= __('Back to API Keys') ?>
                    </a>
                </div>
            </div>
            
            <script>
            function copyGeneratedKey() {
                const key = document.getElementById('generatedKey').textContent.trim();
                navigator.clipboard.writeText(key).then(() => {
                    alert('<?= __('API Key copied to clipboard!') ?>');
                });
            }
            </script>
            <?php
            return;
        } else {
            echo '<div class="error">' . __('Failed to create API key.') . '</div>';
        }
    }
}

echo '<h1>' . __('Generate New API Key') . '</h1>';

?>

<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);max-width:600px;">
    <div style="background:#e3f2fd;padding:15px;border-radius:8px;margin-bottom:25px;">
        <strong style="color:#1565c0;">ℹ️ <?= __('About API Keys') ?></strong>
        <p style="margin:10px 0 0 0;color:#555;">
            <?= __('API keys are used to authenticate external applications with the Transport API. Give each key a descriptive name to identify its purpose.') ?>
        </p>
    </div>
    
    <form method="post" action="">
        <input type="hidden" name="submit" value="1">
        
        <div style="margin-bottom:25px;">
            <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                <?= __('Key Name') ?> <span style="color:red;">*</span>
            </label>
            <input type="text" name="name" required
                   placeholder="<?= __('e.g., Mobile App - Production') ?>"
                   style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:16px;box-sizing:border-box;">
            <small style="color:#666;display:block;margin-top:5px;">
                <?= __('A descriptive name to identify this API key (e.g., "GPS Tracker", "Parent App", "Driver App")') ?>
            </small>
        </div>
        
        <div style="background:#fff3e0;padding:15px;border-radius:8px;margin-bottom:25px;">
            <strong style="color:#e65100;">🔐 <?= __('Security Notice') ?></strong>
            <ul style="margin:10px 0 0 0;padding-left:20px;color:#795548;">
                <li><?= __('The API key will be shown only once after creation') ?></li>
                <li><?= __('Store the key securely and never share it publicly') ?></li>
                <li><?= __('If compromised, regenerate the key immediately') ?></li>
            </ul>
        </div>
        
        <div style="display:flex;gap:15px;justify-content:flex-end;">
            <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/api_keys_manage.php" 
               style="padding:12px 24px;background:#9e9e9e;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">
                <?= __('Cancel') ?>
            </a>
            <button type="submit" 
                    style="padding:12px 24px;background:linear-gradient(135deg,#4CAF50,#388E3C);color:white;border:none;border-radius:8px;cursor:pointer;font-weight:bold;box-shadow:0 4px 15px rgba(76,175,80,0.4);">
                🔑 <?= __('Generate API Key') ?>
            </button>
        </div>
    </form>
</div>
