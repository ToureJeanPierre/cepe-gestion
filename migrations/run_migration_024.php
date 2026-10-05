<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 024 : desps_cursus, colonnes de vérification sur le site ===\n\n";

if ($pdo->query("SHOW COLUMNS FROM desps_cursus LIKE 'verifie_le'")->fetch()) {
    echo "OK - Les colonnes existent déjà\n";
} else {
    $pdo->exec(file_get_contents(__DIR__ . '/024_desps_verification_site.sql'));
    echo "OK - Colonnes source, verifie_le, identite_desps, cursus_brut, derniere_dfa ajoutées\n";
}

echo "\n=== Migration terminée ===\n";
