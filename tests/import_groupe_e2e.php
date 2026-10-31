<?php
// ESSAI DE BOUT EN BOUT de l'import groupé — À LANCER SUR UNE COPIE DE LA BASE, JAMAIS SUR LA BASE RÉELLE : il importe vraiment des listes.
// Préparation :
//   1. copier la base : mysqldump cepe_gestion | mysql cepe_test_groupe  (base créée vide au préalable)
//   2. lancer un serveur de test branché sur la copie, avec une session ouverte (CEPE_DB_NAME évite de toucher la vraie base) :
//      cd public && CEPE_DB_NAME=cepe_test_groupe php -S 127.0.0.1:8098   (+ auto_prepend_file qui ouvre une session de test)
//   3. php tests/import_groupe_e2e.php   puis supprimer la base copie.
// Les fichiers utilisés sont ceux du dossier de dépôts de l'utilisateur (chemins en tête du script).
require 'C:/laragon/www/cepe_gestion/vendor/autoload.php';
$B = 'http://127.0.0.1:8098';
$D = 'C:/Users/hp/Desktop/2026-2027/';
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cepe_test_groupe;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};
function post(string $url, array $champs): array
{
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $champs, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 300]);
    $corps = (string) curl_exec($c);
    $info = ['code' => curl_getinfo($c, CURLINFO_HTTP_CODE), 'url' => curl_getinfo($c, CURLINFO_EFFECTIVE_URL), 'corps' => $corps];
    return $info;
}
function analyse(string $B, string $fichier, string $type, ?int $ecole = null, ?string $nomEnvoye = null): array
{
    $champs = ['fichier' => new CURLFile($fichier, '', $nomEnvoye ?? basename($fichier)), 'type' => $type];
    if ($ecole) { $champs['ecole_id'] = $ecole; }
    return json_decode(post($B . '/api_import_groupe.php', $champs)['corps'], true) ?? ['ok' => false, 'erreur' => 'réponse non JSON'];
}
function importer(string $B, string $type, string $fichier, ?int $ecole, ?string $nomEnvoye = null): array
{
    $nom = $nomEnvoye ?? basename($fichier);
    $champs = ['retour' => 'import_groupe', 'type_groupe' => $type];
    if ($type === 'candidats') {
        $champs += ['importer_candidats' => '1', 'fichier_candidats' => new CURLFile($fichier, '', $nom)];
        if ($ecole) { $champs['ecole_id_import_docx'] = $ecole; }
        $cible = '/candidats.php';
    } else {
        $champs += ['importer_personnel' => '1', 'type_import' => 'enseignant_ecole', 'ecole_id_import' => $ecole, 'fichier_personnel' => new CURLFile($fichier, '', $nom)];
        $cible = '/enseignants.php';
    }
    $r = post($B . $cible, $champs);
    $j = json_decode($r['corps'], true);
    return $j ?: ['msg' => null, 'brut' => substr(strip_tags($r['corps']), 0, 200), 'url' => $r['url']];
}
$nbCand = fn (int $e) => (int) $pdo->query("SELECT COUNT(*) FROM candidats WHERE ecole_id = $e")->fetchColumn();
$nbPers = fn (int $e) => (int) $pdo->query("SELECT COUNT(*) FROM personnel WHERE ecole_id = $e")->fetchColumn();

echo "== 0. La page d'import groupé s'affiche\n";
foreach (['candidats', 'personnel'] as $t) {
    $r = post($B . '/import_groupe.php?type=' . $t, []);
    $p = file_get_contents($B . '/import_groupe.php?type=' . $t);
    $verif("page $t : s'affiche avec la zone de dépôt", [str_contains($p, 'zoneDepot'), str_contains($p, 'Import groupé')], [true, true]);
}

