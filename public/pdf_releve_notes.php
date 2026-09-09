<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';
require_once __DIR__ . '/../src/matieres_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
$filtreEcole = isset($_GET['ecole_id']) && $_GET['ecole_id'] !== '' ? (int) $_GET['ecole_id'] : null;
$tri = ($_GET['tri'] ?? 'alpha') === 'merite' ? 'merite' : 'alpha';

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
$matieres = matieresPourExamen($examen['code']);
$listeMatieres = array_keys($matieres);
$totalMax = array_sum($matieres);

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

// Notes déjà saisies pour cet examen
$notesParCandidat = [];
$stmtNotes = $pdo->prepare("SELECT candidat_id, matiere, note, present FROM notes WHERE examen_id = ?");
$stmtNotes->execute([$examenId]);
foreach ($stmtNotes->fetchAll() as $n) {
    $notesParCandidat[(int) $n['candidat_id']][$n['matiere']] = ['note' => $n['note'], 'present' => (int) $n['present']];
}

// Calcule notes/total/moyenne/résultat pour chaque candidat
foreach ($candidats as &$c) {
    $notesC = $notesParCandidat[$c['id']] ?? [];
    $absent = false;
    $notesParMatiere = [];
    foreach ($listeMatieres as $matiere) {
        $ln = $notesC[$matiere] ?? null;
        if ($ln && (int) $ln['present'] === 0) $absent = true;
        $notesParMatiere[$matiere] = $ln['note'] ?? null;
    }
    $moyenne = $absent ? null : calculerMoyenne20($notesParMatiere, $examen['code']);
    $total = null;
    if (!$absent && !in_array(null, $notesParMatiere, true)) {
        $total = array_sum($notesParMatiere);
    }
    $c['_notes'] = $notesParMatiere;
    $c['_absent'] = $absent;
    $c['_total'] = $total;
    $c['_moyenne'] = $moyenne;
    $c['_observation'] = $absent ? 'Absent' : ($moyenne === null ? '' : ($moyenne >= SEUIL_ADMISSION_CEPE ? 'Admis' : 'Refusé'));
}
unset($c);

$groupes = [];
foreach ($candidats as $c) {
    $groupes[$c['nom_ecole']][] = $c;
}

// Rang de chaque candidat au sein de son école (moyenne décroissante, ex-aequo
// départagés par nom) — calculé une seule fois, réutilisé dans les deux modes
// d'affichage (alphabétique ou mérite). Pas de rang tant que la moyenne n'est
// pas calculable (notes incomplètes ou absence).
foreach ($groupes as $nomEcole => &$lignes) {
    $classement = $lignes;
    usort($classement, function ($a, $b) {
        if ($a['_moyenne'] === null && $b['_moyenne'] === null) return strcmp($a['nom'], $b['nom']);
        if ($a['_moyenne'] === null) return 1;
        if ($b['_moyenne'] === null) return -1;
        return $b['_moyenne'] <=> $a['_moyenne'];
    });
    $rangCourant = 1;
    $rangParId = [];
    foreach ($classement as $c) {
        $rangParId[$c['id']] = $c['_moyenne'] !== null ? $rangCourant++ : null;
    }
    foreach ($lignes as &$c) {
        $c['_rang'] = $rangParId[$c['id']];
    }
    unset($c);
}
unset($lignes);

