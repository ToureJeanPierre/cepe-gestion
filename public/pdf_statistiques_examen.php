<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;

const SEUIL_ADMISSION_STATS = 10.0;

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
    SELECT e.statut,
        COUNT(*) AS effectif,
        SUM(CASE WHEN n.note >= ? THEN 1 ELSE 0 END) AS admis
    FROM candidats c
    INNER JOIN ecoles e ON e.id = c.ecole_id
    INNER JOIN notes n ON n.candidat_id = c.id AND n.examen_id = ?
    WHERE c.annee_id = ? AND n.note IS NOT NULL
    GROUP BY e.statut
");
$stmt->execute([SEUIL_ADMISSION_STATS, $examenId, $anneeId]);
$parSecteur = [];
foreach ($stmt->fetchAll() as $row) {
    $parSecteur[$row['statut']] = ['effectif' => (int) $row['effectif'], 'admis' => (int) $row['admis']];
}
$effectifPublic = $parSecteur['Public']['effectif'] ?? 0;
$admisPublic = $parSecteur['Public']['admis'] ?? 0;
$effectifPrive = $parSecteur['Privé']['effectif'] ?? 0;
$admisPrive = $parSecteur['Privé']['admis'] ?? 0;

$pct = fn($admis, $effectif) => $effectif > 0 ? round($admis / $effectif * 100, 2) : 0;

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    table.doc-table th, table.doc-table td { text-align: center; }
    table.doc-table td:first-child, table.doc-table th:first-child { text-align: left; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('STATISTIQUE DE LA ' . strtoupper($examen['libelle']), '');

$html .= '<table class="doc-table" style="margin-top:10px;"><thead><tr><th></th><th>Effectif</th><th>Admis</th><th>Pourcentage</th></tr></thead><tbody>';
$html .= '<tr><td>PUBLIC</td><td>' . $effectifPublic . '</td><td>' . $admisPublic . '</td><td>' . $pct($admisPublic, $effectifPublic) . '</td></tr>';
$html .= '<tr><td>PRIVE</td><td>' . $effectifPrive . '</td><td>' . $admisPrive . '</td><td>' . $pct($admisPrive, $effectifPrive) . '</td></tr>';
$html .= '<tr style="font-weight:bold;"><td>TOTAL</td><td>' . ($effectifPublic + $effectifPrive) . '</td><td>' . ($admisPublic + $admisPrive) . '</td><td>' . $pct($admisPublic + $admisPrive, $effectifPublic + $effectifPrive) . '</td></tr>';
$html .= '</tbody></table>';

$html .= signatureIepp();
$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('statistiques_' . $examen['code'] . '.pdf', ['Attachment' => false]);
