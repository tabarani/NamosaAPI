<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

CustomAuth Module - Logout Handler
Calls Identity Provider end-session endpoint
*/

// Load OIDC helper
require_once __DIR__ . '/src/OidcHelper.php';

// Check if user is logged in
if (!isset($_SESSION[$guid]['gibbonPersonID'])) {
    header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php');
    exit;
}

// Get ID token hint from session (if stored during login)
$idTokenHint = $_SESSION['oidc_id_token'] ?? null;

// Build logout URL
$oidcHelper = new OidcHelper($connection2, $guid);
$logoutUrl = $oidcHelper->buildLogoutUrl($idTokenHint);

// Clear Gibbon session
session_destroy();

// Redirect to IdP logout (which will redirect back to Gibbon)
header('Location: ' . $logoutUrl);
exit;
