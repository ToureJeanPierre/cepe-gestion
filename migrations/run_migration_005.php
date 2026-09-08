<?php
require_once __DIR__ . '/../config/database.php';

/*
|--------------------------------------------------------------------------
| MIGRATION 005 — Fusion officielle "Cité SODEFOR"
|--------------------------------------------------------------------------
|
| Règle métier (cahier des charges, Module 2, cas particulier) :
|   - L'école est divisée en 2 structures physiques : Cité SODEFOR 1 et
|     Cité SODEFOR 2, gérées séparément en interne (élèves, enseignants,
|     candidats).
|   - Officiellement, les deux structures partagent le MÊME code DSPS.
|     La Directrice du site 1 est l'unique responsable/signataire.
|   - Les bilans officiels et exports DSPS doivent fusionner les deux
|     entités sous l'appellation unique « Cité SODEFOR ».
|
| Mise en œuvre technique :
|   - On réutilise le mécanisme "rattachée -> tutrice" déjà en place dans
|     ecoles.ecole_tutrice_id / ecoles.type_rattachement, qui fait déjà
|     remonter automatiquement les effectifs d'une école rattachée vers
|     son école tutrice partout où c'est nécessaire (centres.php).
|   - Le site 1 devient l'école "tutrice officielle", renommée
|     "CITE SODEFOR" (elle garde son code DSPS).
|   - Le site 2 devient "rattachée" au site 1 via
|     type_rattachement = 'arrimee' (comme les écoles privées sans code
|     DSPS), MAIS reste gérable séparément dans le module Écoles /
|     Personnel / Candidats (aucune donnée n'est supprimée ni fusionnée
|     en base : seuls les exports et bilans officiels agrègent).
|
| Sécurité :
|   - Ce script ne modifie RIEN tant qu'il n'a pas trouvé exactement les
|     deux écoles attendues et que vous n'avez pas confirmé explicitement
|     avec l'argument --confirm.
|   - Relancer ce script après application ne fait plus rien (idempotent).
|
| Usage :
|   php migrations/run_migration_005.php            (mode recherche seule)
|   php migrations/run_migration_005.php --confirm  (applique le correctif)
|
*/

echo "=== Migration 005 : Fusion officielle Cité SODEFOR ===\n\n";

$confirm = in_array('--confirm', $argv ?? [], true);

/*
|--------------------------------------------------------------------------
| 1. RECHERCHE DES DEUX ÉCOLES SODEFOR
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT id, nom, code_dsps, ecole_tutrice_id, type_rattachement
    FROM ecoles
    WHERE nom LIKE '%SODEFOR%'
    ORDER BY nom ASC
");

$candidats = $stmt->fetchAll();

if (count($candidats) === 0) {
    echo "ERREUR - Aucune école contenant 'SODEFOR' n'a été trouvée. Migration interrompue.\n";
    exit;
}

echo "Écoles trouvées :\n";

foreach ($candidats as $c) {
    echo "  - #{$c['id']} : {$c['nom']}"
        . " (code_dsps=" . ($c['code_dsps'] ?? 'NULL') . ")"
        . " (tutrice=" . ($c['ecole_tutrice_id'] ?? 'NULL') . ")"
        . " (rattachement={$c['type_rattachement']})\n";
}

echo "\n";

// On cherche précisément le site 1 (celui qui a le code DSPS, donc
// l'école "officielle") et le site 2 (sans code DSPS).
$site1 = null;
$site2 = null;

foreach ($candidats as $c) {
    if ($c['code_dsps'] !== null && $site1 === null) {
        $site1 = $c;
    } elseif ($c['code_dsps'] === null && $site2 === null) {
        $site2 = $c;
    }
}

if (!$site1 || !$site2) {
    echo "ERREUR - Impossible d'identifier automatiquement le site 1 (avec code DSPS)\n";
    echo "et le site 2 (sans code DSPS) parmi les écoles ci-dessus.\n";
    echo "Corrigez manuellement les codes DSPS en base, ou ajustez ce script, puis relancez.\n";
    exit;
}

// Déjà appliqué ?
if ($site1['nom'] === 'CITE SODEFOR' && $site2['ecole_tutrice_id'] == $site1['id'] && $site2['type_rattachement'] === 'arrimee') {
    echo "OK - La fusion Cité SODEFOR est déjà appliquée. Rien à faire.\n";
    exit;
}

echo "Plan d'action proposé :\n";
echo "  1. École #{$site1['id']} ('{$site1['nom']}') sera renommée en 'CITE SODEFOR'\n";
echo "     (code DSPS conservé : {$site1['code_dsps']})\n";
echo "  2. École #{$site2['id']} ('{$site2['nom']}') sera rattachée à l'école #{$site1['id']}\n";
echo "     (ecole_tutrice_id = {$site1['id']}, type_rattachement = 'arrimee')\n";
echo "     -> Elle reste gérable séparément (élèves, personnel, candidats),\n";
echo "        seuls les bilans officiels/exports DSPS l'agrègeront désormais\n";
echo "        sous 'CITE SODEFOR'.\n\n";

if (!$confirm) {
    echo "Aucune modification effectuée (mode recherche seule).\n";
    echo "Relancez avec --confirm pour appliquer ce plan :\n";
    echo "  php migrations/run_migration_005.php --confirm\n";
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. APPLICATION
|--------------------------------------------------------------------------
*/

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE ecoles SET nom = 'CITE SODEFOR' WHERE id = ?");
    $stmt->execute([$site1['id']]);

    $stmt = $pdo->prepare("
        UPDATE ecoles
        SET ecole_tutrice_id = ?,
            type_rattachement = 'arrimee'
        WHERE id = ?
    ");
    $stmt->execute([$site1['id'], $site2['id']]);

    $pdo->commit();

    echo "OK - Migration appliquée avec succès.\n";
    echo "  - École #{$site1['id']} -> 'CITE SODEFOR' (tutrice officielle)\n";
    echo "  - École #{$site2['id']} -> rattachée à #{$site1['id']}\n";
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "ERREUR lors de l'application : " . $e->getMessage() . "\n";
    exit;
}

echo "\n=== Migration terminée ===\n";
