<?php
/**
 * Database Migration Runner Script
 * 
 * Safely applies SQL migration files to existing installations.
 * Supports dry-run mode, rollback preparation, and error handling.
 * 
 * Usage:
 *   php run_migrations.php                    # Run all pending migrations
 *   php run_migrations.php --dry-run          # Show what would be executed
 *   php run_migrations.php --target=003       # Run up to specific migration
 *   php run_migrations.php --help             # Show usage information
 * 
 * @package Gibbon\Database
 */

// Configuration
$CONFIG = [
    'migrations_dir' => __DIR__ . '/migrations',
    'migrations_table' => 'gibbon_migrations',
    'backup_before_run' => true,
];

// Parse command line arguments
$args = [];
$dryRun = false;
$targetMigration = null;
$showHelp = false;

foreach ($argv as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $showHelp = true;
    } elseif (strpos($arg, '--target=') === 0) {
        $targetMigration = substr($arg, 9);
    } else {
        $args[] = $arg;
    }
}

if ($showHelp) {
    echo "Database Migration Runner\n";
    echo "========================\n\n";
    echo "Usage: php {$argv[0]} [options]\n\n";
    echo "Options:\n";
    echo "  --dry-run       Show what would be executed without making changes\n";
    echo "  --target=XXX    Run migrations up to the specified version (e.g., 003)\n";
    echo "  --help, -h      Show this help message\n\n";
    echo "Examples:\n";
    echo "  php {$argv[0]}\n";
    echo "  php {$argv[0]} --dry-run\n";
    echo "  php {$argv[0]} --target=003\n\n";
    exit(0);
}

/**
 * Get database connection
 * 
 * Attempts to connect using various common configuration methods.
 * Modify this function based on your Gibbon installation's DB config.
 */
