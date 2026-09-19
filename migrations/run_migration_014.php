<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 014 : Dispense d'EPS ===\n\n";

function colonneExiste014($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

if (colonneExiste014($pdo, 'notes', 'dispense')) {
    echo "OK - Colonne 'dispense' existe déjà\n";
} else {
    $pdo->exec("ALTER TABLE `notes` ADD COLUMN `dispense` TINYINT(1) NOT NULL DEFAULT 0 AFTER `present`");
    echo "OK - Colonne 'dispense' ajoutée\n";
}

echo "\n=== Migration terminée ===\n";
