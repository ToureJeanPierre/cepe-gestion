<?php
// Test de la lecture des listes en PDF (lecture seule, rien n'est écrit en base).
//  A. cas fabriqués : colonnes décalées, cellules vides, « Sans Extrait »...
//  B. comparaison avec les vrais fichiers : chaque PDF du dossier de dépôts qui a un jumeau Word (même dossier, même nom)
//     doit donner EXACTEMENT les mêmes personnes et les mêmes valeurs que le Word, qui sert de référence.
// Lancer : php tests/pdf_import_test.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../src/depots_helpers.php';
require __DIR__ . '/../src/pdf_helpers.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "== A. Reconnaissance par le contenu\n";
$verif('matricule', [pdfEstMatricule('27721501Y'), pdfEstMatricule('2772 1501 Y'), pdfEstMatricule('En cours')], [true, false, false]);
$verif('date', [pdfEstDate('21/03/2017'), pdfEstDate('2/3/17'), pdfEstDate('YOPOUGON')], [true, true, false]);
$verif('téléphone', [pdfEstTelephone('07 16 91 06 28'), pdfEstTelephone('0779269802/0546090171'), pdfEstTelephone('5426 DU 03/02/20')], [true, true, false]);
$verif('fonction', [pdfEstFonction('Adjoint'), pdfEstFonction('Directrice'), pdfEstFonction('CE2')], [true, true, false]);
$verif('classe', [pdfEstClasse('CM1'), pdfEstClasse('CP1 A'), pdfEstClasse('Adjoint')], [true, true, false]);

echo "\n== A. Candidats : colonnes décalées d'une page à l'autre\n";
$cand = [
    ['N°', 'NOM', 'PRENOMS', 'SEXE (M/F)', 'NATIONALITE', 'DATE DE NAI.', 'LIEU DE NAI.', 'MATRICULE', '', 'ACTE DE'],
    ['', '', '', '', '', '', '', '', '', 'NAI.'],
    ['1', 'AMONOUA', 'KODJO AXEL TIMEO', 'M', 'IV', '21/03/2017', 'YOPOUGON', '27721501Y', '', ''],
    ['2', 'APLOGAN', 'YENINGNAN KOFFI JORES', 'M', '', '', 'YOPOUGON', '', '', ''],
    ['6', 'DIOMANDE', 'DAOUD YOHAN', 'M', 'M', '', '', '', 'Sans Extrait', ''],
    // page 2 : matricule décalé de deux colonnes, lieu décalé d'une
    ['16', 'LIHIRI', 'YOHANA SARRA MYRIAM', 'F', 'IV', '28/01/2017', 'YOPOUGON', '', '', '27121199V', ''],
    ['18', 'ONANE', 'GBATTA EVE ROXANE', 'F', 'IV', '02/05/2016', '', 'SAÏOUA', '', '27742271U', ''],
    ['19', 'SOKORA', 'SCHANAELLE YEDIDIA', 'F', 'IV', '', '', '', '', '', ''],
    ['', '', '', '', '', '', '', '', '', '', ''],
    ['46', '', '', '', '', '', '', '', '', '', ''],
];
$av = [];
$r = mapperLignesPdfCandidats($cand, 'EPV TEST', 'C1', $av);
$verif('6 candidats (en-têtes et lignes vides ignorés)', count($r), 6);
$verif('ligne normale', array_slice($r[0], 0, 8), ['AMONOUA', 'KODJO AXEL TIMEO', 'M', 'IV', '21/03/2017', 'YOPOUGON', '27721501Y', '']);
$verif('date et nationalité absentes : le lieu reste le lieu', [$r[1][3], $r[1][4], $r[1][5]], ['', '', 'YOPOUGON']);
$verif('« Sans Extrait » = pas d\'acte', $r[2][7], '');
$verif('matricule décalé retrouvé', $r[3][6], '27121199V');
$verif('lieu décalé + matricule décalé', [$r[4][5], $r[4][6]], ['SAÏOUA', '27742271U']);
$verif('école et code fournis par la page', [$r[0][9], $r[0][10]], ['EPV TEST', 'C1']);

