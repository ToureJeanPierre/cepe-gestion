<?php
// Test des cas de référence du bilan DFA (année d'examen 2026-2027).
// Lancer : php tests/dfa_cas_reference.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/dfa_helpers.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};
$fmt = fn (array $r) => implode(' · ', array_map(fn ($l) => $l['annee'] . ' ' . $l['dfa'], $r['lignes']));

echo "== 9 cas de référence (examen 2026)\n";
$cas = [
    ['27000001A', 'CE2', 2024, 'DFA', '2425 A · 2526 A', false],
    ['27000002B', 'CE2', 2023, 'DFA', '2324 A · 2425 R · 2526 A', true],   // + avertissement matricule
    ['25000003C', 'CE1', 2021, 'DFA', '2122 A · 2223 R · 2324 A · 2425 R · 2526 A', false],
    ['26000004D', 'CM2', 2025, 'DFA', '2526 R', false],
    ['27000005E', 'CM1', 2025, 'DFA', '2526 A', false],
    ['27000006F', 'CM2', 2026, 'OK', '', false],
    ['27000007G', 'CE1', 2025, 'SAUT_DE_NIVEAU', '', false],
    ['24000008H', 'CM2', 2024, 'DFA', '2425 R · 2526 R', true],            // + avertissement CM2 2 fois
    ['24000009J', 'CE2', 2020, 'A_VERIFIER', '', false],
];
foreach ($cas as [$mat, $classe, $an, $statut, $lignes, $avert]) {
    $r = calculerDfa($mat, $classe, $an, 2026);
    $verif("$mat $classe $an-" . ($an + 1) . " -> statut", $r['statut'], $statut);
    $verif("$mat lignes", $fmt($r), $lignes);
    $verif("$mat avertissement", count($r['avertissements']) > 0, $avert);
}

echo "\n== Normalisations DESPS\n";
foreach ([['CE 2', 'CE2'], ['ce2', 'CE2'], ['Cours Élémentaire 2', 'CE2'], ['Cours Moyen 2ème année', 'CM2'], ['Cours Préparatoire 1ère année', 'CP1'],
          ['CM1', 'CM1'], ['CP 2', 'CP2'], ['6ème', null], ['', null]] as [$in, $out]) {
    $verif("classe « $in »", normaliserClasseDesps($in), $out);
}
foreach ([['2024-2025', 2024], ['2024/2025', 2024], ['2024 - 2025', 2024], ['24-25', 2024], ['2425', 2024], ['2024', 2024], ['abc', null]] as [$in, $out]) {
    $verif("année « $in »", normaliserAnneeDesps($in), $out);
}
$verif('code année 2025', codeAnnee(2025), '2526');
$verif('nom fichier', nomFichierDfa("EPP NIANG.SUD SOGEFIHA LAGUNE 2"), 'DFA_EPPNIANGSUDSOGEFIHALAGUNE2.xls');
$verif('nom fichier accents', nomFichierDfa("GROUPE SCOLAIRE LA MISÉRICORDE 1"), 'DFA_GROUPESCOLAIRELAMISERICORDE1.xls');

echo "\n== Fichier .xls (format imposé : feuille 'eleves', texte, en-têtes minuscules)\n";
$tmp = sys_get_temp_dir() . '/dfa_test.xls';
ecrireFichierDfa([['annee' => '2324', 'matricule' => '27000002B', 'dfa' => 'A'], ['annee' => '2425', 'matricule' => '27000002B', 'dfa' => 'R'], ['annee' => '2526', 'matricule' => '27000002B', 'dfa' => 'A']], $tmp);
$wb = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
$verif('une seule feuille', $wb->getSheetCount(), 1);
$verif('nom de la feuille', $wb->getSheet(0)->getTitle(), 'eleves');
$donnees = $wb->getSheet(0)->toArray(null, false, false);
$verif('en-têtes', $donnees[0], ['annee', 'matricule', 'dfa']);
$verif('ligne 2 (texte, pas nombre)', $donnees[1], ['2324', '27000002B', 'A']);
$verif('type cellule A2 = texte', $wb->getSheet(0)->getCell('A2')->getDataType(), 's');
$verif('nb de lignes', count($donnees), 4);
@unlink($tmp);

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
