<?php
// Endpoint AJAX : applique une action à plusieurs candidats sélectionnés à la fois
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));

$actionsAutorisees = ['matricule_on', 'matricule_off', 'droits_on', 'droits_off', 'supprimer'];

if (empty($ids) || !in_array($action, $actionsAutorisees, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

if ($anneeLectureSeule) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Année scolaire archivée : lecture seule."]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

try {
    switch ($action) {
        case 'matricule_on':
            $pdo->prepare("UPDATE candidats SET matricule_verifie = 1 WHERE id IN ($placeholders)")->execute($ids);
            break;
        case 'matricule_off':
            $pdo->prepare("UPDATE candidats SET matricule_verifie = 0 WHERE id IN ($placeholders)")->execute($ids);
            break;
        case 'droits_on':
            $pdo->prepare("UPDATE candidats SET droits_payes = 1 WHERE id IN ($placeholders)")->execute($ids);
            break;
        case 'droits_off':
            $pdo->prepare("UPDATE candidats SET droits_payes = 0 WHERE id IN ($placeholders)")->execute($ids);
            break;
        case 'supprimer':
            $pdo->prepare("DELETE FROM candidats WHERE id IN ($placeholders)")->execute($ids);
            break;
    }
    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
