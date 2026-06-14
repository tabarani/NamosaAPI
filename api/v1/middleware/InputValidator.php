<?php
/**
 * Input Validator Middleware
 * Validates and sanitizes input data
 */

namespace NamosaAPI\Middleware;

class InputValidator
{
    /**
     * Validate input against rules
     */
    public static function validate($data, $rules)
    {
        $errors = [];
        $validated = [];
        
        foreach ($rules as $field => $ruleString) {
            $rulesArray = explode('|', $ruleString);
            $value = $data[$field] ?? null;
            
            foreach ($rulesArray as $rule) {
                $ruleParts = explode(':', $rule);
                $ruleName = $ruleParts[0];
                $ruleParam = $ruleParts[1] ?? null;
                
                $error = self::validateRule($field, $value, $ruleName, $ruleParam);
                
                if ($error) {
                    $errors[$field] = $error;
                    break; // Stop on first error for this field
                }
            }
            
            if (!isset($errors[$field])) {
                $validated[$field] = $value;
            }
        }
        
        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'validated' => $validated
        ];
    }
    
    /**
     * Validate a single rule
     */
    private static function validateRule($field, $value, $rule, $param = null)
    {
        switch ($rule) {
            case 'required':
                if ($value === null || $value === '' || (is_array($value) && empty($value))) {
                    return ucfirst($field) . ' is required';
                }
                break;
                
            case 'string':
                if (!is_string($value)) {
                    return ucfirst($field) . ' must be a string';
                }
                break;
                
            case 'integer':
                if (!is_numeric($value) || intval($value) != $value) {
                    return ucfirst($field) . ' must be an integer';
                }
                break;
                
            case 'numeric':
                if (!is_numeric($value)) {
                    return ucfirst($field) . ' must be numeric';
                }
                break;
                
            case 'boolean':
                if (!in_array($value, [true, false, 0, 1, '0', '1'], true)) {
                    return ucfirst($field) . ' must be a boolean';
                }
                break;
                
            case 'email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    return ucfirst($field) . ' must be a valid email address';
                }
                break;
                
            case 'url':
                if (!filter_var($value, FILTER_VALIDATE_URL)) {
                    return ucfirst($field) . ' must be a valid URL';
                }
                break;
                
            case 'date':
                if (!self::isValidDate($value)) {
                    return ucfirst($field) . ' must be a valid date';
                }
                break;
                
            case 'array':
                if (!is_array($value)) {
                    return ucfirst($field) . ' must be an array';
                }
                break;
                
            case 'min':
                if (is_string($value) && strlen($value) < $param) {
                    return ucfirst($field) . ' must be at least ' . $param . ' characters';
                }
                if (is_numeric($value) && $value < $param) {
                    return ucfirst($field) . ' must be at least ' . $param;
                }
                break;
                
            case 'max':
                if (is_string($value) && strlen($value) > $param) {
                    return ucfirst($field) . ' must be at most ' . $param . ' characters';
                }
                if (is_numeric($value) && $value > $param) {
                    return ucfirst($field) . ' must be at most ' . $param;
                }
                break;
                
            case 'in':
                $allowed = explode(',', $param);
                $allowed = array_map('trim', $allowed);
                if (!in_array($value, $allowed)) {
                    return ucfirst($field) . ' must be one of: ' . implode(', ', $allowed);
                }
                break;
                
            case 'regex':
                if (!preg_match($param, $value)) {
                    return ucfirst($field) . ' format is invalid';
                }
                break;
        }
        
        return null;
    }
    
    /**
     * Check if value is a valid date
     */
    private static function isValidDate($value)
    {
        if (!$value) {
            return false;
        }
        
        $date = date_create($value);
        return $date !== false;
    }
    
    /**
     * Sanitize input
     */
    public static function sanitize($value)
    {
        if (is_string($value)) {
            return htmlspecialchars(strip_tags($value), ENT_QUOTES, 'UTF-8');
        }
        
        if (is_array($value)) {
            return array_map([self::class, 'sanitize'], $value);
        }
        
        return $value;
    }
    
    /**
     * Sanitize entire array
     */
    public static function sanitizeArray($data)
    {
        $sanitized = [];
        
        foreach ($data as $key => $value) {
            $sanitized[$key] = self::sanitize($value);
        }
        
        return $sanitized;
    }
}