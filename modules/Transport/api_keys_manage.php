<?php
/*
Gibbon: the flexible, open school platform
Transport Module - API Key Management
*/

$page->title = __('API Key Management');
$page->breadcrumbs->add(__('Transport Settings'), 'settings.php');
$page->breadcrumbs->add(__('API Key Management'));

if (!isActionAccessible($guid, $connection2, '/modules/Transport/api_keys_manage.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Handle key deletion
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['keyID'])) {
    $keyID = intval($_GET['keyID']);
    $stmt = $connection2->prepare("UPDATE gibbonTransportAPIKey SET active = 0 WHERE apiKeyID = ?");
    if ($stmt->execute([$keyID])) {
        echo '<div class="success">' . __('API key deactivated successfully.') . '</div>';
    }
}

// Handle key regeneration
if (isset($_GET['action']) && $_GET['action'] === 'regenerate' && isset($_GET['keyID'])) {
    $keyID = intval($_GET['keyID']);
    $newKey = 'tk_' . bin2hex(random_bytes(32));
    $stmt = $connection2->prepare("UPDATE gibbonTransportAPIKey SET apiKey = ? WHERE apiKeyID = ?");
    if ($stmt->execute([$newKey, $keyID])) {
        echo '<div class="success">' . __('API key regenerated successfully.') . ' ' . __('New key:') . ' <code style="background:#f5f5f5;padding:5px 10px;border-radius:4px;">' . htmlspecialchars($newKey) . '</code></div>';
    }
}

echo '<h1>' . __('Transport Settings') . '</h1>';

