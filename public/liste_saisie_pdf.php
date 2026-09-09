<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
$filtreEcole = isset($_GET['ecole_id']) && $_GET['ecole_id'] !== '' ? (int) $_GET['ecole_id'] : null;

if (!$examenId || !$anneeId) {
    die("Examen invalide.");
}

$stmt = $pdo->prepare("SELECT id, code, libelle FROM examens WHERE id = ? AND annee_id = ?");
$stmt->execute([$examenId, $anneeId]);
$examen = $stmt->fetch();
if (!$examen) {
    die("Examen introuvable pour l'année scolaire consultée.");
}
$estFinal = $examen['code'] === 'CEPE_FINAL';

/*
|--------------------------------------------------------------------------
| CANDIDATS ÉLIGIBLES (même règle que resultats.php), groupés par école
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT c.id, c.nom, c.prenoms, c.matricule_dsps, c.est_candidat_libre,
           COALESCE(e.nom, 'Candidats Libres') AS nom_ecole
    FROM candidats c
    LEFT JOIN ecoles e ON e.id = c.ecole_id
    WHERE c.annee_id = ?
      AND (
            (c.est_candidat_libre = 0 AND c.matricule_verifie = 1 AND c.droits_payes = 1)
         OR (c.est_candidat_libre = 1 AND ? = 1)
      )
";
$params = [$anneeId, $estFinal ? 1 : 0];

if ($filtreEcole) {
    $sql .= " AND c.ecole_id = ?";
    $params[] = $filtreEcole;
}

$sql .= " ORDER BY nom_ecole ASC, c.nom ASC, c.prenoms ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidats = $stmt->fetchAll();

$groupes = [];
foreach ($candidats as $c) {
    $groupes[$c['nom_ecole']][] = $c;
}

/*
|--------------------------------------------------------------------------
| GÉNÉRATION DU HTML (Section A : avec matricule / Section B : sans matricule)
|--------------------------------------------------------------------------
*/
$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    h2 { font-size: 12px; color: #17365d; margin-top: 20px; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    h3 { font-size: 11px; color: #495057; margin-top: 10px; }
    .col-note { width: 60px; text-align: center; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'LISTE DE SAISIE DES NOTES');
$html .= '<div style="text-align:center; margin-bottom:8px;">' . htmlspecialchars($examen['libelle']) . '</div>';

$premiere = true;
foreach ($groupes as $nomEcole => $lignes) {
    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= '<h2>' . htmlspecialchars($nomEcole) . '</h2>';

    $sectionA = array_filter($lignes, fn($c) => !empty($c['matricule_dsps']));
    $sectionB = array_filter($lignes, fn($c) => empty($c['matricule_dsps']));

    foreach (['Section A — Avec Matricule DSPS' => $sectionA, 'Section B — Sans Matricule DSPS' => $sectionB] as $titre => $section) {
        $html .= '<h3>' . $titre . ' (' . count($section) . ')</h3>';
        if (!$section) {
            $html .= '<p><em>Aucun candidat.</em></p>';
            continue;
        }
        $html .= '<table><thead><tr><th>#</th><th>Nom</th><th>Prénoms</th><th>Matricule DSPS</th><th class="col-note">Note /20</th></tr></thead><tbody>';
        $i = 1;
        foreach ($section as $c) {
            $html .= '<tr><td>' . $i++ . '</td><td>' . htmlspecialchars($c['nom']) . '</td><td>' . htmlspecialchars($c['prenoms']) . '</td><td>' . htmlspecialchars($c['matricule_dsps'] ?? '-') . '</td><td class="col-note"></td></tr>';
        }
        $html .= '</tbody></table>';
    }
}

if (!$groupes) {
    $html .= '<p>Aucun candidat éligible pour cet examen.</p>';
}

$html .= '</body></html>';

/*
|--------------------------------------------------------------------------
| RENDU PDF
|--------------------------------------------------------------------------
*/
$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('listes_saisie_' . $examen['code'] . '.pdf', ['Attachment' => false]);
