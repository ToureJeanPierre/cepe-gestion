<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

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

const SEUIL_ADMISSION_EXPORT = 10.0;

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
        COALESCE(t.code_dsps, e.code_dsps) AS code_dsps_effectif,
        COALESCE(t.nom, e.nom) AS nom_ecole_effectif,
        c.nom, c.prenoms, c.sexe, c.date_naissance, c.matricule_dsps,
        n.note, n.present
    FROM candidats c
    INNER JOIN ecoles e ON e.id = c.ecole_id
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    LEFT JOIN notes n ON n.candidat_id = c.id AND n.examen_id = ?
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
$stmt->execute([$examenId, $anneeId]);
$lignes = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Export DSPS');

// NOTE : trame par défaut, à ajuster une fois le modèle Excel officiel DSPS fourni
// (ordre des colonnes précis, laissé "à plus tard" par l'IEPP).
$entetes = ['Code DSPS', 'École', 'Nom', 'Prénoms', 'Sexe', 'Date de naissance', 'Matricule DSPS', 'Note /20', 'Résultat'];
$sheet->fromArray($entetes, null, 'A1');
$sheet->getStyle('A1:I1')->getFont()->setBold(true);

$ligneExcel = 2;
foreach ($lignes as $l) {
    $resultat = $l['note'] === null ? '' : ((float) $l['note'] >= SEUIL_ADMISSION_EXPORT ? 'Admis' : 'Ajourné');
    $sheet->fromArray([
        $l['code_dsps_effectif'],
        $l['nom_ecole_effectif'],
        $l['nom'],
        $l['prenoms'],
        $l['sexe'],
        $l['date_naissance'] ? date('d/m/Y', strtotime($l['date_naissance'])) : '',
        $l['matricule_dsps'],
        $l['note'],
        $resultat,
    ], null, 'A' . $ligneExcel);
    $ligneExcel++;
}

foreach (range('A', 'I') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$nomFichier = 'export_dsps_' . $examen['code'] . '_' . date('Ymd') . '.xlsx';

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment;filename="' . $nomFichier . '"');
header('Cache-Control: max-age=0');

$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
