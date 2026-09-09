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
$estFinal = $examen['code'] === 'CEPE_FINAL';

$stmt = $pdo->prepare("
    SELECT ce.id AS centre_effectif_id, ce.centre_id, e.nom AS nom_centre,
           (SELECT COUNT(*) FROM plan_salles WHERE centre_effectif_id = ce.id) AS nb_salles
    FROM centre_effectifs ce
    INNER JOIN centres c ON c.id = ce.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    WHERE ce.examen_id = ? AND c.annee_id = ?
    ORDER BY e.nom ASC
");
$stmt->execute([$examenId, $anneeId]);
$centres = $stmt->fetchAll();

$stmtOfficiels = $pdo->prepare("
    SELECT
        SUM(CASE WHEN ca.sexe = 'M' THEN 1 ELSE 0 END) AS g,
        SUM(CASE WHEN ca.sexe = 'F' THEN 1 ELSE 0 END) AS f
    FROM candidats ca
    INNER JOIN ecoles e ON e.id = ca.ecole_id
    WHERE ca.annee_id = ? AND ca.est_candidat_libre = 0 AND ca.matricule_verifie = 1 AND ca.droits_payes = 1
      AND (
            e.id IN (SELECT ecole_composante_id FROM ecole_centre WHERE centre_id = ?)
         OR e.ecole_tutrice_id IN (SELECT ecole_composante_id FROM ecole_centre WHERE centre_id = ?)
      )
");
$stmtLibres = $pdo->prepare("
    SELECT
        SUM(CASE WHEN sexe = 'M' THEN 1 ELSE 0 END) AS g,
        SUM(CASE WHEN sexe = 'F' THEN 1 ELSE 0 END) AS f
    FROM candidats
    WHERE annee_id = ? AND est_candidat_libre = 1 AND centre_examen_id = ?
");

$lignes = [];
$totaux = ['og' => 0, 'of' => 0, 'lg' => 0, 'lf' => 0, 'tg' => 0, 'tf' => 0, 'salles' => 0];

foreach ($centres as $centre) {
    $stmtOfficiels->execute([$anneeId, $centre['centre_id'], $centre['centre_id']]);
    $off = $stmtOfficiels->fetch();
    $og = (int) ($off['g'] ?? 0);
    $of = (int) ($off['f'] ?? 0);

    $lg = 0; $lf = 0;
    if ($estFinal) {
        $stmtLibres->execute([$anneeId, $centre['centre_id']]);
        $lib = $stmtLibres->fetch();
        $lg = (int) ($lib['g'] ?? 0);
        $lf = (int) ($lib['f'] ?? 0);
    }

    $lignes[] = [
        'nom_centre' => $centre['nom_centre'],
        'og' => $og, 'of' => $of,
        'lg' => $lg, 'lf' => $lf,
        'tg' => $og + $lg, 'tf' => $of + $lf,
        'nb_salles' => (int) $centre['nb_salles'],
    ];

    $totaux['og'] += $og; $totaux['of'] += $of;
    $totaux['lg'] += $lg; $totaux['lf'] += $lf;
    $totaux['tg'] += $og + $lg; $totaux['tf'] += $of + $lf;
    $totaux['salles'] += (int) $centre['nb_salles'];
}

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    table.doc-table th, table.doc-table td { text-align: center; }
    table.doc-table td:first-child, table.doc-table th:first-child { text-align: left; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'ANNEXE - REPARTITION DES CANDIDATS PAR CENTRE');
$html .= '<div style="text-align:center; margin-bottom:8px;">' . htmlspecialchars($examen['libelle']) . '</div>';

$html .= '<table class="doc-table"><thead><tr>
    <th rowspan="2">N&deg;</th><th rowspan="2">Centres d\'examen</th>
    <th colspan="3">Candidats Officiels</th><th colspan="3">Candidats Libres</th><th colspan="3">Total par genre</th>
    <th rowspan="2">Nombre de salle</th>
</tr><tr><th>G</th><th>F</th><th>Total</th><th>G</th><th>F</th><th>Total</th><th>G</th><th>F</th><th>Total</th></tr></thead><tbody>';

$i = 1;
foreach ($lignes as $l) {
    $html .= '<tr>'
        . '<td>' . $i++ . '</td>'
        . '<td style="text-align:left;">' . htmlspecialchars($l['nom_centre']) . '</td>'
        . '<td>' . $l['og'] . '</td><td>' . $l['of'] . '</td><td>' . ($l['og'] + $l['of']) . '</td>'
        . '<td>' . $l['lg'] . '</td><td>' . $l['lf'] . '</td><td>' . ($l['lg'] + $l['lf']) . '</td>'
        . '<td>' . $l['tg'] . '</td><td>' . $l['tf'] . '</td><td>' . ($l['tg'] + $l['tf']) . '</td>'
        . '<td>' . $l['nb_salles'] . '</td>'
        . '</tr>';
}

$html .= '<tr style="font-weight:bold;">'
    . '<td colspan="2">TOTAUX IEPP YOPOUGON NIANGON</td>'
    . '<td>' . $totaux['og'] . '</td><td>' . $totaux['of'] . '</td><td>' . ($totaux['og'] + $totaux['of']) . '</td>'
    . '<td>' . $totaux['lg'] . '</td><td>' . $totaux['lf'] . '</td><td>' . ($totaux['lg'] + $totaux['lf']) . '</td>'
    . '<td>' . $totaux['tg'] . '</td><td>' . $totaux['tf'] . '</td><td>' . ($totaux['tg'] + $totaux['tf']) . '</td>'
    . '<td>' . $totaux['salles'] . '</td>'
    . '</tr>';

$html .= '</tbody></table>';

if (!$lignes) {
    $html .= '<p>Aucun centre configuré pour cet examen.</p>';
}

$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('annexe_repartition_' . $examen['code'] . '.pdf', ['Attachment' => false]);
