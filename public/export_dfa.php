<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/dfa_helpers.php';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

// Le fichier est construit à partir du bilan STOCKÉ (celui affiché à l'écran) :
// aucun recalcul au téléchargement, le fichier correspond exactement à l'écran.

if (isset($_GET['tous'])) {
    $zipChemin = tempnam(sys_get_temp_dir(), 'dfa_');
    $zip = new ZipArchive();
    $zip->open($zipChemin, ZipArchive::OVERWRITE);
    $temporaires = [];
    $nb = 0;
    foreach (recapBilanParEcole($pdo, (int) $anneeId) as $e) {
        if ($e['DFA'] === 0) {
            continue;
        }
        $lignes = lignesDfaEcole($pdo, (int) $anneeId, $e['id']);
        if (!$lignes) {
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'dfa_');
        ecrireFichierDfa($lignes, $tmp);
        $zip->addFile($tmp, nomFichierDfa($e['nom']));
        $temporaires[] = $tmp;
        $nb++;
    }
    $zip->close();
    if ($nb === 0) {
        foreach ($temporaires as $t) @unlink($t);
        @unlink($zipChemin);
        http_response_code(404);
        die("Aucune école n'a d'élève à remonter par DFA.");
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="DFA_toutes_les_ecoles_' . date('Y-m-d') . '.zip"');
    header('Content-Length: ' . filesize($zipChemin));
    readfile($zipChemin);
    foreach ($temporaires as $t) @unlink($t);
    @unlink($zipChemin);
    exit;
}

$ecoleId = (int) ($_GET['ecole_id'] ?? 0);
$stmt = $pdo->prepare("SELECT id, nom FROM ecoles WHERE id = ?");
$stmt->execute([$ecoleId]);
$ecole = $stmt->fetch();
if (!$ecole) {
    http_response_code(404);
    die("École introuvable.");
}

$lignes = lignesDfaEcole($pdo, (int) $anneeId, $ecoleId);
if (!$lignes) {
    http_response_code(404);
    die("Aucun élève à remonter par DFA pour cette école.");
}

$tmp = tempnam(sys_get_temp_dir(), 'dfa_');
ecrireFichierDfa($lignes, $tmp);
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . nomFichierDfa($ecole['nom']) . '"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: max-age=0');
readfile($tmp);
@unlink($tmp);
exit;
