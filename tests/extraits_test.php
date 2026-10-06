<?php
// Test hors ligne (aucun appel payant à Claude : l'API est simulée) de la lecture des extraits de naissance.
// Lancer : php tests/extraits_test.php
// Les lignes de test sont créées dans extraits_naissance (table propre à cette fonction) et supprimées
// par identifiant exact ; aucune autre table n'est modifiée.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/extraits_helpers.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "== Nom de fichier\n";
$verif('convention de l\'utilisateur', nomFichierExtrait('CHERIF', 'MOHAMED BEN-MOUBARACK AMIRAL', 'jpg', 6), '06_CHERIF_MOHAMED_BEN_MOUBARACK_AMIRAL.jpg');
$verif('accents et apostrophes', nomFichierExtrait("N'GUESSAN", 'Éloïse Marie-Ève', 'JPG'), 'N_GUESSAN_ELOISE_MARIE_EVE.jpg');
$verif('sans nom', nomFichierExtrait(null, '', 'pdf', 12), '12_SANS_NOM.pdf');
$verif('caractères interdits Windows', nomFichierExtrait('A/B:C*D', 'E?F', 'png'), 'A_B_C_D_E_F.png');

echo "\n== Lecture de la réponse du modèle\n";
$verif('JSON entouré de texte et de ```', extraireJsonLecture("Voici :\n```json\n{\"nom\":\"X\"}\n```"), ['nom' => 'X']);
$verif('réponse sans JSON', extraireJsonLecture("Je ne peux pas lire ce document."), null);
$l = normaliserLecture(['type_document' => 'extrait_naissance', 'nom' => ' assagba ', 'prenoms' => 'akou  naomie', 'sexe' => 'Féminin',
    'date_naissance' => '2014-07-30', 'date_acte' => '2014-02-31', 'contact' => 'null', 'confiance' => 'HAUTE', 'alertes' => ['  doute  ', '', 5]]);
$verif('nom en majuscules, espaces nettoyés', [$l['nom'], $l['prenoms']], ['ASSAGBA', 'AKOU NAOMIE']);
$verif('sexe normalisé', $l['sexe'], 'F');
$verif('date valide conservée', $l['date_naissance'], '2014-07-30');
$verif('date impossible -> null + alerte', [$l['date_acte'], count($l['alertes'])], [null, 2]);
$verif('"null" texte -> null', $l['contact'], null);
$verif('confiance normalisée', $l['confiance'], 'haute');
$verif('année invraisemblable refusée', normaliserLecture(['date_naissance' => '1066-01-01'])['date_naissance'], null);
$verif('confiance inconnue -> basse', normaliserLecture(['confiance' => 'peut-être'])['confiance'], 'basse');
$verif('douteuse si confiance moyenne', lectureDouteuse(normaliserLecture(['nom' => 'A', 'prenoms' => 'B', 'date_naissance' => '2015-01-01', 'confiance' => 'moyenne'])), true);
$verif('pas douteuse si tout est sûr', lectureDouteuse(normaliserLecture(['nom' => 'A', 'prenoms' => 'B', 'date_naissance' => '2015-01-01', 'confiance' => 'haute'])), false);
$verif('douteuse si date manquante', lectureDouteuse(normaliserLecture(['nom' => 'A', 'prenoms' => 'B', 'confiance' => 'haute'])), true);
$verif('autre pièce : inutile de relire', lectureDouteuse(normaliserLecture(['type_document' => 'autre', 'confiance' => 'basse'])), false);

echo "\n== Coût\n";
$tarifs = ['m' => ['entree' => 1.0, 'sortie' => 5.0]];
$verif('2500 jetons en entrée + 400 en sortie à 1 $/5 $ = 0,0045 $', round(coutAppelUsd($tarifs, 'm', 2500, 400), 6), 0.0045);
$verif('modèle inconnu : 0', coutAppelUsd($tarifs, 'x', 1000, 1000), 0.0);

echo "\n== Préparation de l'image (réduction)\n";
$grande = imagecreatetruecolor(3000, 2000);
imagefill($grande, 0, 0, imagecolorallocate($grande, 200, 180, 160));
ob_start(); imagejpeg($grande, null, 95); $jpegGrand = (string) ob_get_clean();
$prep = preparerPourClaude($jpegGrand, 'image/jpeg');
$bin = base64_decode($prep['data']);
[$lw, $lh] = getimagesizefromstring($bin);
$verif('côté le plus long ramené à 1568 px', max($lw, $lh), 1568);
$verif('proportions conservées', round($lw / $lh, 2), 1.5);
$petite = imagecreatetruecolor(800, 600);
ob_start(); imagejpeg($petite); $jpegPetit = (string) ob_get_clean();
[$pw] = getimagesizefromstring(base64_decode(preparerPourClaude($jpegPetit, 'image/jpeg')['data']));
$verif('une petite image n\'est pas agrandie', $pw, 800);
$verif('PDF envoyé tel quel (type document)', preparerPourClaude("%PDF-1.4\n%test", 'application/pdf')['kind'], 'document');
try { preparerPourClaude('pas une image', 'image/jpeg'); $verif('image corrompue refusée', false, true); }
catch (ExtraitLectureException $e) { $verif('image corrompue refusée', true, true); }

