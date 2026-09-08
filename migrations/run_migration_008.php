<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 008 : Affectation candidat -> salle (émargement) ===\n\n";

function tableExiste($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['cnt'] > 0;
}

if (tableExiste($pdo, 'plan_salle_candidats')) {
    echo "OK - Table 'plan_salle_candidats' existe déjà\n";
} else {
    $sql = file_get_contents(__DIR__ . '/008_plan_salle_candidats.sql');
    try {
        $pdo->exec($sql);
        echo "OK - Table 'plan_salle_candidats' créée\n";
    } catch (PDOException $e) {
        echo "ERREUR : " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Migration terminée ===\n";
