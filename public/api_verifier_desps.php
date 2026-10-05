<?php
// Vérifie UN élève sur le site AGCP / DSPS-MEN et enregistre le bilan.
// Appelé en boucle (un élève à la fois, avec une pause) depuis bilan_desps.php.
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/desps_site.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['etat' => 'erreur', 'message' => 'Méthode non autorisée']);
    exit;
}
if ($anneeLectureSeule) {
    http_response_code(403);
    echo json_encode(['etat' => 'erreur', 'message' => 'Année archivée : lecture seule']);
    exit;
}
$anneeExamenDebut = anneeDebutDepuisLibelle($ANNEE_SCOLAIRE);
if (!$anneeExamenDebut) {
    http_response_code(500);
    echo json_encode(['etat' => 'erreur', 'message' => 'Année scolaire illisible']);
    exit;
}

set_time_limit(60);

$stmt = $pdo->prepare("
    SELECT id, nom, prenoms, date_naissance, matricule_dsps, ecole_id
    FROM candidats
    WHERE id = ? AND annee_id = ? AND est_candidat_libre = 0 AND ecole_id IS NOT NULL
");
$stmt->execute([(int) ($_POST['candidat_id'] ?? 0), $anneeId]);
$candidat = $stmt->fetch();
if (!$candidat) {
    http_response_code(404);
    echo json_encode(['etat' => 'erreur', 'message' => 'Élève introuvable']);
    exit;
}

try {
    echo json_encode(verifierCandidatSurSite($pdo, $candidat, $anneeExamenDebut, indexEcolesDfa($pdo)), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('api_verifier_desps: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['etat' => 'erreur', 'message' => 'Erreur technique']);
}
