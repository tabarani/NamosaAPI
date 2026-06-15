<?php
/*
Gibbon: the flexible, open school platform
Founded by Ross Parker at ICHK Secondary. Built by Ross Parker, Sandra Kuipers and the Gibbon community (https://gibbonedu.org/about/)
Copyright © 2010, Gibbon Foundation
Gibbon™, Gibbon Education Ltd. (Hong Kong)

CustomAuth Module - OIDC Configuration
*/

// OIDC Provider Configuration
// These settings can be overridden via gibbonSetting table in settings.php

return [
    // Identity Provider base URL (your .NET OpenIddict server)
    'idp_base_url' => 'https://idp.example.com',
    
    // Authorization endpoint
    'authorize_endpoint' => '/connect/authorize',
    
    // Token endpoint
    'token_endpoint' => '/connect/token',
    
    // Userinfo endpoint
    'userinfo_endpoint' => '/connect/userinfo',
    
    // Logout endpoint
    'logout_endpoint' => '/connect/logout',
    
    // Client credentials (register this on your OpenIddict server)
    'client_id' => 'gibbon-sso',
    'client_secret' => 'your-client-secret-here',
    
    // Redirect URI (this file after authentication)
    'redirect_uri' => '/modules/Gibbon_OIDC/callback.php',
    
    // Scopes to request
    'scopes' => 'openid profile email gibbon_id moodle_id',
    
    // Response type
    'response_type' => 'code',
    
    // Use PKCE (Proof Key for Code Exchange) - recommended
    'use_pkce' => true,
    
    // Post-logout redirect URI
    'post_logout_redirect_uri' => '/index.php',
    
    // Session timeout (in seconds) - 0 means use Gibbon's default
    'session_timeout' => 0,
    
    // Auto-redirect unauthenticated users to IdP (true) or show login button (false)
    'auto_redirect' => true,
];
