<?php
/**
 * Core Module - JWT Validator Library
 * 
 * Consolidated JWT validation using OpenSSL for RS256 tokens.
 * Supports JWKS caching with file locking to prevent race conditions.
 * Implements Circuit Breaker pattern for resilient JWKS fetching.
 * 
 * @package Gibbon\Module\Core
 */

namespace Gibbon\Module\Core;

class JWTValidator
{
    private $jwksUrl;
    private $issuer;
    private $audience;
    private $cacheDir;
    private $cacheExpiry = 3600; // 1 hour
    private $lockTimeout = 10;   // Lock timeout in seconds
    
    // Circuit Breaker configuration
    private $circuitBreakerFailureThreshold = 3;     // Failures before opening circuit
    private $circuitBreakerResetTimeout = 60;        // Seconds before trying again (half-open)
    private $circuitBreakerCacheExpiryGrace = 300;   // Grace period for expired cache (5 min)
    
    // Circuit Breaker state
    private $circuitStateFile;
    private $circuitState = 'closed';                // closed, open, half-open
    private $failureCount = 0;
    private $lastFailureTime = null;

    /**
     * Constructor
     * 
     * @param string $jwksUrl URL to fetch JWKS from Identity Provider
     * @param string $issuer Expected issuer claim
     * @param string $audience Expected audience claim
     * @param string|null $cacheDir Directory for caching JWKS keys (defaults to system temp)
     */
    public function __construct($jwksUrl, $issuer, $audience, $cacheDir = null)
    {
        $this->jwksUrl = rtrim($jwksUrl, '/');
        $this->issuer = rtrim($issuer, '/');
        $this->audience = $audience;
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/gibbon_jwt_jwks';
        
        // Circuit breaker state file
        $this->circuitStateFile = $this->cacheDir . '/circuit_breaker_state.json';
        
        // Ensure cache directory exists with proper permissions
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
        
        // Load circuit breaker state
        $this->loadCircuitBreakerState();
    }

