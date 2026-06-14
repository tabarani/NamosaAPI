<?php
/**
 * JWT Helper Class
 * Wrapper around firebase/php-jwt library
 */

namespace NamosaAPI\Lib;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;
use DateTime;

class JWT
{
    private $secret;
    private $algorithm = 'HS256';
    private $tokenLifetime;
    
    public function __construct($secret, $tokenLifetime = 3600)
    {
        $this->secret = $secret;
        $this->tokenLifetime = $tokenLifetime;
    }
    
    /**
     * Generate JWT token
     */
    public function generateToken($payload, $customClaims = [])
    {
        $issuedAt = time();
        $expire = $issuedAt + $this->tokenLifetime;
        
        $defaultClaims = [
            'iss' => 'namosa-api',
            'iat' => $issuedAt,
            'exp' => $expire,
            'jti' => $this->generateJTI() // Unique token ID
        ];
        
        $tokenPayload = array_merge($defaultClaims, $payload, $customClaims);
        
        return FirebaseJWT::encode($tokenPayload, $this->secret, $this->algorithm);
    }
    
    /**
     * Validate and decode JWT token
     */
    public function validateToken($token)
    {
        try {
            $decoded = FirebaseJWT::decode(
                $token,
                new Key($this->secret, $this->algorithm)
            );
            
            // Check if token was revoked
            if ($this->isTokenRevoked($decoded->jti)) {
                throw new \Exception('Token has been revoked');
            }
            
            return (array) $decoded;
            
        } catch (\Exception $e) {
            throw new \Exception('Invalid token: ' . $e->getMessage());
        }
    }
    
    /**
     * Get token payload without validation
     */
    public function peekToken($token)
    {
        try {
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid token format');
            }
            
            $payload = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', $parts[1]))), true);
            
            return $payload;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Check if token is revoked
     */
    private function isTokenRevoked($tokenId)
    {
        $db = \NamosaAPI\Config\Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            SELECT id FROM namosa_api_revoked_tokens 
            WHERE token_id = :token_id 
            AND revoked_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ");
        $stmt->execute([':token_id' => $tokenId]);
        
        return $stmt->fetch() !== false;
    }
    
    /**
     * Revoke token
     */
    public function revokeToken($tokenId, $reason = 'logout')
    {
        $db = \NamosaAPI\Config\Database::getInstance()->getConnection();
        
        $stmt = $db->prepare("
            INSERT INTO namosa_api_revoked_tokens (token_id, reason)
            VALUES (:token_id, :reason)
            ON DUPLICATE KEY UPDATE revoked_at = NOW()
        ");
        $stmt->execute([
            ':token_id' => $tokenId,
            ':reason' => $reason
        ]);
        
        return true;
    }
    
    /**
     * Generate unique token ID
     */
    private function generateJTI()
    {
        return bin2hex(random_bytes(16)) . '_' . time();
    }
    
    /**
     * Get remaining time before expiration
     */
    public function getTokenRemainingTime($token)
    {
        try {
            $payload = $this->peekToken($token);
            if (!$payload || !isset($payload['exp'])) {
                return 0;
            }
            
            return max(0, $payload['exp'] - time());
        } catch (\Exception $e) {
            return 0;
        }
    }
}