echo "\n== 1. Candidats Word : ANTENNE 3 (école 87)\n";
$f1 = $D . 'LISTE/LISTE NOMINATIVE/LISTE PUBLIC/ANTENNE 3.docx';
$a = analyse($B, $f1, 'candidats');
$verif('analyse : lu, type candidats, école reconnue, importable', [$a['ok'], $a['type'], $a['ecole_id'], $a['ecole_sure'], $a['importable'], $a['ecole_requise']], [true, 'candidats', 87, true, true, true]);
$verif('analyse : 42 lignes dont 1 doublon DANS le fichier, 41 nouvelles, aucune déjà en base', [$a['total'], $a['doublons_fichier'], $a['nouveaux'], $a['deja'], $a['statut']], [42, 1, 41, 0, 'nouveau']);
$nouveauxAttendus = $a['nouveaux'];
$verif('analyse : empreinte du fichier fournie (détection des doublons)', strlen($a['hash'] ?? ''), 64);
$verif('même fichier envoyé deux fois = même empreinte', analyse($B, $f1, 'candidats')['hash'], $a['hash']);
$mauvais = analyse($B, $f1, 'personnel');
$verif('envoyé comme « personnel » : refusé avec explication', [$mauvais['importable'], str_contains((string) $mauvais['motif'], 'liste de candidats')], [false, true]);
$avant = $nbCand(87);
$r = importer($B, 'candidats', $f1, 87);
$verif('import : message de réussite', [$r['msg'] !== null && str_contains($r['msg'], 'Import terminé')], [true]);
$verif('import : +41 candidats dans la copie (= nouveaux annoncés par l\'analyse)', $nbCand(87) - $avant, $nouveauxAttendus);
$r2 = importer($B, 'candidats', $f1, 87);
$verif('ré-import du même fichier : 0 nouveau, pas de doublon', [str_contains((string) $r2['msg'], '0 nouveaux'), $nbCand(87) - $avant], [true, $nouveauxAttendus]);
$verif('analyse après import : « déjà enregistré »', analyse($B, $f1, 'candidats')['statut'], 'deja');
$sansEcole = importer($B, 'candidats', $f1, null);
$verif('sans école (Word) : refusé par la page, rien d\'écrit', [$sansEcole['msg'], $nbCand(87) - $avant], [null, $nouveauxAttendus]);

echo "\n== 2. Personnel Word : SODEFOR 1 (école 73)\n";
$f2 = $D . 'LISTE/LISTE ENSEIGNANT/LISTE PUBLIC/SODEFOR 1.docx';
$a = analyse($B, $f2, 'personnel');
$verif('analyse : type personnel, école 73, 6 lignes nouvelles', [$a['type'], $a['ecole_id'], $a['total'], $a['nouveaux'], $a['importable']], ['personnel', 73, 6, 6, true]);
$verif('envoyé comme « candidats » : refusé', analyse($B, $f2, 'candidats')['importable'], false);
$avant = $nbPers(73);
$r = importer($B, 'personnel', $f2, 73);
$verif('import personnel : succès, +6', [str_contains((string) $r['msg'], 'Import terminé'), $nbPers(73) - $avant], [true, 6]);
$r = importer($B, 'personnel', $f2, 73);
$verif('ré-import personnel : aucune fiche en double', $nbPers(73) - $avant, 6);
$verif('analyse après import : déjà enregistré', analyse($B, $f2, 'personnel')['statut'], 'deja');

