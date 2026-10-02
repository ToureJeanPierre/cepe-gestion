<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Même périmètre que la page Personnel (tout le personnel enregistré).
// Les 9 premières colonnes suivent l'ordre de l'import Excel d'enseignants.php.
$personnel = $pdo->query("
    SELECT p.*, ec.nom AS ecole_nom
    FROM personnel p
    LEFT JOIN ecoles ec ON ec.id = p.ecole_id
    ORDER BY p.categorie ASC, ec.nom ASC, p.nom ASC, p.prenoms ASC
")->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Personnel');

$sheet->fromArray([
    'Nom', 'Prénoms', 'Sexe', 'Téléphone', 'Matricule / N° autorisation', 'Niveau tenu', 'Emploi (IO/IA)',
    'Fonction', 'Disponibilité',
    '',
    'École', 'Catégorie', 'Grade', 'Public/Privé',
], null, 'A1');

$categories = ['enseignant' => 'Enseignant', 'conseiller' => 'Conseiller', 'administratif' => 'Administratif'];

$ligne = 2;
foreach ($personnel as $p) {
    $identifiant = $p['matricule'] ?: ($p['numero_autorisation_enseigner'] ?: $p['numero_autorisation_diriger']);
    $sheet->fromArray([
        $p['nom'], $p['prenoms'], $p['sexe'], '', '', $p['niveau_tenu'], $p['emploi'],
        $p['fonction'], $p['disponibilite'],
        '',
        $p['ecole_nom'], $categories[$p['categorie']] ?? $p['categorie'], $p['grade'], $p['type_ecole'],
    ], null, 'A' . $ligne);
    // Texte explicite : téléphones (zéro initial) et matricules ne doivent pas devenir des nombres.
    $sheet->setCellValueExplicit('D' . $ligne, (string) $p['telephone'], DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('E' . $ligne, (string) $identifiant, DataType::TYPE_STRING);
    $ligne++;
}

$sheet->getStyle('A1:N1')->getFont()->setBold(true);
$sheet->getStyle('A1:I1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
$sheet->getStyle('K1:N1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
$sheet->freezePane('A2');
$sheet->setAutoFilter('A1:N' . max(1, $ligne - 1));
foreach (range('A', 'N') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="liste_personnel_' . date('Y-m-d') . '.xlsx"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
