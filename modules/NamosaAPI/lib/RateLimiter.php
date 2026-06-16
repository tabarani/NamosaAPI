<?php
/**
 * RateLimiter - API Request Throttling Middleware
 * 
 * Implements rate limiting using Redis (preferred), APCu, or File-based storage.
 * Protects against DoS and brute-force attacks by limiting requests per IP/API Key/User ID.
 * 
 * Usage:
 *   $rateLimiter = new RateLimiter();
 *   if (!$rateLimiter->allowRequest($identifier)) {
 *       http_response_code(429);
 *       header('Retry-After: ' . $rateLimiter->getRetryAfter());
 *       echo json_encode(['error' => 'Too many requests', 'retry_after' => $rateLimiter->getRetryAfter()]);
 *       exit;
 *   }
 */

namespace Gibbon\Module\NamosaAPI;

class RateLimiter
{
    // Default limits
    private const DEFAULT_LIMIT = 60;          // 60 requests per minute for generic IPs
    private const AUTHENTICATED_LIMIT = 500;   // 500 requests per minute for authenticated users/API keys
    private const WINDOW_SECONDS = 60;         // 1 minute window
    
    // Storage backends
    private const STORAGE_REDIS = 'redis';
    private const STORAGE_APCU = 'apcu';
    private const STORAGE_FILE = 'file';
    
    private $storageType;
    private $redis = null;
    private $fileDir;
    private $currentLimit;
    private $retryAfter = 0;
    
    /**
     * Constructor
     */
    public function __construct()
    {
        // Determine available storage backend
        $this->storageType = $this->detectStorage();
        
        // Initialize Redis if available
        if ($this->storageType === self::STORAGE_REDIS) {
            $this->initRedis();
        }
        
        // Set file directory for file-based storage
        if ($this->storageType === self::STORAGE_FILE) {
            $this->fileDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'namosa_ratelimiter';
            if (!is_dir($this->fileDir)) {
                @mkdir($this->fileDir, 0755, true);
            }
        }
    }
    
    /**
     * Detect best available storage backend
     * 
     * @return string Storage type
     */
    private function detectStorage()
    {
        // Prefer Redis
        if (extension_loaded('redis') && class_exists('Redis')) {
            return self::STORAGE_REDIS;
        }
        
        // Fall back to APCu
        if (extension_loaded('apcu') && ini_get('apc.enabled')) {
            return self::STORAGE_APCU;
        }
        
        // Ultimate fallback to file-based
        return self::STORAGE_FILE;
    }
    
    /**
     * Initialize Redis connection
     */
    private function initRedis()
    {
        try {
            $this->redis = new \Redis();
            
            // Try common Redis configurations
            $redisHost = getenv('REDIS_HOST') ?: '127.0.0.1';
            $redisPort = (int)(getenv('REDIS_PORT') ?: 6379);
            $redisPassword = getenv('REDIS_PASSWORD') ?: null;
            
            $this->redis->connect($redisHost, $redisPort, 2.5);
            
            if ($redisPassword) {
                $this->redis->auth($redisPassword);
            }
            
            // Test connection
            $this->redis->ping();
        } catch (\Exception $e) {
            // Redis unavailable, fall back to APCu or file
            $this->storageType = extension_loaded('apcu') && ini_get('apc.enabled') 
                ? self::STORAGE_APCU 
                : self::STORAGE_FILE;
            error_log('RateLimiter: Redis unavailable, falling back to ' . $this->storageType);
        }
    }
    
    /**
     * Check if request is allowed based on rate limits
     * 
     * @param string|null $identifier Unique identifier (IP address, user ID, or API key). If null, uses client IP.
     * @param bool $isAuthenticated Whether the request is authenticated (uses higher limit)
     * @return bool True if allowed, false if rate limited
     */
    public function allowRequest($identifier = null, $isAuthenticated = false)
    {
        // Get identifier from IP if not provided
        if ($identifier === null) {
            $identifier = $this->getClientIP();
        }
        
        // Set limit based on authentication status
        $this->currentLimit = $isAuthenticated ? self::AUTHENTICATED_LIMIT : self::DEFAULT_LIMIT;
        
        // Prefix identifier based on type
        $prefix = $isAuthenticated ? 'auth:' : 'ip:';
        $identifier = $prefix . $identifier;
        
        $now = time();
        $key = 'ratelimit:' . md5($identifier) . ':' . floor($now / self::WINDOW_SECONDS);
        
        switch ($this->storageType) {
            case self::STORAGE_REDIS:
                return $this->checkRedis($key);
            case self::STORAGE_APCU:
                return $this->checkApcu($key, $now);
            case self::STORAGE_FILE:
            default:
                return $this->checkFile($identifier, $now);
        }
    }
    
