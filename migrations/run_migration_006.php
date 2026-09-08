<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 006 : Correctifs schéma Surveillance & Affectations ===\n\n";

function colonneExiste($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

function indexExiste($pdo, $table, $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return $stmt->fetch()['cnt'] > 0;
}

function fkExiste($pdo, $table, $fk) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
    $stmt->execute([$table, $fk]);
    return $stmt->fetch()['cnt'] > 0;
}

// ------------------------------------------------------------------
// 1. Ajout du rôle "Superviseur"
// ------------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE affectations MODIFY role ENUM('Président','Chef Secrétariat','Membre Secrétariat','Superviseur','Surveillant','Suppléant') NOT NULL");
    echo "OK - Rôle 'Superviseur' ajouté à l'ENUM\n";
} catch (PDOException $e) {
    echo "ERREUR role : " . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------
// 2. Renommage salle_id -> plan_salle_id + correction de la FK
// ------------------------------------------------------------------
if (colonneExiste($pdo, 'affectations', 'plan_salle_id')) {
    echo "OK - Colonne 'plan_salle_id' existe déjà (renommage déjà fait)\n";
} elseif (colonneExiste($pdo, 'affectations', 'salle_id')) {
    try {
        if (fkExiste($pdo, 'affectations', 'affectations_ibfk_4')) {
            $pdo->exec("ALTER TABLE affectations DROP FOREIGN KEY affectations_ibfk_4");
        }
        $pdo->exec("ALTER TABLE affectations CHANGE salle_id plan_salle_id INT DEFAULT NULL");
        $pdo->exec("ALTER TABLE affectations ADD CONSTRAINT affectations_ibfk_4 FOREIGN KEY (plan_salle_id) REFERENCES plan_salles (id) ON DELETE SET NULL");
        echo "OK - Colonne 'salle_id' renommée en 'plan_salle_id', FK pointant vers plan_salles\n";
    } catch (PDOException $e) {
        echo "ERREUR renommage plan_salle_id : " . $e->getMessage() . "\n";
    }
} else {
    echo "ERREUR - Ni 'salle_id' ni 'plan_salle_id' n'existent sur affectations. Migration interrompue.\n";
    exit;
}

// ------------------------------------------------------------------
// 3. Clé unique sans redondance de rôle
// ------------------------------------------------------------------
if (indexExiste($pdo, 'affectations', 'unique_affectation_enseignant')) {
    echo "OK - Clé unique 'unique_affectation_enseignant' existe déjà\n";
} else {
    try {
        if (indexExiste($pdo, 'affectations', 'unique_affectation')) {
            $pdo->exec("ALTER TABLE affectations DROP INDEX unique_affectation");
        }
        $pdo->exec("ALTER TABLE affectations ADD UNIQUE KEY unique_affectation_enseignant (annee_id, type_examen, enseignant_id)");
        echo "OK - Clé unique 'unique_affectation_enseignant' ajoutée (un enseignant = un seul rôle par examen)\n";
    } catch (PDOException $e) {
        echo "ERREUR clé unique : " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration terminée ===\n";
echo "\nStructure actuelle de la table affectations :\n";
$result = $pdo->query("DESCRIBE affectations")->fetchAll();
foreach ($result as $row) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