    /**
     * Validate JWT token and return payload
     * 
     * @param string $token JWT token
     * @return array|null Decoded payload on success, null on failure
     */
    public function validate($token)
    {
        try {
            // Basic validation
            if (empty($token)) {
                throw new \Exception('Empty token');
            }

            // Split token into parts
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new \Exception('Invalid token format');
            }

            // Decode header
            $header = $this->base64UrlDecode($parts[0]);
            $headerData = json_decode($header, true);
            
            if (!$headerData) {
                throw new \Exception('Invalid header');
            }

            // Verify algorithm
            if (!isset($headerData['alg']) || $headerData['alg'] !== 'RS256') {
                throw new \Exception('Unsupported algorithm. Expected RS256.');
            }

            // Decode payload
            $payload = $this->base64UrlDecode($parts[1]);
            $payloadData = json_decode($payload, true);
            
            if (!$payloadData) {
                throw new \Exception('Invalid payload');
            }

            // Validate standard claims (issuer, audience, expiration, not before)
            $this->validateClaims($payloadData);

            // Get signing key from JWKS
            $kid = $headerData['kid'] ?? null;
            $publicKey = $this->getPublicKey($kid);

            // Verify signature using OpenSSL
            $message = $parts[0] . '.' . $parts[1];
            $signature = $this->base64UrlDecode($parts[2]);
            
            $valid = openssl_verify(
                $message,
                $signature,
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($valid !== 1) {
                $error = openssl_error_string();
                throw new \Exception('Invalid signature' . ($error ? ': ' . $error : ''));
            }

            return $payloadData;

        } catch (\Exception $e) {
            error_log('JWT Validation Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate token claims (issuer, audience, expiration, not before)
     * 
     * @param array $payload Decoded JWT payload
     * @throws \Exception If any claim validation fails
     */
    private function validateClaims($payload)
    {
        // Check issuer
        if (!isset($payload['iss'])) {
            throw new \Exception('Missing issuer claim');
        }
        if (rtrim($payload['iss'], '/') !== $this->issuer) {
            throw new \Exception('Invalid issuer');
        }

        // Check audience
        if (!isset($payload['aud'])) {
            throw new \Exception('Missing audience claim');
        }
        
        $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array($this->audience, $audiences)) {
            throw new \Exception('Invalid audience');
        }

        // Check expiration
        if (isset($payload['exp'])) {
            if (!is_numeric($payload['exp'])) {
                throw new \Exception('Invalid exp claim');
            }
            if ($payload['exp'] < time()) {
                throw new \Exception('Token expired');
            }
        }

        // Check not before
        if (isset($payload['nbf'])) {
            if (!is_numeric($payload['nbf'])) {
                throw new \Exception('Invalid nbf claim');
            }
            if ($payload['nbf'] > time()) {
                throw new \Exception('Token not yet valid');
            }
        }
    }

    /**
     * Get public key from JWKS (with caching, file locking, and circuit breaker)
     * 
     * @param string|null $kid Key ID from token header
     * @return string PEM formatted public key
     * @throws \\Exception If key not found or JWKS fetch fails
     */
    private function getPublicKey($kid)
    {
        if (!$kid) {
            throw new \Exception('Missing kid in token header');
        }

        // Cache file path - use hash to avoid special characters
        $cacheFile = $this->cacheDir . '/jwk_' . md5($kid) . '.pem';
        $lockFile = $cacheFile . '.lock';

        // Check circuit breaker state before attempting fetch
        $circuitState = $this->getCircuitBreakerState();
        
        if ($circuitState['state'] === 'open') {
            // Circuit is open - try to use cached key even if expired
            $cachedKey = $this->getCachedKeyWithGrace($cacheFile);
            if ($cachedKey) {
                error_log('[Circuit Breaker] Using cached key (grace period) for kid: ' . $kid);
                return $cachedKey;
            }
            
            // No valid cache available - fail fast
            throw new \Exception(
                'JWKS service unavailable (circuit open). Cached key not available.',
                503
            );
        }

        // Try cache first (with lock to prevent race conditions)
        if (file_exists($cacheFile)) {
            $lockResource = fopen($lockFile, 'c');
            if ($lockResource && flock($lockResource, LOCK_SH | LOCK_NB)) {
                // Shared lock acquired, read cache
                if ((time() - filemtime($cacheFile) < $this->cacheExpiry)) {
                    $key = file_get_contents($cacheFile);
                    flock($lockResource, LOCK_UN);
                    fclose($lockResource);
                    if ($key) {
                        return $key;
                    }
                }
                flock($lockResource, LOCK_UN);
                fclose($lockResource);
            } elseif ($lockResource) {
                fclose($lockResource);
            }
        }

        // Need to fetch/refresh - acquire exclusive lock
        $lockResource = fopen($lockFile, 'c');
        if (!$lockResource) {
            throw new \Exception('Failed to acquire lock for JWKS cache');
        }

        // Wait for exclusive lock with timeout
        $startTime = time();
        while (!flock($lockResource, LOCK_EX | LOCK_NB)) {
            if (time() - $startTime > $this->lockTimeout) {
                fclose($lockResource);
                throw new \Exception('Lock timeout while fetching JWKS');
            }
            usleep(100000); // 100ms
        }

        try {
            // Double-check cache after acquiring lock (another process may have updated it)
            if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheExpiry)) {
                $key = file_get_contents($cacheFile);
                if ($key) {
                    flock($lockResource, LOCK_UN);
                    fclose($lockResource);
                    return $key;
                }
            }

            // Fetch JWKS (with circuit breaker protection)
            $jwks = $this->fetchJWKSWithCircuitBreaker();
            
            // Find the key with matching kid
            foreach ($jwks['keys'] as $key) {
                if (($key['kid'] ?? '') === $kid) {
                    $pem = $this->jwkToPem($key);
                    
                    // Atomically write to cache
                    $tmpFile = $cacheFile . '.tmp.' . getmypid();
                    file_put_contents($tmpFile, $pem);
                    rename($tmpFile, $cacheFile);
                    
                    // Reset circuit breaker on success
                    $this->recordCircuitBreakerSuccess();
                    
                    flock($lockResource, LOCK_UN);
                    fclose($lockResource);
                    
                    return $pem;
                }
            }

            flock($lockResource, LOCK_UN);
            fclose($lockResource);
            
            throw new \Exception('Public key not found for kid: ' . $kid);

        } catch (\Exception $e) {
            flock($lockResource, LOCK_UN);
            fclose($lockResource);
            
            // Record failure for circuit breaker
            $this->recordCircuitBreakerFailure();
            
            throw $e;
        }
    }

    /**
     * Fetch JWKS from Identity Provider
     * 
     * @return array Decoded JWKS response
     * @throws \Exception If fetch fails
     */
    private function fetchJWKS()
    {
        $ch = curl_init($this->jwksUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'User-Agent: Gibbon-Core-JWTValidator/1.0'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('Failed to fetch JWKS: HTTP ' . $httpCode);
        }
        
        if (!$response) {
            throw new \Exception('Failed to fetch JWKS: ' . ($curlError ?: 'Empty response'));
        }

        $jwks = json_decode($response, true);
        if (!$jwks || !isset($jwks['keys'])) {
            throw new \Exception('Invalid JWKS response format');
        }

        return $jwks;
    }

