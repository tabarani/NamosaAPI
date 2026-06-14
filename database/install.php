<?php
/*
 * Namosa API - Database Installation Script
 * Runs automatically when module is installed in Gibbon
 */

namespace Gibbon\Module\NamosaAPI;

use Gibbon\Contracts\Database\Connection;
use Gibbon\Forms\Form;

class Install
{
    private $connection;
    
    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }
    
    public function run()
    {
        $sql = "
        -- API Clients Table
        CREATE TABLE IF NOT EXISTS `namosa_api_clients` (
            `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` VARCHAR(64) NOT NULL UNIQUE,
            `client_secret_hash` VARCHAR(255) NOT NULL,
            `name` VARCHAR(100) NOT NULL,
            `description` TEXT NULL,
            `redirect_uri` VARCHAR(255) NULL,
            `scopes` TEXT NOT NULL COMMENT 'JSON array of allowed scopes',
            `active` ENUM('Y', 'N') DEFAULT 'Y',
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_client_id` (`client_id`),
            INDEX `idx_active` (`active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        -- API Access Logs Table
        CREATE TABLE IF NOT EXISTS `namosa_api_logs` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `client_id` VARCHAR(64) NULL,
            `endpoint` VARCHAR(255) NOT NULL,
            `method` VARCHAR(10) NOT NULL,
            `ip_address` VARCHAR(45) NOT NULL,
            `user_agent` VARCHAR(255) NULL,
            `status_code` INT(11) NOT NULL,
            `response_time_ms` INT(11) NOT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            INDEX `idx_client_id` (`client_id`),
            INDEX `idx_created_at` (`created_at`),
            INDEX `idx_status_code` (`status_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        -- Revoked Tokens Table (for token invalidation)
        CREATE TABLE IF NOT EXISTS `namosa_api_revoked_tokens` (
            `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            `token_id` VARCHAR(64) NOT NULL UNIQUE COMMENT 'JWT jti claim',
            `revoked_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
            `reason` VARCHAR(100) NULL,
            PRIMARY KEY (`id`),
            INDEX `idx_token_id` (`token_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        
        -- API Settings (override module settings if needed)
        CREATE TABLE IF NOT EXISTS `namosa_api_settings` (
            `name` VARCHAR(100) NOT NULL UNIQUE,
            `value` TEXT NULL,
            `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        // Execute multi-query
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $this->connection->executeQuery($statement);
                } catch (\Exception $e) {
                    // Log error but continue (table might already exist)
                    error_log("NamosaAPI Install Error: " . $e->getMessage());
                }
            }
        }
        
        // Insert default API client for testing
        $this->createDefaultClient();
        
        return true;
    }
    
    private function createDefaultClient()
    {
        $clientId = 'namosa_mobile_app_' . bin2hex(random_bytes(8));
        $clientSecret = bin2hex(random_bytes(32));
        $secretHash = password_hash($clientSecret, PASSWORD_DEFAULT);
        
        $data = [
            'client_id' => $clientId,
            'client_secret_hash' => $secretHash,
            'name' => 'Namosa Mobile App (Default)',
            'description' => 'Default client for Namosa Mobile application',
            'scopes' => json_encode([
                'students.read',
                'attendance.read',
                'behavior.read',
                'transport.read',
                'fees.read'
            ]),
            'active' => 'Y'
        ];
        
        try {
            $this->connection->insert('namosa_api_clients', $data);
            
            // Save credentials to file (for admin to retrieve)
            $credentialsFile = __DIR__ . '/../.default_client';
            file_put_contents($credentialsFile, json_encode([
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'generated_at' => date('Y-m-d H:i:s')
            ], JSON_PRETTY_PRINT));
            
        } catch (\Exception $e) {
            error_log("NamosaAPI: Could not create default client: " . $e->getMessage());
        }
    }
    
    public function uninstall()
    {
        $sql = "
        DROP TABLE IF EXISTS `namosa_api_clients`;
        DROP TABLE IF EXISTS `namosa_api_logs`;
        DROP TABLE IF EXISTS `namosa_api_revoked_tokens`;
        DROP TABLE IF EXISTS `namosa_api_settings`;
        ";
        
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        foreach ($statements as $statement) {
            if (!empty($statement)) {
                try {
                    $this->connection->executeQuery($statement);
                } catch (\Exception $e) {
                    error_log("NamosaAPI Uninstall Error: " . $e->getMessage());
                }
            }
        }
        
        return true;
    }
}