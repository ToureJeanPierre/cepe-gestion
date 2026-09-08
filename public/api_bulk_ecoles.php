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

try {
    // Détache d'abord toute école rattachée à l'une des écoles supprimées,
    // pour éviter de laisser une clé étrangère orpheline.
    $pdo->prepare("UPDATE ecoles SET ecole_tutrice_id = NULL WHERE ecole_tutrice_id IN ($placeholders)")->execute($ids);
    $pdo->prepare("DELETE FROM ecoles WHERE id IN ($placeholders)")->execute($ids);

    recalculerGroupesScolaires($pdo);

    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
