<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 010 : Superviseur multi-centres ===\n\n";

function indexExiste010($pdo, $table, $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return $stmt->fetch()['cnt'] > 0;
}

if (indexExiste010($pdo, 'affectations', 'unique_affectation_enseignant_centre')) {
    echo "OK - Index 'unique_affectation_enseignant_centre' existe déjà\n";
} else {
    try {
        if (indexExiste010($pdo, 'affectations', 'unique_affectation_enseignant')) {
            $pdo->exec("ALTER TABLE affectations DROP INDEX unique_affectation_enseignant");
            echo "OK - Ancien index 'unique_affectation_enseignant' supprimé\n";
        }
        $pdo->exec("ALTER TABLE affectations ADD UNIQUE KEY unique_affectation_enseignant_centre (annee_id, type_examen, enseignant_id, centre_id)");
        echo "OK - Nouvel index 'unique_affectation_enseignant_centre' ajouté (autorise plusieurs centres pour un Superviseur)\n";
    } catch (PDOException $e) {
        echo "ERREUR : " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Migration terminée ===\n";
