<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';
require_once __DIR__ . '/../src/matieres_config.php';

use Dompdf\Dompdf;
use Dompdf\Options;

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

$listeMatieres = array_keys(matieresPourExamen($examen['code']));

// Candidats de cet examen (officiels validés + libres si Final) avec le secteur de leur école
$estFinal = $examen['code'] === 'CEPE_FINAL';
$stmt = $pdo->prepare("
    SELECT c.id, e.statut
    FROM candidats c
    LEFT JOIN ecoles e ON e.id = c.ecole_id
    WHERE c.annee_id = ?
      AND (
            (c.est_candidat_libre = 0 AND c.matricule_verifie = 1 AND c.droits_payes = 1)
         OR (c.est_candidat_libre = 1 AND ? = 1)
      )
");
$stmt->execute([$anneeId, $estFinal ? 1 : 0]);
$candidats = $stmt->fetchAll();

$notesParCandidat = [];
$stmtNotes = $pdo->prepare("SELECT candidat_id, matiere, note, present FROM notes WHERE examen_id = ?");
$stmtNotes->execute([$examenId]);
foreach ($stmtNotes->fetchAll() as $n) {
    $notesParCandidat[(int) $n['candidat_id']][$n['matiere']] = ['note' => $n['note'], 'present' => (int) $n['present']];
}

$parSecteur = ['Public' => ['effectif' => 0, 'admis' => 0], 'Privé' => ['effectif' => 0, 'admis' => 0]];
foreach ($candidats as $c) {
    $secteur = $c['statut'] ?? null;
    if (!isset($parSecteur[$secteur])) continue; // candidat libre sans école -> hors secteur

    $notesCandidat = $notesParCandidat[(int) $c['id']] ?? [];
    $estAbsent = false;
    $notesParMatiere = [];
    foreach ($listeMatieres as $matiere) {
        $ligneNote = $notesCandidat[$matiere] ?? null;
        if ($ligneNote && (int) $ligneNote['present'] === 0) {
            $estAbsent = true;
        }
        $notesParMatiere[$matiere] = $ligneNote['note'] ?? null;
    }
    if ($estAbsent) continue;

    $moyenne = calculerMoyenne20($notesParMatiere, $examen['code']);
    if ($moyenne === null) continue; // notes incomplètes, pas encore comptabilisé

    $parSecteur[$secteur]['effectif']++;
    if ($moyenne >= SEUIL_ADMISSION_CEPE) {
        $parSecteur[$secteur]['admis']++;
    }
}

$effectifPublic = $parSecteur['Public']['effectif'];
$admisPublic = $parSecteur['Public']['admis'];
$effectifPrive = $parSecteur['Privé']['effectif'];
$admisPrive = $parSecteur['Privé']['admis'];

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