function getDbConnection()
{
    // Try to load from Gibbon config if available
    $configPaths = [
        __DIR__ . '/../config.php',
        __DIR__ . '/../../config.php',
        __DIR__ . '/../../gibbon/config.php',
    ];
    
    foreach ($configPaths as $path) {
        if (file_exists($path)) {
            require_once $path;
            break;
        }
    }
    
    // Check for environment variables (common in Docker/CI)
    $host = getenv('DB_HOST') ?: 'localhost';
    $port = getenv('DB_PORT') ?: '3306';
    $database = getenv('DB_DATABASE') ?: 'gibbon';
    $username = getenv('DB_USERNAME') ?: 'root';
    $password = getenv('DB_PASSWORD') ?: '';
    
    // If Gibbon config loaded, use those values
    if (isset($databaseInfo) && is_array($databaseInfo)) {
        $host = $databaseInfo['host'] ?? $host;
        $port = $databaseInfo['port'] ?? $port;
        $database = $databaseInfo['name'] ?? $database;
        $username = $databaseInfo['username'] ?? $username;
        $password = $databaseInfo['password'] ?? $password;
    }
    
    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        return $pdo;
    } catch (PDOException $e) {
        echo "ERROR: Failed to connect to database.\n";
        echo "Please ensure database credentials are configured.\n";
        echo "Details: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * Create migrations tracking table if it doesn't exist
 */
function ensureMigrationsTable($pdo, $tableName)
{
    $sql = "CREATE TABLE IF NOT EXISTS {$tableName} (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        migration_name VARCHAR(255) NOT NULL UNIQUE,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        checksum VARCHAR(64) DEFAULT NULL,
        status ENUM('success', 'failed', 'rolled_back') DEFAULT 'success'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($sql);
}

/**
 * Get list of already executed migrations
 */
function getExecutedMigrations($pdo, $tableName)
{
    $stmt = $pdo->query("SELECT migration_name FROM {$tableName} WHERE status = 'success' ORDER BY migration_name");
    return array_column($stmt->fetchAll(), 'migration_name');
}

/**
 * Get list of migration files
 */
function getMigrationFiles($directory)
{
    if (!is_dir($directory)) {
        echo "ERROR: Migrations directory not found: {$directory}\n";
        exit(1);
    }
    
    $files = glob($directory . '/*.sql');
    sort($files); // Ensure alphabetical order
    
    return $files;
}

/**
 * Calculate MD5 checksum of migration file
 */
function calculateChecksum($filePath)
{
    return md5_file($filePath);
}

/**
 * Record migration execution
 */
function recordMigration($pdo, $tableName, $migrationName, $status = 'success', $checksum = null)
{
    $stmt = $pdo->prepare(
        "INSERT INTO {$tableName} (migration_name, status, checksum) 
         VALUES (:name, :status, :checksum)
         ON DUPLICATE KEY UPDATE status = :status, checksum = :checksum"
    );
    
    $stmt->execute([
        ':name' => basename($migrationName),
        ':status' => $status,
        ':checksum' => $checksum,
    ]);
}

/**
 * Execute a single migration file
 */
function executeMigration($pdo, $filePath, $dryRun = false)
{
    $fileName = basename($filePath);
    $checksum = calculateChecksum($filePath);
    
    echo "Processing: {$fileName}\n";
    
    if ($dryRun) {
        echo "  [DRY-RUN] Would execute migration\n";
        return true;
    }
    
    try {
        // Read and execute SQL file
        $sql = file_get_contents($filePath);
        
        // Split by semicolons (basic splitting, may need enhancement for complex SQL)
        $statements = array_filter(
            array_map('trim', preg_split('/;(?=\s*(?:--|$))/m', $sql)),
            function($stmt) {
                return !empty($stmt) && 
                       !preg_match('/^\s*--/', $stmt) && 
                       strlen(trim($stmt)) > 1;
            }
        );
        
        // Execute each statement in a transaction
        $pdo->beginTransaction();
        
        foreach ($statements as $statement) {
            if (empty(trim($statement))) {
                continue;
            }
            $pdo->exec($statement);
        }
        
        $pdo->commit();
        
        // Record successful migration
        recordMigration($pdo, $GLOBALS['CONFIG']['migrations_table'], $fileName, 'success', $checksum);
        
        echo "  ✓ Completed successfully\n";
        return true;
        
    } catch (Exception $e) {
        // Rollback on error
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        echo "  ✗ FAILED: " . $e->getMessage() . "\n";
        
        // Record failed migration
        recordMigration($pdo, $GLOBALS['CONFIG']['migrations_table'], $fileName, 'failed', $checksum);
        
        return false;
    }
}

/**
 * Main migration runner
 */
function runMigrations($config, $dryRun, $targetMigration)
{
    global $CONFIG;
    
    echo "===========================================\n";
    echo "Gibbon Database Migration Runner\n";
    echo "===========================================\n\n";
    
    if ($dryRun) {
        echo "*** DRY RUN MODE - No changes will be made ***\n\n";
    }
    
    // Connect to database
    $pdo = getDbConnection();
    echo "✓ Connected to database\n\n";
    
    // Ensure migrations tracking table exists
    ensureMigrationsTable($pdo, $config['migrations_table']);
    echo "✓ Migrations tracking table ready\n\n";
    
    // Get executed migrations
    $executed = getExecutedMigrations($pdo, $config['migrations_table']);
    echo "Previously executed migrations: " . count($executed) . "\n";
    if (!empty($executed)) {
        echo "  " . implode(', ', $executed) . "\n";
    }
    echo "\n";
    
    // Get migration files
    $files = getMigrationFiles($config['migrations_dir']);
    echo "Available migration files: " . count($files) . "\n\n";
    
    // Filter and execute pending migrations
    $pending = [];
    foreach ($files as $file) {
        $fileName = basename($file);
        
        // Skip if already executed
        if (in_array($fileName, $executed)) {
            continue;
        }
        
        // Check target migration limit
        if ($targetMigration !== null) {
            $fileNumber = preg_replace('/^(\d+).*/', '$1', $fileName);
            if ($fileNumber > $targetMigration) {
                break;
            }
        }
        
        $pending[] = $file;
    }
    
    if (empty($pending)) {
        echo "✓ No pending migrations. Database is up to date.\n";
        return true;
    }
    
    echo "Pending migrations: " . count($pending) . "\n";
    foreach ($pending as $file) {
        echo "  - " . basename($file) . "\n";
    }
    echo "\n";
    
    if (!$dryRun) {
        echo "Starting migration execution...\n\n";
    }
    
    $successCount = 0;
    $failureCount = 0;
    
    foreach ($pending as $file) {
        if (executeMigration($pdo, $file, $dryRun)) {
            $successCount++;
        } else {
            $failureCount++;
            echo "\n!!! Migration failed. Stopping execution. !!!\n";
            break;
        }
    }
    
    echo "\n===========================================\n";
    echo "Migration Summary\n";
    echo "===========================================\n";
    echo "Successful: {$successCount}\n";
    echo "Failed: {$failureCount}\n";
    
    if ($dryRun) {
        echo "\n[DRY-RUN] No actual changes were made.\n";
        echo "Run without --dry-run to apply migrations.\n";
    }
    
    return $failureCount === 0;
}

// Run migrations
$success = runMigrations($CONFIG, $dryRun, $targetMigration);
exit($success ? 0 : 1);
