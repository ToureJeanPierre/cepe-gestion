<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 017 : personnel.telephone en varchar(50) ===\n\n";

$col = $pdo->query("SHOW COLUMNS FROM personnel LIKE 'telephone'")->fetch();

if ($col && str_contains($col['Type'], 'varchar(50)')) {
    echo "OK - La colonne est déjà en varchar(50)\n";
} else {
    $pdo->exec("ALTER TABLE `personnel` MODIFY COLUMN `telephone` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL");
    echo "OK - Colonne telephone élargie à varchar(50)\n";
}

echo "\n=== Migration terminée ===\n";
