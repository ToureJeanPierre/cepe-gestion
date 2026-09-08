<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 003 : Verrou manuel Groupe Scolaire ===\n\n";

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

if (colonneExiste($pdo, 'ecoles', 'groupe_scolaire_manuel')) {
    echo "OK - Colonne 'groupe_scolaire_manuel' existe deja\n";
} else {
    try {
        $pdo->exec("ALTER TABLE ecoles ADD COLUMN groupe_scolaire_manuel TINYINT(1) DEFAULT 0 AFTER groupe_scolaire");
        echo "OK - Colonne 'groupe_scolaire_manuel' ajoutee avec succes\n";
    } catch (PDOException $e) {
        echo "ERREUR : " . $e->getMessage() . "\n";
    }
}

echo "\n=== Migration terminee ===\n";
echo "\nStructure actuelle de la table ecoles :\n";
$result = $pdo->query("DESCRIBE ecoles")->fetchAll();
foreach ($result as $row) {
    echo "  - {$row['Field']} ({$row['Type']})\n";
}

// ==========================================
// RECALCUL DES GROUPES SCOLAIRES EXISTANTS
// Corrige les données actuelles (mal renseignées avant cette migration)
// ==========================================
echo "\n=== Recalcul des Groupes Scolaires existants ===\n";

function extraireBaseEcole($nom) {
    $base = trim($nom);
    $base = preg_replace('/\s+\d+$/', '', $base);
    $base = preg_replace('/\s+[A-Za-z]$/', '', $base);
    return trim($base);
}

function extraireNomLieu($base) {
    $prefixes = [
        'ECOLE PRIMAIRE PUBLIQUE', 'ECOLE PRIMAIRE PRIVEE', 'ECOLE PRIMAIRE CATHOLIQUE',
        'GROUPE SCOLAIRE', 'EPP', 'EPC', 'EPU', 'EPRIV', 'EP', 'GS', 'CEG', 'COURS',
    ];
    $resteApresPrefixe = $base;
    foreach ($prefixes as $prefixe) {
        if (stripos(trim($base), $prefixe) === 0) {
            $reste = trim(substr(trim($base), strlen($prefixe)));
            if ($reste !== '') {
                $resteApresPrefixe = $reste;
            }
            break;
        }
    }
    return $resteApresPrefixe;
}

$ecoles = $pdo->query("SELECT id, nom, groupe_scolaire_manuel FROM ecoles")->fetchAll();

$paquets = [];
foreach ($ecoles as $e) {
    if (!empty($e['groupe_scolaire_manuel'])) continue;
    $base = extraireBaseEcole($e['nom']);
    $paquets[$base][] = $e['id'];
}

$nbGroupes = 0;
$nbSeules = 0;
foreach ($paquets as $base => $ids) {
    if (count($ids) >= 2) {
        $nomGroupe = 'Groupe Scolaire ' . extraireNomLieu($base);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE ecoles SET groupe_scolaire = ? WHERE id IN ($placeholders)")
            ->execute(array_merge([$nomGroupe], $ids));
        $nbGroupes++;
        echo "  - $nomGroupe (" . count($ids) . " écoles)\n";
    } else {
        $pdo->prepare("UPDATE ecoles SET groupe_scolaire = NULL WHERE id = ?")->execute([$ids[0]]);
        $nbSeules++;
    }
}

echo "\nOK - $nbGroupes groupes scolaires recrees, $nbSeules ecoles seules (sans groupe)\n";
