<?php
/**
 * Base Controller
 * Parent class for all API controllers
 */

namespace NamosaAPI\Controllers;

class BaseController
{
    /**
     * Get JSON input from request body
     */
    protected function getJsonInput()
    {
        $json = file_get_contents('php://input');
        
        if (empty($json)) {
            return [];
        }
        
        $data = json_decode($json, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON: ' . json_last_error_msg());
        }
        
        return $data ?? [];
    }
    
    /**
     * Get query parameters
     */
    protected function getQueryParams()
    {
        return $_GET;
    }
    
    /**
     * Get request method
     */
    protected function getRequestMethod()
    {
        return $_SERVER['REQUEST_METHOD'] ?? 'GET';
    }
    
    /**
     * Get request headers
     */
    protected function getRequestHeaders()
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (substr($key, 0, 5) === 'HTTP_') {
                $header = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$header] = $value;
            }
        }
        
        return $headers;
    }
    
    /**
     * Validate required fields
     */
    protected function validateRequired($data, $requiredFields)
    {
        $missing = [];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                $missing[] = $field;
            }
        }
        
        if (!empty($missing)) {
            throw new \Exception('Missing required fields: ' . implode(', ', $missing));
        }
        
        return true;
    }
}