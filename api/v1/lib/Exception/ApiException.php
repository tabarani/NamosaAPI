<?php
/**
 * Base API Exception
 */

namespace NamosaAPI\Lib\Exception;

class ApiException extends \Exception
{
    protected $statusCode = 500;
    protected $errorCode = 'INTERNAL_ERROR';
    
    public function __construct($message = '', $statusCode = null, $errorCode = null, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        
        if ($statusCode !== null) {
            $this->statusCode = $statusCode;
        }
        
        if ($errorCode !== null) {
            $this->errorCode = $errorCode;
        }
    }
    
    public function getStatusCode()
    {
        return $this->statusCode;
    }
    
    public function getErrorCode()
    {
        return $this->errorCode;
    }
    
    public function toArray()
    {
        return [
            'success' => false,
            'error' => [
                'code' => $this->errorCode,
                'message' => $this->getMessage(),
                'statusCode' => $this->statusCode
            ]
        ];
    }
}