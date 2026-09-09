<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';
require_once __DIR__ . '/../src/cap_ceap_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$nature = $_GET['nature'] ?? '';
if (!array_key_exists($nature, CAP_CEAP_NATURES)) {
    die("Nature d'examen invalide.");
}
if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$stmt = $pdo->prepare("SELECT * FROM cap_ceap_candidats WHERE annee_id = ? AND nature_examen = ? ORDER BY nom ASC");
$stmt->execute([$anneeId, $nature]);
$candidats = $stmt->fetchAll();

$pieces = CAP_CEAP_PIECES[$nature];

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    table.doc-table th, table.doc-table td { font-size: 8px; text-align: center; }
    table.doc-table td:nth-child(2), table.doc-table th:nth-child(2) { text-align: left; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp(strtoupper(CAP_CEAP_NATURES[$nature]) . ' - SESSION ' . date('Y'), 'BORDEREAUX DE TRANSMISSION DES DOSSIERS D\'INSCRIPTION');
$html .= '<div style="text-align:center; margin-bottom:8px;">IEPP : YOPOUGON NIANGON &nbsp;&nbsp;&nbsp; DRENA/ET : ABIDJAN 3 &nbsp;&nbsp;&nbsp; Date : ' . date('d/m/Y') . '</div>';

$html .= '<table class="doc-table"><thead><tr><th rowspan="2">N&deg;</th><th rowspan="2">Nom et Prénoms</th>';
foreach ($pieces as $piece) {
    $html .= '<th>' . htmlspecialchars($piece) . '</th>';
}
$html .= '<th rowspan="2">Observations</th></tr></thead><tbody>';

$i = 1;
foreach ($candidats as $c) {
    $piecesFournies = json_decode($c['pieces_json'] ?? '{}', true) ?: [];
    $html .= '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars($c['nom'] . ' ' . $c['prenoms']) . '</td>';
    foreach ($pieces as $piece) {
        $html .= '<td>' . (!empty($piecesFournies[$piece]) ? 'OUI' : 'NON') . '</td>';
    }
    $html .= '<td>' . htmlspecialchars($c['observations']) . '</td></tr>';
}
if (!$candidats) {
    $html .= '<tr><td colspan="' . (count($pieces) + 3) . '"><em>Aucun candidat pour cette nature d\'examen.</em></td></tr>';
}
$html .= '</tbody></table>';

$html .= '<div style="margin-top:30px;">Nom, prénoms, contact, signature<br>et cachet du Gestionnaire examens DRENA</div>';
$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('bordereau_detail_' . $nature . '.pdf', ['Attachment' => false]);