echo "\n== A. Personnel : colonnes variables\n";
$pers = [
    ['N°', 'NOM', 'PRENOMS', 'SEXE', 'N° D’AUTORISATION (Saisir sans espace)', 'CORPS & GRADE', 'FONCTION', 'COURS TENU', 'CONTACT', 'SURVEILLANCE'],
    ['1', 'ANIN', 'MARIE-PAULE', 'F', 'En cours', 'IA', 'Adjoint', 'CP1', '07 16 91 06 28', ''],
    ['4', 'KPEUKPA', 'MARCELLIN', 'M', '5426 DU 03/02/20', 'IA', 'Directeur', 'CE2', '01 42 77 24 48', ''],
    ['5', 'KOUAKOU', 'KOUAME MARCEL', 'M', '', '', 'Adjoint', 'CM1', '07 97 65 47 77', ''],
    ['6', 'N’GBESSO', 'SERGE PACOME', '', 'En cours', '', '', '', '', ''], // colonnes décalées / vides
    ['7', '', '', '', '', '', '', '', '', ''],
];
$av = [];
$p = mapperLignesPdfPersonnel($pers, $av);
$verif('4 enseignants', count($p), 4);
$verif('« En cours » = identifiant tel quel', $p[0], ['ANIN', 'MARIE-PAULE', 'F', '07 16 91 06 28', 'En cours', 'CP1', 'IA', 'Adjoint', '']);
$verif('vrai n° et directeur', [$p[1][4], $p[1][7], $p[1][5]], ['5426 DU 03/02/20', 'Directeur', 'CE2']);
$verif('sans identifiant ni corps', [$p[2][4], $p[2][6]], ['', '']);
$verif('ligne incomplète : signalée', count($av) >= 1, true);

echo "\n== B. Comparaison avec les vrais fichiers (PDF contre son jumeau Word)\n";
$dossier = dossierDepots($pdo);
$paires = 0; $identiques = 0; $differents = [];
if (is_dir($dossier)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD);
    $it->setMaxDepth(3);
    $norm = fn ($t) => preg_replace('/[^A-Z0-9]+/', '', retirerAccents(mb_strtoupper(trim((string) $t)))); // sans espaces : Word recolle les mots coupés par un retour à la ligne
    $chiffres = fn ($t) => preg_replace('/\D+/', '', (string) $t);
    $cle = function (array $r, string $type) use ($norm, $chiffres) {
        if ($type === 'candidats') {
            return implode('|', [$norm($r[0]), $norm($r[1]), $norm($r[2]), $norm($r[3]), (string) parserDateNaissanceImport($r[4]), $norm($r[5]), $norm($r[6]), in_array(strtoupper(trim($r[7])), ['O', 'OUI', '1'], true) ? 'O' : 'N']);
        }
        return implode('|', [$norm($r[0]), $norm($r[1]), $norm($r[2]), $chiffres($r[3]), $norm($r[4]), $norm($r[5]), $norm($r[7])]);
    };
    foreach ($it as $f) {
        if (!$f->isFile() || strtolower($f->getExtension()) !== 'pdf') { continue; }
        $docx = $f->getPath() . '/' . $f->getBasename('.' . $f->getExtension()) . '.docx';
        if (!is_file($docx)) { continue; }
        try {
            $lignesDocx = extraireTableauDocx($docx);
            $entete = retirerAccents(mb_strtoupper(implode(' | ', $lignesDocx[0] ?? [])));
            $type = (str_contains($entete, 'FONCTION') || str_contains($entete, 'CORPS')) ? 'personnel' : ((str_contains($entete, 'NAI') || str_contains($entete, 'MATRICULE')) ? 'candidats' : null);
            if ($type === null) { continue; }
            $ref = $type === 'candidats' ? mapperLignesDocxCandidats($lignesDocx, '', null) : mapperLignesDocxPersonnel($lignesDocx);
            $lp = extraireTableauPdf($f->getPathname());
            $av = [];
            $pdfRows = $type === 'candidats' ? mapperLignesPdfCandidats($lp, '', null, $av) : mapperLignesPdfPersonnel($lp, $av);
        } catch (Throwable $e) {
            $paires++; $differents[] = [$f->getFilename(), 'erreur : ' . $e->getMessage()]; continue;
        }
        $paires++;
        $a = array_map(fn ($r) => $cle($r, $type), $ref);
        $b = array_map(fn ($r) => $cle($r, $type), $pdfRows);
        sort($a); sort($b);
        if ($a === $b) { $identiques++; continue; }
        $manque = array_values(array_diff($a, $b));
        $enPlus = array_values(array_diff($b, $a));
        $differents[] = [$f->getFilename() . " ($type, " . count($a) . " Word / " . count($b) . " PDF)", "dans le Word seulement : " . json_encode(array_slice($manque, 0, 2), JSON_UNESCAPED_UNICODE) . " — dans le PDF seulement : " . json_encode(array_slice($enPlus, 0, 2), JSON_UNESCAPED_UNICODE)];
    }
}
echo "  $paires paire(s) PDF/Word comparée(s) : $identiques identique(s), " . count($differents) . " différente(s)\n";
foreach ($differents as [$nom, $detail]) { echo "     - $nom\n         $detail\n"; }
$verif('toutes les paires PDF/Word donnent exactement les mêmes données', count($differents), 0);