    /**
     * Fetch JWKS with Circuit Breaker protection
     * 
     * Checks circuit breaker state before attempting fetch.
     * In half-open state, allows one test request through.
     * 
     * @return array Decoded JWKS response
     * @throws \Exception If fetch fails or circuit is open
     */
    private function fetchJWKSWithCircuitBreaker()
    {
        $circuitState = $this->getCircuitBreakerState();
        
        // If circuit is open, check if we should transition to half-open
        if ($circuitState['state'] === 'open') {
            $timeSinceFailure = time() - ($circuitState['last_failure_time'] ?? 0);
            
            if ($timeSinceFailure < $this->circuitBreakerResetTimeout) {
                // Still in timeout period - fail fast
                throw new \Exception(
                    'Circuit breaker is OPEN. JWKS service unavailable.',
                    503
                );
            }
            
            // Timeout elapsed - transition to half-open (will be handled by caller)
            error_log('[Circuit Breaker] Transitioning to HALF-OPEN state for JWKS fetch');
        }
        
        // Attempt fetch (circuit is closed or half-open)
        return $this->fetchJWKS();
    }

    /**
     * Load circuit breaker state from file
     */
    private function loadCircuitBreakerState()
    {
        if (file_exists($this->circuitStateFile)) {
            $data = json_decode(file_get_contents($this->circuitStateFile), true);
            if ($data) {
                $this->failureCount = $data['failure_count'] ?? 0;
                $this->lastFailureTime = $data['last_failure_time'] ?? null;
                $this->circuitState = $data['state'] ?? 'closed';
                
                // Auto-transition from open to half-open if timeout elapsed
                if ($this->circuitState === 'open' && $this->lastFailureTime) {
                    $timeSinceFailure = time() - $this->lastFailureTime;
                    if ($timeSinceFailure >= $this->circuitBreakerResetTimeout) {
                        $this->circuitState = 'half-open';
                        error_log('[Circuit Breaker] Auto-transitioned to HALF-OPEN state');
                    }
                }
            }
        }
    }

    /**
     * Get current circuit breaker state
     * 
     * @return array State information
     */
    private function getCircuitBreakerState()
    {
        return [
            'state' => $this->circuitState,
            'failure_count' => $this->failureCount,
            'last_failure_time' => $this->lastFailureTime,
        ];
    }

    /**
     * Record a successful JWKS fetch
     * Resets the circuit breaker to closed state
     */
    private function recordCircuitBreakerSuccess()
    {
        // Reset on success
        $this->failureCount = 0;
        $this->circuitState = 'closed';
        $this->saveCircuitBreakerState();
        
        error_log('[Circuit Breaker] Success recorded - circuit CLOSED');
    }

    /**
     * Record a failed JWKS fetch
     * Opens circuit after threshold failures
     */
    private function recordCircuitBreakerFailure()
    {
        $this->failureCount++;
        $this->lastFailureTime = time();
        
        // Check if we should open the circuit
        if ($this->failureCount >= $this->circuitBreakerFailureThreshold) {
            $this->circuitState = 'open';
            error_log(
                "[Circuit Breaker] Circuit OPENED after {$this->failureCount} failures"
            );
        } else {
            error_log(
                "[Circuit Breaker] Failure recorded ({$this->failureCount}/{$this->circuitBreakerFailureThreshold})"
            );
        }
        
        $this->saveCircuitBreakerState();
    }

    /**
     * Save circuit breaker state to file
     */
    private function saveCircuitBreakerState()
    {
        $data = [
            'state' => $this->circuitState,
            'failure_count' => $this->failureCount,
            'last_failure_time' => $this->lastFailureTime,
            'updated_at' => time(),
        ];
        
        // Atomic write using temp file
        $tmpFile = $this->circuitStateFile . '.tmp.' . getmypid();
        file_put_contents($tmpFile, json_encode($data, JSON_PRETTY_PRINT));
        rename($tmpFile, $this->circuitStateFile);
    }

    /**
     * Get cached key with grace period extension
     * 
     * Returns cached key even if expired, within the grace period.
     * Used when circuit is open to provide best-effort validation.
     * 
     * @param string $cacheFile Path to cached key file
     * @return string|null PEM key or null if not available
     */
    private function getCachedKeyWithGrace($cacheFile)
    {
        if (!file_exists($cacheFile)) {
            return null;
        }
        
        $age = time() - filemtime($cacheFile);
        $maxAge = $this->cacheExpiry + $this->circuitBreakerCacheExpiryGrace;
        
        if ($age > $maxAge) {
            // Cache too old even for grace period
            return null;
        }
        
        $key = file_get_contents($cacheFile);
        return $key ?: null;
    }

