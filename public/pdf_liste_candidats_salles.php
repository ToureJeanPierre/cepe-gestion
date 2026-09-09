<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

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

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    h2 { font-size: 12px; color: #17365d; margin: 14px 0 6px; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    table.doc-table th, table.doc-table td { font-size: 9px; }
    .col-salle { width: 70px; text-align: center; font-weight: bold; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'LISTE DES CANDIDATS PAR SALLE');
$html .= '<div style="text-align:center; margin-bottom:8px;">' . htmlspecialchars($examen['libelle']) . ' — Ordre alphabétique, avec la salle de composition</div>';

if (!$centres) {
    $html .= '<p>Aucune liste disponible. Générez d\'abord le plan de salle puis la liste d\'émargement depuis le module Plans de salle.</p>';
}

$premiere = true;
foreach ($centres as $centre) {
    $stmtCand = $pdo->prepare("
        SELECT c.nom, c.prenoms, c.matricule_dsps, c.est_candidat_libre, ps.numero_salle
        FROM plan_salle_candidats psc
        INNER JOIN candidats c ON c.id = psc.candidat_id
        INNER JOIN plan_salles ps ON ps.id = psc.plan_salle_id
        WHERE ps.centre_effectif_id = ? AND psc.examen_id = ?
        ORDER BY c.nom ASC, c.prenoms ASC
    ");
    $stmtCand->execute([$centre['centre_effectif_id'], $examenId]);
    $candidats = $stmtCand->fetchAll();

    if (!$candidats) {
        continue;
    }

    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= '<h2>' . htmlspecialchars($centre['nom_centre']) . ' (' . count($candidats) . ' candidats)</h2>';
    $html .= '<table class="doc-table"><thead><tr><th>#</th><th>Nom</th><th>Prénoms</th><th>Matricule</th><th class="col-salle">Salle</th></tr></thead><tbody>';
    $i = 1;
    foreach ($candidats as $c) {
        $matricule = $c['matricule_dsps'] ?: ($c['est_candidat_libre'] ? 'Libre' : '-');
        $html .= '<tr>'
            . '<td>' . $i++ . '</td>'
            . '<td>' . htmlspecialchars($c['nom']) . '</td>'
            . '<td>' . htmlspecialchars($c['prenoms']) . '</td>'
            . '<td>' . htmlspecialchars($matricule) . '</td>'
            . '<td class="col-salle">Salle ' . (int) $c['numero_salle'] . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
}

$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('candidats_salles_' . $examen['code'] . '.pdf', ['Attachment' => false]);
