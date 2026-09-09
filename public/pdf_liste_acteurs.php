<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Iepp\CepeGestion\AffectationEngine;

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
$filtreCentreId = isset($_GET['centre_id']) && $_GET['centre_id'] !== '' ? (int) $_GET['centre_id'] : null;

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

$sqlCentres = "
    SELECT DISTINCT a.centre_id, e.nom AS nom_centre
    FROM affectations a
    INNER JOIN centres c ON c.id = a.centre_id
    INNER JOIN ecoles e ON e.id = c.ecole_id
    WHERE a.annee_id = ? AND a.type_examen = ?
";
$paramsCentres = [$anneeId, $typeExamen];
if ($filtreCentreId) {
    $sqlCentres .= " AND a.centre_id = ?";
    $paramsCentres[] = $filtreCentreId;
}
$sqlCentres .= " ORDER BY e.nom ASC";

$stmt = $pdo->prepare($sqlCentres);
$stmt->execute($paramsCentres);
$centres = $stmt->fetchAll();

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    .champ-role { margin: 3px 0; }
    .champ-role strong { display: inline-block; width: 170px; }
    .page-break { page-break-before: always; }
</style></head><body>';

if (!$centres) {
    $html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
    $html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'LISTE DES ACTEURS DU CENTRE');
    $html .= '<p>Aucune affectation enregistrée pour cet examen. Générez d\'abord les affectations depuis le module Surveillance.</p>';
}

$premiere = true;
foreach ($centres as $centre) {
    $stmt = $pdo->prepare("
        SELECT a.role, a.plan_salle_id, ps.numero_salle,
               p.nom, p.prenoms, p.matricule, p.fonction, ecp.nom AS nom_ecole_personnel
        FROM affectations a
        INNER JOIN personnel p ON p.id = a.enseignant_id
        LEFT JOIN ecoles ecp ON ecp.id = p.ecole_id
        LEFT JOIN plan_salles ps ON ps.id = a.plan_salle_id
        WHERE a.annee_id = ? AND a.type_examen = ? AND a.centre_id = ?
        ORDER BY FIELD(a.role, 'Président','Chef Secrétariat','Membre Secrétariat','Superviseur','Surveillant','Suppléant'), ps.numero_salle ASC, p.nom ASC
    ");
    $stmt->execute([$anneeId, $typeExamen, $centre['centre_id']]);
    $lignes = $stmt->fetchAll();

    $chefCentre = null;
    $chefSecretariat = null;
    $membresSecretariat = [];
    $surveillants = [];

    foreach ($lignes as $l) {
        switch ($l['role']) {
            case 'Président': $chefCentre = $l; break;
            case 'Chef Secrétariat': $chefSecretariat = $l; break;
            case 'Membre Secrétariat': $membresSecretariat[] = $l; break;
            case 'Surveillant':
            case 'Suppléant':
                $surveillants[] = $l;
                break;
        }
    }

    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
    $html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'LISTE DES ACTEURS DU CENTRE');

    $html .= '<div class="champ-role"><strong>CENTRE :</strong> ' . htmlspecialchars($centre['nom_centre']) . '</div>';
    $html .= '<div class="champ-role"><strong>CHEF DE CENTRE :</strong> ' . ($chefCentre ? htmlspecialchars($chefCentre['nom'] . ' ' . $chefCentre['prenoms']) : '……………………………') . '</div>';
    $html .= '<div class="champ-role"><strong>CHEF DE SECRETARIAT :</strong> ' . ($chefSecretariat ? htmlspecialchars($chefSecretariat['nom'] . ' ' . $chefSecretariat['prenoms']) : '……………………………') . '</div>';
    $html .= '<div class="champ-role"><strong>MEMBRES DE SECRETARIAT :</strong></div>';
    if ($membresSecretariat) {
        foreach ($membresSecretariat as $m) {
            $html .= '<div style="margin-left:170px;">- ' . htmlspecialchars($m['nom'] . ' ' . $m['prenoms']) . '</div>';
        }
    } else {
        $html .= '<div style="margin-left:170px;">……………………………</div>';
    }

    $html .= '<table class="doc-table" style="margin-top:12px;"><thead><tr><th>N&deg;</th><th>Nom et Prénoms</th><th>Matricule</th><th>École</th><th>Fonction</th></tr></thead><tbody>';
    if ($surveillants) {
        $i = 1;
        foreach ($surveillants as $s) {
            $html .= '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars($s['nom'] . ' ' . $s['prenoms']) . '</td><td>' . htmlspecialchars($s['matricule'] ?? '-') . '</td><td>' . htmlspecialchars($s['nom_ecole_personnel'] ?? '-') . '</td><td>' . htmlspecialchars($s['fonction'] ?? '-') . '</td></tr>';
        }
    } else {
        $html .= '<tr><td colspan="5"><em>Aucun surveillant affecté.</em></td></tr>';
    }
    $html .= '</tbody></table>';

    $html .= signatureIepp();
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('liste_acteurs_' . $examen['code'] . '.pdf', ['Attachment' => false]);
