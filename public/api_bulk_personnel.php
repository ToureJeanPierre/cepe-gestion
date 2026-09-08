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

try {
    $pdo->prepare("DELETE FROM personnel WHERE id IN ($placeholders)")->execute($ids);
    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