    /**
     * Manually reset circuit breaker (useful for testing or admin intervention)
     * 
     * @return void
     */
    public function resetCircuitBreaker()
    {
        $this->failureCount = 0;
        $this->circuitState = 'closed';
        $this->lastFailureTime = null;
        $this->saveCircuitBreakerState();
        
        error_log('[Circuit Breaker] Manually reset to CLOSED state');
    }

    /**
     * Get circuit breaker status for monitoring/debugging
     * 
     * @return array Status information
     */
    public function getCircuitBreakerStatus()
    {
        $this->loadCircuitBreakerState();
        
        return [
            'state' => $this->circuitState,
            'failure_count' => $this->failureCount,
            'failure_threshold' => $this->circuitBreakerFailureThreshold,
            'reset_timeout' => $this->circuitBreakerResetTimeout,
            'last_failure_time' => $this->lastFailureTime,
            'time_until_retry' => $this->lastFailureTime 
                ? max(0, $this->circuitBreakerResetTimeout - (time() - $this->lastFailureTime))
                : 0,
        ];
    }

    /**
     * Convert JWK (JSON Web Key) to PEM format
     * 
     * @param array $jwk JSON Web Key
     * @return string PEM formatted public key
     * @throws \Exception If conversion fails
     */
    private function jwkToPem($jwk)
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            throw new \Exception('Only RSA keys supported');
        }

        if (!isset($jwk['n']) || !isset($jwk['e'])) {
            throw new \Exception('Invalid JWK: missing modulus or exponent');
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        // Remove leading zero bytes if present
        $modulus = ltrim($modulus, "\x00");
        $exponent = ltrim($exponent, "\x00");

        // Construct ASN.1 DER sequence for RSA public key
        // SEQUENCE {
        //   SEQUENCE {
        //     OBJECT IDENTIFIER rsaEncryption (1 2 840 113549 1 1 1)
        //     NULL
        //   }
        //   BIT STRING {
        //     SEQUENCE {
        //       INTEGER modulus
        //       INTEGER exponent
        //     }
        //   }
        // }
        
        $modulusLen = strlen($modulus);
        $exponentLen = strlen($exponent);
        
        // Encode modulus as INTEGER
        if (ord($modulus[0]) & 0x80) {
            $modulus = "\x00" . $modulus;
            $modulusLen++;
        }
        $modulusEncoded = $this->encodeLength($modulusLen) . $modulus;
        
        // Encode exponent as INTEGER
        if (ord($exponent[0]) & 0x80) {
            $exponent = "\x00" . $exponent;
            $exponentLen++;
        }
        $exponentEncoded = $this->encodeLength($exponentLen) . $exponent;
        
        // RSAPublicKey SEQUENCE
        $rsaPublicKey = "\x02" . $modulusEncoded . "\x02" . $exponentEncoded;
        $rsaPublicKey = $this->encodeLength(strlen($rsaPublicKey)) . $rsaPublicKey;
        
        // AlgorithmIdentifier SEQUENCE
        $algorithmIdentifier = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";
        
        // SubjectPublicKeyInfo SEQUENCE
        $spki = $algorithmIdentifier . "\x03" . $this->encodeLength(strlen($rsaPublicKey) + 1) . "\x00" . $rsaPublicKey;
        $spki = $this->encodeLength(strlen($spki)) . $spki;
        
        $der = $spki;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . 
               chunk_split(base64_encode($der), 64, "\n") . 
               "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * Encode length for ASN.1 DER format
     * 
     * @param int $length Length to encode
     * @return string Encoded length
     */
    private function encodeLength($length)
    {
        if ($length < 128) {
            return chr($length);
        }
        
        $lengthBytes = '';
        while ($length > 0) {
            $lengthBytes = chr($length & 0xFF) . $lengthBytes;
            $length >>= 8;
        }
        
        return chr(0x80 | strlen($lengthBytes)) . $lengthBytes;
    }

    /**
     * Base64URL decode
     * 
     * @param string $data Base64URL encoded string
     * @return string Decoded binary data
     */
    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }

    /**
     * Clear JWKS cache (useful for testing or manual refresh)
     * 
     * @param string|null $kid Optional specific key ID to clear
     * @return bool Success status
     */
    public function clearCache($kid = null)
    {
        if ($kid) {
            $cacheFile = $this->cacheDir . '/jwk_' . md5($kid) . '.pem';
            $lockFile = $cacheFile . '.lock';
            return @unlink($cacheFile) && @unlink($lockFile);
        }
        
        // Clear all cached keys
        $files = glob($this->cacheDir . '/jwk_*.pem');
        $lockFiles = glob($this->cacheDir . '/jwk_*.pem.lock');
        
        $success = true;
        foreach ($files as $file) {
            $success = $success && @unlink($file);
        }
        foreach ($lockFiles as $file) {
            $success = $success && @unlink($file);
        }
        
        return $success;
    }
}
