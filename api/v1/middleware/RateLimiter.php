<?php
/**
 * Rate Limiter Middleware
 * Prevents API abuse by limiting requests per client
 */

namespace NamosaAPI\Middleware;

use NamosaAPI\Lib\Response;

class RateLimiter
{
    private $cache;
    private $limits;
    private $clientId;
    
    // Default limits
    private $defaultLimits = [
        'anonymous' => ['requests' => 100, 'window' => 3600],    // 100/hr
        'authenticated' => ['requests' => 1000, 'window' => 3600], // 1000/hr
        'premium' => ['requests' => 10000, 'window' => 3600]      // 10k/hr
    ];
    
    public function __construct($cache = null)
    {
        $this->cache = $cache;
        $this->limits = $this->defaultLimits;
    }
    
    /**
     * Check rate limit
     */
    public function check($clientId = 'anonymous', $tier = 'authenticated')
    {
        $this->clientId = $clientId;
        
        // Get limit configuration
        $limit = $this->limits[$tier] ?? $this->defaultLimits['authenticated'];
        
        $key = "rate_limit:{$clientId}";
        
        // Use Redis/Memcached if available, otherwise use file-based cache
        if ($this->cache) {
            return $this->checkWithCache($key, $limit);
        } else {
            return $this->checkWithFile($key, $limit);
        }
    }
    
    /**
     * Check with cache (Redis/Memcached)
     */
    private function checkWithCache($key, $limit)
    {
        $current = $this->cache->incr($key);
        
        if ($current === 1) {
            $this->cache->expire($key, $limit['window']);
        }
        
        if ($current > $limit['requests']) {
            $retryAfter = $limit['window'];
            Response::error(
                "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                429,
                'TOO_MANY_REQUESTS',
                ['retryAfter' => $retryAfter]
            );
        }
        
        return [
            'remaining' => $limit['requests'] - $current,
            'reset' => time() + $limit['window'],
            'limit' => $limit['requests']
        ];
    }
    
    /**
     * Check with file-based cache (fallback)
     */
    private function checkWithFile($key, $limit)
    {
        $cacheFile = sys_get_temp_dir() . '/' . md5($key) . '.ratelimit';
        
        // Read current count and timestamp
        $data = ['count' => 0, 'timestamp' => time()];
        
        if (file_exists($cacheFile)) {
            $data = json_decode(file_get_contents($cacheFile), true) ?: $data;
        }
        
        // Reset counter if window expired
        if (time() - $data['timestamp'] > $limit['window']) {
            $data['count'] = 0;
            $data['timestamp'] = time();
        }
        
        // Increment counter
        $data['count']++;
        
        // Check limit
        if ($data['count'] > $limit['requests']) {
            $retryAfter = $limit['window'] - (time() - $data['timestamp']);
            Response::error(
                "Rate limit exceeded. Try again in {$retryAfter} seconds.",
                429,
                'TOO_MANY_REQUESTS',
                ['retryAfter' => max(1, $retryAfter)]
            );
        }
        
        // Save data
        file_put_contents($cacheFile, json_encode($data));
        
        return [
            'remaining' => $limit['requests'] - $data['count'],
            'reset' => $data['timestamp'] + $limit['window'],
            'limit' => $limit['requests']
        ];
    }
    
    /**
     * Set custom limits
     */
    public function setLimits($tier, $requests, $window)
    {
        $this->limits[$tier] = [
            'requests' => $requests,
            'window' => $window
        ];
        return $this;
    }
    
    /**
     * Get current limits
     */
    public function getLimits($tier = null)
    {
        if ($tier) {
            return $this->limits[$tier] ?? null;
        }
        
        return $this->limits;
    }
    
    /**
     * Clear rate limit for client
     */
    public function clear($clientId)
    {
        $key = "rate_limit:{$clientId}";
        
        if ($this->cache) {
            $this->cache->del($key);
        } else {
            $cacheFile = sys_get_temp_dir() . '/' . md5($key) . '.ratelimit';
            if (file_exists($cacheFile)) {
                unlink($cacheFile);
            }
        }
    }
}