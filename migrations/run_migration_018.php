<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 018 : affectations.conflit_force ===\n\n";

$col = $pdo->query("SHOW COLUMNS FROM affectations LIKE 'conflit_force'")->fetch();

if ($col) {
    echo "OK - La colonne conflit_force existe déjà\n";
} else {
    $pdo->exec("ALTER TABLE `affectations` ADD COLUMN `conflit_force` TINYINT(1) NOT NULL DEFAULT 0 AFTER `est_manuel`");
    echo "OK - Colonne conflit_force ajoutée\n";
}

echo "\n=== Migration terminée ===\n";
