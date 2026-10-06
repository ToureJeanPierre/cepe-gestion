<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 025 : extraits_naissance (lecture et renommage des extraits de naissance) ===\n\n";

if ($pdo->query("SHOW TABLES LIKE 'extraits_naissance'")->fetch()) {
    echo "OK - La table existe déjà\n";
} else {
    $pdo->exec(file_get_contents(__DIR__ . '/025_extraits_naissance.sql'));
    echo "OK - Table extraits_naissance créée\n";
}

echo "\n=== Migration terminée ===\n";
