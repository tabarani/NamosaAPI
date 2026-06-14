<?php
/**
 * CORS Middleware
 * Handles Cross-Origin Resource Sharing headers
 */

namespace NamosaAPI\Middleware;

class CORS
{
    private $allowedOrigins;
    private $allowedMethods = ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'];
    private $allowedHeaders = ['Content-Type', 'Authorization', 'X-Requested-With'];
    private $maxAge = 86400; // 24 hours
    
    public function __construct($allowedOrigins = ['*'])
    {
        $this->allowedOrigins = $allowedOrigins;
    }
    
    /**
     * Handle CORS preflight and headers
     */
    public function handle()
    {
        // Get origin from request
        $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
        
        // Set Access-Control-Allow-Origin
        if ($origin && in_array($origin, $this->allowedOrigins) || in_array('*', $this->allowedOrigins)) {
            header("Access-Control-Allow-Origin: " . ($origin ?? '*'));
        }
        
        // Always allow credentials
        header('Access-Control-Allow-Credentials: true');
        
        // Set allowed methods
        header('Access-Control-Allow-Methods: ' . implode(', ', $this->allowedMethods));
        
        // Set allowed headers
        header('Access-Control-Allow-Headers: ' . implode(', ', $this->allowedHeaders));
        
        // Set max age
        header("Access-Control-Max-Age: {$this->maxAge}");
        
        // Handle preflight OPTIONS request
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }
    
    /**
     * Set custom allowed origins
     */
    public function setAllowedOrigins($origins)
    {
        $this->allowedOrigins = $origins;
        return $this;
    }
    
    /**
     * Add allowed origin
     */
    public function addAllowedOrigin($origin)
    {
        $this->allowedOrigins[] = $origin;
        return $this;
    }
    
    /**
     * Set custom allowed methods
     */
    public function setAllowedMethods($methods)
    {
        $this->allowedMethods = $methods;
        return $this;
    }
    
    /**
     * Set custom allowed headers
     */
    public function setAllowedHeaders($headers)
    {
        $this->allowedHeaders = $headers;
        return $this;
    }
}