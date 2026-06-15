<?php
/**
 * Sync Logger - Records all sync operations for the dashboard
 */

namespace Gibbon\Module\NamosaAPI\Moodle;

use PDO;

class SyncLogger
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->ensureTable();
    }

    private function ensureTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS namosa_sync_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            action_type VARCHAR(50) NOT NULL,
            gibbon_id INT NOT NULL,
            moodle_id INT DEFAULT NULL,
            success TINYINT(1) NOT NULL,
            message TEXT DEFAULT NULL,
            timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            user_action VARCHAR(50) DEFAULT 'manual',
            INDEX idx_action (action_type),
            INDEX idx_gibbon (gibbon_id),
            INDEX idx_timestamp (timestamp)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        
        $this->pdo->exec($sql);
    }

    public function log(string $actionType, int $gibbonId, ?int $moodleId, bool $success, ?string $message = null): void
    {
        $sql = "INSERT INTO namosa_sync_log 
                (action_type, gibbon_id, moodle_id, success, message) 
                VALUES (:action, :gibbon, :moodle, :success, :msg)";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'action' => $actionType,
            'gibbon' => $gibbonId,
            'moodle' => $moodleId,
            'success' => $success ? 1 : 0,
            'msg' => $message
        ]);
    }

    public function getRecentLogs(int $limit = 100): array
    {
        $sql = "SELECT * FROM namosa_sync_log ORDER BY timestamp DESC LIMIT :limit";
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getSyncStats(): array
    {
        $sql = "SELECT 
                    action_type,
                    COUNT(*) as total,
                    SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed
                FROM namosa_sync_log 
                GROUP BY action_type";
        
        $stmt = $this->pdo->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function getLogsByDateRange(string $startDate, string $endDate): array
    {
        $sql = "SELECT * FROM namosa_sync_log 
                WHERE DATE(timestamp) BETWEEN :start AND :end 
                ORDER BY timestamp DESC";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['start' => $startDate, 'end' => $endDate]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