    /**
     * Check rate limit using Redis
     * 
     * @param string $key Cache key
     * @return bool True if allowed
     */
    private function checkRedis($key)
    {
        try {
            $count = (int)$this->redis->get($key);
            
            if ($count >= $this->currentLimit) {
                $ttl = $this->redis->ttl($key);
                $this->retryAfter = max(1, $ttl);
                return false;
            }
            
            $this->redis->incr($key);
            $this->redis->expire($key, self::WINDOW_SECONDS + 1);
            
            return true;
        } catch (\Exception $e) {
            error_log('RateLimiter Redis error: ' . $e->getMessage());
            // Fail open - allow request if Redis fails
            return true;
        }
    }
    
    /**
     * Check rate limit using APCu
     * 
     * @param string $key Cache key
     * @param int $now Current timestamp
     * @return bool True if allowed
     */
    private function checkApcu($key, $now)
    {
        $count = apcu_fetch($key, $success);
        
        if (!$success) {
            $count = 0;
        }
        
        if ($count >= $this->currentLimit) {
            // Calculate retry after from existing TTL
            $this->retryAfter = max(1, self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS));
            return false;
        }
        
        apcu_store($key, $count + 1, self::WINDOW_SECONDS + 1);
        
        return true;
    }
    
    /**
     * Check rate limit using file-based storage
     * 
     * @param string $identifier Client identifier
     * @param int $now Current timestamp
     * @return bool True if allowed
     */
    private function checkFile($identifier, $now)
    {
        $filename = $this->fileDir . DIRECTORY_SEPARATOR . md5($identifier) . '.dat';
        
        // Use file locking for atomic operations
        $lockFile = $filename . '.lock';
        $lockHandle = fopen($lockFile, 'c');
        
        if (!flock($lockHandle, LOCK_EX)) {
            fclose($lockHandle);
            // Fail open on lock failure
            return true;
        }
        
        try {
            $data = ['window' => floor($now / self::WINDOW_SECONDS), 'count' => 0];
            
            if (file_exists($filename)) {
                $content = file_get_contents($filename);
                $stored = @unserialize($content);
                if ($stored && isset($stored['window'])) {
                    $data = $stored;
                }
            }
            
            // Reset counter if we're in a new window
            $currentWindow = floor($now / self::WINDOW_SECONDS);
            if ($data['window'] !== $currentWindow) {
                $data = ['window' => $currentWindow, 'count' => 0];
            }
            
            if ($data['count'] >= $this->currentLimit) {
                $this->retryAfter = self::WINDOW_SECONDS - ($now % self::WINDOW_SECONDS);
                flock($lockHandle, LOCK_UN);
                fclose($lockHandle);
                return false;
            }
            
            $data['count']++;
            file_put_contents($filename, serialize($data));
            
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            
            return true;
        } catch (\Exception $e) {
            error_log('RateLimiter file error: ' . $e->getMessage());
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            // Fail open on error
            return true;
        }
    }
    
    /**
     * Get client IP address
     * 
     * @return string IP address
     */
    private function getClientIP()
    {
        $ip = '';
        
        // Check various headers for real IP (behind proxy/load balancer)
        $headers = [
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_FORWARDED_FOR',       // Common proxy header
            'HTTP_X_REAL_IP',             // Nginx proxy
            'HTTP_CLIENT_IP',             // Some proxies
            'REMOTE_ADDR'                 // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // X-Forwarded-For may contain multiple IPs
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                break;
            }
        }
        
        // Validate IP format
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '0.0.0.0';
        }
        
        return $ip;
    }
    
    /**
     * Get seconds until rate limit resets
     * 
     * @return int Seconds
     */
    public function getRetryAfter()
    {
        return max(1, $this->retryAfter);
    }
    
    /**
     * Get current rate limit
     * 
     * @return int Requests per window
     */
    public function getLimit()
    {
        return $this->currentLimit;
    }
    
    /**
     * Get storage backend in use
     * 
     * @return string Storage type
     */
    public function getStorageType()
    {
        return $this->storageType;
    }
    
    /**
     * Send rate limit exceeded response
     */
    public function sendRateLimitResponse()
    {
        http_response_code(429);
        header('Content-Type: application/json');
        header('Retry-After: ' . $this->getRetryAfter());
        header('X-RateLimit-Limit: ' . $this->getLimit());
        header('X-RateLimit-Reset: ' . (time() + $this->getRetryAfter()));
        
        echo json_encode([
            'error' => 'Too Many Requests',
            'message' => 'Rate limit exceeded. Please slow down.',
            'retry_after' => $this->getRetryAfter(),
            'limit' => $this->getLimit()
        ]);
    }
}
