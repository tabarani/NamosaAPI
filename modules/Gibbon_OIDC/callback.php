<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

CustomAuth Module - OIDC Callback Handler
Processes authorization code and creates Gibbon session
*/

// Load OIDC helper
require_once __DIR__ . '/src/OidcHelper.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get authorization code and state from callback
$code = $_GET['code'] ?? null;
$state = $_GET['state'] ?? null;
$error = $_GET['error'] ?? null;
$errorDescription = $_GET['error_description'] ?? '';

// Check for errors from IdP
if ($error) {
    $_SESSION[$guid]['message'] = __('Authentication failed: ') . $error . ' - ' . $errorDescription;
    header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/CustomAuth/login.php');
    exit;
}

// Validate state
if (!$code || !$state || $state !== ($_SESSION['oidc_state'] ?? '')) {
    $_SESSION[$guid]['message'] = __('Invalid authentication response. Please try again.');
    header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/CustomAuth/login.php');
    exit;
}

try {
    // Initialize OIDC helper
    $oidcHelper = new OidcHelper($connection2, $guid);
    
    // Exchange code for tokens
    $codeVerifier = $_SESSION['oidc_code_verifier'] ?? '';
    $tokens = $oidcHelper->exchangeCodeForTokens($code, $codeVerifier);
    
    // Decode ID token
    $idToken = $tokens['id_token'] ?? null;
    if (!$idToken) {
        throw new Exception('Missing ID token in response');
    }
    
    $claims = $oidcHelper->decodeIdToken($idToken);
    
    // Optional: Fetch additional user info
    // $userInfo = $oidcHelper->getUserInfo($tokens['access_token']);
    
    // Create Gibbon session
    $person = $oidcHelper->createGibbonSession($claims);
    
    // Clear OIDC session variables
    unset($_SESSION['oidc_state']);
    unset($_SESSION['oidc_code_verifier']);
    
    // Log successful login
    error_log('OIDC Login successful for user: ' . $person['username'] . ' (ID: ' . $person['gibbonPersonID'] . ')');
    
    // Redirect to requested page or home
    $returnUrl = $_SESSION['oidc_return_url'] ?? '/index.php';
    unset($_SESSION['oidc_return_url']);
    
    // Ensure absolute URL
    if (strpos($returnUrl, 'http') !== 0) {
        $returnUrl = $_SESSION[$guid]['absoluteURL'] . $returnUrl;
    }
    
    header('Location: ' . $returnUrl);
    exit;
    
} catch (Exception $e) {
    error_log('OIDC Callback error: ' . $e->getMessage());
    
    $_SESSION[$guid]['message'] = __('Login failed: ') . $e->getMessage();
    
    header('Location: ' . $_SESSION[$guid]['absoluteURL'] . '/index.php?q=/modules/CustomAuth/login.php');
    exit;
}
