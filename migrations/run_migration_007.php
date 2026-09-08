<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 007 : Rattachement à l'année scolaire (candidats, écoles, personnel) ===\n\n";

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

$anneeEnCoursId = $pdo->query("SELECT id FROM annees WHERE statut = 'en_cours' LIMIT 1")->fetchColumn();
if (!$anneeEnCoursId) {
    echo "ERREUR - Aucune année 'en_cours' trouvée dans la table annees. Migration interrompue.\n";
    exit(1);
}
echo "Année en cours détectée : id=$anneeEnCoursId\n\n";

// ------------------------------------------------------------------
// 1. Colonne annee_id sur candidats, ecoles, personnel
// ------------------------------------------------------------------
foreach (['candidats', 'ecoles', 'personnel'] as $table) {
    if (colonneExiste($pdo, $table, 'annee_id')) {
        echo "OK - Colonne 'annee_id' existe déjà sur $table\n";
        continue;
    }
    try {
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `annee_id` INT NULL AFTER `id`");
        echo "OK - Colonne 'annee_id' ajoutée sur $table\n";
    } catch (PDOException $e) {
        echo "ERREUR ajout annee_id sur $table : " . $e->getMessage() . "\n";
        exit(1);
    }
}

// ------------------------------------------------------------------
// 2. Backfill des lignes existantes vers l'année en cours
// ------------------------------------------------------------------
foreach (['candidats', 'ecoles', 'personnel'] as $table) {
    $nb = $pdo->exec("UPDATE `$table` SET `annee_id` = $anneeEnCoursId WHERE `annee_id` IS NULL");
    echo "OK - $nb ligne(s) de $table rattachée(s) à l'année en cours\n";
}

// ------------------------------------------------------------------
// 3. candidats.annee_id devient obligatoire (nouvelle cohorte chaque année)
// ------------------------------------------------------------------
try {
    $pdo->exec("ALTER TABLE `candidats` MODIFY `annee_id` INT NOT NULL");
    echo "OK - candidats.annee_id passé en NOT NULL\n";
} catch (PDOException $e) {
    echo "ERREUR NOT NULL candidats.annee_id : " . $e->getMessage() . "\n";
}

// ------------------------------------------------------------------
// 4. Index + clés étrangères
// ------------------------------------------------------------------
$fks = [
    'candidats'  => ['idx_candidats_annee', 'candidats_ibfk_annee', 'CASCADE'],
    'ecoles'     => ['idx_ecoles_annee', 'ecoles_ibfk_annee', 'SET NULL'],
    'personnel'  => ['idx_personnel_annee', 'personnel_ibfk_annee', 'SET NULL'],
];

foreach ($fks as $table => [$idxName, $fkName, $onDelete]) {
    if (!indexExiste($pdo, $table, $idxName)) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD KEY `$idxName` (`annee_id`)");
            echo "OK - Index '$idxName' ajouté sur $table\n";
        } catch (PDOException $e) {
            echo "ERREUR index $idxName : " . $e->getMessage() . "\n";
        }
    } else {
        echo "OK - Index '$idxName' existe déjà sur $table\n";
    }

    if (!fkExiste($pdo, $table, $fkName)) {
        try {
            $pdo->exec("ALTER TABLE `$table` ADD CONSTRAINT `$fkName` FOREIGN KEY (`annee_id`) REFERENCES `annees` (`id`) ON DELETE $onDelete");
            echo "OK - Contrainte '$fkName' ajoutée sur $table (ON DELETE $onDelete)\n";
        } catch (PDOException $e) {
            echo "ERREUR FK $fkName : " . $e->getMessage() . "\n";
        }
    } else {
        echo "OK - Contrainte '$fkName' existe déjà sur $table\n";
    }
}

echo "\n=== Migration terminée ===\n";
