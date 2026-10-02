<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Les 10 premières colonnes reprennent EXACTEMENT l'ordre de l'import Excel
// d'ecoles.php : le fichier exporté peut être corrigé puis ré-importé tel quel.
// Les colonnes d'information (L et suivantes) sont ignorées par l'import.
$stmt = $pdo->prepare("
    SELECT
        e.nom, e.statut, e.code_dsps,
        t.nom AS tutrice_nom,
        e.type_rattachement,
        e.groupe_scolaire, e.groupe_scolaire_manuel,
        e.directeur_nom, e.directeur_telephone,
        e.effectif_general, e.est_centre_examen,
        (SELECT COUNT(*) FROM candidats c WHERE c.ecole_id = e.id AND c.annee_id = ?) AS nb_candidats,
        (SELECT COUNT(*) FROM personnel p WHERE p.ecole_id = e.id AND p.annee_id = ?) AS nb_personnel
    FROM ecoles e
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    ORDER BY e.nom ASC
");
$stmt->execute([$anneeId, $anneeId]);
$ecoles = $stmt->fetchAll();

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->setTitle('Écoles');

$entetes = [
    'Nom École', 'Statut (Public/Privé)', 'Code DSPS', 'Nom École Tutrice',
    'Type Ratt. (Arrimée/Sans Code)', 'Groupe Scolaire (manuel)', 'Directeur', 'Téléphone',
    'Effectif Global', 'Centre Examen (O/N)',
    '',
    'Groupe Scolaire (calculé)', 'Candidats ' . ($ANNEE_SCOLAIRE ?? ''), 'Personnel ' . ($ANNEE_SCOLAIRE ?? ''),
];
$sheet->fromArray($entetes, null, 'A1');

$ligne = 2;
foreach ($ecoles as $e) {
    $typeRatt = match ($e['type_rattachement']) {
        'arrimee' => 'Arrimée',
        'sans_code_dsps' => 'Sans Code',
        default => '',
    };
    $sheet->fromArray([
        $e['nom'],
        $e['statut'],
        $e['code_dsps'],
        $e['tutrice_nom'],
        $typeRatt,
        // Colonne F de l'import : une valeur = groupe verrouillé à la main. On
        // n'y exporte donc que les groupes réellement manuels, sinon un
        // ré-import verrouillerait d'un coup tous les groupes calculés.
        (int) $e['groupe_scolaire_manuel'] === 1 ? $e['groupe_scolaire'] : '',
        $e['directeur_nom'],
        $e['directeur_telephone'],
        $e['effectif_general'],
        (int) $e['est_centre_examen'] === 1 ? 'O' : 'N',
        '',
        $e['groupe_scolaire'],
        (int) $e['nb_candidats'],
        (int) $e['nb_personnel'],
    ], null, 'A' . $ligne);
    // Texte explicite : évite qu'Excel transforme un code DSPS ou un téléphone en nombre.
    $sheet->setCellValueExplicit('C' . $ligne, (string) $e['code_dsps'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('H' . $ligne, (string) $e['directeur_telephone'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
    $ligne++;
}

$derniereColonne = 'N';
$sheet->getStyle("A1:{$derniereColonne}1")->getFont()->setBold(true);
$sheet->getStyle("A1:J1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
$sheet->getStyle("L1:N1")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
$sheet->freezePane('A2');
$sheet->setAutoFilter("A1:{$derniereColonne}" . max(1, $ligne - 1));
foreach (range('A', $derniereColonne) as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
}

$nomFichier = 'liste_ecoles_' . date('Y-m-d') . '.xlsx';
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $nomFichier . '"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
