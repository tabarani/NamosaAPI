<?php
/**
 * Authentication Controller
 * Handles token generation, validation, and revocation
 */

namespace NamosaAPI\Controllers;

use NamosaAPI\Lib\JWT;
use NamosaAPI\Lib\Response;
use NamosaAPI\Config\Database;

class AuthController extends BaseController
{
    private $jwt;
    private $db;
    
    public function __construct($secret, $tokenLifetime)
    {
        parent::__construct();
        $this->jwt = new JWT($secret, $tokenLifetime);
        $this->db = Database::getInstance()->getConnection();
    }
    
    /**
     * POST /auth/token
     * Generate access token
     */
    public function generateToken()
    {
        $input = $this->getJsonInput();
        
        $clientId = $input['client_id'] ?? '';
        $clientSecret = $input['client_secret'] ?? '';
        
        if (empty($clientId) || empty($clientSecret)) {
            Response::error('Client ID and secret are required', 400, 'VALIDATION_ERROR');
        }
        
        // Validate client credentials
        $client = $this->validateClient($clientId, $clientSecret);
        
        if (!$client) {
            Response::error('Invalid client credentials', 401, 'UNAUTHORIZED');
        }
        
        if ($client['active'] !== 'Y') {
            Response::error('Client is inactive', 403, 'FORBIDDEN');
        }
        
        // Decode scopes (stored as JSON)
        $scopes = json_decode($client['scopes'], true) ?: [];
        
        // Generate token
        $payload = [
            'client_id' => $client['client_id'],
            'client_name' => $client['name'],
            'scopes' => $scopes
        ];
        
        $token = $this->jwt->generateToken($payload);
        
        Response::success([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwt->getTokenRemainingTime($token),
            'scopes' => $scopes,
            'client' => [
                'id' => $client['client_id'],
                'name' => $client['name']
            ]
        ], 'Token generated successfully', 200);
    }
    
    /**
     * POST /auth/validate
     * Validate token without generating new one
     */
    public function validateToken()
    {
        $input = $this->getJsonInput();
        $token = $input['token'] ?? '';
        
        if (empty($token)) {
            Response::error('Token is required', 400, 'VALIDATION_ERROR');
        }
        
        try {
            $payload = $this->jwt->validateToken($token);
            Response::success([
                'valid' => true,
                'payload' => $payload,
                'expires_in' => $this->jwt->getTokenRemainingTime($token)
            ], 'Token is valid', 200);
        } catch (\Exception $e) {
            Response::success([
                'valid' => false,
                'error' => $e->getMessage()
            ], 'Token is invalid or expired', 200);
        }
    }
    
    /**
     * POST /auth/revoke
     * Revoke token
     */
    public function revokeToken()
    {
        $input = $this->getJsonInput();
        $token = $input['token'] ?? '';
        
        if (empty($token)) {
            Response::error('Token is required', 400, 'VALIDATION_ERROR');
        }
        
        try {
            $payload = $this->jwt->peekToken($token);
            
            if (!$payload || !isset($payload['jti'])) {
                Response::error('Invalid token format', 400, 'VALIDATION_ERROR');
            }
            
            $this->jwt->revokedToken($payload['jti'], 'revoked_by_client');
            
            Response::success([], 'Token revoked successfully', 200);
        } catch (\Exception $e) {
            Response::error('Failed to revoke token: ' . $e->getMessage(), 500, 'INTERNAL_ERROR');
        }
    }
    
    /**
     * Validate client credentials
     */
    private function validateClient($clientId, $clientSecret)
    {
        $stmt = $this->db->prepare("
            SELECT * FROM namosa_api_clients 
            WHERE client_id = :client_id 
            AND active = 'Y'
            LIMIT 1
        ");
        $stmt->execute([':client_id' => $clientId]);
        $client = $stmt->fetch();
        
        if (!$client) {
            return null;
        }
        
        // Verify client secret
        if (!password_verify($clientSecret, $client['client_secret_hash'])) {
            return null;
        }
        
        return $client;
    }
}