$echantillon = 'C:/Users/hp/Desktop/2026-2027/immatriculation/CENTRE 3 26-27 EXTRAITS RENOMMES/01_ASSAGBA_AKOU_NAOMIE.jpg';
if (is_file($echantillon)) {
    $brut = file_get_contents($echantillon);
    $p = preparerPourClaude($brut, 'image/jpeg');
    [$w, $h] = getimagesizefromstring(base64_decode($p['data']));
    echo "  INFO extrait réel : " . round(strlen($brut) / 1024) . " Ko -> " . round($p['octets'] / 1024) . " Ko, {$w}x{$h} px (≈ " . (int) round($w * $h / 750) . " jetons d'image)\n";
}

echo "\n== Enregistrement, lecture simulée, doublons, plafond\n";
$idsTest = [];
$cheminsTest = [];
$plafondInitial = reglageLire($pdo, 'extraits_plafond_usd', null);
try {
    $anneeTest = (int) $pdo->query("SELECT id FROM annees ORDER BY id DESC LIMIT 1")->fetchColumn();
    $img = imagecreatetruecolor(400, 300);
    imagefill($img, 0, 0, imagecolorallocate($img, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    ob_start(); imagejpeg($img); $jpegTest = (string) ob_get_clean();

    $r = enregistrerFichierExtrait($pdo, $anneeTest, null, 'ZZTEST_extrait.jpg', $jpegTest);
    $verif('premier envoi accepté', $r['etat'], 'ajoute');
    $idTest = (int) $r['id'];
    $idsTest[] = $idTest;
    $cheminsTest[] = extraitsDossier() . '/' . $pdo->query("SELECT chemin FROM extraits_naissance WHERE id = $idTest")->fetchColumn();
    $verif('même fichier renvoyé : doublon, pas de nouvelle ligne', enregistrerFichierExtrait($pdo, $anneeTest, null, 'ZZTEST_autre_nom.jpg', $jpegTest)['etat'], 'doublon');
    $verif('fichier texte refusé', enregistrerFichierExtrait($pdo, $anneeTest, null, 'ZZTEST.jpg', 'ceci est du texte')['etat'], 'refuse');
    $verif('fichier vide refusé', enregistrerFichierExtrait($pdo, $anneeTest, null, 'ZZTEST.jpg', '')['etat'], 'refuse');

    // Candidat réel (lecture seule) pour tester le rapprochement automatique.
    $cand = $pdo->query("SELECT id, nom, prenoms, date_naissance FROM candidats WHERE date_naissance IS NOT NULL ORDER BY id LIMIT 1")->fetch();

    $appels = [];
    $faux = function (string $modele, array $f) use (&$appels, $cand) {
        $appels[] = $modele;
        $haute = str_contains($modele, 'sonnet');
        return ['texte' => "```json\n" . json_encode([
            'type_document' => 'extrait_naissance',
            'nom' => $cand['nom'] ?? 'TEST', 'prenoms' => $cand['prenoms'] ?? 'TEST', 'sexe' => 'M',
            'date_naissance' => $cand['date_naissance'] ?? '2015-01-01', 'confiance' => $haute ? 'haute' : 'moyenne', 'alertes' => $haute ? [] : ['tampon sur le nom'],
        ]) . "\n```", 'entree' => 2000, 'sortie' => 300];
    };
    $res = lireExtraitNaissance($pdo, $idTest, $faux);
    $verif('lecture douteuse -> relue par le modèle fort', [$res['escalade'], $appels], [true, ['claude-haiku-4-5', 'claude-sonnet-5-5']]);
    $ligne = $pdo->query("SELECT * FROM extraits_naissance WHERE id = $idTest")->fetch();
    $verif('statut « lu » (jamais validé tout seul)', $ligne['statut'], 'lu');
    $verif('dépense = somme des deux appels', round((float) $ligne['cout_usd'], 5), round((2000 * 1 + 300 * 5) / 1e6 + (2000 * 2 + 300 * 10) / 1e6, 5));
    if ($cand) {
        $verif('candidat reconnu automatiquement (nom + prénom + date)', (int) $ligne['candidat_id'], (int) $cand['id']);
    }

    $appels = [];
    $faux2 = function (string $m, array $f) use (&$appels) { $appels[] = $m; return ['texte' => json_encode(['type_document' => 'extrait_naissance', 'nom' => 'ZZ', 'prenoms' => 'ZZ', 'date_naissance' => '2015-05-05', 'confiance' => 'haute', 'alertes' => []]), 'entree' => 1500, 'sortie' => 200]; };
    lireExtraitNaissance($pdo, $idTest, $faux2);
    $verif('lecture sûre : modèle économique seul', $appels, ['claude-haiku-4-5']);

    // Réponse illisible : erreur, mais la dépense est quand même comptée.
    $avant = (float) $pdo->query("SELECT cout_usd FROM extraits_naissance WHERE id = $idTest")->fetchColumn();
    $fauxMauvais = fn (string $m, array $f) => ['texte' => 'désolé', 'entree' => 1000, 'sortie' => 10];
    try { lireExtraitNaissance($pdo, $idTest, $fauxMauvais); $verif('réponse illisible = erreur', false, true); }
    catch (ExtraitLectureException $e) { $verif('réponse illisible = erreur', true, true); }
    $apres = $pdo->query("SELECT statut, cout_usd FROM extraits_naissance WHERE id = $idTest")->fetch();
    $verif('... statut erreur, dépense comptée malgré l\'échec', [$apres['statut'], (float) $apres['cout_usd'] > $avant], ['erreur', true]);

    // Plafond.
    reglageEcrire($pdo, 'extraits_plafond_usd', '0');
    $appelsPlafond = 0;
    try { lireExtraitNaissance($pdo, $idTest, function () use (&$appelsPlafond) { $appelsPlafond++; return []; }); $verif('plafond atteint : lecture refusée', false, true); }
    catch (ExtraitLectureException $e) { $verif('plafond atteint : lecture refusée, lot arrêté', [$e->arreter, $appelsPlafond], [true, 0]); }
    if ($plafondInitial !== null) { reglageEcrire($pdo, 'extraits_plafond_usd', $plafondInitial); }
    else { $pdo->exec("DELETE FROM reglages WHERE cle = 'extraits_plafond_usd'"); }
    $plafondInitial = '__restaure__';

    // Un extrait validé n'est jamais relu.
    $pdo->prepare("UPDATE extraits_naissance SET statut = 'valide' WHERE id = ?")->execute([$idTest]);
    try { lireExtraitNaissance($pdo, $idTest, $faux); $verif('extrait validé : pas de relecture payante', false, true); }
    catch (ExtraitLectureException $e) { $verif('extrait validé : pas de relecture payante', true, true); }

    // Sans clé : message clair et arrêt du lot.
    $pdo->prepare("UPDATE extraits_naissance SET statut = 'a_lire' WHERE id = ?")->execute([$idTest]);
    if (!extraitsConfig()['cle_presente']) {
        try { lireExtraitNaissance($pdo, $idTest); $verif('sans clé API : erreur explicite', false, true); }
        catch (ExtraitLectureException $e) { $verif('sans clé API : erreur explicite qui arrête le lot', $e->arreter, true); }
        $verif("... et l'extrait reste « à lire » (la panne n'est pas de sa faute)", $pdo->query("SELECT statut FROM extraits_naissance WHERE id = $idTest")->fetchColumn(), 'a_lire');
    } else {
        echo "  INFO clé API présente : test « sans clé » ignoré\n";
    }

    echo "\n== Rapprochement avec les candidats\n";
    if ($cand) {
        $props = proposerCandidatsExtrait($pdo, $anneeTest, ['nom' => $cand['nom'], 'prenoms' => $cand['prenoms'], 'date_naissance' => $cand['date_naissance']]);
        $verif('un candidat existant est retrouvé', in_array((int) $cand['id'], array_column($props, 'id'), true), true);
        $inverse = implode(' ', array_reverse(explode(' ', $cand['nom'])));
        $props2 = proposerCandidatsExtrait($pdo, $anneeTest, ['nom' => mb_strtolower($inverse), 'prenoms' => $cand['prenoms'], 'date_naissance' => $cand['date_naissance']]);
        $verif('ordre des mots / casse sans importance', in_array((int) $cand['id'], array_column($props2, 'id'), true), true);
    }
    $verif('nom inconnu : aucune proposition', proposerCandidatsExtrait($pdo, $anneeTest, ['nom' => 'ZZZNOMINEXISTANT', 'prenoms' => 'QQQ', 'date_naissance' => '2015-01-01']), []);
} finally {
    // Nettoyage : uniquement les lignes et fichiers créés par CE test, par identifiant exact.
    foreach ($idsTest as $id) {
        $pdo->prepare("DELETE FROM extraits_naissance WHERE id = ? AND nom_original LIKE 'ZZTEST\\_%'")->execute([$id]);
    }
    foreach ($cheminsTest as $c) { if (is_file($c)) { unlink($c); } }
    if ($plafondInitial !== '__restaure__') {
        if ($plafondInitial !== null) { reglageEcrire($pdo, 'extraits_plafond_usd', $plafondInitial); }
        else { $pdo->exec("DELETE FROM reglages WHERE cle = 'extraits_plafond_usd'"); }
    }
}

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
