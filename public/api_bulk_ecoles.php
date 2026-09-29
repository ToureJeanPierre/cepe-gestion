<?php
// Endpoint AJAX : supprime plusieurs écoles sélectionnées à la fois
require_once '../config/database.php';
require_once __DIR__ . '/../src/groupe_scolaire_helpers.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));

if (empty($ids) || $action !== 'supprimer') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// candidats.ecole_id et centres.ecole_id n'ont pas de contrainte de clé
// étrangère vers ecoles : sans ce contrôle, supprimer une école qui a encore
// des candidats réels ou sert de centre d'examen les rend orphelins en
// silence plutôt que de bloquer une action destructrice sur de vraies
// données.
$stmtCandidatsLies = $pdo->prepare("SELECT COUNT(*) FROM candidats WHERE ecole_id IN ($placeholders)");
$stmtCandidatsLies->execute($ids);
$nbCandidatsLies = (int) $stmtCandidatsLies->fetchColumn();

$stmtCentresLies = $pdo->prepare("SELECT COUNT(*) FROM centres WHERE ecole_id IN ($placeholders)");
$stmtCentresLies->execute($ids);
$nbCentresLies = (int) $stmtCentresLies->fetchColumn();

if ($nbCandidatsLies > 0 || $nbCentresLies > 0) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => "Suppression refusée : $nbCandidatsLies candidat(s) et $nbCentresLies centre(s) d'examen sont encore rattachés à une ou plusieurs de ces écoles."]);
    exit;
}

// Une rattachée détachée (ecole_tutrice_id -> NULL, cf. plus bas) sans code
// DSPS propre n'a plus aucun moyen d'être comptée dans un centre — sauf si
// elle est elle-même supprimée dans la même opération (auquel cas la
// vérification candidats ci-dessus la couvre déjà).
$stmtRattachees = $pdo->prepare("
    SELECT er.id, er.nom, (er.code_dsps IS NULL OR er.code_dsps = '') AS sans_code_dsps,
           (SELECT COUNT(*) FROM candidats WHERE ecole_id = er.id) AS nb_candidats
    FROM ecoles er
    WHERE er.ecole_tutrice_id IN ($placeholders) AND er.id NOT IN ($placeholders)
");
$stmtRattachees->execute(array_merge($ids, $ids));
$rattacheesProblematiques = array_filter(
    $stmtRattachees->fetchAll(),
    fn ($r) => (int) $r['nb_candidats'] > 0 || (int) $r['sans_code_dsps'] === 1
);

if ($rattacheesProblematiques) {
    $noms = implode(', ', array_map(fn ($r) => $r['nom'], $rattacheesProblematiques));
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => "Suppression refusée : au moins une école est tutrice de $noms, qui n'ont pas de code DSPS propre et/ou ont déjà des candidats — les détacher les rendrait orphelines."]);
    exit;
}

try {
    $pdo->beginTransaction();
    // Détache d'abord toute école rattachée à l'une des écoles supprimées,
    // pour éviter de laisser une clé étrangère orpheline.
    $pdo->prepare("UPDATE ecoles SET ecole_tutrice_id = NULL WHERE ecole_tutrice_id IN ($placeholders)")->execute($ids);
    $pdo->prepare("DELETE FROM ecoles WHERE id IN ($placeholders)")->execute($ids);
    $pdo->commit();

    recalculerGroupesScolaires($pdo);

    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    $pdo->rollBack();
    error_log('api_bulk_ecoles: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur technique lors du traitement.']);
}
