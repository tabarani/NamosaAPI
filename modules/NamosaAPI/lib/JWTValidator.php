<?php
/**
 * NamosaAPI - JWT Validator Library
 * 
 * Validates JWT tokens issued by external IdentityProvider using JWKS (RS256)
 * Supports caching of JWKS keys for performance
 */

class JWTValidator
{
    private $jwksUrl;
    private $issuer;
    private $audience;
    private $cacheDir;
    private $cacheExpiry = 3600; // 1 hour

    public function __construct($jwksUrl, $issuer, $audience, $cacheDir = null)
    {
        $this->jwksUrl = rtrim($jwksUrl, '/');
        $this->issuer = rtrim($issuer, '/');
        $this->audience = $audience;
        $this->cacheDir = $cacheDir ?: sys_get_temp_dir() . '/namosa_jwks';
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Validate JWT token and return payload
     * 
     * @param string $token JWT token
     * @return array|null Decoded payload or null if invalid
     */
    public function validate($token)
    {
        try {
            // Split token
            $parts = explode('.', $token);
            if (count($parts) !== 3) {
                throw new Exception('Invalid token format');
            }

            $header = $this->base64UrlDecode($parts[0]);
            $payload = $this->base64UrlDecode($parts[1]);
            $signature = $parts[2];

            $headerData = json_decode($header, true);
            if (!$headerData || !isset($headerData['alg']) || $headerData['alg'] !== 'RS256') {
                throw new Exception('Unsupported algorithm. Expected RS256.');
            }

            $payloadData = json_decode($payload, true);
            if (!$payloadData) {
                throw new Exception('Invalid payload');
            }

            // Validate standard claims
            $this->validateClaims($payloadData);

            // Get signing key from JWKS
            $kid = $headerData['kid'] ?? null;
            $publicKey = $this->getPublicKey($kid);

            // Verify signature
            $message = $parts[0] . '.' . $parts[1];
            $valid = openssl_verify(
                $message,
                $this->base64UrlDecode($signature),
                $publicKey,
                OPENSSL_ALGO_SHA256
            );

            if ($valid !== 1) {
                throw new Exception('Invalid signature');
            }

            return $payloadData;

        } catch (Exception $e) {
            error_log('JWT Validation Error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Validate token claims (issuer, audience, expiration)
     */
    private function validateClaims($payload)
    {
        // Check issuer
        if (!isset($payload['iss']) || rtrim($payload['iss'], '/') !== $this->issuer) {
            throw new Exception('Invalid issuer');
        }

        // Check audience
        if (!isset($payload['aud'])) {
            throw new Exception('Missing audience claim');
        }
        
        $audiences = is_array($payload['aud']) ? $payload['aud'] : [$payload['aud']];
        if (!in_array($this->audience, $audiences)) {
            throw new Exception('Invalid audience');
        }

        // Check expiration
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            throw new Exception('Token expired');
        }

        // Check not before
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            throw new Exception('Token not yet valid');
        }
    }

    /**
     * Get public key from JWKS (with caching)
     */
    private function getPublicKey($kid)
    {
        if (!$kid) {
            throw new Exception('Missing kid in token header');
        }

        $cacheFile = $this->cacheDir . '/jwks_' . md5($kid) . '.key';
        
        // Try cache first
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheExpiry)) {
            $key = file_get_contents($cacheFile);
            if ($key) {
                return $key;
            }
        }

        // Fetch JWKS
        $jwks = $this->fetchJWKS();
        
        foreach ($jwks['keys'] as $key) {
            if (($key['kid'] ?? '') === $kid) {
                $pem = $this->jwkToPem($key);
                
                // Cache the key
                file_put_contents($cacheFile, $pem);
                
                return $pem;
            }
        }

        throw new Exception('Public key not found for kid: ' . $kid);
    }

    /**
     * Fetch JWKS from IdentityProvider
     */
    private function fetchJWKS()
    {
        $ch = curl_init($this->jwksUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new Exception('Failed to fetch JWKS');
        }

        $jwks = json_decode($response, true);
        if (!$jwks || !isset($jwks['keys'])) {
            throw new Exception('Invalid JWKS response');
        }

        return $jwks;
    }

    /**
     * Convert JWK to PEM format
     */
    private function jwkToPem($jwk)
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            throw new Exception('Only RSA keys supported');
        }

        $modulus = $this->base64UrlDecode($jwk['n']);
        $exponent = $this->base64UrlDecode($jwk['e']);

        // Construct ASN.1 DER sequence
        $modulus = ltrim($modulus, "\x00");
        $exponent = ltrim($exponent, "\x00");

        $sequence = "\x30\x81\x9f\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00\x03\x81\x8d\x00";
        $sequence .= "\x02\x81\x81\x00" . $modulus . "\x02\x03\x01\x00\x01";

        $der = $sequence;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . 
               chunk_split(base64_encode($der), 64, "\n") . 
               "-----END PUBLIC KEY-----\n";

        return $pem;
    }

    /**
     * Base64URL decode
     */
    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
