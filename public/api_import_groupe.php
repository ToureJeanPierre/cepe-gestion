<?php
// Analyse d'UN fichier envoyé pour l'import groupé (candidats ou personnel) : type, école proposée, lignes, déjà en base / nouveaux.
// LECTURE SEULE : rien n'est écrit dans la base, le fichier envoyé est analysé puis oublié.
// L'import lui-même passe par les pages Candidats / Personnel, exactement comme un import à la main.
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/depots_helpers.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json; charset=utf-8');
$repondre = function (array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $repondre(['ok' => false, 'erreur' => 'Méthode non autorisée'], 405);
}
if ($anneeLectureSeule) {
    $repondre(['ok' => false, 'erreur' => 'Année archivée : lecture seule'], 403);
}
set_time_limit(180);

$typeAttendu = ($_POST['type'] ?? '') === 'personnel' ? 'personnel' : 'candidats';
$fichier = $_FILES['fichier'] ?? null;
if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
    $repondre(['ok' => false, 'erreur' => 'Envoi interrompu ou fichier trop gros pour le serveur'], 400);
}
$nom = basename((string) $fichier['name']);
$ext = strtolower(pathinfo($nom, PATHINFO_EXTENSION));
if (!in_array($ext, ['docx', 'pdf', 'xlsx', 'xls'], true)) {
    $repondre(['ok' => false, 'nom' => $nom, 'erreur' => 'Format non accepté (Word, PDF ou Excel seulement)']);
}
if ($fichier['size'] > 30 * 1024 * 1024) {
    $repondre(['ok' => false, 'nom' => $nom, 'erreur' => 'Fichier trop volumineux (plus de 30 Mo)']);
}
$tmp = $fichier['tmp_name'];

$ecoles = ecolesPourProposition($pdo);
$ecoleForcee = (int) ($_POST['ecole_id'] ?? 0) ?: null;
if ($ecoleForcee !== null && !isset($ecoles[$ecoleForcee])) {
    $ecoleForcee = null;
}

$f = ['nom' => $nom, 'ext' => $ext, 'statut' => 'excel', 'type' => null, 'ecole_texte' => null, 'ecole_id' => null, 'ecole_sure' => false,
    'ecoles_possibles' => [], 'total' => 0, 'deja' => 0, 'nouveaux' => 0, 'doublons_fichier' => 0, 'erreur' => null];

try {
    if (in_array($ext, ['docx', 'pdf'], true)) {
        $f = analyserFichierListe($pdo, (int) $anneeId, $tmp, $f, $ecoles, $ecoleForcee, false);
        $ecoleRequise = true; // un fichier Word / PDF = une école, qu'il faut connaître
    } else {
        // Excel : le type est celui de la page ; les candidats portent leur école dans une colonne, pas le personnel.
        $f['type'] = $typeAttendu;
        $rows = IOFactory::load($tmp)->getActiveSheet()->toArray();
        array_shift($rows); // en-tête
        $rows = array_values(array_filter($rows, fn ($r) => trim((string) ($r[0] ?? '')) !== ''));
        if ($ecoleForcee !== null) {
            $f['ecole_id'] = $ecoleForcee;
            $f['ecole_sure'] = true;
        }
        $cmp = comparerDepotAvecBase($pdo, (int) $anneeId, $typeAttendu, $rows, $ecoleForcee);
        $f = array_merge($f, $cmp);
        $f['statut'] = $cmp['total'] === 0 ? 'vide' : ($cmp['nouveaux'] === 0 ? 'deja' : ($cmp['deja'] > 0 ? 'partiel' : 'nouveau'));
        $ecoleRequise = $typeAttendu === 'personnel';
    }
} catch (Throwable $e) {
    $f['statut'] = 'illisible';
    $f['erreur'] = "Lecture impossible : " . $e->getMessage();
    $ecoleRequise = true;
}

$libellesType = ['candidats' => 'une liste de candidats', 'personnel' => 'une liste d\'enseignants'];
$typeOk = $f['type'] === $typeAttendu;
$importable = $typeOk && in_array($f['statut'], ['nouveau', 'partiel', 'deja'], true);
$motif = null;
if ($f['statut'] === 'illisible') {
    $motif = $f['erreur'] ?: 'Fichier illisible';
} elseif ($f['statut'] === 'non_reconnu') {
    $motif = "Fichier non reconnu : ni liste de candidats ni liste d'enseignants";
} elseif ($f['statut'] === 'vide') {
    $motif = 'Modèle vide : aucune ligne remplie';
} elseif (!$typeOk && $f['type'] !== null) {
    $motif = 'C\'est ' . $libellesType[$f['type']] . ', pas ' . $libellesType[$typeAttendu] . ' : à importer depuis l\'autre page';
}

$repondre($f + [
    'ok' => true, 'hash' => hash_file('sha256', $tmp), 'taille' => (int) $fichier['size'], 'type_attendu' => $typeAttendu,
    'type_ok' => $typeOk, 'ecole_requise' => $ecoleRequise, 'importable' => $importable, 'motif' => $motif,
]);
