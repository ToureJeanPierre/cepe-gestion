<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 012 : Notes par matière ===\n\n";

function colonneExiste012($pdo, $table, $colonne) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $colonne]);
    return $stmt->fetch()['cnt'] > 0;
}

function indexExiste012($pdo, $table, $index) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?");
    $stmt->execute([$table, $index]);
    return $stmt->fetch()['cnt'] > 0;
}

if (colonneExiste012($pdo, 'notes', 'matiere')) {
    echo "OK - Colonne 'matiere' existe déjà\n";
} else {
    // La table notes n'a encore aucune donnée réelle à ce stade du projet ;
    // si des lignes existent déjà (note globale ancien format), elles sont
    // supprimées car elles ne portent pas de matière valide.
    $nb = (int) $pdo->query("SELECT COUNT(*) FROM notes")->fetchColumn();
    if ($nb > 0) {
        echo "ATTENTION - $nb ligne(s) existante(s) dans 'notes' (ancien format sans matière) seront supprimées.\n";
        $pdo->exec("DELETE FROM notes");
    }
    $pdo->exec("ALTER TABLE `notes` ADD COLUMN `matiere` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER `examen_id`");
    echo "OK - Colonne 'matiere' ajoutée\n";
}

if (!indexExiste012($pdo, 'notes', 'uq_note_candidat_examen_matiere')) {
    // Ajouté AVANT de supprimer l'ancien index : 'uq_note_candidat_examen' sert
    // de support à la contrainte de clé étrangère sur candidat_id, il faut donc
    // qu'un autre index commençant par candidat_id existe déjà au moment du DROP.
    $pdo->exec("ALTER TABLE notes ADD UNIQUE KEY uq_note_candidat_examen_matiere (candidat_id, examen_id, matiere)");
    echo "OK - Index 'uq_note_candidat_examen_matiere' ajouté\n";
} else {
    echo "OK - Index 'uq_note_candidat_examen_matiere' existe déjà\n";
}

if (indexExiste012($pdo, 'notes', 'uq_note_candidat_examen')) {
    $pdo->exec("ALTER TABLE notes DROP INDEX uq_note_candidat_examen");
    echo "OK - Ancien index 'uq_note_candidat_examen' supprimé\n";
} else {
    echo "OK - Ancien index 'uq_note_candidat_examen' déjà absent\n";
}

echo "\n=== Migration terminée ===\n";
