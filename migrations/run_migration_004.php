<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 004 : Refonte du Module Personnel ===\n\n";

function tableExiste($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['cnt'] > 0;
}

function colonneExiste($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

// ------------------------------------------------------------------
// 1. Renommage enseignants -> personnel
// ------------------------------------------------------------------
if (tableExiste($pdo, 'personnel')) {
    echo "OK - Table 'personnel' existe deja (renommage deja fait)\n";
} elseif (tableExiste($pdo, 'enseignants')) {
    try {
        $pdo->exec("RENAME TABLE enseignants TO personnel");
        echo "OK - Table 'enseignants' renommee en 'personnel'\n";
    } catch (PDOException $e) {
        echo "ERREUR renommage : " . $e->getMessage() . "\n";
        exit;
    }
} else {
    echo "ERREUR - Ni 'enseignants' ni 'personnel' n'existent. Migration interrompue.\n";
    exit;
}

// ------------------------------------------------------------------
// 2. ecole_id devient nullable
// ------------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE personnel MODIFY ecole_id INT NULL");
    echo "OK - ecole_id est maintenant optionnel\n";
} catch (PDOException $e) {
    echo "ERREUR ecole_id : " . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------
// 3. Nouvelles colonnes (ajout idempotent)
// ------------------------------------------------------------------
$colonnes = [
    ['categorie', "ALTER TABLE personnel ADD COLUMN categorie ENUM('enseignant','conseiller','administratif') NOT NULL DEFAULT 'enseignant' AFTER ecole_id"],
    ['sous_type', "ALTER TABLE personnel ADD COLUMN sous_type VARCHAR(100) DEFAULT NULL AFTER categorie"],
    ['numero_autorisation_enseigner', "ALTER TABLE personnel ADD COLUMN numero_autorisation_enseigner VARCHAR(50) DEFAULT NULL AFTER matricule"],
    ['numero_autorisation_diriger', "ALTER TABLE personnel ADD COLUMN numero_autorisation_diriger VARCHAR(50) DEFAULT NULL AFTER numero_autorisation_enseigner"],
    ['plus_haut_diplome', "ALTER TABLE personnel ADD COLUMN plus_haut_diplome VARCHAR(100) DEFAULT NULL"],
    ['plus_haut_niveau_etude', "ALTER TABLE personnel ADD COLUMN plus_haut_niveau_etude VARCHAR(100) DEFAULT NULL"],
];

foreach ($colonnes as [$colonne, $sql]) {
    if (colonneExiste($pdo, 'personnel', $colonne)) {
        echo "OK - Colonne '$colonne' existe deja\n";
    } else {
        try {
            $pdo->exec($sql);
            echo "OK - Colonne '$colonne' ajoutee\n";
        } catch (PDOException $e) {
            echo "ERREUR '$colonne' : " . $e->getMessage() . "\n";
        }
    }
}

// ------------------------------------------------------------------
// 4. Fonction élargie en texte libre
// ------------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE personnel MODIFY fonction VARCHAR(100) DEFAULT 'Adjoint'");
    echo "OK - Colonne 'fonction' elargie en texte libre\n";
} catch (PDOException $e) {
    echo "ERREUR fonction : " . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------
// 5. Normalisation puis élargissement de 'disponibilite'
// ------------------------------------------------------------------
try {
    $pdo->exec("UPDATE personnel SET disponibilite = 'En activité' WHERE disponibilite = 'Disponible'");
    $pdo->exec("UPDATE personnel SET disponibilite = 'Congé maladie' WHERE disponibilite = 'Malade'");
    $pdo->exec("UPDATE personnel SET disponibilite = 'Autre' WHERE disponibilite = 'Congé'");
    $pdo->exec("ALTER TABLE personnel MODIFY disponibilite ENUM('En activité','Congé maternité','Congé maladie','Absent','Autre') DEFAULT 'En activité'");
    echo "OK - Colonne 'disponibilite' mise a jour (En activite / Conge maternite / Conge maladie / Absent / Autre)\n";
} catch (PDOException $e) {
    echo "ERREUR disponibilite : " . $e->getMessage() . "\n";
}

echo "\n=== Migration terminee ===\n";
echo "\nStructure actuelle de la table personnel :\n";
$result = $pdo->query("DESCRIBE personnel")->fetchAll();
foreach ($result as $row) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}