echo "\n== C. Mode strict (Écoles, Centres, Notes) : accepté seulement si le PDF reproduit le modèle\n";
$tmpDir = sys_get_temp_dir();
$exporter = function (array $lignes, string $fichier) {
    $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $feuille = $classeur->getActiveSheet();
    $feuille->fromArray($lignes, null, 'A1', true);
    $feuille->setPrintGridlines(true); // bordures visibles, comme un tableau imprimé
    (new \PhpOffice\PhpSpreadsheet\Writer\Pdf\Dompdf($classeur))->save($fichier);
};
$modele = [
    ['Nom École', 'Statut', 'Code DSPS', 'Nom École Tutrice', 'Type Ratt.', 'Groupe Scolaire', 'Directeur', 'Téléphone', 'Effectif', 'Centre Examen'],
    ['EPP TEST UN', 'Public', '27000001A', '', '', '', 'KONE ALI', '0707070707', '52', 'N'],
    ['EPV TEST DEUX', 'Privé', '', 'EPP TEST UN', 'Arrimée', '', '', '', '', 'O'],
    ['EPP TEST TROIS', 'Public', '27000003C', '', '', 'GROUPE X', 'DIALLO', '', '', ''],
];
$pdfOk = $tmpDir . '/zz_modele_ecoles.pdf';
$exporter($modele, $pdfOk);
try {
    $lu = lignesPdfModeleStrict($pdfOk, 10, ['NOM', 'STATUT', 'CODE']);
    $verif("modèle Écoles exporté en PDF : relu à l'identique (colonnes vides comprises)", array_slice($lu, 1), array_slice($modele, 1));
} catch (Throwable $e) {
    $verif("modèle Écoles exporté en PDF : relu à l'identique", 'REFUS : ' . $e->getMessage(), 'lecture réussie');
}
// Notes : 3 + 2 matières + Présent = 6 colonnes, avec des notes absentes (cases vides)
$notes = [
    ['Matricule DSPS', 'Nom', 'Prénoms', 'Français', 'Maths', 'Présent'],
    ['27000009Q', 'DUPONT', 'MARIE', '14,5', '', 'O'],
    ['', 'MARTIN', 'PAUL', '', '', 'N'],
];
$pdfNotes = $tmpDir . '/zz_notes.pdf';
$exporter($notes, $pdfNotes);
try {
    $lu = lignesPdfModeleStrict($pdfNotes, 6, ['MATRICULE', 'NOM', 'PRENOM']);
    $verif('notes absentes (cases vides) : chaque note reste dans SA matière', array_slice($lu, 1), array_slice($notes, 1));
} catch (Throwable $e) {
    $verif('notes en PDF', 'REFUS : ' . $e->getMessage(), 'lecture réussie');
}
// Mauvais nombre de colonnes / mauvais en-tête : refus, jamais de devinette
foreach ([[$pdfNotes, 7, ['MATRICULE', 'NOM', 'PRENOM'], 'mauvais nombre de colonnes (7 au lieu de 6)'], [$pdfNotes, 6, ['STATUT', 'NOM', 'PRENOM'], 'en-tête qui ne correspond pas au modèle']] as [$p, $nb, $mots, $quoi]) {
    try { lignesPdfModeleStrict($p, $nb, $mots); $verif("refus : $quoi", 'accepté à tort', 'refusé'); }
    catch (RuntimeException $e) { $verif("refus : $quoi", str_contains($e->getMessage(), 'modèle Excel'), true); }
}
$enseignants = null;
foreach (listerFichiersDepot(dossierDepots($pdo)) as $f) {
    if ($f['ext'] === 'pdf' && stripos($f['nom'], 'BAPTISTE ANNEXE') === 0 && stripos($f['nom'], 'NOMINATIVE') === false) { $enseignants = dossierDepots($pdo) . '/' . $f['relatif']; break; }
}
if ($enseignants) {
    try { lignesPdfModeleStrict($enseignants, 10, ['NOM', 'STATUT', 'CODE']); $verif("une liste d'enseignants n'est pas prise pour un modèle Écoles", 'accepté à tort', 'refusé'); }
    catch (RuntimeException $e) { $verif("une liste d'enseignants n'est pas prise pour un modèle Écoles", true, true); }
}
@unlink($pdfOk);
@unlink($pdfNotes);

