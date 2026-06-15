<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

CustomAuth Module - OIDC Login Entry Point
Redirects unauthenticated users to Identity Provider
*/

use Gibbon\Forms\Form;

// Load OIDC helper
require_once __DIR__ . '/src/OidcHelper.php';

// Check if already logged in
if (isset($_SESSION[$guid]['gibbonPersonID'])) {
    header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php');
    exit;
}

$page->title = __('Single Sign-On Login');
$page->breadcrumbs->add(__('SSO Login'));

// Load configuration
$config = include __DIR__ . '/config.php';

// Check if auto-redirect is enabled
if ($config['auto_redirect'] === true) {
    // Generate state and PKCE
    $state = bin2hex(random_bytes(32));
    $oidcHelper = new OidcHelper($connection2, $guid);
    $pkcePair = $oidcHelper->generatePkcePair();
    
    // Store in session for callback validation
    $_SESSION['oidc_state'] = $state;
    $_SESSION['oidc_code_verifier'] = $pkcePair['code_verifier'];
    
    // Build authorization URL and redirect
    $returnUrl = $_GET['returnUrl'] ?? '/index.php';
    $authorizeUrl = $oidcHelper->buildAuthorizeUrl($state, $pkcePair['code_challenge'], $returnUrl);
    
    header('Location: ' . $authorizeUrl);
    exit;
}

// Manual login mode - show button
echo '<div style="max-width:600px;margin:80px auto;padding:40px;background:white;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,0.1);text-align:center;">';

echo '<h1 style="color:#333;margin-bottom:10px;">' . __('Welcome') . '</h1>';
echo '<p style="color:#666;font-size:16px;margin-bottom:30px;">' . __('Sign in with your organization account') . '</p>';

echo '<div style="margin-bottom:30px;">';
echo '<img src="' . $_SESSION[$guid]['absoluteURL'] . '/themes/Default/img/logo.png" alt="Logo" style="max-width:200px;margin-bottom:20px;">';
echo '</div>';

// Generate state and PKCE for manual mode
$state = bin2hex(random_bytes(32));
$oidcHelper = new OidcHelper($connection2, $guid);
$pkcePair = $oidcHelper->generatePkcePair();

$_SESSION['oidc_state'] = $state;
$_SESSION['oidc_code_verifier'] = $pkcePair['code_verifier'];

$returnUrl = $_GET['returnUrl'] ?? '/index.php';
$authorizeUrl = $oidcHelper->buildAuthorizeUrl($state, $pkcePair['code_challenge'], $returnUrl);

echo '<a href="' . htmlspecialchars($authorizeUrl) . '" 
      style="display:inline-block;padding:15px 40px;background:linear-gradient(135deg,#2196F3,#1976D2);color:white;text-decoration:none;border-radius:8px;font-size:18px;font-weight:bold;box-shadow:0 4px 15px rgba(33,150,243,0.4);transition:transform 0.2s;">';
echo '🔐 ' . __('Sign in with SSO');
echo '</a>';

echo '<div style="margin-top:30px;padding-top:20px;border-top:1px solid #eee;color:#999;font-size:14px;">';
echo '<p>' . __('Powered by OpenIddict Single Sign-On') . '</p>';
echo '</div>';

echo '</div>';
