<?php
// Test du moteur d'export (src/export_vue.php) : filtres repris dans les liens, résumé des filtres, fichiers produits.
// Lancer : php tests/export_vue_test.php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/export_vue.php';

$echecs = 0;
$verif = function (string $nom, $obtenu, $attendu) use (&$echecs) {
    $ok = $obtenu === $attendu;
    if (!$ok) { $echecs++; }
    echo ($ok ? "  OK   " : "  ECHEC") . " $nom" . ($ok ? '' : "\n         obtenu : " . json_encode($obtenu, JSON_UNESCAPED_UNICODE) . "\n         attendu: " . json_encode($attendu, JSON_UNESCAPED_UNICODE)) . "\n";
};

echo "== Filtres\n";
$verif('seuls les filtres renseignés sont résumés', resumeFiltres(['Statut' => 'Privé', 'Recherche' => '', 'École' => null, 'Catégorie' => ' Enseignant ']), 'Statut : Privé · Catégorie : Enseignant');
$verif('aucun filtre', resumeFiltres(['Statut' => '', 'Recherche' => '']), 'Aucun filtre (liste complète)');

echo "\n== Menu « Exporter » : reprend la page et ses filtres\n";
$_GET = ['filtre_statut' => 'Privé', 'recherche' => 'lagune', 'msg' => 'ok', 'page' => '3', 'export' => 'xlsx'];
$menu = boutonExportVue([['type' => 'depots', 'titre' => 'Dépôts'], ['url' => 'export_ecoles.php', 'titre' => 'Complet']]);
$verif('filtre Privé et recherche conservés', str_contains($menu, 'filtre_statut=Priv%C3%A9') && str_contains($menu, 'recherche=lagune'), true);
$verif('message, pagination et ancien export retirés', !str_contains($menu, 'msg=') && !str_contains($menu, 'page=3') && substr_count($menu, 'export=xlsx') === 2, true);
$verif('export dédié (type)', str_contains($menu, 'export_type=depots'), true);
$verif('lien existant proposé tel quel', str_contains($menu, 'href="export_ecoles.php"'), true);
$verif('Excel et PDF proposés', str_contains($menu, 'export=pdf') && str_contains($menu, 'Excel'), true);

echo "\n== Demande d'export\n";
$_GET = ['export' => 'pdf', 'export_type' => 'effectifs; DROP TABLE'];
$verif('format reconnu', exportDemande(), 'pdf');
$verif('type nettoyé (lettres et _ seulement)', exportType(), 'effectifs');
$_GET = ['export' => 'exe'];
$verif('format inconnu refusé', exportDemande(), null);

echo "\n== Fichiers produits (dans un processus séparé, car l'export termine la requête)\n";
$script = tempnam(sys_get_temp_dir(), 'exp') . '.php';
file_put_contents($script, '<?php require ' . var_export(__DIR__ . '/../vendor/autoload.php', true) . '; require ' . var_export(__DIR__ . '/../src/export_vue.php', true) . ';
$ANNEE_SCOLAIRE = "2026-2027";
ob_start(); echo "PARASITE-A-IGNORER"; // sortie tamponnée par la page : ne doit pas se retrouver dans le fichier
$lignes = [["EPP A", "Public", 10, "0707070707"], ["EPV B", "Privé", 5, "0101010101"]];
exporterVue("Test export", ["École", "Statut", "Effectif", "Téléphone"], $lignes, $argv[1], ["sous_titre" => "Statut : tous", "totaux" => [2], "nom_fichier" => "test"]);');
$php = PHP_BINARY;
$tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
shell_exec('"' . $php . '" "' . $script . '" xlsx > "' . $tmp . '"'); // redirection vers un fichier : sortie binaire intacte
$xlsx = (string) file_get_contents($tmp);
$verif('Excel : vrai fichier .xlsx (zip), la sortie parasite tamponnée est écartée', substr((string) $xlsx, 0, 2), 'PK');
$tmp = tempnam(sys_get_temp_dir(), 'x') . '.xlsx';
file_put_contents($tmp, preg_replace('/^.*?(PK\x03\x04)/s', '$1', (string) $xlsx));
$feuille = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet()->toArray(null, true, true, false);
$verif('titre', $feuille[0][0], 'TEST EXPORT — 2026-2027');
$verif('en-tête', $feuille[3], ['École', 'Statut', 'Effectif', 'Téléphone']);
$verif('le zéro initial du téléphone est conservé (texte)', $feuille[4][3], '0707070707');
$verif('ligne TOTAL calculée', [$feuille[6][0], (int) $feuille[6][2]], ['TOTAL', 15]);
$tmpPdf = tempnam(sys_get_temp_dir(), 'p') . '.pdf';
shell_exec('"' . $php . '" "' . $script . '" pdf > "' . $tmpPdf . '"');
$pdf = (string) file_get_contents($tmpPdf);
$verif('PDF : vrai fichier PDF', substr((string) $pdf, 0, 4), '%PDF');
@unlink($script); @unlink($tmp); @unlink($tmpPdf);

echo "\n" . ($echecs === 0 ? "TOUS LES TESTS PASSENT\n" : "$echecs ECHEC(S)\n");
exit($echecs === 0 ? 0 : 1);
