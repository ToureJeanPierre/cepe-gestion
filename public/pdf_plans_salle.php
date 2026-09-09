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

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    h2 { font-size: 12px; color: #17365d; margin: 14px 0 2px; }
    .categories { font-style: italic; color: #495057; margin-bottom: 6px; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'REPARTITION DES CANDIDATS PAR CENTRE');
$html .= '<div style="text-align:center; margin-bottom:8px;">' . htmlspecialchars($examen['libelle']) . '</div>';

$numero = 1;
$premiere = true;
foreach ($centres as $centre) {
    $stmtSalles = $pdo->prepare("SELECT id, numero_salle, COALESCE(effectif_retenu, effectif_calcule) AS capacite, est_manuel FROM plan_salles WHERE centre_effectif_id = ? ORDER BY numero_salle ASC");
    $stmtSalles->execute([$centre['centre_effectif_id']]);
    $salles = $stmtSalles->fetchAll();

    $html .= (!$premiere) ? '<div class="page-break"></div>' : '';
    $premiere = false;

    $html .= '<h2>' . $numero++ . '. ' . htmlspecialchars($centre['nom_centre']) . ' (' . (int) $centre['effectif'] . ' candidats)</h2>';

    if (!$salles) {
        $html .= '<p><em>Aucun plan de salle généré pour ce centre.</em></p>';
        continue;
    }

    // "Catégories : X salles de Y et Z salles de W" — regroupe les salles de même capacité
    $groupesCapacite = [];
    foreach ($salles as $s) {
        $groupesCapacite[(int) $s['capacite']] = ($groupesCapacite[(int) $s['capacite']] ?? 0) + 1;
    }
    krsort($groupesCapacite);
    $descriptions = [];
    foreach ($groupesCapacite as $capacite => $nb) {
        $descriptions[] = "$nb salle" . ($nb > 1 ? 's' : '') . " de $capacite";
    }
    $html .= '<div class="categories">Catégories : ' . implode(' et ', $descriptions) . '</div>';

    $html .= '<table class="doc-table"><thead><tr><th>Salle</th><th>Effectif</th><th>Plage de numéros</th></tr></thead><tbody>';
    $total = 0;
    foreach ($salles as $s) {
        $total += (int) $s['capacite'];

        $stmtPlage = $pdo->prepare("SELECT MIN(numero_ordre) AS mini, MAX(numero_ordre) AS maxi FROM plan_salle_candidats WHERE plan_salle_id = ? AND examen_id = ?");
        $stmtPlage->execute([$s['id'], $examenId]);
        $plage = $stmtPlage->fetch();
        $plageTexte = ($plage && $plage['mini'] !== null) ? ($plage['mini'] . ' à ' . $plage['maxi']) : '—';

        $html .= '<tr><td>S' . (int) $s['numero_salle'] . '</td><td>' . (int) $s['capacite'] . '</td><td>' . $plageTexte . '</td></tr>';
    }
    $html .= '<tr class="total"><td>Total</td><td>' . $total . '</td><td></td></tr>';
    $html .= '</tbody></table>';
}

if (!$centres) {
    $html .= '<p>Aucun centre configuré pour cet examen.</p>';
}

$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('plans_de_salle_' . $examen['code'] . '.pdf', ['Attachment' => false]);