/*
|--------------------------------------------------------------------------
| GÉNÉRATION DU HTML
|--------------------------------------------------------------------------
*/
$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    h2 { font-size: 12px; color: #17365d; margin-top: 20px; border-bottom: 1px solid #17365d; padding-bottom: 3px; }
    h3 { font-size: 11px; color: #495057; margin-top: 10px; }
    .col-note { width: 50px; text-align: center; }
    .col-rang { width: 35px; text-align: center; }
    table.doc-table th, table.doc-table td { font-size: 9px; text-align: center; }
    table.doc-table td.gauche, table.doc-table th.gauche { text-align: left; }
    .badge-admis { color: #1a7d3a; font-weight: bold; }
    .badge-refuse { color: #b02a2a; font-weight: bold; }
    .page-break { page-break-before: always; }
</style></head><body>';

$html .= enteteIepp($ANNEE_SCOLAIRE ?? '');
$html .= titreDocumentIepp('CEPE SESSION ' . date('Y'), 'RELEVÉ DE NOTES');
$html .= '<div style="text-align:center; margin-bottom:8px;">' . htmlspecialchars($examen['libelle']) . ' — Classement ' . ($tri === 'merite' ? 'par ordre de mérite' : 'par ordre alphabétique') . '</div>';

function celluleObservation(string $obs): string
{
    if ($obs === 'Admis') return '<span class="badge-admis">Admis</span>';
    if ($obs === 'Refusé') return '<span class="badge-refuse">Refusé</span>';
    if ($obs === 'Absent') return 'Absent';
    return '—';
}

function enTeteColonnesNotes(array $matieres): string
{
    $html = '';
    foreach ($matieres as $matiere => $max) {
        $html .= '<th class="col-note">' . htmlspecialchars($matiere) . '<br>/' . $max . '</th>';
    }
    return $html;
}

function ligneNotes(array $c, array $listeMatieres): string
{
    $html = '';
    foreach ($listeMatieres as $matiere) {
        $val = $c['_notes'][$matiere] ?? null;
        $html .= '<td class="col-note">' . ($val !== null ? htmlspecialchars($val) : ($c['_absent'] ? '-' : '')) . '</td>';
    }
    return $html;
}

$premiere = true;
foreach ($groupes as $nomEcole => $lignes) {
    $html .= $premiere ? '' : '<div class="page-break"></div>';
    $premiere = false;

    $html .= '<h2>' . htmlspecialchars($nomEcole) . '</h2>';

    if ($tri === 'alpha') {
        $sectionA = array_filter($lignes, fn ($c) => !empty($c['matricule_dsps']));
        $sectionB = array_filter($lignes, fn ($c) => empty($c['matricule_dsps']));

        foreach (['Section A — Avec Matricule DSPS' => $sectionA, 'Section B — Sans Matricule DSPS' => $sectionB] as $titre => $section) {
            $html .= '<h3>' . $titre . ' (' . count($section) . ')</h3>';
            if (!$section) {
                $html .= '<p><em>Aucun candidat.</em></p>';
                continue;
            }
            $html .= '<table class="doc-table"><thead><tr><th class="gauche">Matricule</th><th class="gauche">Nom</th><th class="gauche">Prénoms</th>' . enTeteColonnesNotes($matieres) . '<th>Total /' . $totalMax . '</th><th>Moyenne /20</th><th>Rang</th><th>Observation</th></tr></thead><tbody>';
            foreach ($section as $c) {
                $html .= '<tr>'
                    . '<td class="gauche">' . htmlspecialchars($c['matricule_dsps'] ?? '-') . '</td>'
                    . '<td class="gauche">' . htmlspecialchars($c['nom']) . '</td>'
                    . '<td class="gauche">' . htmlspecialchars($c['prenoms']) . '</td>'
                    . ligneNotes($c, $listeMatieres)
                    . '<td>' . ($c['_total'] !== null ? htmlspecialchars($c['_total']) : '—') . '</td>'
                    . '<td><strong>' . ($c['_moyenne'] !== null ? htmlspecialchars($c['_moyenne']) : '—') . '</strong></td>'
                    . '<td>' . ($c['_rang'] !== null ? $c['_rang'] : 'n/c') . '</td>'
                    . '<td>' . celluleObservation($c['_observation']) . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }
    } else {
        // Ordre du mérite : classement unique par moyenne décroissante (les
        // candidats sans moyenne complète — non saisie ou absents — en fin de
        // liste, non classés).
        usort($lignes, function ($a, $b) {
            if ($a['_moyenne'] === null && $b['_moyenne'] === null) return strcmp($a['nom'], $b['nom']);
            if ($a['_moyenne'] === null) return 1;
            if ($b['_moyenne'] === null) return -1;
            return $b['_moyenne'] <=> $a['_moyenne'];
        });

        $html .= '<table class="doc-table"><thead><tr><th class="gauche">Matricule</th><th class="gauche">Nom</th><th class="gauche">Prénoms</th>' . enTeteColonnesNotes($matieres) . '<th>Total /' . $totalMax . '</th><th>Moyenne /20</th><th>Rang</th><th>Observation</th></tr></thead><tbody>';
        foreach ($lignes as $c) {
            $html .= '<tr>'
                . '<td class="gauche">' . htmlspecialchars($c['matricule_dsps'] ?? '-') . '</td>'
                . '<td class="gauche">' . htmlspecialchars($c['nom']) . '</td>'
                . '<td class="gauche">' . htmlspecialchars($c['prenoms']) . '</td>'
                . ligneNotes($c, $listeMatieres)
                . '<td>' . ($c['_total'] !== null ? htmlspecialchars($c['_total']) : '—') . '</td>'
                . '<td><strong>' . ($c['_moyenne'] !== null ? htmlspecialchars($c['_moyenne']) : '—') . '</strong></td>'
                . '<td>' . ($c['_rang'] !== null ? $c['_rang'] : 'n/c') . '</td>'
                . '<td>' . celluleObservation($c['_observation']) . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';
    }
}

if (!$groupes) {
    $html .= '<p>Aucun candidat éligible pour cet examen.</p>';
}

$html .= signatureIepp();
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
$dompdf->setPaper('A4', 'landscape');
$dompdf->render();
$dompdf->stream('releve_notes_' . $examen['code'] . '_' . $tri . '.pdf', ['Attachment' => false]);