echo "\n== 3. PDF\n";
$pdfs = ['personnel' => $D . 'LISTE/listes venues de DONGO/LISTE DES ENSEIGNANTS 2026-2027/LAGUNE 1.pdf', 'candidats' => $D . 'LISTE/listes venues de DONGO/LISTE NOMINATIVE 2026-2027/CAISTAB 1.pdf'];
foreach ($pdfs as $type => $pdf) {
    if (!is_file($pdf)) { echo "  (absent : $pdf)\n"; continue; }
    $a = analyse($B, $pdf, $type);
    $verif("PDF $type : lu et reconnu", [$a['ok'], $a['type'], $a['total'] > 0], [true, $type, true]);
    echo "       → école " . var_export($a['ecole_id'], true) . ' sûre=' . var_export($a['ecole_sure'], true) . ", {$a['total']} lignes, statut {$a['statut']}\n";
    if ($a['importable'] && !$a['ecole_id']) { // école incertaine : l'utilisateur la choisit dans la liste, la lecture est refaite avec ce choix
        $ecole = (int) $pdo->query("SELECT id FROM ecoles WHERE nom LIKE '%CAISTAB 1%' LIMIT 1")->fetchColumn();
        $a = analyse($B, $pdf, $type, $ecole);
        $verif('PDF ' . $type . ' : école choisie à la main prise en compte', [$a['ecole_id'], $a['ecole_sure']], [$ecole, true]);
    }
    if ($a['importable'] && $a['ecole_id']) {
        $ecole = (int) $a['ecole_id'];
        $avant = $type === 'candidats' ? $nbCand($ecole) : $nbPers($ecole);
        $r = importer($B, $type, $pdf, $ecole);
        $apres = $type === 'candidats' ? $nbCand($ecole) : $nbPers($ecole);
        $verif("PDF $type : import réussi, nombre de nouvelles fiches = nouveaux annoncés", [str_contains((string) $r['msg'], 'Import terminé'), $apres - $avant], [true, $a['nouveaux']]);
        $verif("PDF $type : ré-analyse = déjà enregistré", analyse($B, $pdf, $type, $ecole)['statut'], 'deja');
    }
}

echo "\n== 4. Excel candidats (école lue dans une colonne)\n";
$xl = sys_get_temp_dir() . '/zz_candidats_test.xlsx';
$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
$ss->getActiveSheet()->fromArray([
    ['Nom', 'Prénoms', 'Sexe', 'Nationalité', 'Date naissance', 'Lieu', 'Matricule', 'Acte', 'Statut', 'École', 'Code'],
    ['ZZEXCEL', 'UN', 'M', 'IV', '01/02/2015', 'ABIDJAN', '', 'O', '', 'EPP AZITO 1', ''],
    ['ZZEXCEL', 'DEUX', 'F', 'IV', '03/04/2015', 'ABIDJAN', '', 'O', '', 'EPP AZITO 1', ''],
], null, 'A1');
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save($xl);
$a = analyse($B, $xl, 'candidats');
$verif('Excel candidats : 2 lignes nouvelles, école non exigée', [$a['ok'], $a['total'], $a['nouveaux'], $a['ecole_requise'], $a['importable']], [true, 2, 2, false, true]);
$azito = (int) $pdo->query("SELECT id FROM ecoles WHERE nom = 'EPP AZITO 1'")->fetchColumn();
$avant = $nbCand($azito);
$r = importer($B, 'candidats', $xl, null);
$verif('Excel : import +2 dans l\'école indiquée par la colonne', [str_contains((string) $r['msg'], 'Import terminé'), $nbCand($azito) - $avant], [true, 2]);
$verif('Excel : ré-analyse = déjà enregistré', analyse($B, $xl, 'candidats')['statut'], 'deja');
$xlp = analyse($B, $xl, 'personnel');
$verif('Excel : école exigée pour le personnel', $xlp['ecole_requise'], true);

echo "\n== 5. Fichiers défectueux\n";
$cassé = sys_get_temp_dir() . '/zz_casse.docx';
file_put_contents($cassé, 'ceci n\'est pas un vrai fichier Word');
$a = analyse($B, $cassé, 'candidats');
$verif('faux .docx : refusé proprement', [$a['ok'], $a['importable'], $a['statut']], [true, false, 'illisible']);
$txt = sys_get_temp_dir() . '/zz_note.txt';
file_put_contents($txt, 'texte');
$a = analyse($B, $txt, 'candidats');
$verif('format non accepté (.txt)', [$a['ok'], str_contains((string) $a['erreur'], 'Format non accepté')], [false, true]);

echo "\n" . ($echecs === 0 ? "TOUT PASSE\n" : "$echecs ECHEC(S)\n");
@unlink($xl); @unlink($cassé); @unlink($txt);
exit($echecs === 0 ? 0 : 1);
