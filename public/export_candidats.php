<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Tous les candidats de l'année consultée. Les 11 premières colonnes suivent
// l'ordre de l'import Excel de candidats.php (fichier corrigeable puis
// ré-importable) ; les colonnes d'état de validation viennent après.
$stmt = $pdo->prepare("
    SELECT
        c.nom, c.prenoms, c.sexe, c.nationalite, c.date_naissance, c.lieu_naissance,
        c.matricule_dsps, c.a_acte_naissance, c.statut_demande,
        c.est_candidat_libre, e.nom AS ecole_nom, e.code_dsps AS ecole_code,
        c.matricule_verifie, c.droits_payes,
        ce.nom AS centre_nom
    FROM candidats c
    LEFT JOIN ecoles e ON e.id = c.ecole_id
    LEFT JOIN centres ct ON ct.id = c.centre_examen_id
    LEFT JOIN ecoles ce ON ce.id = ct.ecole_id
    WHERE c.annee_id = ?
    ORDER BY e.nom ASC, c.nom ASC, c.prenoms ASC
");
$stmt->execute([$anneeId]);
$candidats = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Candidats');

$sheet->fromArray([
    'Nom', 'Prénoms', 'Sexe', 'Nationalité', 'Date de naissance', 'Lieu de naissance',
    'Matricule DSPS', 'Acte de naissance (O/N)', 'Statut demande', 'École (ou LIBRE)', 'Code DSPS école',
    '',
    'Matricule vérifié (O/N)', 'Droits payés (O/N)', 'Centre d\'examen (candidats libres)',
], null, 'A1');

$ligne = 2;
foreach ($candidats as $c) {
    $libre = (int) $c['est_candidat_libre'] === 1;
    $date = $c['date_naissance'] ? date('d/m/Y', strtotime($c['date_naissance'])) : '';
    $sheet->fromArray([
        $c['nom'], $c['prenoms'], $c['sexe'], $c['nationalite'], $date, $c['lieu_naissance'],
        '', (int) $c['a_acte_naissance'] === 1 ? 'O' : 'N', $c['statut_demande'],
        $libre ? 'LIBRE' : $c['ecole_nom'], $libre ? '' : $c['ecole_code'],
        '',
        (int) $c['matricule_verifie'] === 1 ? 'O' : 'N', (int) $c['droits_payes'] === 1 ? 'O' : 'N', $c['centre_nom'],
    ], null, 'A' . $ligne);
    // Texte explicite : un matricule ne doit jamais être converti en nombre par Excel.
    $sheet->setCellValueExplicit('G' . $ligne, (string) $c['matricule_dsps'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('K' . $ligne, (string) ($libre ? '' : $c['ecole_code']), DataType::TYPE_STRING);
    $ligne++;
}

$sheet->getStyle('A1:O1')->getFont()->setBold(true);
$sheet->getStyle('A1:K1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
$sheet->getStyle('M1:O1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:O' . max(1, $ligne - 1));
foreach (range('A', 'O') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="liste_candidats_' . preg_replace('/[^0-9\-]/', '', (string) $ANNEE_SCOLAIRE) . '_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
