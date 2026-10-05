<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 023 : table desps_cursus (bilan DESPS / DFA) ===\n\n";

if ($pdo->query("SHOW TABLES LIKE 'desps_cursus'")->fetch()) {
    echo "OK - La table desps_cursus existe déjà\n";
} else {
    $pdo->exec(file_get_contents(__DIR__ . '/023_desps_cursus.sql'));
    echo "OK - Table desps_cursus créée\n";
}

echo "\n=== Migration terminée ===\n";
