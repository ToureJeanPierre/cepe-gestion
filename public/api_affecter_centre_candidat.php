<?php
// Endpoint AJAX : affecte (ou retire) le centre d'examen d'un candidat libre,
// sans recharger la page — n'agit que sur un candidat réellement libre.
require_once '../config/database.php';

header('Content-Type: application/json');

$id = (int) ($_POST['id'] ?? 0);
$centreIdRaw = trim($_POST['centre_id'] ?? '');
$centreId = $centreIdRaw !== '' ? (int) $centreIdRaw : null;

if (!$id) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Paramètres invalides.']);
    exit;
}

if ($anneeLectureSeule) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Année scolaire archivée : lecture seule."]);
    exit;
}

$stmtCandidat = $pdo->prepare("SELECT annee_id, est_candidat_libre FROM candidats WHERE id = ?");
$stmtCandidat->execute([$id]);
$candidat = $stmtCandidat->fetch();

if (!$candidat) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Candidat introuvable.']);
    exit;
}

if ((int) $candidat['est_candidat_libre'] !== 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => "Seuls les candidats libres peuvent être affectés à un centre d'examen."]);
    exit;
}

if (estAnneeArchivee((int) $candidat['annee_id'], $anneesDisponibles)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => "Ce candidat appartient à une année scolaire archivée (lecture seule)."]);
    exit;
}

if ($centreId !== null) {
    $stmtCentre = $pdo->prepare("SELECT COUNT(*) FROM centres WHERE id = ?");
    $stmtCentre->execute([$centreId]);
    if ((int) $stmtCentre->fetchColumn() === 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Centre introuvable.']);
        exit;
    }
}

try {
    $pdo->prepare("UPDATE candidats SET centre_examen_id = ? WHERE id = ?")->execute([$centreId, $id]);
    echo json_encode(['success' => true, 'centre_examen_id' => $centreId]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
