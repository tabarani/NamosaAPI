<?php
/**
 * Authentication Service
 * Handles user authentication and token management
 */

namespace NamosaAPI\Services;

use NamosaAPI\Lib\JWT;
use NamosaAPI\Repositories\UserRepository;

class AuthService
{
    private $jwt;
    private $userRepository;
    
    public function __construct($secret, $tokenLifetime = 3600)
    {
        $this->jwt = new JWT($secret, $tokenLifetime);
        $this->userRepository = new UserRepository();
    }
    
    /**
     * Authenticate user and generate token
     */
    public function login($username, $password)
    {
        // Authenticate user
        $user = $this->userRepository->authenticate($username, $password);
        
        if (!$user) {
            throw new \Exception('Invalid credentials');
        }
        
        // Generate JWT token
        $payload = [
            'userId' => $user['id'],
            'username' => $user['username'],
            'role' => $user['roleCategory'],
            'roles' => array_column($user['roles'] ?? [], 'name')
        ];
        
        $token = $this->jwt->generateToken($payload);
        
        return [
            'token' => $token,
            'user' => $user,
            'expiresIn' => $this->jwt->getTokenRemainingTime($token)
        ];
    }
    
    /**
     * Validate token
     */
    public function validateToken($token)
    {
        try {
            $payload = $this->jwt->validateToken($token);
            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Revoke token
     */
    public function logout($token)
    {
        $payload = $this->jwt->peekToken($token);
        
        if (!$payload || !isset($payload['jti'])) {
            throw new \Exception('Invalid token');
        }
        
        $this->jwt->revokeToken($payload['jti'], 'logout');
        
        return true;
    }
    
    /**
     * Refresh token
     */
    public function refreshToken($token)
    {
        $payload = $this->validateToken($token);
        
        if (!$payload) {
            throw new \Exception('Invalid or expired token');
        }
        
        // Generate new token with same payload
        $newToken = $this->jwt->generateToken($payload);
        
        // Revoke old token
        $this->jwt->revokeToken($payload['jti'], 'refreshed');
        
        return [
            'token' => $newToken,
            'expiresIn' => $this->jwt->getTokenRemainingTime($newToken)
        ];
    }
    
    /**
     * Get current user from token
     */
    public function getCurrentUser($token)
    {
        $payload = $this->validateToken($token);
        
        if (!$payload || !isset($payload['userId'])) {
            return null;
        }
        
        return $this->userRepository->getById($payload['userId']);
    }
}