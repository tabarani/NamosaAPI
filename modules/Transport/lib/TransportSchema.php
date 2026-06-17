<?php
/**
 * Lightweight compatibility helpers for Transport installations that have not
 * yet run every historical migration. These helpers prevent dashboard pages
 * from crashing while administrators finish database upgrades.
 */

if (!function_exists('transportColumnExists')) {
    function transportColumnExists(PDO $connection2, string $table, string $column): bool
    {
        $stmt = $connection2->prepare("SHOW COLUMNS FROM `$table` LIKE :columnName");
        $stmt->execute(['columnName' => $column]);
        return $stmt->rowCount() > 0;
    }
}

if (!function_exists('transportEnsureCompatibilitySchema')) {
    function transportEnsureCompatibilitySchema(PDO $connection2): void
    {
        $requiredColumns = [
            'gibbonTransportRoute' => [
                'routeType' => "ALTER TABLE gibbonTransportRoute ADD COLUMN routeType ENUM('to_school', 'from_school', 'both') NOT NULL DEFAULT 'both' AFTER name",
                'gibbonPersonIDSupervisor' => "ALTER TABLE gibbonTransportRoute ADD COLUMN gibbonPersonIDSupervisor INT(10) UNSIGNED ZEROFILL NULL AFTER driverPhone",
                'supervisorEnabled' => "ALTER TABLE gibbonTransportRoute ADD COLUMN supervisorEnabled ENUM('Y','N') NOT NULL DEFAULT 'N' AFTER driverPhone"
            ],
            'gibbonTransportStudent' => [
                'gibbonTransportStopID' => "ALTER TABLE gibbonTransportStudent ADD COLUMN gibbonTransportStopID INT(10) UNSIGNED NULL AFTER gibbonTransportRouteID"
            ]
        ];

        foreach ($requiredColumns as $table => $columns) {
            foreach ($columns as $column => $sql) {
                if (!transportColumnExists($connection2, $table, $column)) {
                    try {
                        $connection2->exec($sql);
                    } catch (Exception $e) {
                        // Keep pages usable even if DB user cannot ALTER; queries below still use fallbacks when possible.
                    }
                }
            }
        }
    }
}
