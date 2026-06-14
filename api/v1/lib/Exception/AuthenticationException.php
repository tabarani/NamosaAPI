<?php
/**
 * Authentication Exception
 */

namespace NamosaAPI\Lib\Exception;

class AuthenticationException extends ApiException
{
    public function __construct($message = 'Unauthorized', $previous = null)
    {
        parent::__construct($message, 401, 'UNAUTHORIZED', $previous);
    }
}