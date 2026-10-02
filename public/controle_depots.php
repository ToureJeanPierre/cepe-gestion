<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/depots_helpers.php';

$pageTitle = 'Contrôle du dossier';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$error = null;

// ==========================================
// CHOIX DU DOSSIER
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer_dossier'])) {
    $nouveau = rtrim(str_replace('\\', '/', trim($_POST['dossier'] ?? '')), '/');
    if ($nouveau === '' || !is_dir($nouveau)) {
        $error = "Ce dossier est introuvable sur le PC où tourne l'application : « " . $nouveau . " ».";
    } else {
        reglageEcrire($pdo, 'dossier_depots', $nouveau);
        header('Location: controle_depots.php?analyser=1');
        exit;
    }
}

$dossier = dossierDepots($pdo);
$analyse = isset($_GET['analyser']) ? analyserDossierDepots($pdo, (int) $anneeId, $dossier) : null;

$statutsLibelles = [
    'nouveau' => ['À importer', 'bg-primary'],
    'partiel' => ['Partiellement enregistré', 'bg-warning text-dark'],
    'deja' => ['Déjà enregistré', 'bg-success'],
    'vide' => ['Modèle vide', 'bg-secondary'],
    'non_reconnu' => ['Non reconnu', 'bg-secondary'],
    'illisible' => ['Illisible', 'bg-danger'],
    'excel' => ['Excel (non analysé)', 'bg-light text-dark border'],
];
$typesLibelles = ['candidats' => 'Candidats', 'personnel' => 'Personnel'];

