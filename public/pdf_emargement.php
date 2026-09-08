<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
if (!$examenId || !$anneeId) {
    die("Examen invalide.");
}

$stmt = $pdo->prepare("SELECT id, code, libelle FROM examens WHERE id = ? AND annee_id = ?");
$stmt->execute([$examenId, $anneeId]);
$examen = $stmt->fetch();
if (!$examen) {
    die("Examen introuvable pour l'année scolaire consultée.");
}

$stmt = $pdo->prepare("
    SELECT DISTINCT ce.id AS centre_effectif_id, e.nom AS nom_centre
    FROM centre_effectifs ce
    INNER JOIN centres c ON c.id = ce.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    INNER JOIN plan_salles ps ON ps.centre_effectif_id = ce.id
    INNER JOIN plan_salle_candidats psc ON psc.plan_salle_id = ps.id AND psc.examen_id = ?
    WHERE ce.examen_id = ? AND c.annee_id = ?
    ORDER BY e.nom ASC
");
$stmt->execute([$examenId, $examenId, $anneeId]);
$centres = $stmt->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #263238; }
    h1 { font-size: 16px; color: #17365d; margin-bottom: 2px; }
    h2 { font-size: 13px; color: #17365d; margin-top: 0; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    h3 { font-size: 11px; color: #495057; margin-top: 10px; }
    .subtitle { color: #6c757d; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #f0f2f5; }
    .col-sign { width: 90px; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= '<h1>IEPP Yopougon-Niangon — Listes d&#39;émargement</h1>';
$html .= '<div class="subtitle">' . htmlspecialchars($examen['libelle']) . '</div>';

if (!$centres) {
    $html .= '<p>Aucune liste d\'émargement disponible. Générez d\'abord le plan de salle puis la liste d\'émargement depuis le module Plans de salle.</p>';
}

$premiereCentre = true;
foreach ($centres as $centre) {
    $stmtSalles = $pdo->prepare("SELECT id, numero_salle FROM plan_salles WHERE centre_effectif_id = ? ORDER BY numero_salle ASC");
    $stmtSalles->execute([$centre['centre_effectif_id']]);
    $salles = $stmtSalles->fetchAll();

    $html .= $premiereCentre ? '' : '<div class="page-break"></div>';
    $premiereCentre = false;

    $html .= '<h2>' . htmlspecialchars($centre['nom_centre']) . '</h2>';

    $premiereSalle = true;
    foreach ($salles as $salle) {
        $stmtCand = $pdo->prepare("
            SELECT c.nom, c.prenoms, c.matricule_dsps, c.est_candidat_libre
            FROM plan_salle_candidats psc
            INNER JOIN candidats c ON c.id = psc.candidat_id
            WHERE psc.plan_salle_id = ? AND psc.examen_id = ?
            ORDER BY psc.numero_ordre ASC
        ");
        $stmtCand->execute([$salle['id'], $examenId]);
        $candidatsSalle = $stmtCand->fetchAll();

        if (!$candidatsSalle) {
            continue;
        }

        $html .= $premiereSalle ? '' : '<div class="page-break"></div>';
        $premiereSalle = false;

        $html .= '<h3>Salle ' . (int) $salle['numero_salle'] . ' (' . count($candidatsSalle) . ' candidats)</h3>';
        $html .= '<table><thead><tr><th>#</th><th>Nom</th><th>Prénoms</th><th>Matricule</th><th class="col-sign">Émargement</th></tr></thead><tbody>';
        $i = 1;
        foreach ($candidatsSalle as $c) {
            $matricule = $c['matricule_dsps'] ?: ($c['est_candidat_libre'] ? 'Libre' : '-');
            $html .= '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars($c['nom']) . '</td><td>' . htmlspecialchars($c['prenoms']) . '</td><td>' . htmlspecialchars($matricule) . '</td><td></td></tr>';
        }
        $html .= '</tbody></table>';
    }
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('emargement_' . $examen['code'] . '.pdf', ['Attachment' => false]);
