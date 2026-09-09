<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Iepp\CepeGestion\AffectationEngine;

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
$filtrePersonnelId = isset($_GET['personnel_id']) && $_GET['personnel_id'] !== '' ? (int) $_GET['personnel_id'] : null;

if (!$examenId || !$anneeId) {
    die("Examen invalide.");
}

$stmt = $pdo->prepare("SELECT id, code, libelle FROM examens WHERE id = ? AND annee_id = ?");
$stmt->execute([$examenId, $anneeId]);
$examen = $stmt->fetch();
if (!$examen) {
    die("Examen introuvable pour l'année scolaire consultée.");
}
$typeExamen = AffectationEngine::libelleTypeExamen($examen['code']);

$sql = "
    SELECT a.enseignant_id, p.nom, p.prenoms, p.matricule, p.fonction, p.emploi,
           e.nom AS nom_centre
    FROM affectations a
    INNER JOIN personnel p ON p.id = a.enseignant_id
    INNER JOIN centres c ON c.id = a.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    WHERE a.annee_id = ? AND a.type_examen = ? AND a.role = 'Superviseur'
";
$params = [$anneeId, $typeExamen];
if ($filtrePersonnelId) {
    $sql .= " AND a.enseignant_id = ?";
    $params[] = $filtrePersonnelId;
}
$sql .= " ORDER BY p.nom ASC, e.nom ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();

$parSuperviseur = [];
foreach ($lignes as $l) {
    $parSuperviseur[$l['enseignant_id']]['info'] = $l;
    $parSuperviseur[$l['enseignant_id']]['centres'][] = $l['nom_centre'];
}

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    .corps-mission { text-align: justify; line-height: 1.8; margin-top: 20px; font-size: 12px; }
    .page-break { page-break-before: always; }
</style></head><body>';

if (!$parSuperviseur) {
    $html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
    $html .= titreDocumentIepp('EXAMEN DU CEPE SESSION ' . date('Y'), 'MISSION DE SUPERVISION');
    $html .= '<p>Aucun superviseur affecté pour cet examen. Attribuez d\'abord le rôle Superviseur depuis le module Surveillance.</p>';
}

$premiere = true;
foreach ($parSuperviseur as $data) {
    $info = $data['info'];
    $listeCentres = 'l\'' . implode(', l\'', $data['centres']);

    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
    $html .= numeroReferencePointille();
    $html .= titreDocumentIepp('EXAMEN DU CEPE SESSION ' . date('Y'), 'MISSION DE SUPERVISION');

    $html .= '<div class="corps-mission">';
    $html .= 'Dans le cadre du déroulement de l\'examen du CEPE à l\'IEPP YOPOUGON NIANGON, le ……………………… , de ……h…… à ……h……, ';
    $html .= 'Monsieur/Madame <strong>' . htmlspecialchars($info['nom'] . ' ' . $info['prenoms']) . '</strong>, ';
    $html .= 'Matricule : <strong>' . htmlspecialchars($info['matricule'] ?? '……………') . '</strong>, ';
    $html .= 'Fonction : <strong>' . htmlspecialchars($info['fonction'] ?? '……………') . '</strong>, ';
    $html .= 'est chargé(e) de superviser au nom de l\'IEPP YOPOUGON NIANGON les travaux des centres de ' . htmlspecialchars($listeCentres) . '.';
    $html .= '<br><br>';
    $html .= 'A ce titre, il/elle est habilité(e) à contrôler toutes les opérations qui concourent au bon déroulement de l\'examen, ';
    $html .= 'à assurer l\'équité pour tous et à fournir un rapport à l\'issue de sa mission.';
    $html .= '</div>';

    $html .= signatureIepp();
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('mission_supervision_' . $examen['code'] . '.pdf', ['Attachment' => false]);
