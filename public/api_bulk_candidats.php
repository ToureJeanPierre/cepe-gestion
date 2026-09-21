<?php
// Endpoint AJAX : applique une action à plusieurs candidats sélectionnés à la fois
require_once '../config/database.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? '';
$idsRaw = $_POST['ids'] ?? '';
$ids = array_values(array_filter(array_map('intval', explode(',', $idsRaw))));
$centreId = !empty($_POST['centre_id']) ? (int) $_POST['centre_id'] : null;

$actionsAutorisees = ['matricule_on', 'matricule_off', 'droits_on', 'droits_off', 'supprimer', 'affecter_centre'];

if (empty($ids) || !in_array($action, $actionsAutorisees, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

if ($action === 'affecter_centre' && !$centreId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Centre non spécifié.']);
    exit;
}

if ($anneeLectureSeule) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Année scolaire archivée : lecture seule."]);
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

// Vérifie l'année propre de CHAQUE candidat ciblé, pas seulement celle
// sélectionnée en session (un onglet resté ouvert sur une autre année ne
// doit pas pouvoir modifier des candidats d'une année archivée).
$stmtAnneesCibles = $pdo->prepare("SELECT DISTINCT annee_id FROM candidats WHERE id IN ($placeholders)");
$stmtAnneesCibles->execute($ids);
foreach ($stmtAnneesCibles->fetchAll(PDO::FETCH_COLUMN) as $anneeCible) {
    if (estAnneeArchivee((int) $anneeCible, $anneesDisponibles)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => "Un ou plusieurs candidats sélectionnés appartiennent à une année scolaire archivée (lecture seule)."]);
        exit;
    }
}

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
        case 'affecter_centre':
            // Ne touche que les candidats réellement libres parmi la sélection —
            // un candidat rattaché à une école n'a pas de centre_examen_id.
            $pdo->prepare("UPDATE candidats SET centre_examen_id = ? WHERE id IN ($placeholders) AND est_candidat_libre = 1")
                ->execute(array_merge([$centreId], $ids));
            break;
    }
    echo json_encode(['success' => true, 'nb' => count($ids)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
