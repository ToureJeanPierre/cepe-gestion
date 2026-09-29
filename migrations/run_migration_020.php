<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 020 : contrainte unique candidats(annee_id, matricule_dsps) ===\n\n";

$stmt = $pdo->query("SHOW INDEX FROM candidats WHERE Key_name = 'uq_candidats_annee_matricule'");
if ($stmt->fetch()) {
    echo "OK - La contrainte existe déjà\n";
} else {
    $doublons = $pdo->query("
        SELECT annee_id, matricule_dsps, COUNT(*) AS nb
        FROM candidats
        WHERE matricule_dsps IS NOT NULL AND matricule_dsps != ''
        GROUP BY annee_id, matricule_dsps
        HAVING nb > 1
    ")->fetchAll();

    if ($doublons) {
        echo "ERREUR - Des doublons de matricule existent déjà, la contrainte ne peut pas être ajoutée :\n";
        foreach ($doublons as $d) {
            echo "  - année {$d['annee_id']}, matricule {$d['matricule_dsps']} : {$d['nb']} candidats\n";
        }
        echo "Corrigez ces doublons manuellement avant de relancer cette migration.\n";
        exit(1);
    }

    $pdo->exec("ALTER TABLE `candidats` ADD UNIQUE KEY `uq_candidats_annee_matricule` (`annee_id`, `matricule_dsps`)");
    echo "OK - Contrainte ajoutée\n";
}

echo "\n=== Migration terminée ===\n";
