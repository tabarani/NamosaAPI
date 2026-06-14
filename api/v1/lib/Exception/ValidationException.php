<?php
/**
 * Validation Exception
 */

namespace NamosaAPI\Lib\Exception;

class ValidationException extends ApiException
{
    private $errors = [];
    
    public function __construct($message = 'Validation failed', $errors = [], $previous = null)
    {
        parent::__construct($message, 400, 'VALIDATION_ERROR', $previous);
        $this->errors = $errors;
    }
    
    public function getErrors()
    {
        return $this->errors;
    }
    
    public function toArray()
    {
        $data = parent::toArray();
        $data['error']['details'] = $this->errors;
        return $data;
    }
}