<?php
/**
 * API Configuration Settings
 */

return [
    // API Settings
    'enabled' => true,
    'version' => '1.0.0',
    'debug' => false,
    
    // Authentication
    'jwt_secret' => getenv('JWT_SECRET') ?: 'change-this-secret-in-production',
    'token_lifetime' => 3600, // 1 hour
    'token_refresh_enabled' => true,
    
    // Rate Limiting
    'rate_limit_enabled' => true,
    'rate_limit_anonymous' => 100, // requests per hour
    'rate_limit_authenticated' => 1000, // requests per hour
    'rate_limit_premium' => 10000, // requests per hour
    
    // CORS
    'cors_enabled' => true,
    'cors_origins' => ['*'], // Or specific domains: ['https://app.yourschool.com']
    'cors_max_age' => 86400, // 24 hours
    
    // Logging
    'logging_enabled' => true,
    'log_level' => 'info', // debug, info, warning, error
    
    // Database
    'table_prefix' => 'gibbon',
    
    // Cache
    'cache_enabled' => true,
    'cache_ttl' => 300, // 5 minutes
    
    // SMS
    'sms_enabled' => true,
    'sms_provider' => 'africastalking', // africastalking, twilio, local
];