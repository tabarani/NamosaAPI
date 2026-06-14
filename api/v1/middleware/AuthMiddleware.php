<?php
/**
 * Authentication Middleware
 * Validates JWT tokens on protected endpoints
 */

namespace NamosaAPI\Middleware;

use NamosaAPI\Lib\JWT;
use NamosaAPI\Lib\Response;

class AuthMiddleware
{
    private $jwt;
    private $requiredScopes = [];
    
    public function __construct($secret, $tokenLifetime = 3600)
    {
        $this->jwt = new JWT($secret, $tokenLifetime);
    }
    
    /**
     * Handle authentication check
     */
    public function handle($requiredScopes = [])
    {
        $this->requiredScopes = $requiredScopes;
        
        // Get Authorization header
        $authHeader = $this->getAuthorizationHeader();
        
        if (!$authHeader) {
            Response::error('Authorization header missing', 401, 'UNAUTHORIZED');
        }
        
        // Extract token (Bearer <token>)
        if (!preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            Response::error('Invalid authorization format', 401, 'UNAUTHORIZED');
        }
        
        $token = $matches[1];
        
        // Validate token
        try {
            $payload = $this->jwt->validateToken($token);
        } catch (\Exception $e) {
            Response::error('Invalid or expired token', 401, 'UNAUTHORIZED', $e->getMessage());
        }
        
        // Check scopes if required
        if (!empty($this->requiredScopes)) {
            $tokenScopes = $payload['scopes'] ?? [];
            
            if (is_string($tokenScopes)) {
                $tokenScopes = json_decode($tokenScopes, true) ?: [];
            }
            
            if (!$this->hasRequiredScopes($tokenScopes)) {
                Response::error('Insufficient permissions', 403, 'FORBIDDEN');
            }
        }
        
        // Store payload in global for controllers to use
        $GLOBALS['api_authenticated_payload'] = $payload;
        $GLOBALS['api_authenticated_client_id'] = $payload['client_id'] ?? null;
        
        return $payload;
    }
    
    /**
     * Get Authorization header
     */
    private function getAuthorizationHeader()
    {
        // Check standard header
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }
        
        // Check Apache-specific header (if PHP is running as Apache module)
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        
        // Check for token in query parameter (fallback for testing)
        if (isset($_GET['token'])) {
            return 'Bearer ' . $_GET['token'];
        }
        
        return null;
    }
    
    /**
     * Check if token has required scopes
     */
    private function hasRequiredScopes($tokenScopes)
    {
        // Admin scope bypasses all checks
        if (in_array('admin', $tokenScopes)) {
            return true;
        }
        
        // Check each required scope
        foreach ($this->requiredScopes as $requiredScope) {
            if (!in_array($requiredScope, $tokenScopes)) {
                return false;
            }
        }
        
        return true;
    }
    
    /**
     * Get authenticated payload
     */
    public function getPayload()
    {
        return $GLOBALS['api_authenticated_payload'] ?? null;
    }
    
    /**
     * Get client ID from token
     */
    public function getClientId()
    {
        return $GLOBALS['api_authenticated_client_id'] ?? null;
    }
}