// Settings Navigation Tabs
echo '<div style="display:flex;gap:10px;margin-bottom:30px;border-bottom:2px solid #ddd;padding-bottom:10px;">';
$tabs = [
    ['settings.php', __('SMS Configuration'), '📱', false],
    ['api_keys_manage.php', __('API Key Management'), '🔑', true],
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

<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:25px;border-bottom:2px solid #FF9800;padding-bottom:15px;">
        <h2 style="margin:0;color:#333;display:flex;align-items:center;gap:10px;">
            <span style="font-size:28px;">🔑</span>
            <?= __('API Key Management') ?>
        </h2>
        <a href="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Transport/api_keys_manage_add.php" 
           style="display:flex;align-items:center;gap:8px;padding:12px 24px;background:linear-gradient(135deg,#4CAF50,#388E3C);color:white;text-decoration:none;border-radius:8px;font-weight:bold;box-shadow:0 4px 15px rgba(76,175,80,0.4);">
            <span style="font-size:20px;">➕</span>
            <?= __('Generate New Key') ?>
        </a>
    </div>
    
    <div style="background:#e3f2fd;padding:15px;border-radius:8px;margin-bottom:25px;">
        <strong style="color:#1565c0;">ℹ️ <?= __('About API Keys') ?></strong>
        <p style="margin:10px 0 0 0;color:#555;">
            <?= __('API keys allow external applications (mobile apps, GPS trackers, etc.) to securely access the Transport API. Each key should be used for a single integration.') ?>
        </p>
    </div>

    <?php
    // Fetch all API keys
    $stmt = $connection2->query("
        SELECT k.*, p.firstName, p.surname 
        FROM gibbonTransportAPIKey k
        LEFT JOIN gibbonPerson p ON k.createdBy = p.gibbonPersonID
        ORDER BY k.timestampCreated DESC
    ");
    $keys = $stmt->fetchAll();
    
    if (empty($keys)) {
        echo '<div style="text-align:center;padding:60px 20px;background:#f5f5f5;border-radius:12px;">';
        echo '<div style="font-size:64px;margin-bottom:20px;">🔐</div>';
        echo '<h3 style="color:#666;margin:0 0 10px 0;">' . __('No API Keys Yet') . '</h3>';
        echo '<p style="color:#888;margin:0 0 20px 0;">' . __('Generate your first API key to enable external integrations.') . '</p>';
        echo '<a href="' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/Transport/api_keys_manage_add.php" 
               style="display:inline-flex;align-items:center;gap:8px;padding:12px 24px;background:#4CAF50;color:white;text-decoration:none;border-radius:8px;font-weight:bold;">';
        echo '<span>➕</span> ' . __('Generate First Key') . '</a>';
        echo '</div>';
    } else {
        echo '<table class="smallIntBorder" style="width:100%;border-collapse:separate;border-spacing:0 8px;">';
        echo '<thead>';
        echo '<tr style="background:#f8f9fa;">';
        echo '<th style="padding:15px;text-align:left;border-radius:8px 0 0 8px;">' . __('Name') . '</th>';
        echo '<th style="padding:15px;text-align:left;">' . __('API Key') . '</th>';
        echo '<th style="padding:15px;text-align:center;">' . __('Status') . '</th>';
        echo '<th style="padding:15px;text-align:left;">' . __('Last Used') . '</th>';
        echo '<th style="padding:15px;text-align:left;">' . __('Created By') . '</th>';
        echo '<th style="padding:15px;text-align:center;border-radius:0 8px 8px 0;">' . __('Actions') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($keys as $key) {
            $statusColor = $key['active'] ? '#4CAF50' : '#9e9e9e';
            $statusText = $key['active'] ? __('Active') : __('Inactive');
            $rowBg = $key['active'] ? 'white' : '#fafafa';
            
            echo '<tr style="background:' . $rowBg . ';box-shadow:0 2px 8px rgba(0,0,0,0.05);">';
            
            // Name
            echo '<td style="padding:15px;border-radius:8px 0 0 8px;">';
            echo '<strong style="color:#333;">' . htmlspecialchars($key['name']) . '</strong>';
            echo '</td>';
            
            // API Key (masked)
            echo '<td style="padding:15px;">';
            $maskedKey = substr($key['apiKey'], 0, 8) . '••••••••••••' . substr($key['apiKey'], -4);
            echo '<code style="background:#f5f5f5;padding:8px 12px;border-radius:6px;font-family:monospace;font-size:13px;">';
            echo htmlspecialchars($maskedKey);
            echo '</code>';
            echo '<button onclick="copyKey(\'' . htmlspecialchars($key['apiKey']) . '\')" 
                        style="margin-left:10px;background:none;border:none;cursor:pointer;font-size:16px;" title="' . __('Copy') . '">📋</button>';
            echo '</td>';
            
            // Status
            echo '<td style="padding:15px;text-align:center;">';
            echo '<span style="display:inline-flex;align-items:center;gap:5px;padding:6px 12px;background:' . $statusColor . '20;color:' . $statusColor . ';border-radius:20px;font-weight:bold;font-size:12px;">';
            echo '<span style="width:8px;height:8px;background:' . $statusColor . ';border-radius:50%;"></span>';
            echo $statusText;
            echo '</span>';
            echo '</td>';
            
            // Last Used
            echo '<td style="padding:15px;color:#666;">';
            echo $key['lastUsed'] ? date('M j, Y H:i', strtotime($key['lastUsed'])) : '<span style="color:#999;">' . __('Never') . '</span>';
            echo '</td>';
            
            // Created By
            echo '<td style="padding:15px;color:#666;">';
            echo $key['firstName'] ? htmlspecialchars($key['firstName'] . ' ' . $key['surname']) : '<span style="color:#999;">' . __('System') . '</span>';
            echo '<br><small style="color:#999;">' . date('M j, Y', strtotime($key['timestampCreated'])) . '</small>';
            echo '</td>';
            
            // Actions
            echo '<td style="padding:15px;text-align:center;border-radius:0 8px 8px 0;">';
            echo '<div style="display:flex;gap:8px;justify-content:center;">';
            
            if ($key['active']) {
                echo '<a href="?q=/modules/Transport/api_keys_manage.php&action=regenerate&keyID=' . $key['apiKeyID'] . '" 
                       onclick="return confirm(\'' . __('Are you sure you want to regenerate this key? The old key will stop working immediately.') . '\')"
                       style="padding:8px 12px;background:#FF9800;color:white;text-decoration:none;border-radius:6px;font-size:12px;font-weight:bold;"
                       title="' . __('Regenerate') . '">🔄</a>';
                echo '<a href="?q=/modules/Transport/api_keys_manage.php&action=delete&keyID=' . $key['apiKeyID'] . '" 
                       onclick="return confirm(\'' . __('Are you sure you want to deactivate this API key?') . '\')"
                       style="padding:8px 12px;background:#f44336;color:white;text-decoration:none;border-radius:6px;font-size:12px;font-weight:bold;"
                       title="' . __('Deactivate') . '">🚫</a>';
            } else {
                echo '<span style="color:#999;font-style:italic;font-size:12px;">' . __('Deactivated') . '</span>';
            }
            
            echo '</div>';
            echo '</td>';
            
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
    }
    ?>
</div>

<!-- API Documentation Section -->
<div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);margin-top:30px;">
    <h2 style="margin-top:0;color:#333;display:flex;align-items:center;gap:10px;">
        <span style="font-size:24px;">📖</span>
        <?= __('API Documentation') ?>
    </h2>
    
    <div style="background:#263238;color:#aed581;padding:20px;border-radius:8px;font-family:monospace;overflow-x:auto;">
        <div style="color:#81d4fa;margin-bottom:10px;">// <?= __('Authentication Header') ?></div>
        <div>Authorization: Bearer <span style="color:#ffcc80;">YOUR_API_KEY</span></div>
        <br>
        <div style="color:#81d4fa;margin-bottom:10px;">// <?= __('Example: Record Boarding Event') ?></div>
        <div style="color:#ce93d8;">POST</div> <span style="color:#fff;">/modules/Transport/api/events.php</span>
        <div style="margin-top:10px;">
<pre style="color:#aed581;margin:0;">{
  "routeID": 1,
  "studentID": 12345,
  "type": "pickup",
  "status": "OnTime",
  "latitude": -4.3175,
  "longitude": 15.3139
}</pre>
        </div>
    </div>
    
    <div style="margin-top:20px;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:15px;">
        <div style="padding:15px;background:#e3f2fd;border-radius:8px;">
            <strong style="color:#1565c0;">📍 Events API</strong>
            <p style="margin:5px 0 0 0;font-size:14px;color:#555;"><?= __('Record boarding/dropoff events') ?></p>
        </div>
        <div style="padding:15px;background:#fff3e0;border-radius:8px;">
            <strong style="color:#e65100;">🚨 Alerts API</strong>
            <p style="margin:5px 0 0 0;font-size:14px;color:#555;"><?= __('Create and manage safety alerts') ?></p>
        </div>
        <div style="padding:15px;background:#e8f5e9;border-radius:8px;">
            <strong style="color:#2e7d32;">🚌 Routes API</strong>
            <p style="margin:5px 0 0 0;font-size:14px;color:#555;"><?= __('Fetch route and stop data') ?></p>
        </div>
    </div>
</div>

<script>
function copyKey(key) {
    navigator.clipboard.writeText(key).then(() => {
        alert('<?= __('API Key copied to clipboard!') ?>');
    }).catch(err => {
        console.error('Failed to copy:', err);
    });
}
</script>
