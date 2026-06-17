<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

Gibbon OIDC Module - OIDC Settings
*/

$page->title = __('OIDC Settings');
$page->breadcrumbs->add(__('OIDC Settings'));

if (!isActionAccessible($guid, $connection2, '/modules/Gibbon_OIDC/settings.php')) {
    $page->addError(__('Access denied'));
    return;
}

// Handle form submission
if (isset($_POST['submit'])) {
    $settings = [
        'idp_base_url' => $_POST['idp_base_url'] ?? '',
        'client_id' => $_POST['client_id'] ?? '',
        'client_secret' => $_POST['client_secret'] ?? '',
        'scopes' => $_POST['scopes'] ?? 'openid profile email gibbon_id',
        'auto_redirect' => isset($_POST['auto_redirect']) ? 'Y' : 'N',
        'redirect_uri' => $_POST['redirect_uri'] ?? '/modules/Gibbon_OIDC/callback.php',
        'post_logout_redirect_uri' => $_POST['post_logout_redirect_uri'] ?? '/index.php',
    ];
    
    $success = true;
    foreach ($settings as $name => $value) {
        // Check if setting exists
        $check = $connection2->prepare("SELECT COUNT(*) FROM gibbonSetting WHERE scope = 'Gibbon OIDC' AND name = ?");
        $check->execute([$name]);
        
        if ($check->fetchColumn() > 0) {
            $stmt = $connection2->prepare("UPDATE gibbonSetting SET value = ? WHERE scope = 'Gibbon OIDC' AND name = ?");
            $result = $stmt->execute([$value, $name]);
        } else {
            $stmt = $connection2->prepare("INSERT INTO gibbonSetting (scope, name, value, description) VALUES ('Gibbon OIDC', ?, ?, 'Gibbon OIDC setting')");
            $result = $stmt->execute([$name, $value]);
        }
        $success = $success && $result;
    }
    
    if ($success) {
        echo '<div class="success">' . __('OIDC settings saved successfully!') . '</div>';
    } else {
        echo '<div class="error">' . __('Failed to save OIDC settings.') . '</div>';
    }
}

// Helper function to get setting
function getGibbonOIDCSetting($connection2, $name, $default = '')
{
    $stmt = $connection2->prepare("SELECT value FROM gibbonSetting WHERE scope = 'Gibbon OIDC' AND name = ?");
    $stmt->execute([$name]);
    $result = $stmt->fetch();
    return $result ? $result['value'] : $default;
}

// Load current settings
$currentSettings = [
    'idp_base_url' => getGibbonOIDCSetting($connection2, 'idp_base_url', 'https://idp.example.com'),
    'client_id' => getGibbonOIDCSetting($connection2, 'client_id', 'gibbon-sso'),
    'client_secret' => getGibbonOIDCSetting($connection2, 'client_secret', ''),
    'scopes' => getGibbonOIDCSetting($connection2, 'scopes', 'openid profile email gibbon_id'),
    'auto_redirect' => getGibbonOIDCSetting($connection2, 'auto_redirect', 'Y'),
    'redirect_uri' => getGibbonOIDCSetting($connection2, 'redirect_uri', '/modules/Gibbon_OIDC/callback.php'),
    'post_logout_redirect_uri' => getGibbonOIDCSetting($connection2, 'post_logout_redirect_uri', '/index.php'),
];

?>

