<?php
// Test du lecteur de la fiche cursus (fiche fictive) et du contrôle d'identité.
// Lancer : php tests/desps_site_test.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/desps_site.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

$html = file_get_contents(__DIR__ . '/fixtures/fiche_cursus_fictive.html');
$f = parserFicheCursus($html);
echo "== Lecture de la fiche\n";
$verif('fiche trouvée', $f['trouve'], true);
$verif('nom', $f['identite']['Nom'] ?? null, 'DUPONT-TEST');
$verif('prénoms', $f['identite']['Prénom(s)'] ?? null, 'MARIE CLAIRE');
$verif('naissance', $f['identite']['Date et lieu de naissance'] ?? null, '05/03/2015 A ABIDJAN');
$verif('5 lignes de cursus', count($f['cursus']), 5);
$verif('ligne 3', $f['cursus'][2], ['annee' => '2023-2024', 'iep' => 'YOPOUGON TEST', 'ecole' => 'EPP EXEMPLE 2', 'niveau' => 'CE1', 'dfa' => 'R', 'ce_sn' => 'CE']);

$der = derniereLigneCursus($f['cursus']);
$verif('dernière ligne RENSEIGNÉE (les années vides sont ignorées)', [$der['annee'], $der['ecole'], $der['niveau']], ['2023-2024', 'EPP EXEMPLE 2', 'CE1']);
$verif('classe normalisée', normaliserClasseDesps($der['niveau']), 'CE1');
$verif('année normalisée', normaliserAnneeDesps($der['annee']), 2023);

echo "\n== Matricule introuvable : le site renvoie le formulaire vide\n";
$vide = parserFicheCursus('<html><body>:: QUITUS :: AGCP / DSPS-MEN Entrez votre numéro matricule Imprimer</body></html>');
$verif('non trouvé', $vide['trouve'], false);
$verif('reconnu comme formulaire (donc "introuvable", pas "panne")', $vide['formulaire'], true);
$inconnu = parserFicheCursus('<html><body>Erreur 500</body></html>');
$verif('réponse inconnue : ni trouvé ni formulaire (=> erreur, jamais "introuvable")', [$inconnu['trouve'], $inconnu['formulaire']], [false, false]);

echo "\n== Contrôle d'identité\n";
$ident = $f['identite'];
$verif('identité concordante', identiteDifferente($ident, ['nom' => 'Dupont-Test', 'prenoms' => 'Marie Claire', 'date_naissance' => '2015-03-05']), []);
$verif('prénoms dans un autre ordre / incomplets acceptés', identiteDifferente($ident, ['nom' => 'DUPONT TEST', 'prenoms' => 'CLAIRE', 'date_naissance' => '2015-03-05']), []);
$verif('accents ignorés', identiteDifferente(['Nom' => 'KOUAMÉ', 'Prénom(s)' => 'ÉLISE'], ['nom' => 'KOUAME', 'prenoms' => 'ELISE', 'date_naissance' => null]), []);
$verif('autre nom détecté', count(identiteDifferente($ident, ['nom' => 'MARTIN', 'prenoms' => 'Marie Claire', 'date_naissance' => '2015-03-05'])), 1);
$verif('autre date détectée', count(identiteDifferente($ident, ['nom' => 'DUPONT-TEST', 'prenoms' => 'Marie', 'date_naissance' => '2014-03-05'])), 1);
$verif('élève totalement différent : 3 différences', count(identiteDifferente($ident, ['nom' => 'MARTIN', 'prenoms' => 'Paul', 'date_naissance' => '2012-01-01'])), 3);

echo "\n== Identité très différente : pas de DFA\n";
$base = ['statut' => 'DFA', 'changement_ecole' => true, 'motif' => '', 'lignes' => [['annee' => '2526', 'matricule' => 'X', 'dfa' => 'A', 'classe' => 'CM1']], 'avertissements' => []];
$fort = appliquerControleIdentite($base, identiteDifferente($ident, ['nom' => 'MARTIN', 'prenoms' => 'Paul', 'date_naissance' => '2012-01-01']));
$verif('nom + prénoms différents -> A_VERIFIER', $fort['statut'], 'A_VERIFIER');
$verif('... sans aucune ligne DFA', $fort['lignes'], []);
$leger = appliquerControleIdentite($base, identiteDifferente($ident, ['nom' => 'DUPONT-TEST', 'prenoms' => 'Marie', 'date_naissance' => '2014-03-05']));
$verif('seule la date diffère -> reste DFA', $leger['statut'], 'DFA');
$verif('... mais avec un avertissement', count($leger['avertissements']), 1);
$nom = appliquerControleIdentite($base, identiteDifferente($ident, ['nom' => 'DUPONT', 'prenoms' => 'Marie', 'date_naissance' => '2015-03-05']));
$verif('seul le nom diffère (variante d\'écriture) -> reste DFA', $nom['statut'], 'DFA');

echo "\n== Bilan calculé depuis la dernière ligne (CE1, 2023-2024, examen 2026)\n";
$r = calculerDfa('27000009Q', 'CE1', 2023, 2026);
$verif('statut', $r['statut'], 'DFA');
$verif('lignes', implode(' · ', array_map(fn ($l) => $l['annee'] . ' ' . $l['dfa'], $r['lignes'])), '2324 A · 2425 A · 2526 A');

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
