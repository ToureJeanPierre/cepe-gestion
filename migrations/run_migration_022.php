<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 022 : table reglages ===\n\n";

if ($pdo->query("SHOW TABLES LIKE 'reglages'")->fetch()) {
    echo "OK - La table reglages existe déjà\n";
} else {
    $pdo->exec("
        CREATE TABLE `reglages` (
            `cle` VARCHAR(100) NOT NULL,
            `valeur` TEXT NULL,
            `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`cle`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK - Table reglages créée\n";
}

echo "\n=== Migration terminée ===\n";
