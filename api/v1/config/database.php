<?php
/**
 * Database Configuration for Namosa API
 * Uses Gibbon's existing database connection
 */

namespace NamosaAPI\Config;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $pdo;
    private $config;
    
    private function __construct()
    {
        $this->loadConfig();
        $this->connect();
    }
    
    /**
     * Singleton pattern - get database instance
     */
    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Load database configuration from Gibbon session or environment
     */
    private function loadConfig()
    {
        global $databaseName, $databaseServer, $databaseUsername, $databasePassword, $guid;
        
        // Try to get from Gibbon globals first (when accessed via Gibbon)
        if (isset($databaseName) && !empty($databaseName)) {
            $this->config = [
                'host' => $databaseServer ?? 'localhost',
                'dbname' => $databaseName,
                'user' => $databaseUsername,
                'pass' => $databasePassword,
                'charset' => 'utf8mb4'
            ];
        } else {
            // Fallback to environment variables (for external access)
            $this->config = [
                'host' => getenv('DB_HOST') ?: 'localhost',
                'dbname' => getenv('DB_NAME') ?: 'gibbon',
                'user' => getenv('DB_USER') ?: 'root',
                'pass' => getenv('DB_PASS') ?: '',
                'charset' => 'utf8mb4'
            ];
        }
    }
    
    /**
     * Establish PDO connection
     */
    private function connect()
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            $this->config['host'],
            $this->config['dbname'],
            $this->config['charset']
        );
        
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];
        
        try {
            $this->pdo = new PDO($dsn, $this->config['user'], $this->config['pass'], $options);
        } catch (PDOException $e) {
            http_response_code(503);
            die(json_encode([
                'error' => [
                    'code' => 'DATABASE_ERROR',
                    'message' => 'Database connection failed',
                    'details' => defined('DEBUG') ? $e->getMessage() : null
                ]
            ]));
        }
    }
    
    /**
     * Get PDO instance
     */
    public function getConnection()
    {
        return $this->pdo;
    }
    
    /**
     * Get table prefix (usually 'gibbon')
     */
    public function getTablePrefix()
    {
        // Try to get from Gibbon settings
        global $connection2;
        
        if (isset($connection2)) {
            try {
                $result = $connection2->query("SELECT value FROM gibbonSetting WHERE name='databaseTablePrefix'")->fetch();
                return $result['value'] ?? 'gibbon';
            } catch (\Exception $e) {
                return 'gibbon';
            }
        }
        
        return getenv('DB_TABLE_PREFIX') ?: 'gibbon';
    }
    
    /**
     * Close connection
     */
    public function close()
    {
        $this->pdo = null;
        self::$instance = null;
    }
}