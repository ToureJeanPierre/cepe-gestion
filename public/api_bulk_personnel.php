<?php
// Endpoint AJAX : supprime plusieurs membres du personnel sélectionnés à la fois
require_once '../config/database.php';

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

// Vérifie l'année propre de CHAQUE personne ciblée : contrairement à
// candidats.php/api_bulk_candidats.php, cet endpoint ne faisait jusqu'ici
// aucun contrôle d'année archivée, alors que personnel.annee_id existe et
// est bien traité comme annuel ailleurs dans l'application.
$stmtAnneesCibles = $pdo->prepare("SELECT DISTINCT annee_id FROM personnel WHERE id IN ($placeholders) AND annee_id IS NOT NULL");
$stmtAnneesCibles->execute($ids);
foreach ($stmtAnneesCibles->fetchAll(PDO::FETCH_COLUMN) as $anneeCible) {
    if (estAnneeArchivee((int) $anneeCible, $anneesDisponibles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Une ou plusieurs personnes sélectionnées appartiennent à une année scolaire archivée (lecture seule)."]);
        exit;
    }
}

try {
    $pdo->prepare("DELETE FROM personnel WHERE id IN ($placeholders)")->execute($ids);
    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    error_log('api_bulk_personnel: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erreur technique lors du traitement.']);
}
