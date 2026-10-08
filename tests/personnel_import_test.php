<?php
// Test (LECTURE SEULE sur la base) : retrouver une fiche du personnel à l'import.
// Cas réel : « En cours » servait d'identifiant, donc chaque enseignant « En cours » écrasait la fiche du premier.
// Lancer : php tests/personnel_import_test.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/depots_helpers.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "== Vrai identifiant ou simple mention ?\n";
foreach (['En cours' => false, 'EN COURS' => false, 'ENCOURS' => false, 'F' => false, 'N' => false, 'CAFOP PRIVE CELAF' => false, '' => false,
    '5426 DU 03/02/20' => true, '6616 du 02 /12/21' => true, '300MEN/CAB/SAPEP DU 20 SEPTEMBRE 2007' => true, '27000009Q' => true] as $v => $attendu) {
    $verif("« $v »", identifiantPersonnelExploitable($v), $attendu);
}
$verif('null', identifiantPersonnelExploitable(null), false);

echo "\n== Même personne ?\n";
$verif('même nom, ordre des mots et accents indifférents', memePersonneParNom('N’GBESSO', 'SERGE PACOME', 'n’gbesso', 'serge'), true);
$verif('prénom en commun suffit (nom mal orthographié)', memePersonneParNom('KOUAME', 'MARCEL', 'KOUAKOU', 'KOUAME MARCEL'), true);
$verif('deux personnes différentes', memePersonneParNom('AMAFFE', 'STEPHANE', 'KOUAKOU', 'KOUAME MARCEL'), false);

echo "\n== Cas Baptiste Annexe (base réelle, aucune écriture)\n";
$ecole = (int) $pdo->query("SELECT id FROM ecoles WHERE nom = 'EPV BAPTISTE ANNEXE'")->fetchColumn();
if (!$ecole) {
    echo "  (école absente de cette base : cas réels ignorés)\n";
} else {
    foreach ([['ANIN', 'MARIE-PAULE'], ['LAKPA', 'NANCY PRISCA'], ['KANOHIN', 'WILLEWE HOZANA'], ['KOUAKOU', 'KOUAME MARCEL']] as [$n, $p]) {
        $r = trouverPersonnelExistant($pdo, $n, $p, 'enseignant', $ecole, null, 'En cours', null);
        $verif("$n « En cours » : nouvelle fiche, ne retrouve plus AMAFFE", $r, ['id' => null, 'avertissement' => null]);
    }
    $directeur = $pdo->query("SELECT id FROM personnel WHERE nom = 'KPEUKPA' AND ecole_id = $ecole")->fetchColumn();
    if ($directeur) {
        $r = trouverPersonnelExistant($pdo, 'KPEUKPA', 'MARCELLIN', 'enseignant', $ecole, null, null, '5426 DU 03/02/20');
        $verif('vrai n° d\'autorisation + même nom : retrouve la fiche', $r['id'], (int) $directeur);
        $r = trouverPersonnelExistant($pdo, 'DUPONT', 'PAUL', 'enseignant', $ecole, null, null, '5426 DU 03/02/20');
        $verif('même vrai n° mais AUTRE personne : pas de fusion, avertissement', [$r['id'], $r['avertissement'] !== null], [null, true]);
        $r = trouverPersonnelExistant($pdo, 'KPEUKPA', 'MARCELLIN', 'enseignant', $ecole, null, null, null);
        $verif('sans identifiant : retrouvé par nom + école', $r['id'], (int) $directeur);
    }
}

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
