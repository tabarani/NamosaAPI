<?php
/**
 * Standardized API Response Handler
 */

namespace NamosaAPI\Lib;

class Response
{
    /**
     * Send JSON success response
     */
    public static function success($data = [], $message = 'Success', $statusCode = 200, $meta = [])
    {
        http_response_code($statusCode);
        
        $response = [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
        
        if (!empty($meta)) {
            $response['meta'] = $meta;
        }
        
        self::sendJson($response);
    }
    
    /**
     * Send JSON error response
     */
    public static function error($message, $statusCode = 400, $errorCode = null, $details = null)
    {
        http_response_code($statusCode);
        
        $error = [
            'success' => false,
            'error' => [
                'message' => $message,
                'code' => $errorCode ?: self::getStatusCodeText($statusCode)
            ]
        ];
        
        if ($details !== null && defined('DEBUG')) {
            $error['error']['details'] = $details;
        }
        
        // Add request ID for debugging
        $error['error']['requestId'] = self::generateRequestId();
        
        self::sendJson($error);
    }
    
    /**
     * Send paginated response
     */
    public static function paginated($data, $total, $limit, $offset, $baseUrl, $filters = [])
    {
        $currentPage = floor($offset / $limit) + 1;
        $totalPages = ceil($total / $limit);
        
        $pagination = [
            'total' => $total,
            'count' => count($data),
            'perPage' => $limit,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
            'links' => [
                'next' => ($currentPage < $totalPages) ? self::buildUrl($baseUrl, [
                    'limit' => $limit,
                    'offset' => $offset + $limit
                ] + $filters) : null,
                'prev' => ($currentPage > 1) ? self::buildUrl($baseUrl, [
                    'limit' => $limit,
                    'offset' => max(0, $offset - $limit)
                ] + $filters) : null
            ]
        ];
        
        self::success($data, 'Success', 200, ['pagination' => $pagination]);
    }
    
    /**
     * Send JSON response with proper headers
     */
    private static function sendJson($data)
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit;
    }
    
    /**
     * Get HTTP status code text
     */
    private static function getStatusCodeText($code)
    {
        $texts = [
            400 => 'BAD_REQUEST',
            401 => 'UNAUTHORIZED',
            403 => 'FORBIDDEN',
            404 => 'NOT_FOUND',
            405 => 'METHOD_NOT_ALLOWED',
            429 => 'TOO_MANY_REQUESTS',
            500 => 'INTERNAL_ERROR',
            503 => 'SERVICE_UNAVAILABLE'
        ];
        
        return $texts[$code] ?? 'ERROR';
    }
    
    /**
     * Generate unique request ID
     */
    private static function generateRequestId()
    {
        return 'req_' . bin2hex(random_bytes(8)) . '_' . time();
    }
    
    /**
     * Build URL with query parameters
     */
    private static function buildUrl($baseUrl, $params)
    {
        $query = http_build_query($params);
        return $baseUrl . (strpos($baseUrl, '?') === false ? '?' : '&') . $query;
    }
}