// ==========================================
// EXPORT EXCEL
// ==========================================
if ($analyse && ($_GET['format'] ?? '') === 'xlsx') {
    $nomsEcoles = array_column($analyse['ecoles'], 'nom', 'id');
    $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $f1 = $classeur->getActiveSheet();
    $f1->setTitle('Fichiers du dossier');
    $f1->fromArray(['Fichier', 'Sous-dossier', 'Type', 'École détectée', 'École sûre ?', 'Lignes', 'Déjà en base', 'Nouveaux', 'Doublons dans le fichier', 'Statut', 'Identique à'], null, 'A1');
    $l = 2;
    foreach ($analyse['fichiers'] as $f) {
        $f1->fromArray([
            $f['nom'], dirname($f['relatif']) === '.' ? '' : dirname($f['relatif']),
            $typesLibelles[$f['type']] ?? '', $f['ecole_id'] ? ($nomsEcoles[$f['ecole_id']] ?? '') : '', $f['ecole_sure'] ? 'oui' : 'non',
            $f['total'], $f['deja'], $f['nouveaux'], $f['doublons_fichier'], $statutsLibelles[$f['statut']][0] ?? $f['statut'], $f['meme_fichier_que'] ?? '',
        ], null, 'A' . $l++);
    }
    $f2 = $classeur->createSheet();
    $f2->setTitle('Écoles');
    $f2->fromArray(['École', 'Candidats en base', 'Fichier candidats dans le dossier', 'Personnel en base', 'Fichier personnel dans le dossier'], null, 'A1');
    $l = 2;
    foreach ($analyse['ecoles'] as $e) {
        $f2->fromArray([$e['nom'], $e['nb_candidats'], implode(' ; ', $e['fichiers_candidats']), $e['nb_personnel'], implode(' ; ', $e['fichiers_personnel'])], null, 'A' . $l++);
    }
    foreach ([$f1, $f2] as $feuille) {
        $derniere = $feuille->getHighestColumn();
        $feuille->getStyle('A1:' . $derniere . '1')->getFont()->setBold(true);
        $feuille->getStyle('A1:' . $derniere . '1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
        $feuille->freezePane('A2');
        $feuille->setAutoFilter('A1:' . $derniere . ($l > 2 ? $feuille->getHighestRow() : 1));
        for ($c = 1, $n = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($derniere); $c <= $n; $c++) {
            $feuille->getColumnDimensionByColumn($c)->setAutoSize(true);
        }
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="controle_dossier_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save('php://output');
    exit;
}

$vue = $_GET['vue'] ?? 'a_traiter';
$ecolesParId = [];
$nomsEcoles = [];
foreach ($pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll() as $e) {
    $nomsEcoles[(int) $e['id']] = $e['nom'];
}

$fichiersAffiches = [];
if ($analyse) {
    foreach ($analyse['fichiers'] as $f) {
        $garde = match ($vue) {
            'a_traiter' => in_array($f['statut'], ['nouveau', 'partiel'], true),
            'deja' => $f['statut'] === 'deja',
            'vides' => in_array($f['statut'], ['vide', 'non_reconnu', 'illisible'], true),
            'excel' => $f['statut'] === 'excel',
            'copies' => $f['meme_fichier_que'] !== null,
            default => true,
        };
        if ($garde) {
            $fichiersAffiches[] = $f;
        }
    }
}

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2><i class="bi bi-folder-check"></i> Contrôle du dossier de dépôts</h2>
    <?php if ($analyse): ?>
        <a href="controle_depots.php?analyser=1&format=xlsx" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Exporter le contrôle en Excel</a>
    <?php endif; ?>
</div>

<?php if (!empty($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?>
        <small class="d-block">Le détail des lignes ignorées éventuelles s'affiche sur la page Candidats / Personnel.</small>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="alert alert-light border small">
    Les listes reçues par WhatsApp sont enregistrées dans un dossier de ce PC. Cette page <strong>lit ce dossier sans rien importer</strong> :
    pour chaque fichier Word elle reconnaît le type (candidats / personnel), l'école, le nombre de lignes, et compare avec ce qui est
    <strong>déjà dans l'application</strong> — tu vois ainsi tout de suite ce qui a déjà été enregistré (inutile de le refaire), ce qui reste à importer,
    et quelles écoles n'ont encore rien envoyé.
</div>

<form method="POST" class="card card-body shadow-sm mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label small mb-1">Dossier où sont enregistrés les fichiers reçus (sur le PC de l'application, sous-dossiers inclus)</label>
            <input type="text" name="dossier" value="<?= htmlspecialchars($dossier) ?>" class="form-control" required>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button type="submit" name="enregistrer_dossier" class="btn btn-primary"><i class="bi bi-search"></i> Enregistrer et analyser</button>
            <?php if (is_dir($dossier)): ?><a href="controle_depots.php?analyser=1" class="btn btn-outline-primary">Ré-analyser</a><?php endif; ?>
        </div>
    </div>
    <?php if (!is_dir($dossier)): ?>
        <div class="text-danger small mt-2"><i class="bi bi-exclamation-triangle"></i> Ce dossier n'existe pas sur ce PC.</div>
    <?php endif; ?>
</form>

<?php if (!$analyse): ?>
    <p class="text-muted">Clique sur « Enregistrer et analyser » pour lancer le contrôle.</p>
<?php else: ?>
    <?php
    $r = $analyse['resume'];
    $nbEcolesAvecBase = count(array_filter($analyse['ecoles'], fn ($e) => $e['nb_candidats'] > 0 || $e['nb_personnel'] > 0));
    $nbRienNulPart = count(array_filter($analyse['ecoles'], fn ($e) => !$e['nb_candidats'] && !$e['nb_personnel'] && !$e['fichiers_candidats'] && !$e['fichiers_personnel']));
    $copies = array_values(array_filter($analyse['fichiers'], fn ($f) => $f['meme_fichier_que'] !== null));
    ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3"><?php statCard('bi-file-earmark-word', 'navy', (string) $r['word'], 'Fichiers Word dans le dossier', $r['excel'] . ' fichier(s) Excel non analysés'); ?></div>
        <div class="col-md-3"><?php statCard('bi-check2-circle', 'green', (string) $r['deja'], 'Déjà enregistrés', 'inutile de les ré-importer', 'controle_depots.php?analyser=1&vue=deja'); ?></div>
        <div class="col-md-3"><?php statCard('bi-box-arrow-in-down', 'orange', (string) ($r['nouveau'] + $r['partiel']), 'À importer', ($r['partiel'] ? $r['partiel'] . ' partiellement enregistré(s)' : 'pas encore dans l\'application'), 'controle_depots.php?analyser=1&vue=a_traiter'); ?></div>
        <div class="col-md-3"><?php statCard('bi-building-x', 'red', (string) $nbRienNulPart, 'Écoles sans aucun fichier ni fiche', $nbEcolesAvecBase . ' école(s) ont déjà des fiches en base'); ?></div>
    </div>

    <?php if ($copies): ?>
        <div class="alert alert-warning">
            <strong><i class="bi bi-files"></i> <?= count($copies) ?> fichier(s) identique(s) à un autre fichier du dossier</strong> (même contenu, octet pour octet) —
            un directeur a peut-être renvoyé la liste d'une autre école, ou le fichier a été enregistré deux fois :
            <ul class="mb-0">
                <?php foreach ($copies as $c): ?>
                    <li><?= htmlspecialchars($c['nom']) ?> <span class="text-muted">=</span> <?= htmlspecialchars($c['meme_fichier_que']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <h5 class="mt-4">1. Les fichiers du dossier</h5>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php
        $onglets = ['a_traiter' => ['🆕 À importer', $r['nouveau'] + $r['partiel']], 'deja' => ['✅ Déjà enregistrés', $r['deja']],
            'vides' => ['📄 Modèles vides / non reconnus', $r['vide'] + $r['non_reconnu'] + $r['illisible']],
            'copies' => ['👥 Copies identiques', $r['copies']], 'excel' => ['Excel', $r['excel']], 'tous' => ['Tous', count($analyse['fichiers'])]];
        foreach ($onglets as $code => [$libelle, $nb]): ?>
            <a href="controle_depots.php?analyser=1&vue=<?= $code ?>" class="btn btn-sm <?= $vue === $code ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $libelle ?> <span class="badge bg-light text-dark border"><?= $nb ?></span></a>
        <?php endforeach; ?>
    </div>

    <div class="card shadow-sm mb-4">
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr><th>Fichier</th><th>Type</th><th>École</th><th class="text-end">Lignes</th><th class="text-end">Déjà en base</th><th class="text-end">Nouveaux</th><th>Statut</th><th style="min-width:330px;">Action</th></tr>
                </thead>
                <tbody>
                    <?php if (!$fichiersAffiches): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Aucun fichier dans cette vue.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($fichiersAffiches as $f): ?>
                        <?php [$libStatut, $classeStatut] = $statutsLibelles[$f['statut']]; $importable = in_array($f['statut'], ['nouveau', 'partiel', 'deja'], true); ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($f['nom']) ?></strong>
                                <div class="small text-muted"><?= htmlspecialchars(dirname($f['relatif']) === '.' ? 'racine du dossier' : dirname($f['relatif'])) ?> · <?= date('d/m/Y H:i', $f['mtime']) ?></div>
                                <?php if ($f['meme_fichier_que']): ?><div class="small text-warning"><i class="bi bi-files"></i> identique à « <?= htmlspecialchars(basename($f['meme_fichier_que'])) ?> »</div><?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($typesLibelles[$f['type']] ?? '—') ?></td>
                            <td class="small">
                                <?php if ($f['ecole_id']): ?>
                                    <i class="bi bi-check2 text-success"></i> <?= htmlspecialchars($nomsEcoles[$f['ecole_id']] ?? '') ?>
                                <?php elseif ($importable): ?>
                                    <span class="text-warning"><i class="bi bi-question-circle"></i> à confirmer</span>
                                    <?php if ($f['ecole_texte']): ?><div class="text-muted">écrit dans le fichier : « <?= htmlspecialchars($f['ecole_texte']) ?> »</div><?php endif; ?>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="text-end"><?= $f['total'] ?: '' ?><?php if ($f['doublons_fichier']): ?><div class="small text-warning" title="Lignes répétées dans le fichier"><?= $f['doublons_fichier'] ?> doublon(s)</div><?php endif; ?></td>
                            <td class="text-end"><?= $f['total'] ? $f['deja'] : '' ?></td>
                            <td class="text-end"><?= $f['total'] ? '<strong>' . $f['nouveaux'] . '</strong>' : '' ?></td>
                            <td><span class="badge <?= $classeStatut ?>"><?= $libStatut ?></span><?php if ($f['erreur']): ?><div class="small text-danger"><?= htmlspecialchars($f['erreur']) ?></div><?php endif; ?></td>
                            <td>
                                <?php if ($importable): ?>
                                    <?php $cible = $f['type'] === 'candidats' ? 'candidats.php' : 'enseignants.php'; ?>
                                    <form method="POST" action="<?= $cible ?>" class="d-flex gap-1"
                                          onsubmit="var s=this.querySelector('select'); if(!s.value){alert('Choisis d\'abord l\'école.');return false;} return confirm('Importer « <?= htmlspecialchars(addslashes($f['nom'])) ?> » (' + <?= (int) $f['total'] ?> + ' lignes) dans l\'école « ' + s.options[s.selectedIndex].text + ' » ?');">
                                        <input type="hidden" name="depot_chemin" value="<?= htmlspecialchars($f['relatif']) ?>">
                                        <input type="hidden" name="retour" value="controle_depots">
                                        <?php if ($f['type'] === 'candidats'): ?>
                                            <input type="hidden" name="importer_candidats" value="1">
                                            <select name="ecole_id_import_docx" class="form-select form-select-sm">
                                        <?php else: ?>
                                            <input type="hidden" name="importer_personnel" value="1">
                                            <input type="hidden" name="type_import" value="enseignant_ecole">
                                            <select name="ecole_id_import" class="form-select form-select-sm">
                                        <?php endif; ?>
                                            <option value="">— choisir l'école —</option>
                                            <?php
                                            $propose = $f['ecole_id'] ? [$f['ecole_id']] : $f['ecoles_possibles'];
                                            foreach ($propose as $pid): ?>
                                                <option value="<?= $pid ?>" <?= $f['ecole_id'] === $pid ? 'selected' : '' ?>>★ <?= htmlspecialchars($nomsEcoles[$pid] ?? '') ?></option>
                                            <?php endforeach; ?>
                                            <?php foreach ($nomsEcoles as $id => $nom): if (in_array($id, $propose, true)) continue; ?>
                                                <option value="<?= $id ?>"><?= htmlspecialchars($nom) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-sm <?= $f['statut'] === 'deja' ? 'btn-outline-secondary' : 'btn-success' ?>" style="white-space:nowrap;">
                                            <?= $f['statut'] === 'deja' ? 'Ré-importer' : 'Importer' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <h5 class="mt-4">2. Les écoles : qu'a-t-on reçu ?</h5>
    <?php
    $vueEcoles = $_GET['ve'] ?? 'toutes';
    $ecolesAff = array_values(array_filter($analyse['ecoles'], function ($e) use ($vueEcoles) {
        $rien = !$e['nb_candidats'] && !$e['nb_personnel'] && !$e['fichiers_candidats'] && !$e['fichiers_personnel'];
        return match ($vueEcoles) {
            'rien' => $rien,
            'a_importer' => (!$e['nb_candidats'] && $e['fichiers_candidats']) || (!$e['nb_personnel'] && $e['fichiers_personnel']),
            'complet' => $e['nb_candidats'] && $e['nb_personnel'],
            default => true,
        };
    }));
    ?>
    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach (['toutes' => 'Toutes', 'a_importer' => 'Fichier dans le dossier, pas encore en base', 'rien' => 'Rien reçu du tout', 'complet' => 'Candidats + personnel en base'] as $code => $libelle): ?>
            <a href="controle_depots.php?analyser=1&vue=<?= htmlspecialchars($vue) ?>&ve=<?= $code ?>#ecoles" class="btn btn-sm <?= $vueEcoles === $code ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $libelle ?></a>
        <?php endforeach; ?>
    </div>
    <div class="card shadow-sm" id="ecoles">
        <div class="card-header"><strong><?= count($ecolesAff) ?></strong> école(s)</div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>École</th><th>Candidats</th><th>Personnel</th></tr></thead>
                <tbody>
                    <?php foreach ($ecolesAff as $e): ?>
                        <tr>
                            <?php foreach (['candidats' => 'nb_candidats', 'personnel' => 'nb_personnel'] as $cle => $champNb): ?>
                            <?php if ($cle === 'candidats'): ?><td><strong><?= htmlspecialchars($e['nom']) ?></strong></td><?php endif; ?>
                            <td class="small">
                                <?php if ($e[$champNb] > 0): ?>
                                    <span class="badge bg-success">enregistrés : <?= $e[$champNb] ?></span>
                                <?php elseif ($e['fichiers_' . $cle]): ?>
                                    <span class="badge bg-primary">dans le dossier, à importer</span>
                                    <div class="text-muted"><?= htmlspecialchars(implode(' ; ', $e['fichiers_' . $cle])) ?></div>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border">rien reçu</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php include '../views/layouts/footer.php'; ?>
