<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$ecoles = $pdo->query("
    SELECT e.*, t.nom AS nom_tuteur
    FROM ecoles e
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    ORDER BY e.nom ASC
")->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('ANNEE SCOLAIRE ' . ($ANNEE_SCOLAIRE ?? ''), 'REPERTOIRE DES ECOLES');
$html .= '<div style="text-align:center; margin-bottom:10px;">' . count($ecoles) . ' écoles — édité le ' . date('d/m/Y') . '</div>';

$html .= '<table class="doc-table"><thead><tr>
    <th>Nom</th><th>Code DSPS</th><th>Secteur</th><th>Rattachement</th>
    <th>Directeur</th><th>Contact</th><th>Groupe Scolaire</th><th>Centre ?</th>
</tr></thead><tbody>';

foreach ($ecoles as $e) {
    $rattachement = $e['ecole_tutrice_id'] ? htmlspecialchars($e['nom_tuteur']) : '-';
    $html .= '<tr>'
        . '<td>' . htmlspecialchars($e['nom']) . '</td>'
        . '<td>' . htmlspecialchars($e['code_dsps'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($e['statut']) . '</td>'
        . '<td>' . $rattachement . '</td>'
        . '<td>' . htmlspecialchars($e['directeur_nom'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($e['directeur_telephone'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($e['groupe_scolaire'] ?? '-') . '</td>'
        . '<td>' . ($e['est_centre_examen'] ? 'Oui' : '-') . '</td>'
        . '</tr>';
}
$html .= '</tbody></table>';
$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('repertoire_ecoles_' . date('Ymd') . '.pdf', ['Attachment' => false]);
