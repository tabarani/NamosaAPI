<?php
/**
 * Logger Class
 * Handles API request logging and auditing
 */

namespace NamosaAPI\Lib;

use NamosaAPI\Config\Database;

class Logger
{
    private $db;
    private $enabled;
    
    public function __construct($enabled = true)
    {
        $this->enabled = $enabled;
        
        if ($enabled) {
            $this->db = Database::getInstance()->getConnection();
        }
    }
    
    /**
     * Log API request
     */
    public function logRequest($endpoint, $method, $statusCode, $responseTime, $clientId = null)
    {
        if (!$this->enabled) {
            return;
        }
        
        $data = [
            'client_id' => $clientId,
            'endpoint' => $endpoint,
            'method' => $method,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'status_code' => $statusCode,
            'response_time_ms' => $responseTime
        ];
        
        try {
            $this->db->insert('namosa_api_logs', $data);
        } catch (\Exception $e) {
            // Log to file if database fails
            error_log("Logger Error: " . $e->getMessage());
        }
    }
    
    /**
     * Log error
     */
    public function logError($message, $context = [])
    {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] ERROR: " . $message . "\n";
        
        if (!empty($context)) {
            $logMessage .= "Context: " . json_encode($context, JSON_PRETTY_PRINT) . "\n";
        }
        
        $logMessage .= "Stack Trace:\n" . $this->getStackTrace() . "\n";
        $logMessage .= str_repeat('=', 80) . "\n";
        
        // Log to error log
        error_log($logMessage);
        
        // Also log to database if enabled
        if ($this->enabled) {
            try {
                $this->db->insert('namosa_api_logs', [
                    'endpoint' => $_SERVER['REQUEST_URI'] ?? 'unknown',
                    'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
                    'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                    'status_code' => 500,
                    'response_time_ms' => 0
                ]);
            } catch (\Exception $e) {
                // Ignore database logging errors
            }
        }
    }
    
    /**
     * Log info message
     */
    public function logInfo($message, $context = [])
    {
        $logMessage = "[" . date('Y-m-d H:i:s') . "] INFO: " . $message;
        
        if (!empty($context)) {
            $logMessage .= " | Context: " . json_encode($context);
        }
        
        error_log($logMessage);
    }
    
    /**
     * Log debug message (only in debug mode)
     */
    public function logDebug($message, $context = [])
    {
        if (!defined('DEBUG') || !DEBUG) {
            return;
        }
        
        $logMessage = "[" . date('Y-m-d H:i:s') . "] DEBUG: " . $message;
        
        if (!empty($context)) {
            $logMessage .= " | Context: " . json_encode($context);
        }
        
        error_log($logMessage);
    }
    
    /**
     * Get stack trace
     */
    private function getStackTrace($depth = 10)
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, $depth);
        $output = '';
        
        foreach ($trace as $i => $frame) {
            $file = $frame['file'] ?? 'unknown';
            $line = $frame['line'] ?? 'unknown';
            $function = $frame['function'] ?? 'unknown';
            $class = $frame['class'] ?? '';
            $type = $frame['type'] ?? '';
            
            $output .= "#{$i} {$file}({$line}): {$class}{$type}{$function}()\n";
        }
        
        return $output;
    }
    
    /**
     * Disable logging
     */
    public function disable()
    {
        $this->enabled = false;
    }
    
    /**
     * Enable logging
     */
    public function enable()
    {
        $this->enabled = true;
    }
}