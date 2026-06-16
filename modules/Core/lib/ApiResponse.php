<?php
/**
 * Centralized API Response Formatter
 * 
 * Provides a standard JSON structure for all API error responses.
 * Ensures consistent error handling across all modules and endpoints.
 * 
 * Standard Response Format:
 * {
 *   "error": {
 *     "code": "AUTH_001",
 *     "message": "Human-readable message",
 *     "details": {...}
 *   },
 *   "correlation_id": "unique-request-id"
 * }
 * 
 * @package Gibbon\Module\Core
 */

namespace Gibbon\Module\Core;

class ApiResponse
{
    /**
     * Error code constants for common scenarios
     */
    const ERROR_CODES = [
        // Authentication errors (401)
        'UNAUTHORIZED' => 'AUTH_001',
        'INVALID_TOKEN' => 'AUTH_002',
        'EXPIRED_TOKEN' => 'AUTH_003',
        'MISSING_AUTH_HEADER' => 'AUTH_004',
        
        // Authorization errors (403)
        'FORBIDDEN' => 'AUTHZ_001',
        'INSUFFICIENT_PERMISSIONS' => 'AUTHZ_002',
        'ACCESS_DENIED' => 'AUTHZ_003',
        
        // Validation errors (400)
        'VALIDATION_ERROR' => 'VAL_001',
        'MISSING_REQUIRED_FIELD' => 'VAL_002',
        'INVALID_FORMAT' => 'VAL_003',
        'INVALID_PARAMETER' => 'VAL_004',
        
        // Not found errors (404)
        'NOT_FOUND' => 'NF_001',
        'RESOURCE_NOT_FOUND' => 'NF_002',
        
        // Rate limiting (429)
        'RATE_LIMIT_EXCEEDED' => 'RATE_001',
        'TOO_MANY_REQUESTS' => 'RATE_002',
        
        // Server errors (500)
        'INTERNAL_ERROR' => 'SRV_001',
        'SERVICE_UNAVAILABLE' => 'SRV_002',
        'DATABASE_ERROR' => 'SRV_003',
        'EXTERNAL_SERVICE_ERROR' => 'SRV_004',
        
        // Method errors (405)
        'METHOD_NOT_ALLOWED' => 'METHOD_001',
    ];

    /**
     * Production mode flag - when true, hides sensitive details
     * @var bool
     */
    private static $productionMode = null;

    /**
     * Set production mode
     * 
     * @param bool $mode True for production, false for development
     */
    public static function setProductionMode($mode)
    {
        self::$productionMode = (bool) $mode;
    }

    /**
     * Check if running in production mode
     * 
     * @return bool
     */
    public static function isProductionMode()
    {
        if (self::$productionMode === null) {
            // Auto-detect based on common indicators
            self::$productionMode = !(
                defined('DEBUG') && DEBUG === true ||
                defined('ENVIRONMENT') && ENVIRONMENT === 'development' ||
                isset($_SERVER['APP_ENV']) && $_SERVER['APP_ENV'] === 'development'
            );
        }
        return self::$productionMode;
    }

    /**
     * Generate a unique correlation ID for request tracking
     * 
     * @return string
     */
    public static function generateCorrelationId()
    {
        return 'req_' . bin2hex(random_bytes(8)) . '_' . time();
    }

    /**
     * Send a standardized error response
     * 
     * @param string $message Human-readable error message
     * @param int $statusCode HTTP status code (400, 401, 403, 404, 500, etc.)
     * @param string|null $errorCode Application-specific error code (e.g., 'AUTH_001')
     * @param array|null $details Additional error details (hidden in production)
     * @param string|null $correlationId Optional correlation ID for tracking
     * @return void
     */
    public static function error($message, $statusCode = 400, $errorCode = null, $details = null, $correlationId = null)
    {
        // Generate or use provided correlation ID
        $correlationId = $correlationId ?: self::generateCorrelationId();
        
        // Use provided error code or map from status code
        if ($errorCode === null) {
            $errorCode = self::getErrorCodeFromStatus($statusCode);
        }
        
        // Build error response
        $response = [
            'error' => [
                'code' => $errorCode,
                'message' => $message,
            ],
            'correlation_id' => $correlationId
        ];
        
        // Add details only in non-production mode or if explicitly safe
        if ($details !== null && !self::isProductionMode()) {
            $response['error']['details'] = $details;
        } elseif ($details !== null && self::isProductionMode()) {
            // In production, log details but don't expose them
            error_log(sprintf(
                "[API Error] CorrelationID: %s | Code: %s | Message: %s | Details: %s",
                $correlationId,
                $errorCode,
                $message,
                json_encode($details)
            ));
        }
        
        self::sendJson($response, $statusCode);
    }

