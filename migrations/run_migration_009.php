<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 009 : Module Résultats (Compositions 1 & 2 + table notes) ===\n\n";

function tableExiste($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['cnt'] > 0;
}

// 1. Ajoute COMPO_1 / COMPO_2 pour chaque année qui ne les a pas encore,
//    et renumérote pour que l'ordre chronologique reste correct.
$annees = $pdo->query("SELECT id, annee_scolaire FROM annees")->fetchAll();

foreach ($annees as $annee) {
    $anneeId = $annee['id'];

    $dejaCompo1 = $pdo->prepare("SELECT id FROM examens WHERE annee_id = ? AND code = 'COMPO_1'");
    $dejaCompo1->execute([$anneeId]);
    if ($dejaCompo1->fetch()) {
        echo "OK - COMPO_1 existe déjà pour {$annee['annee_scolaire']}\n";
        continue;
    }

    // Décale les examens existants pour laisser la place (1, 2) aux compositions
    $pdo->prepare("UPDATE examens SET ordre = ordre + 2 WHERE annee_id = ?")->execute([$anneeId]);

    preg_match('/^(\d{4})-(\d{4})$/', $annee['annee_scolaire'], $m);
    $session = $m[2] ?? $annee['annee_scolaire'];

    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'COMPO_1', ?, 1, 1)")
        ->execute([$anneeId, "Composition n°1 - année scolaire {$annee['annee_scolaire']}"]);
    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'COMPO_2', ?, 2, 1)")
        ->execute([$anneeId, "Composition n°2 - année scolaire {$annee['annee_scolaire']}"]);

    echo "OK - COMPO_1 et COMPO_2 ajoutées pour {$annee['annee_scolaire']}\n";
}

// 2. Table des notes
if (tableExiste($pdo, 'notes')) {
    echo "OK - Table 'notes' existe déjà\n";
} else {
    try {
        $pdo->exec("
            CREATE TABLE `notes` (
              `id` int NOT NULL AUTO_INCREMENT,
              `candidat_id` int NOT NULL,
              `examen_id` int NOT NULL,
              `note` decimal(5,2) DEFAULT NULL,
              `present` tinyint(1) NOT NULL DEFAULT '1',
              `source` enum('saisie','import') NOT NULL DEFAULT 'saisie',
              `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
              `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
              PRIMARY KEY (`id`),
              UNIQUE KEY `uq_note_candidat_examen` (`candidat_id`, `examen_id`),
              KEY `idx_notes_examen` (`examen_id`),
              CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`) ON DELETE CASCADE,
              CONSTRAINT `notes_ibfk_2` FOREIGN KEY (`examen_id`) REFERENCES `examens` (`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        echo "OK - Table 'notes' créée\n";
    } catch (PDOException $e) {
        echo "ERREUR : " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Migration terminée ===\n";
