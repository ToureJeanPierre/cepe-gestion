<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration: Mise à jour de la table ecoles ===\n\n";

// Fonction pour vérifier si une colonne existe
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

// Fonction pour vérifier si une contrainte existe
function contrainteExiste($pdo, $table, $contrainte) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as cnt 
        FROM information_schema.TABLE_CONSTRAINTS 
        WHERE TABLE_SCHEMA = DATABASE() 
        AND TABLE_NAME = ? 
        AND CONSTRAINT_NAME = ?
    ");
    $stmt->execute([$table, $contrainte]);
    return $stmt->fetch()['cnt'] > 0;
}

$modifications = [
    ['code_ecole', "ALTER TABLE ecoles ADD COLUMN code_ecole VARCHAR(50) NULL AFTER nom"],
    ['est_arrimee', "ALTER TABLE ecoles ADD COLUMN est_arrimee TINYINT(1) DEFAULT 0 AFTER code_ecole"],
    ['ecole_tutrice_id', "ALTER TABLE ecoles ADD COLUMN ecole_tutrice_id INT NULL AFTER est_arrimee"],
    ['est_subdivision', "ALTER TABLE ecoles ADD COLUMN est_subdivision TINYINT(1) DEFAULT 0 AFTER ecole_tutrice_id"],
    ['effectif', "ALTER TABLE ecoles ADD COLUMN effectif INT NULL DEFAULT 0 AFTER est_subdivision"],
    ['effectif_total', "ALTER TABLE ecoles ADD COLUMN effectif_total INT NULL DEFAULT 0 AFTER effectif"],
];

foreach ($modifications as [$colonne, $sql]) {
    if (colonneExiste($pdo, 'ecoles', $colonne)) {
        echo "✓ Colonne '$colonne' existe déjà\n";
    } else {
        try {
            $pdo->exec($sql);
            echo "✓ Colonne '$colonne' ajoutée avec succès\n";
        } catch (PDOException $e) {
            echo "✗ Erreur lors de l'ajout de '$colonne': " . $e->getMessage() . "\n";
        }
    }
}

// Ajouter la clé étrangère
if (contrainteExiste($pdo, 'ecoles', 'fk_ecole_tutrice')) {
    echo "✓ Clé étrangère 'fk_ecole_tutrice' existe déjà\n";
} else {
    try {
        $pdo->exec("ALTER TABLE ecoles ADD CONSTRAINT fk_ecole_tutrice FOREIGN KEY (ecole_tutrice_id) REFERENCES ecoles(id) ON DELETE SET NULL");
        echo "✓ Clé étrangère 'fk_ecole_tutrice' ajoutée avec succès\n";
    } catch (PDOException $e) {
        echo "✗ Erreur lors de l'ajout de la clé étrangère: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration terminée ===\n";

// Vérification finale
echo "\nStructure actuelle de la table ecoles:\n";
$result = $pdo->query("DESCRIBE ecoles")->fetchAll();
foreach ($result as $row) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
