<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 016 : colonnes ecole et contact sur cap_ceap_candidats ===\n\n";

$colonnes = $pdo->query("SHOW COLUMNS FROM cap_ceap_candidats")->fetchAll(PDO::FETCH_COLUMN);

if (in_array('ecole', $colonnes, true) && in_array('contact', $colonnes, true)) {
    echo "OK - Les colonnes existent déjà\n";
} else {
    $pdo->exec("
        ALTER TABLE `cap_ceap_candidats`
            ADD COLUMN `ecole` VARCHAR(255) NULL AFTER `prenoms`,
            ADD COLUMN `contact` VARCHAR(50) NULL AFTER `ecole`
    ");
    echo "OK - Colonnes ecole et contact ajoutées\n";
}

echo "\n=== Migration terminée ===\n";
