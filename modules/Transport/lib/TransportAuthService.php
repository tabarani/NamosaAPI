<?php
/*
Gibbon: the flexible, open school platform
Copyright © 2010, Gibbon Foundation

Transport Module - Authentication Service
Supports both API Key (legacy) and JWT/OIDC (new) authentication
*/

namespace Gibbon\Module\Transport\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class TransportAuthService
{
    private $connection2;
    private $settings;
    
    public function __construct($connection2, $settings = [])
    {
        $this->connection2 = $connection2;
        $this->settings = $settings;
    }
    
    /**
     * Authenticate request using either API Key or JWT
     * @return array ['success' => bool, 'user' => array|null, 'method' => string, 'error' => string|null]
     */
    public function authenticate($headers, $getParams = [])
    {
        // Try JWT first (if enabled in settings)
        if ($this->settings['auth_jwt_enabled'] ?? false) {
            $jwtResult = $this->validateJWT($headers);
            if ($jwtResult['success']) {
                return $jwtResult;
            }
        }
        
        // Fallback to API Key
        return $this->validateAPIKey($headers, $getParams);
    }
    
    /**
     * Validate JWT token from IdentityProvider
     */
    private function validateJWT($headers)
    {
        $authHeader = $headers['Authorization'] ?? '';
        
        if (empty($authHeader) || !preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Missing Bearer token'];
        }
        
        $token = $matches[1];
        
        try {
            // Get JWKS from IdentityProvider
            $jwksUrl = $this->settings['idp_jwks_url'] ?? '';
            $issuer = $this->settings['idp_issuer'] ?? '';
            $audience = $this->settings['idp_audience'] ?? 'transport-api';
            $userIdClaim = $this->settings['idp_user_claim'] ?? 'sub';
            
            if (empty($jwksUrl) || empty($issuer)) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'JWT not configured'];
            }
            
            // Fetch JWKS keys
            $jwks = $this->fetchJWKS($jwksUrl);
            $publicKey = $this->findPublicKey($jwks, $token);
            
