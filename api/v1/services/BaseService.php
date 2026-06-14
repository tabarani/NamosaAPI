<?php
/**
 * Base Service
 * Parent class for all service classes
 */

namespace NamosaAPI\Services;

class BaseService
{
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
    
    /**
     * Sanitize input data
     */
    protected function sanitize($value)
    {
        if (is_string($value)) {
            return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
        }
        
        return $value;
    }
    
    /**
     * Format date to ISO 8601
     */
    protected function formatDate($date, $format = 'Y-m-d H:i:s')
    {
        if ($date instanceof \DateTime) {
            return $date->format($format);
        }
        
        if (is_string($date)) {
            $dt = new \DateTime($date);
            return $dt->format($format);
        }
        
        return null;
    }
}