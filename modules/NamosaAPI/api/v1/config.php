<?php
/*
NamosaAPI v1 - Configuration
Load settings from Gibbon database or defaults
*/

namespace Gibbon\Module\NamosaAPI;

class Config
{
    private $pdo;

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Get NamosaAPI configuration
     */
    public function get()
    {
        // Default values
        $config = [
            'jwks_url' => '',
            'issuer' => '',
            'audience' => 'namosa-api',
            'user_id_claim' => 'sub',
            'enabled' => false
        ];

        // Try to load from Gibbon settings (Scope: Gibbon OIDC)
        $data = ['scope' => 'Gibbon OIDC'];
        $sql = "SELECT name, value FROM gibbonSetting WHERE scope = :scope";
        
        $stmt = $this->pdo->execute($data, $sql);
        
        if ($stmt->rowCount() > 0) {
            while ($row = $stmt->fetch()) {
                switch ($row['name']) {
                    case 'idpURL':
                        $baseUrl = rtrim($row['value'], '/');
                        if (empty($config['jwks_url'])) {
                            $config['jwks_url'] = $baseUrl . '/.well-known/jwks.json';
                        }
                        if (empty($config['issuer'])) {
                            $config['issuer'] = $baseUrl;
                        }
                        break;
                    case 'oidcClientID':
                        $config['client_id'] = $row['value'];
                        break;
                    case 'oidcClientSecret':
                        $config['client_secret'] = $row['value'];
                        break;
                }
            }
        }

        // Allow override via local config file if exists
        $localConfigFile = __DIR__ . '/../config.local.php';
        if (file_exists($localConfigFile)) {
            $localConfig = include $localConfigFile;
            if (is_array($localConfig)) {
                $config = array_merge($config, $localConfig);
            }
        }

        return $config;
    }

    /**
     * Check if API is properly configured
     */
    public function isConfigured()
    {
        $config = $this->get();
        return !empty($config['jwks_url']) && !empty($config['issuer']);
    }
}
