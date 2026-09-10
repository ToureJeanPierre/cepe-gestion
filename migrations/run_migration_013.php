<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 013 : Nationalité des candidats ===\n\n";

function colonneExiste013($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

if (colonneExiste013($pdo, 'candidats', 'nationalite')) {
    echo "OK - Colonne 'nationalite' existe déjà\n";
} else {
    $pdo->exec("ALTER TABLE `candidats` ADD COLUMN `nationalite` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `sexe`");
    echo "OK - Colonne 'nationalite' ajoutée\n";
}

echo "\n=== Migration terminée ===\n";