            if (!$publicKey) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Invalid token signature'];
            }
            
            // Decode and validate token
            $decoded = JWT::decode($token, new Key($publicKey, 'RS256'));
            
            // Validate issuer
            if (($decoded->iss ?? '') !== $issuer) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Invalid issuer'];
            }
            
            // Validate audience
            $aud = $decoded->aud ?? '';
            if (is_array($aud)) {
                if (!in_array($audience, $aud)) {
                    return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Invalid audience'];
                }
            } elseif ($aud !== $audience) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Invalid audience'];
            }
            
            // Extract user ID from token
            $gibbonPersonID = $decoded->$userIdClaim ?? null;
            
            // If claim is nested or different, try common alternatives
            if (!$gibbonPersonID && isset($decoded->sub)) {
                $gibbonPersonID = $decoded->sub;
            }
            
            if (!$gibbonPersonID) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'User ID not found in token'];
            }
            
            // Load user from Gibbon database
            $user = $this->loadGibbonUser($gibbonPersonID);
            
            if (!$user) {
                return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'User not found in Gibbon'];
            }
            
            return [
                'success' => true,
                'user' => $user,
                'method' => 'jwt',
                'tokenData' => $decoded,
                'error' => null
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'user' => null, 'method' => 'jwt', 'error' => 'Token validation failed: ' . $e->getMessage()];
        }
    }
    
    /**
     * Validate legacy API Key
     */
    private function validateAPIKey($headers, $getParams)
    {
        $apiKey = $headers['X-API-Key'] ?? $getParams['api_key'] ?? null;
        
        if (!$apiKey) {
            return ['success' => false, 'user' => null, 'method' => 'apikey', 'error' => 'Missing API key'];
        }
        
        try {
            $stmt = $this->connection2->prepare("
                SELECT k.*, p.gibbonPersonID, p.firstName, p.surname, p.email, p.status, p.role
                FROM gibbonTransportAPIKey k
                LEFT JOIN gibbonPerson p ON k.gibbonPersonID = p.gibbonPersonID
                WHERE k.apiKey = ? AND k.active = 1
            ");
            $stmt->execute([$apiKey]);
            $keyData = $stmt->fetch();
            
            if (!$keyData) {
                return ['success' => false, 'user' => null, 'method' => 'apikey', 'error' => 'Invalid API key'];
            }
            
            // Build user object from API key owner
            $user = [
                'gibbonPersonID' => $keyData['gibbonPersonID'],
                'firstName' => $keyData['firstName'],
                'surname' => $keyData['surname'],
                'email' => $keyData['email'],
                'status' => $keyData['status'],
                'role' => $keyData['role'] ?? 'API User',
                'apiKeyName' => $keyData['name'],
                'apiKeyPermissions' => json_decode($keyData['permissions'] ?? '[]', true)
            ];
            
            return [
                'success' => true,
                'user' => $user,
                'method' => 'apikey',
                'error' => null
            ];
            
        } catch (\Exception $e) {
            return ['success' => false, 'user' => null, 'method' => 'apikey', 'error' => 'Authentication error'];
        }
    }
    
    /**
     * Fetch JWKS from IdentityProvider with caching
     */
    private function fetchJWKS($url)
    {
        $cacheFile = sys_get_temp_dir() . '/transport_jwks_cache.json';
        $cacheTime = 3600; // Cache for 1 hour
        
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTime) {
            return json_decode(file_get_contents($cacheFile), true);
        }
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200 || !$response) {
            throw new \Exception('Failed to fetch JWKS');
        }
        
        $jwks = json_decode($response, true);
        
        // Cache the result
        file_put_contents($cacheFile, $response);
        
        return $jwks;
    }
    
    /**
     * Find matching public key for token
     */
    private function findPublicKey($jwks, $token)
    {
        // Extract kid from token header
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        $header = json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true);
        $kid = $header['kid'] ?? null;
        
        if (!$kid) {
            // If no kid, try first key
            if (!empty($jwks['keys'])) {
                $keyData = $jwks['keys'][0];
                return $this->convertJWKToPEM($keyData);
            }
            return null;
        }
        
        // Find matching key
        if (!empty($jwks['keys'])) {
            foreach ($jwks['keys'] as $keyData) {
                if (($keyData['kid'] ?? '') === $kid) {
                    return $this->convertJWKToPEM($keyData);
                }
            }
        }
        
        return null;
    }
    
    /**
     * Convert JWK to PEM format
     */
    private function convertJWKToPEM($jwk)
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            return null;
        }
        
        $modulus = $this->base64url_decode($jwk['n']);
        $exponent = $this->base64url_decode($jwk['e']);
        
        if (!$modulus || !$exponent) {
            return null;
        }
        
        // Create RSA public key
        $rsa = new \RSA();
        $rsa->loadKey([
            'e' => new \phpseclib3\Math\BigInteger($exponent, 256),
            'n' => new \phpseclib3\Math\BigInteger($modulus, 256)
        ]);
        
        return $rsa->getPublicKey();
    }
    
    /**
     * Load user from Gibbon database
     */
    private function loadGibbonUser($gibbonPersonID)
    {
        $stmt = $this->connection2->prepare("
            SELECT p.gibbonPersonID, p.firstName, p.surname, p.email, p.status, 
                   p.phone1, p.phone2, p.image_240, r.name as roleName
            FROM gibbonPerson p
            LEFT JOIN gibbonRole r ON p.gibbonRoleIDAll = r.gibbonRoleID
            WHERE p.gibbonPersonID = ?
        ");
        $stmt->execute([$gibbonPersonID]);
        $user = $stmt->fetch();
        
        if (!$user || $user['status'] !== 'Full') {
            return null;
        }
        
        // Load permissions
        $user['permissions'] = $this->loadUserPermissions($gibbonPersonID);
        
        return $user;
    }
    
    /**
     * Load user permissions from Gibbon
     */
    private function loadUserPermissions($gibbonPersonID)
    {
        $stmt = $this->connection2->prepare("
            SELECT DISTINCT ap.actionName
            FROM gibbonPermission p
            JOIN gibbonAction a ON p.gibbonActionID = a.gibbonActionID
            JOIN gibbonModule m ON a.gibbonModuleID = m.gibbonModuleID
            WHERE p.gibbonRoleID IN (
                SELECT gibbonRoleID FROM gibbonRoleCategory WHERE gibbonPersonID = ?
                UNION
                SELECT gibbonRoleIDAll FROM gibbonPerson WHERE gibbonPersonID = ?
            )
        ");
        $stmt->execute([$gibbonPersonID, $gibbonPersonID]);
        
        $permissions = [];
        while ($row = $stmt->fetch()) {
            $permissions[] = $row['actionName'];
        }
        
        return $permissions;
    }
    
    /**
     * Check if user has specific permission
     */
    public function hasPermission($user, $permission)
    {
        if (!$user || empty($user['permissions'])) {
            return false;
        }
        
        // Admin role has all permissions
        if (($user['roleName'] ?? '') === 'Admin') {
            return true;
        }
        
        return in_array($permission, $user['permissions']);
    }
    
    private function base64url_decode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