<div style="max-width:900px;margin:0 auto;">
    <h1><?= __('OIDC Single Sign-On Configuration') ?></h1>
    
    <div style="background:#e3f2fd;padding:15px;border-radius:8px;margin-bottom:25px;">
        <strong style="color:#1565c0;">ℹ️ <?= __('About OIDC Integration') ?></strong>
        <p style="margin:10px 0 0 0;color:#555;">
            <?= __('Configure the connection to your OpenIddict Identity Provider. After setup, users will be redirected to the SSO portal for authentication and automatically logged into Gibbon.') ?>
        </p>
    </div>

    <div style="background:white;padding:30px;border-radius:12px;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
        <form method="post" action="<?= $_SESSION[$guid]['absoluteURL'] ?>/index.php?q=/modules/Gibbon_OIDC/settings.php">
            <input type="hidden" name="submit" value="1">
            
            <!-- Identity Provider URL -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Identity Provider Base URL') ?> <span style="color:red;">*</span>
                </label>
                <input type="url" name="idp_base_url" value="<?= htmlspecialchars($currentSettings['idp_base_url']) ?>"
                       placeholder="https://idp.example.com"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;"
                       required>
                <small style="color:#666;display:block;margin-top:5px;">
                    <?= __('The base URL of your OpenIddict server (e.g., https://idp.example.com)') ?>
                </small>
            </div>

            <!-- Client ID -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Client ID') ?> <span style="color:red;">*</span>
                </label>
                <input type="text" name="client_id" value="<?= htmlspecialchars($currentSettings['client_id']) ?>"
                       placeholder="gibbon-sso"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;"
                       required>
            </div>

            <!-- Client Secret -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Client Secret') ?> <span style="color:red;">*</span>
                </label>
                <div style="position:relative;">
                    <input type="password" name="client_secret" id="client_secret" 
                           value="<?= htmlspecialchars($currentSettings['client_secret']) ?>"
                           placeholder="your-client-secret"
                           style="width:100%;padding:12px 45px 12px 12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;"
                           required>
                    <button type="button" onclick="togglePassword('client_secret')" 
                            style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:18px;">
                        👁️
                    </button>
                </div>
            </div>

            <!-- Scopes -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Scopes') ?> <span style="color:red;">*</span>
                </label>
                <input type="text" name="scopes" value="<?= htmlspecialchars($currentSettings['scopes']) ?>"
                       placeholder="openid profile email gibbon_id moodle_id"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;"
                       required>
                <small style="color:#666;display:block;margin-top:5px;">
                    <?= __('Space-separated list of OAuth2 scopes. Must include "openid" and "gibbon_id"') ?>
                </small>
            </div>

            <!-- Redirect URI -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Redirect URI') ?>
                </label>
                <input type="text" name="redirect_uri" value="<?= htmlspecialchars($currentSettings['redirect_uri']) ?>"
                       placeholder="/modules/Gibbon_OIDC/callback.php"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
                <small style="color:#666;display:block;margin-top:5px;">
                    <?= __('Must match exactly with the redirect URI registered on your OpenIddict server') ?>
                </small>
            </div>

            <!-- Post Logout Redirect URI -->
            <div style="margin-bottom:25px;">
                <label style="display:block;font-weight:bold;margin-bottom:8px;color:#333;">
                    <?= __('Post-Logout Redirect URI') ?>
                </label>
                <input type="text" name="post_logout_redirect_uri" value="<?= htmlspecialchars($currentSettings['post_logout_redirect_uri']) ?>"
                       placeholder="/index.php"
                       style="width:100%;padding:12px;border:2px solid #ddd;border-radius:8px;font-size:14px;box-sizing:border-box;">
            </div>

            <!-- Auto Redirect Toggle -->
            <div style="background:#f8f9fa;padding:20px;border-radius:8px;margin-bottom:25px;border:2px solid #ddd;">
                <div style="display:flex;align-items:center;justify-content:space-between;">
                    <div>
                        <h3 style="margin:0;color:#333;"><?= __('Auto-Redirect to SSO') ?></h3>
                        <p style="margin:5px 0 0 0;color:#666;font-size:14px;">
                            <?= __('Automatically redirect unauthenticated users to the Identity Provider (disable to show login button)') ?>
                        </p>
                    </div>
                    <label style="position:relative;display:inline-block;width:60px;height:34px;">
                        <input type="checkbox" name="auto_redirect" value="Y" <?= $currentSettings['auto_redirect'] === 'Y' ? 'checked' : '' ?> 
                               style="opacity:0;width:0;height:0;">
                        <span style="position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:<?= $currentSettings['auto_redirect'] === 'Y' ? '#4CAF50' : '#ccc' ?>;transition:.4s;border-radius:34px;">
                            <span style="position:absolute;content:'';height:26px;width:26px;left:4px;bottom:4px;background:white;transition:.4s;border-radius:50%;transform:<?= $currentSettings['auto_redirect'] === 'Y' ? 'translateX(26px)' : 'none' ?>;"></span>
                        </span>
                    </label>
                </div>
            </div>

            <!-- Submit Button -->
            <div style="text-align:right;padding-top:20px;border-top:2px solid #eee;">
                <button type="submit" 
                        style="padding:15px 40px;background:linear-gradient(135deg,#2196F3,#1976D2);color:white;border:none;border-radius:8px;cursor:pointer;font-size:16px;font-weight:bold;box-shadow:0 4px 15px rgba(33,150,243,0.4);">
                    💾 <?= __('Save OIDC Settings') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Integration Guide -->
   <div style="background:#fff3e0;padding:25px;border-radius:8px;margin-top:30px;border:2px solid #ff9800;">
    <h3 style="margin-top:0;color:#e65100;display:flex;align-items:center;gap:10px;">
        <span>📖</span>
        <?= __('OpenIddict Server Setup') ?>
    </h3>
    <ol style="color:#795548;line-height:1.8;">
        <li><?= __('Register a new client on your OpenIddict server') ?></li>
        <li><?= __('Set client_id to: <code>') . htmlspecialchars($currentSettings['client_id']) . '</code>' ?></li>
        <li><?= __('Set redirect_uri to: <code>') . $_SESSION[$guid]['absoluteURL'] . htmlspecialchars($currentSettings['redirect_uri']) . '</code>' ?></li>
        <li><?= __('Enable Authorization Code flow with PKCE') ?></li>
        <li><?= __('Allow scopes: <code>openid profile email gibbon_id moodle_id</code>') ?></li>
        <li><?= __('Ensure the ID token includes the <code>gibbon_id</code> claim') ?></li>
        <li><?= __('Configure the UserMap entity in your .NET application to link users') ?></li>
    </ol>
</div>
</div>

<script>
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    field.type = field.type === 'password' ? 'text' : 'password';
}
</script>
