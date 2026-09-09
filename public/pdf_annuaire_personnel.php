<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$personnels = $pdo->query("
    SELECT p.*, e.nom AS nom_ecole
    FROM personnel p
    LEFT JOIN ecoles e ON e.id = p.ecole_id
    ORDER BY COALESCE(e.nom, 'ZZZ'), p.nom ASC, p.prenoms ASC
")->fetchAll();

$badgeCat = ['enseignant' => 'Enseignant', 'conseiller' => 'Conseiller', 'administratif' => 'Personnel Administratif'];

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('ANNEE SCOLAIRE ' . ($ANNEE_SCOLAIRE ?? ''), 'ANNUAIRE DU PERSONNEL');
$html .= '<div style="text-align:center; margin-bottom:10px;">' . count($personnels) . ' membres — édité le ' . date('d/m/Y') . '</div>';

$html .= '<table class="doc-table"><thead><tr>
    <th>Nom</th><th>Prénoms</th><th>Catégorie</th><th>Fonction</th><th>École</th>
    <th>Niveau tenu</th><th>Téléphone</th><th>Disponibilité</th>
</tr></thead><tbody>';

foreach ($personnels as $p) {
    $html .= '<tr>'
        . '<td>' . htmlspecialchars($p['nom']) . '</td>'
        . '<td>' . htmlspecialchars($p['prenoms']) . '</td>'
        . '<td>' . htmlspecialchars($badgeCat[$p['categorie']] ?? $p['categorie']) . '</td>'
        . '<td>' . htmlspecialchars($p['fonction'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($p['nom_ecole'] ?? 'Inspection') . '</td>'
        . '<td>' . htmlspecialchars($p['niveau_tenu'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($p['telephone'] ?? '-') . '</td>'
        . '<td>' . htmlspecialchars($p['disponibilite'] ?? '-') . '</td>'
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
$dompdf->stream('annuaire_personnel_' . date('Ymd') . '.pdf', ['Attachment' => false]);
