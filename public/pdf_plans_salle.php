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
    SELECT ce.id AS centre_effectif_id, e.nom AS nom_centre,
           COALESCE(ce.effectif_retenu, ce.effectif_calcule) AS effectif
    FROM centre_effectifs ce
    INNER JOIN centres c ON c.id = ce.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    WHERE ce.examen_id = ? AND c.annee_id = ?
    ORDER BY e.nom ASC
");
$stmt->execute([$examenId, $anneeId]);
$centres = $stmt->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #263238; }
    h1 { font-size: 16px; color: #17365d; margin-bottom: 2px; }
    h2 { font-size: 13px; color: #17365d; margin-top: 0; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    .subtitle { color: #6c757d; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
    th { background: #f0f2f5; }
    .page-break { page-break-before: always; }
    .total { font-weight: bold; }
</style></head><body>';

$html .= '<h1>IEPP Yopougon-Niangon — Plans de salle par centre</h1>';
$html .= '<div class="subtitle">' . htmlspecialchars($examen['libelle']) . '</div>';

$premiere = true;
foreach ($centres as $centre) {
    $stmtSalles = $pdo->prepare("SELECT numero_salle, COALESCE(effectif_retenu, effectif_calcule) AS capacite, est_manuel FROM plan_salles WHERE centre_effectif_id = ? ORDER BY numero_salle ASC");
    $stmtSalles->execute([$centre['centre_effectif_id']]);
    $salles = $stmtSalles->fetchAll();

    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= '<h2>' . htmlspecialchars($centre['nom_centre']) . ' — ' . (int) $centre['effectif'] . ' candidat(s)</h2>';

    if (!$salles) {
        $html .= '<p><em>Aucun plan de salle généré pour ce centre.</em></p>';
        continue;
    }

    $html .= '<table><thead><tr><th>Salle</th><th>Effectif</th><th>Origine</th></tr></thead><tbody>';
    $total = 0;
    foreach ($salles as $s) {
        $total += (int) $s['capacite'];
        $html .= '<tr><td>Salle ' . (int) $s['numero_salle'] . '</td><td>' . (int) $s['capacite'] . '</td><td>' . ($s['est_manuel'] ? 'Manuel' : 'Automatique') . '</td></tr>';
    }
    $html .= '<tr class="total"><td>Total</td><td>' . $total . '</td><td></td></tr>';
    $html .= '</tbody></table>';
}

if (!$centres) {
    $html .= '<p>Aucun centre configuré pour cet examen.</p>';
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('plans_de_salle_' . $examen['code'] . '.pdf', ['Attachment' => false]);
