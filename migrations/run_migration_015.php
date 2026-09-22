<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 015 : type_rattachement 'candidats_libres' ===\n\n";

$col = $pdo->query("SHOW COLUMNS FROM ecoles LIKE 'type_rattachement'")->fetch();

if ($col && str_contains($col['Type'], "'candidats_libres'")) {
    echo "OK - La valeur 'candidats_libres' existe déjà\n";
} else {
    $pdo->exec("ALTER TABLE `ecoles` MODIFY COLUMN `type_rattachement` ENUM('aucun','sans_code_dsps','arrimee','candidats_libres') DEFAULT 'aucun'");
    echo "OK - Valeur 'candidats_libres' ajoutée\n";
}

echo "\n=== Migration terminée ===\n";
