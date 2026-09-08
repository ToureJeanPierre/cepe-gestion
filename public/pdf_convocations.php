<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Iepp\CepeGestion\AffectationEngine;

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

$typeExamen = AffectationEngine::libelleTypeExamen($examen['code']);

$stmt = $pdo->prepare("
    SELECT a.role, a.plan_salle_id, ps.numero_salle,
           p.nom, p.prenoms, p.telephone, ecp.nom AS nom_ecole_personnel,
           e.nom AS nom_centre, a.centre_id
    FROM affectations a
    INNER JOIN personnel p ON p.id = a.enseignant_id
    INNER JOIN centres c ON c.id = a.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    LEFT JOIN ecoles ecp ON ecp.id = p.ecole_id
    LEFT JOIN plan_salles ps ON ps.id = a.plan_salle_id
    WHERE a.annee_id = ? AND a.type_examen = ?
    ORDER BY e.nom ASC, FIELD(a.role, 'Président','Chef Secrétariat','Membre Secrétariat','Superviseur','Surveillant','Suppléant'), ps.numero_salle ASC, p.nom ASC
");
$stmt->execute([$anneeId, $typeExamen]);
$lignes = $stmt->fetchAll();

$parCentre = [];
foreach ($lignes as $l) {
    $parCentre[$l['nom_centre']][] = $l;
}

$html = '<html><head><meta charset="UTF-8"><style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #263238; }
    h1 { font-size: 16px; color: #17365d; margin-bottom: 2px; }
    h2 { font-size: 13px; color: #17365d; margin-top: 0; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    .subtitle { color: #6c757d; margin-bottom: 15px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
    th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; }
    th { background: #f0f2f5; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= '<h1>IEPP Yopougon-Niangon — Convocations des surveillants et responsables de centre</h1>';
$html .= '<div class="subtitle">' . htmlspecialchars($examen['libelle']) . '</div>';

if (!$parCentre) {
    $html .= '<p>Aucune affectation enregistrée pour cet examen. Générez d\'abord les affectations depuis le module Surveillance.</p>';
}

$premiere = true;
foreach ($parCentre as $nomCentre => $personnes) {
    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= '<h2>Centre : ' . htmlspecialchars($nomCentre) . '</h2>';
    $html .= '<table><thead><tr><th>Rôle</th><th>Nom</th><th>Prénoms</th><th>École d\'origine</th><th>Téléphone</th><th>Salle</th></tr></thead><tbody>';
    foreach ($personnes as $p) {
        $html .= '<tr>'
            . '<td>' . htmlspecialchars($p['role']) . '</td>'
            . '<td>' . htmlspecialchars($p['nom']) . '</td>'
            . '<td>' . htmlspecialchars($p['prenoms']) . '</td>'
            . '<td>' . htmlspecialchars($p['nom_ecole_personnel'] ?? '-') . '</td>'
            . '<td>' . htmlspecialchars($p['telephone'] ?? '-') . '</td>'
            . '<td>' . ($p['numero_salle'] ? 'Salle ' . (int) $p['numero_salle'] : '-') . '</td>'
            . '</tr>';
    }
    $html .= '</tbody></table>';
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('convocations_' . $examen['code'] . '.pdf', ['Attachment' => false]);
