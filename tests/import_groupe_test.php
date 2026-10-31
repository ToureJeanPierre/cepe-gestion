<?php
// Test (LECTURE SEULE sur la base) des briques de l'import groupé : analyse d'un fichier, école imposée, retour léger.
// Les cas qui dépendent des vrais fichiers du dossier de dépôts sont ignorés s'ils sont absents.
// Lancer : php tests/import_groupe_test.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/depots_helpers.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "== Écoles pour la reconnaissance\n";
$ecoles = ecolesPourProposition($pdo);
$verif('toutes les écoles chargées, avec leurs mots distinctifs', [count($ecoles) > 0, is_array(reset($ecoles)['jetons'])], [true, true]);

echo "\n== Retour léger de l'import groupé\n";
$_POST = [];
$verif('import à la main : retour inchangé', urlRetourImport('candidats.php', 'ok'), 'candidats.php?msg=ok');
$_POST = ['retour' => 'controle_depots'];
$verif('contrôle du dossier : retour inchangé', urlRetourImport('candidats.php', 'ok'), 'controle_depots.php?analyser=1&msg=ok');
$_POST = ['retour' => 'import_groupe', 'type_groupe' => 'personnel'];
$verif('import groupé (personnel)', urlRetourImport('enseignants.php', 'Import terminé'), 'import_groupe.php?type=personnel&msg=Import+termin%C3%A9');
$_POST = ['retour' => 'import_groupe', 'type_groupe' => 'n_importe_quoi'];
$verif('import groupé : type inconnu ramené à « candidats »', urlRetourImport('candidats.php', 'x'), 'import_groupe.php?type=candidats&msg=x');
$_POST = ['retour' => 'import_groupe'];
$verif('import groupé : type absent, aucune erreur PHP', urlRetourImport('candidats.php', 'x'), 'import_groupe.php?type=candidats&msg=x');
$_POST = [];

echo "\n== Analyse d'un fichier réel (Word et PDF jumeaux)\n";
$racine = dossierDepots($pdo);
$paire = [];
foreach (listerFichiersDepot($racine) as $f) {
    if ($f['ext'] === 'pdf' && stripos($f['nom'], 'BAPTISTE ANNEXE NOMINATIVE') === 0) { $paire['pdf'] = $racine . '/' . $f['relatif']; }
    if ($f['ext'] === 'docx' && stripos($f['nom'], 'BAPTISTE ANNEXE NOMINATIVE') === 0) { $paire['docx'] = $racine . '/' . $f['relatif']; }
}
if (count($paire) === 2) {
    $base = ['statut' => 'excel', 'type' => null, 'ecole_texte' => null, 'ecole_id' => null, 'ecole_sure' => false, 'ecoles_possibles' => [],
        'total' => 0, 'deja' => 0, 'nouveaux' => 0, 'doublons_fichier' => 0, 'erreur' => null];
    $docx = analyserFichierListe($pdo, (int) $anneeId, $paire['docx'], ['nom' => basename($paire['docx']), 'ext' => 'docx'] + $base, $ecoles);
    $pdf = analyserFichierListe($pdo, (int) $anneeId, $paire['pdf'], ['nom' => basename($paire['pdf']), 'ext' => 'pdf'] + $base, $ecoles, null, false);
    $verif('le type est reconnu (candidats)', [$docx['type'], $pdf['type']], ['candidats', 'candidats']);
    $verif('Word et PDF jumeaux : mêmes lignes, mêmes « déjà en base » / nouveaux', [$pdf['total'], $pdf['deja'], $pdf['nouveaux']], [$docx['total'], $docx['deja'], $docx['nouveaux']]);
    $verif('une école est proposée au moins parmi les candidates', count($docx['ecoles_possibles']) > 0, true);

    $autre = null;
    foreach ($ecoles as $id => $e) { if ($id !== ($docx['ecole_id'] ?? 0)) { $autre = $id; break; } }
    $force = analyserFichierListe($pdo, (int) $anneeId, $paire['docx'], ['nom' => basename($paire['docx']), 'ext' => 'docx'] + $base, $ecoles, $autre);
    $verif('école choisie à la main : elle remplace la proposition', [$force['ecole_id'], $force['ecole_sure']], [$autre, true]);
    $verif('école imposée inexistante : ignorée', analyserFichierListe($pdo, (int) $anneeId, $paire['docx'], ['nom' => 'x.docx', 'ext' => 'docx'] + $base, $ecoles, 99999999)['ecole_id'] === 99999999, false);
} else {
    echo "  (liste BAPTISTE ANNEXE NOMINATIVE absente du dossier : cas réels ignorés)\n";
}

$casse = tempnam(sys_get_temp_dir(), 'zz') . '.docx';
file_put_contents($casse, 'pas un vrai fichier Word');
$r = analyserFichierListe($pdo, (int) $anneeId, $casse, ['nom' => 'casse.docx', 'ext' => 'docx', 'statut' => 'excel', 'type' => null, 'ecole_texte' => null, 'ecole_id' => null, 'ecole_sure' => false,
    'ecoles_possibles' => [], 'total' => 0, 'deja' => 0, 'nouveaux' => 0, 'doublons_fichier' => 0, 'erreur' => null], $ecoles);
$verif('faux fichier Word : « illisible », sans exception', $r['statut'], 'illisible');
@unlink($casse);

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
