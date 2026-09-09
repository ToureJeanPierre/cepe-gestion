<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/matieres_config.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

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

$matieres = matieresPourExamen($examen['code']);
$listeMatieres = array_keys($matieres);

/*
|--------------------------------------------------------------------------
| CONSOLIDATION DSPS
|--------------------------------------------------------------------------
| Uniquement les candidats officiels immatriculés et validés (matricule DSPS
| vérifié + droits payés). Les écoles rattachées / sous-tutelle sont
| réagglomérées sous le code DSPS et le nom de leur école tutrice
| (règle 7.3 du cahier des charges — cas Cité SODEFOR inclus, puisqu'il
| utilise le même mécanisme générique tutrice/rattachée).
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        c.id AS candidat_id,
        COALESCE(t.code_dsps, e.code_dsps) AS code_dsps_effectif,
        COALESCE(t.nom, e.nom) AS nom_ecole_effectif,
        c.nom, c.prenoms, c.sexe, c.date_naissance, c.matricule_dsps
    FROM candidats c
    INNER JOIN ecoles e ON e.id = c.ecole_id
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    WHERE c.annee_id = ?
      AND c.est_candidat_libre = 0
      AND c.matricule_verifie = 1
      AND c.droits_payes = 1
      AND c.matricule_dsps IS NOT NULL
      AND c.matricule_dsps != ''
      AND COALESCE(t.code_dsps, e.code_dsps) IS NOT NULL
    ORDER BY code_dsps_effectif ASC, c.nom ASC, c.prenoms ASC
";
$stmt = $pdo->prepare($sql);
$stmt->execute([$anneeId]);
$lignes = $stmt->fetchAll();

// Notes par matière pour cet examen, indexées par candidat
$notesParCandidat = [];
$stmtNotes = $pdo->prepare("SELECT candidat_id, matiere, note, present FROM notes WHERE examen_id = ?");
$stmtNotes->execute([$examenId]);
foreach ($stmtNotes->fetchAll() as $n) {
    $notesParCandidat[(int) $n['candidat_id']][$n['matiere']] = ['note' => $n['note'], 'present' => (int) $n['present']];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Export DSPS');

// NOTE : trame par défaut, à ajuster une fois le modèle Excel officiel DSPS fourni
// (ordre des colonnes précis, laissé "à plus tard" par l'IEPP).
$entetes = array_merge(
    ['Code DSPS', 'École', 'Nom', 'Prénoms', 'Sexe', 'Date de naissance', 'Matricule DSPS'],
    array_map(fn ($m) => $m . ' /' . $matieres[$m], $listeMatieres),
    ['Moyenne /20', 'Résultat']
);
$derniereColonne = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($entetes));
$sheet->fromArray($entetes, null, 'A1');
$sheet->getStyle('A1:' . $derniereColonne . '1')->getFont()->setBold(true);

$ligneExcel = 2;
foreach ($lignes as $l) {
    $notesCandidat = $notesParCandidat[(int) $l['candidat_id']] ?? [];
    $estAbsent = false;
    $notesParMatiere = [];
    $valeursMatieres = [];
    foreach ($listeMatieres as $matiere) {
        $ligneNote = $notesCandidat[$matiere] ?? null;
        if ($ligneNote && (int) $ligneNote['present'] === 0) {
            $estAbsent = true;
        }
        $notesParMatiere[$matiere] = $ligneNote['note'] ?? null;
        $valeursMatieres[] = $ligneNote['note'] ?? '';
    }
    $moyenne = $estAbsent ? null : calculerMoyenne20($notesParMatiere, $examen['code']);
    $resultat = $estAbsent ? 'Absent' : ($moyenne === null ? '' : ($moyenne >= SEUIL_ADMISSION_CEPE ? 'Admis' : 'Ajourné'));

    $ligne = array_merge(
        [
            $l['code_dsps_effectif'],
            $l['nom_ecole_effectif'],
            $l['nom'],
            $l['prenoms'],
            $l['sexe'],
            $l['date_naissance'] ? date('d/m/Y', strtotime($l['date_naissance'])) : '',
            $l['matricule_dsps'],
        ],
        $valeursMatieres,
        [$moyenne, $resultat]
    );
    $sheet->fromArray($ligne, null, 'A' . $ligneExcel);
    $ligneExcel++;
}

foreach (range(1, count($entetes)) as $i) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$nomFichier = 'export_dsps_' . $examen['code'] . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nomFichier . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
