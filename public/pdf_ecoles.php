<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$ecoles = $pdo->query("
    SELECT e.*, t.nom AS nom_tuteur
    FROM ecoles e
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    ORDER BY e.nom ASC
")->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #263238; }
    h1 { font-size: 16px; color: #17365d; margin-bottom: 2px; }
    .subtitle { color: #6c757d; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; }
    th, td { border: 1px solid #ccc; padding: 4px 6px; text-align: left; }
    th { background: #f0f2f5; }
</style></head><body>';

$html .= '<h1>IEPP Yopougon-Niangon — Répertoire des écoles</h1>';
$html .= '<div class="subtitle">' . count($ecoles) . ' écoles — édité le ' . date('d/m/Y') . '</div>';

$html .= '<table><thead><tr>
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
$html .= '</tbody></table></body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('repertoire_ecoles_' . date('Ymd') . '.pdf', ['Attachment' => false]);
