<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';
require_once __DIR__ . '/../src/cap_ceap_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$stmt = $pdo->prepare("SELECT * FROM cap_ceap_candidats WHERE annee_id = ? ORDER BY nature_examen ASC, nom ASC");
$stmt->execute([$anneeId]);
$candidats = $stmt->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    .destinataire { margin: 20px 0; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= numeroReferencePointille();
$html .= titreDocumentIepp('BORDEREAU RECAPITULATIF', 'DES PIECES ADRESSEES');

$html .= '<div class="destinataire">
    A<br>
    Monsieur le Directeur Régional<br>
    ABIDJAN 3
</div>';

$html .= '<div style="text-align:center; font-weight:bold; margin-bottom:8px;">LISTE DES CANDIDATS AU CAP ET CEAP SESSION ' . date('Y') . '</div>';

$html .= '<table class="doc-table"><thead><tr><th>N&deg;</th><th>Nom et Prénoms</th><th>Nature de l\'examen</th><th>Observations</th></tr></thead><tbody>';
$i = 1;
foreach ($candidats as $c) {
    $html .= '<tr>'
        . '<td>' . str_pad($i++, 2, '0', STR_PAD_LEFT) . '</td>'
        . '<td>' . htmlspecialchars($c['nom'] . ' ' . $c['prenoms']) . '</td>'
        . '<td>' . htmlspecialchars(CAP_CEAP_NATURES[$c['nature_examen']] ?? $c['nature_examen']) . '</td>'
        . '<td>' . htmlspecialchars($c['observations']) . '</td>'
        . '</tr>';
}
if (!$candidats) {
    $html .= '<tr><td colspan="4"><em>Aucun candidat enregistré.</em></td></tr>';
}
$html .= '</tbody></table>';

$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('bordereau_recapitulatif_cap_ceap.pdf', ['Attachment' => false]);
