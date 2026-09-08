<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 002 : Validation des candidats ===\n\n";

function colonneExiste($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

$modifications = [
    ['matricule_verifie', "ALTER TABLE candidats ADD COLUMN matricule_verifie TINYINT(1) DEFAULT 0 AFTER matricule_dsps"],
    ['droits_payes', "ALTER TABLE candidats ADD COLUMN droits_payes TINYINT(1) DEFAULT 0 AFTER matricule_verifie"],
];

foreach ($modifications as [$colonne, $sql]) {
    if (colonneExiste($pdo, 'candidats', $colonne)) {
        echo "OK - Colonne '$colonne' existe deja\n";
    } else {
        try {
            $pdo->exec($sql);
            echo "OK - Colonne '$colonne' ajoutee avec succes\n";
        } catch (PDOException $e) {
            echo "ERREUR lors de l'ajout de '$colonne' : " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== Migration terminee ===\n";

echo "\nStructure actuelle de la table candidats :\n";
$result = $pdo->query("DESCRIBE candidats")->fetchAll();
foreach ($result as $row) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
