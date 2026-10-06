<?php
// Export des extraits de naissance lus : classeur Excel (mêmes colonnes que les classeurs d'immatriculation)
// ou archive ZIP des COPIES renommées « NN_NOM_PRENOMS.ext », rangées par école.
// Les fichiers d'origine ne sont jamais renommés ni déplacés : on ne touche qu'à des copies.
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/extraits_helpers.php';

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}
$format = $_GET['format'] ?? 'xlsx';
$ecoleId = (int) ($_GET['ecole_id'] ?? 0);
$tous = ($_GET['inclure'] ?? 'valides') === 'tous'; // par défaut : seulement ce qui a été validé par une personne

$sql = "SELECT x.*, e.nom AS ecole_nom, c.nom AS cand_nom, c.prenoms AS cand_prenoms, c.date_naissance AS cand_naissance
        FROM extraits_naissance x
        LEFT JOIN ecoles e ON e.id = x.ecole_id
        LEFT JOIN candidats c ON c.id = x.candidat_id
        WHERE x.annee_id = ? AND x.statut " . ($tous ? "IN ('lu', 'valide')" : "= 'valide'");
$params = [$anneeId];
if ($ecoleId) {
    $sql .= " AND x.ecole_id = ?";
    $params[] = $ecoleId;
}
$sql .= " ORDER BY e.nom, x.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$lignes = $stmt->fetchAll();
$rangs = rangsExtraitsParEcole($pdo, (int) $anneeId); // numérotation stable : ne change pas quand on valide d'autres extraits

if (!$lignes) {
    http_response_code(404);
    die("Rien à exporter" . ($tous ? '' : " : aucun extrait n'est encore validé (ou cocher « inclure les extraits non validés »)") . ".");
}

$fmtDate = fn (?string $d) => $d ? date('d/m/Y', strtotime($d)) : '';

if ($format === 'zip') {
    $tmp = tempnam(sys_get_temp_dir(), 'extr');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        die("Impossible de créer l'archive.");
    }
    $zip->setArchiveComment('Copies renommées : les fichiers d\'origine n\'ont pas été modifiés.');
    foreach ($lignes as $l) {
        $source = extraitsDossier() . '/' . $l['chemin'];
        if (!is_file($source)) {
            continue;
        }
        $zip->addFile($source, nomDossierEcole($l['ecole_nom']) . '/' . nomFichierExtrait($l['nom'], $l['prenoms'], $l['extension'], $rangs[(int) $l['id']] ?? null));
    }
    $zip->close();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="extraits_renommes_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// --- Excel : une feuille par école.
$classeur = new Spreadsheet();
$classeur->removeSheetByIndex(0);
$parEcole = [];
foreach ($lignes as $l) {
    $parEcole[$l['ecole_nom'] ?? 'Sans école'][] = $l;
}
$colonnes = colonnesImmatriculation();
foreach ($parEcole as $nomEcole => $liste) {
    $titre = mb_substr((string) preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $nomEcole), 0, 31);
    $feuille = $classeur->createSheet();
    $feuille->setTitle($titre !== '' ? $titre : 'Feuille');
    $feuille->fromArray($colonnes, null, 'A1');
    $feuille->getStyle('A1:Q1')->getFont()->setBold(true);
    $feuille->getStyle('A1:Q1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
    $r = 2;
    foreach ($liste as $l) {
        $aVerifier = [];
        if ($l['alertes']) {
            $aVerifier[] = $l['alertes'];
        }
        if ($l['candidat_id']) {
            $diff = identiteDifferente(
                ['Nom' => (string) $l['nom'], 'Prénom(s)' => (string) $l['prenoms'], 'Date et lieu de naissance' => $fmtDate($l['date_naissance'])],
                ['nom' => $l['cand_nom'], 'prenoms' => $l['cand_prenoms'], 'date_naissance' => $l['cand_naissance']]
            );
            if ($diff) {
                $aVerifier[] = 'Différent de la fiche candidat : ' . implode(' ; ', $diff);
            }
        } else {
            $aVerifier[] = 'Non rattaché à un candidat';
        }
        $valeurs = [
            $rangs[(int) $l['id']] ?? '', $l['nom'], $l['prenoms'], $l['sexe'], $fmtDate($l['date_naissance']), $l['lieu_naissance'],
            $l['sous_prefecture'], $l['pere'], $l['mere'], $l['contact'], $l['numero_acte'], $fmtDate($l['date_acte']), $l['lieu_acte'],
            $l['nationalite'], '', implode(' | ', $aVerifier), $l['statut'] === 'valide' ? 'Validé' : 'À valider',
        ];
        foreach ($valeurs as $i => $v) {
            // Tout en texte : un numéro d'acte « 0912 » ou une date ne doivent pas être « corrigés » par Excel.
            $feuille->setCellValueExplicit([$i + 1, $r], (string) ($v ?? ''), DataType::TYPE_STRING);
        }
        $r++;
    }
    foreach (range('A', 'Q') as $col) {
        $feuille->getColumnDimension($col)->setAutoSize(true);
    }
    $feuille->freezePane('A2');
}
$classeur->setActiveSheetIndex(0);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="immatriculation_extraits_' . date('Y-m-d') . '.xlsx"');
(new Xlsx($classeur))->save('php://output');
