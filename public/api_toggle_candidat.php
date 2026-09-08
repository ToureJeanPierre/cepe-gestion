<?php
// Endpoint AJAX : bascule une case de validation candidat sans recharger la page
require_once '../config/database.php';

header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
$champ = $_POST['champ'] ?? '';

$champsAutorises = ['matricule_verifie', 'droits_payes'];

if (!$id || !in_array($champ, $champsAutorises, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

if ($anneeLectureSeule) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Année scolaire archivée : lecture seule."]);
    exit;
}

try {
    $pdo->prepare("UPDATE candidats SET $champ = NOT $champ WHERE id = ?")->execute([$id]);

    $stmt = $pdo->prepare("SELECT matricule_verifie, droits_payes FROM candidats WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Candidat introuvable.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'matricule_verifie' => (int) $row['matricule_verifie'],
        'droits_payes' => (int) $row['droits_payes'],
        'est_valide' => ((int) $row['matricule_verifie'] === 1 && (int) $row['droits_payes'] === 1),
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