echo "\n== D. Modèle sans numéro de ligne et avec « NOM & PRENOMS » dans une seule colonne (cas réel : LISTE DES ENSEIGNANTS PUBLIC-1)\n";
$sansNumero = [
    ['MINISTERE DE L’EDUCATION NATIONALE ETABLISSEMENT : EPP TEST', 'LISTE DES ENSEIGNANTS', 'REPUBLIQUE DE COTE D’IVOIRE'],
    ['ETABLISSEMENT : EPP TEST', '', ''],
    ['', '', ''],
    ['N°', 'NOM & PRENOMS', 'MATRICULE', 'CORPS & GRADE', 'FONCTION', 'COURS TENU', 'CONTACT', 'SURVEILLANCE', 'CORRECTION', 'SECRETARIAT'],
    ['', '', '', '', '', '', '', '1er EB', '2e EB', '6e'],
    ['', 'Mme ONAMON Née ANGORA ADJO ROSE', '801099 V', 'IA C3', 'ADJOINTE', 'CP1', '07 48 33 66 16', '', '', ''],
    ['', 'DOSSO MESSEVE', '272932 Y', 'IO B3', 'DIRECTEUR', 'CE2', '07 08 29 40 34', '', '', ''],
    ['', '', '', '', '', '', '', '', '', ''],
    ['YOPOUGON, le', 'Le Directeur d’école (nom, prénoms, cachet et signature)', '', '', '', '', '', '', '', ''],
];
$av = [];
$s = mapperLignesPdfPersonnel($sansNumero, $av);
$verif('2 enseignants lus (en-têtes, sous-titres, pied de page et ligne vide ignorés)', count($s), 2);
$verif('identifiant, classe, fonction, téléphone', [$s[0][4], $s[0][5], $s[0][7], $s[0][3]], ['801099 V', 'CP1', 'ADJOINTE', '07 48 33 66 16']);
$verif('emploi déduit du corps (IA C3 -> IA, IO B3 -> IO)', [$s[0][6], $s[1][6]], ['IA', 'IO']);
$verif('le directeur est reconnu', $s[1][7], 'DIRECTEUR');
$verif('type de liste reconnu malgré le petit tableau « ministère » en tête', typeListePdf($sansNumero, ''), 'personnel');
$candSans = [
    ['N°', 'NOM', 'PRENOMS', 'SEXE', 'DATE DE NAI.', 'LIEU', 'MATRICULE'],
    ['', 'AMONOUA', 'KODJO AXEL', 'M', '21/03/2017', 'YOPOUGON', '27721501Y'],
    ['', 'ASSOUKROU', 'LOGON MARIE', 'F', '13/10/2017', 'YOPOUGON', '27742329M'],
    ['', 'CAMARA', 'AMADOU', 'M', '', '', ''],
];
$c = mapperLignesPdfCandidats($candSans, 'EPV TEST', null);
$verif('candidats sans numéro de ligne : 3 lus, en-tête ignoré', [count($c), $c[0][0], $c[0][6], $c[2][0]], [3, 'AMONOUA', '27721501Y', 'CAMARA']);

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
