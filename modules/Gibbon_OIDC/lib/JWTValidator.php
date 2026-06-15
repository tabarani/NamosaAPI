<?php
/*
Gibbon OIDC & NamosaAPI - JWT Validator
Validates JWTs from external IdentityProvider using JWKS
*/

namespace Gibbon\Module\Gibbon_OIDC;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;

class JWTValidator
{
    private $jwksUrl;
    private $issuer;
    private $audience;
    private $cacheTime = 3600; // Cache keys for 1 hour

    public function __construct($jwksUrl, $issuer, $audience)
    {
        $this->jwksUrl = $jwksUrl;
        $this->issuer = $issuer;
        $this->audience = $audience;
    }

    /**
     * Validate JWT token and return payload
     * @param string $token
     * @return array|null Returns payload on success, null on failure
     */
    public function validate($token)
    {
        if (empty($token)) {
            return null;
        }

        try {
            // Get Public Keys from JWKS
            $keys = $this->getJWKSKeys();
            
            // Find the key used to sign this token
            $header = JWT::decodeHeader($token);
            $kid = $header->kid ?? null;
            
            if (!$kid || !isset($keys[$kid])) {
                throw new \Exception('Invalid key ID in token');
            }

            $publicKey = $keys[$kid];

            // Decode and Validate
            $payload = JWT::decode(
                $token,
                new Key($publicKey, 'RS256')
            );

            // Validate Issuer
            if ($this->issuer && ($payload->iss ?? '') !== $this->issuer) {
                throw new \Exception('Invalid issuer');
            }

            // Validate Audience
            if ($this->audience) {
                $aud = $payload->aud ?? '';
                if (is_array($aud)) {
                    if (!in_array($this->audience, $aud)) {
                        throw new \Exception('Invalid audience');
                    }
                } else {
                    if ($aud !== $this->audience) {
                        throw new \Exception('Invalid audience');
                    }
                }
            }

            return (array) $payload;

        } catch (ExpiredException $e) {
            error_log('JWT Expired: ' . $e->getMessage());
            return null;
        } catch (SignatureInvalidException $e) {
            error_log('JWT Signature Invalid: ' . $e->getMessage());
            return null;
        } catch (\Exception $e) {
            error_log('JWT Validation Failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch JWKS from IdP and cache locally
     */
    private function getJWKSKeys()
    {
        $cacheFile = sys_get_temp_dir() . '/gibbon_jwks_cache.json';
        
        // Check cache
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < $this->cacheTime)) {
            $cachedData = json_decode(file_get_contents($cacheFile), true);
            if ($cachedData) {
                return $this->parseJWKS($cachedData);
            }
        }

        // Fetch fresh keys
        $ch = curl_init($this->jwksUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Verify SSL
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            throw new \Exception('Failed to fetch JWKS from IdentityProvider');
        }

        $jwks = json_decode($response, true);
        
        // Save to cache
        file_put_contents($cacheFile, $response);

        return $this->parseJWKS($jwks);
    }

    /**
     * Convert JWK to PEM format for Firebase/JWT library
     */
    private function parseJWKS($jwks)
    {
        $keys = [];
        foreach ($jwks['keys'] as $key) {
            $pem = $this->jwkToPem($key);
            $keys[$key['kid']] = $pem;
        }
        return $keys;
    }

    /**
     * Convert JWK (JSON Web Key) to PEM format
     */
    private function jwkToPem($jwk)
    {
        if (!isset($jwk['kty']) || $jwk['kty'] !== 'RSA') {
            throw new \Exception('Unsupported key type');
        }

        // Use phpseclib3 if available (Standard in modern Gibbon)
        if (class_exists('phpseclib3\Crypt\RSA')) {
            $key = \phpseclib3\Crypt\RSA::loadFormat('JWK', $jwk);
            return $key->toString('PKCS8');
        }

        throw new \Exception('phpseclib3 required for JWK to PEM conversion. Please install.');
    }
}