    /**
     * Send a standardized success response
     * 
     * @param array $data Response data
     * @param string $message Success message
     * @param int $statusCode HTTP status code (default 200)
     * @param array $meta Optional metadata
     * @param string|null $correlationId Optional correlation ID
     * @return void
     */
    public static function success($data = [], $message = 'Success', $statusCode = 200, $meta = [], $correlationId = null)
    {
        $correlationId = $correlationId ?: self::generateCorrelationId();
        
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'correlation_id' => $correlationId
        ];
        
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        
        self::sendJson($response, $statusCode);
    }

    /**
     * Get error code from HTTP status code
     * 
     * @param int $statusCode HTTP status code
     * @return string Error code
     */
    private static function getErrorCodeFromStatus($statusCode)
    {
        $mapping = [
            400 => 'VALIDATION_ERROR',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_ERROR',
            502 => 'BAD_GATEWAY',
            503 => 'SERVICE_UNAVAILABLE',
            504 => 'GATEWAY_TIMEOUT',
        ];
        
        $key = $mapping[$statusCode] ?? 'INTERNAL_ERROR';
        return self::ERROR_CODES[$key] ?? 'UNKNOWN_ERROR';
    }

    /**
     * Send JSON response with proper headers
     * 
     * @param array $data Response data
     * @param int $statusCode HTTP status code
     * @return void
     */
    private static function sendJson($data, $statusCode)
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        header('X-Correlation-ID: ' . ($data['correlation_id'] ?? 'unknown'));
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }

    /**
     * Handle exception and convert to error response
     * 
     * @param \Exception $exception
     * @param int $defaultStatusCode Default status code if not determined
     * @return void
     */
    public static function handleException($exception, $defaultStatusCode = 500)
    {
        $statusCode = $defaultStatusCode;
        $errorCode = 'INTERNAL_ERROR';
        $message = $exception->getMessage();
        
        // Map common exception types to status codes
        if ($exception instanceof \InvalidArgumentException) {
            $statusCode = 400;
            $errorCode = 'VALIDATION_ERROR';
        } elseif ($exception instanceof \UnauthorizedException) {
            $statusCode = 401;
            $errorCode = 'UNAUTHORIZED';
        } elseif ($exception instanceof \ForbiddenException) {
            $statusCode = 403;
            $errorCode = 'FORBIDDEN';
        } elseif ($exception instanceof \NotFoundException) {
            $statusCode = 404;
            $errorCode = 'NOT_FOUND';
        }
        
        $details = [
            'type' => get_class($exception),
            'file' => self::isProductionMode() ? '[hidden]' : $exception->getFile(),
            'line' => self::isProductionMode() ? '[hidden]' : $exception->getLine(),
        ];
        
        if (!self::isProductionMode()) {
            $details['trace'] = $exception->getTraceAsString();
        }
        
        self::error($message, $statusCode, $errorCode, $details);
    }

    /**
     * Create a validation error response
     * 
     * @param string $message Error message
     * @param array $fields Fields that failed validation
     * @return void
     */
    public static function validationError($message = 'Validation failed', $fields = [])
    {
        $details = ['fields' => $fields];
        self::error($message, 400, 'VALIDATION_ERROR', $details);
    }

    /**
     * Create an authentication error response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function unauthorizedError($message = 'Authentication required')
    {
        self::error($message, 401, 'UNAUTHORIZED');
    }

    /**
     * Create an authorization error response
     * 
     * @param string $message Error message
     * @return void
     */
    public static function forbiddenError($message = 'Access denied')
    {
        self::error($message, 403, 'FORBIDDEN');
    }

    /**
     * Create a not found error response
     * 
     * @param string $resource Type of resource not found
     * @param mixed $id The ID that was not found
     * @return void
     */
    public static function notFoundError($resource = 'Resource', $id = null)
    {
        $message = $id 
            ? "{$resource} with ID '{$id}' not found"
            : "{$resource} not found";
        self::error($message, 404, 'NOT_FOUND');
    }

    /**
     * Create a rate limit exceeded error response
     * 
     * @param int $retryAfter Seconds until retry is allowed
     * @return void
     */
    public static function rateLimitError($retryAfter = 60)
    {
        $details = ['retry_after' => $retryAfter];
        self::error(
            "Rate limit exceeded. Try again in {$retryAfter} seconds.",
            429,
            'TOO_MANY_REQUESTS',
            $details
        );
    }

    /**
     * Create a service unavailable error response
     * 
     * @param string $serviceName Name of the unavailable service
     * @return void
     */
    public static function serviceUnavailableError($serviceName = 'Service')
    {
        self::error(
            "{$serviceName} is currently unavailable. Please try again later.",
            503,
            'SERVICE_UNAVAILABLE'
        );
    }
}
