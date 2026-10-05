<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/dfa_helpers.php';

$pageTitle = 'Bilan DESPS / DFA';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

// L'année d'examen est celle de l'année consultée (jamais codée en dur).
$anneeExamenDebut = anneeDebutDepuisLibelle($ANNEE_SCOLAIRE);
if (!$anneeExamenDebut) {
    die("Année scolaire illisible : " . htmlspecialchars((string) $ANNEE_SCOLAIRE));
}

$success = null;
$error = null;
$erreursImport = [];

// ==========================================
// ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($anneeLectureSeule) {
        $error = "Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.";
    } elseif (isset($_POST['importer_desps'])) {
        if (isset($_FILES['fichier_desps']) && $_FILES['fichier_desps']['error'] === 0) {
            try {
                $res = importerResultatsDesps($pdo, (int) $anneeId, $anneeExamenDebut, $_FILES['fichier_desps']['tmp_name']);
                $success = "Résultats DESPS importés : {$res['importes']} élève(s) (dont {$res['introuvables']} matricule(s) introuvable(s)).";
                $erreursImport = $res['erreurs'];
            } catch (Throwable $e) {
                $error = "Fichier illisible : " . $e->getMessage();
            }
        } else {
            $error = "Choisissez un fichier Excel ou CSV.";
        }
    } elseif (isset($_POST['modifier_cursus'])) {
        $stmt = $pdo->prepare("SELECT id, matricule_dsps, ecole_id FROM candidats WHERE id = ? AND annee_id = ? AND est_candidat_libre = 0");
        $stmt->execute([(int) ($_POST['candidat_id'] ?? 0), $anneeId]);
        $cand = $stmt->fetch();
        if (!$cand) {
            $error = "Élève introuvable.";
        } else {
            $introuvable = isset($_POST['introuvable']) ? 1 : 0;
            $classe = normaliserClasseDesps($_POST['classe_desps'] ?? '');
            $annee = normaliserAnneeDesps($_POST['annee_desps'] ?? '');
            if (!$introuvable && ($classe === null || $annee === null)) {
                $error = "Classe (ex. CE2) et année (ex. 2024-2025) obligatoires, sauf si le matricule est introuvable.";
            } else {
                enregistrerCursusEtBilan($pdo, $cand, [
                    'introuvable' => $introuvable,
                    'ecole_desps' => trim($_POST['ecole_desps'] ?? '') ?: null,
                    'classe_desps' => $classe, 'annee_debut' => $annee,
                    'ecole_conforme' => isset($_POST['ecole_conforme']) ? 1 : 0,
                ], $anneeExamenDebut, indexEcolesDfa($pdo));
                $success = "Données DESPS enregistrées, bilan recalculé.";
            }
        }
    } elseif (isset($_POST['recalculer'])) {
        $n = recalculerTousLesBilans($pdo, (int) $anneeId, $anneeExamenDebut);
        $success = "Bilan recalculé pour $n élève(s).";
    }
}

$ecoleId = (int) ($_GET['ecole_id'] ?? 0);
$ecole = null;
if ($ecoleId) {
    $stmt = $pdo->prepare("SELECT id, nom FROM ecoles WHERE id = ?");
    $stmt->execute([$ecoleId]);
    $ecole = $stmt->fetch() ?: null;
}

$badges = [
    'OK' => ['OK', 'bg-success'],
    'DFA' => ['DFA', 'bg-warning text-dark'],
    'CHANGEMENT_ECOLE' => ['Changement d\'école', 'bg-primary'],
    'SAUT_DE_NIVEAU' => ['Saut de niveau', 'bg-danger'],
    'MATRICULE_INTROUVABLE' => ['Matricule introuvable', 'bg-danger'],
    'A_VERIFIER' => ['À vérifier', 'bg-danger'],
    'NON_VERIFIE' => ['Pas encore vérifié', 'bg-secondary'],
];

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><i class="bi bi-mortarboard"></i> Bilan DESPS / DFA — <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>
        <?php if ($ecole): ?><small class="text-muted">· <?= htmlspecialchars($ecole['nom']) ?></small><?php endif; ?></h2>
    <div class="d-flex gap-2">
        <?php if ($ecole): ?><a href="bilan_desps.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Toutes les écoles</a><?php endif; ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalImportDesps"><i class="bi bi-upload"></i> Importer les résultats DESPS</button>
        <form method="POST" class="d-inline"><button name="recalculer" class="btn btn-outline-primary" <?= $anneeLectureSeule ? 'disabled' : '' ?> title="Recalcule le bilan de tous les élèves (après un changement d'école, d'année...)"><i class="bi bi-arrow-repeat"></i> Recalculer</button></form>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
<?php if ($erreursImport): ?>
    <div class="alert alert-warning"><strong><?= count($erreursImport) ?> remarque(s) à l'import :</strong>
        <ul class="mb-0 small"><?php foreach (array_slice($erreursImport, 0, 30) as $m): ?><li><?= htmlspecialchars($m) ?></li><?php endforeach; ?></ul>
        <?php if (count($erreursImport) > 30): ?><div class="small text-muted">… et <?= count($erreursImport) - 30 ?> autre(s).</div><?php endif; ?></div>
<?php endif; ?>

<?php if (!$ecole): ?>
    <?php $recap = recapBilanParEcole($pdo, (int) $anneeId); ?>
    <div class="alert alert-light border small">
        Pour chaque élève, l'application compare ce qui est <strong>déclaré</strong> (école, CM2) avec la <strong>dernière ligne du cursus DESPS</strong>
        (école, classe, année) et en déduit : <span class="badge bg-success">OK</span> <span class="badge bg-warning text-dark">DFA à remonter</span>
        <span class="badge bg-primary">changement d'école</span> ou un cas à traiter à la main. Importe d'abord les résultats DESPS (Excel : matricule, école, classe, année scolaire).
        Les élèves « pas encore vérifiés » n'ont pas encore de résultat DESPS.
    </div>
    <div class="card shadow-sm">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= count($recap) ?> école(s) avec des candidats</strong>
            <a href="export_dfa.php?tous=1" class="btn btn-sm btn-outline-warning"><i class="bi bi-file-zip"></i> Télécharger toutes les DFA (zip)</a>
        </div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>École</th><th class="text-end">Élèves</th><th class="text-end">OK</th><th class="text-end">DFA</th><th class="text-end">Chgt école</th><th class="text-end">À traiter</th><th class="text-end">Pas vérifiés</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                    <?php if (!$recap): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucun candidat enregistré pour le moment.</td></tr><?php endif; ?>
                    <?php foreach ($recap as $r): ?>
                        <tr>
                            <td><a href="bilan_desps.php?ecole_id=<?= $r['id'] ?>"><strong><?= htmlspecialchars($r['nom']) ?></strong></a></td>
                            <td class="text-end"><?= $r['total'] ?></td>
                            <td class="text-end"><?= $r['OK'] ?: '<span class="text-muted">0</span>' ?></td>
                            <td class="text-end"><?= $r['DFA'] ? '<span class="badge bg-warning text-dark">' . $r['DFA'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td class="text-end"><?= $r['CHANGEMENT_ECOLE'] ? '<span class="badge bg-primary">' . $r['CHANGEMENT_ECOLE'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td class="text-end"><?= $r['a_traiter'] ? '<span class="badge bg-danger">' . $r['a_traiter'] . '</span>' : '<span class="text-muted">0</span>' ?></td>
                            <td class="text-end"><?= $r['NON_VERIFIE'] ?: '<span class="text-muted">0</span>' ?></td>
                            <td class="text-end">
                                <a href="bilan_desps.php?ecole_id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-secondary">Détail</a>
                                <?php if ($r['DFA'] > 0): ?>
                                    <a href="export_dfa.php?ecole_id=<?= $r['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-download"></i> DFA (<?= $r['DFA'] ?>)</a>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-secondary" disabled title="Aucun élève à remonter">DFA (0)</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <?php
    $eleves = lireBilanEcole($pdo, (int) $anneeId, $ecoleId);
    $compte = array_fill_keys(array_keys($badges), 0);
    foreach ($eleves as $e) { $compte[$e['statut']] = ($compte[$e['statut']] ?? 0) + 1; }
    $nbDfa = $compte['DFA'];
    ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <?php foreach ($badges as $code => [$lib, $classe]): if (!$compte[$code]) continue; ?>
            <span class="badge <?= $classe ?> fs-6"><?= $lib ?> : <?= $compte[$code] ?></span>
        <?php endforeach; ?>
        <span class="text-muted small"><?= count($eleves) ?> élève(s)</span>
        <span class="ms-auto">
            <?php if ($nbDfa > 0): ?>
                <a href="export_dfa.php?ecole_id=<?= $ecoleId ?>" class="btn btn-warning"><i class="bi bi-download"></i> Télécharger la DFA (<?= $nbDfa ?>)</a>
            <?php else: ?>
                <button class="btn btn-outline-secondary" disabled title="Aucun élève à remonter">Télécharger la DFA (0)</button>
            <?php endif; ?>
        </span>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Élève</th><th>Matricule</th><th>DESPS (dernière ligne)</th><th>Statut</th><th>Lignes DFA calculées</th><th>Avertissements</th><th></th></tr></thead>
                <tbody>
                    <?php if (!$eleves): ?><tr><td colspan="7" class="text-center text-muted py-4">Aucun candidat dans cette école.</td></tr><?php endif; ?>
                    <?php foreach ($eleves as $e): ?>
                        <?php [$lib, $classe] = $badges[$e['statut']] ?? [$e['statut'], 'bg-secondary']; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($e['nom']) ?></strong> <?= htmlspecialchars($e['prenoms']) ?></td>
                            <td><code><?= htmlspecialchars((string) $e['matricule_dsps']) ?: '—' ?></code></td>
                            <td class="small">
                                <?php if ($e['cursus_id'] && !$e['introuvable']): ?>
                                    <?= htmlspecialchars((string) $e['ecole_desps']) ?: '<span class="text-muted">école non renseignée</span>' ?><br>
                                    <span class="text-muted"><?= htmlspecialchars((string) $e['classe_desps']) ?> · <?= $e['annee_debut'] ? $e['annee_debut'] . '-' . ($e['annee_debut'] + 1) : '?' ?></span>
                                <?php elseif ($e['introuvable']): ?><span class="text-danger">introuvable sur DESPS</span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= $classe ?>"><?= $lib ?></span>
                                <?php if ($e['changement_ecole'] && $e['statut'] === 'DFA'): ?><span class="badge bg-primary">+ changement d'école</span><?php endif; ?>
                                <?php if ($e['motif']): ?><div class="small text-muted"><?= htmlspecialchars($e['motif']) ?></div><?php endif; ?>
                            </td>
                            <td class="small">
                                <?php foreach ($e['lignes'] as $i => $l): ?><?= $i ? ' · ' : '' ?><code><?= htmlspecialchars($l['annee'] . ' ' . $l['dfa']) ?></code> <span class="text-muted">(<?= htmlspecialchars($l['classe']) ?>)</span><?php endforeach; ?>
                            </td>
                            <td class="small text-warning-emphasis"><?php foreach ($e['avert'] as $a): ?><div><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($a) ?></div><?php endforeach; ?></td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary btn-edit-desps" data-bs-toggle="modal" data-bs-target="#modalDesps"
                                    data-id="<?= $e['id'] ?>" data-nom="<?= htmlspecialchars($e['nom'] . ' ' . $e['prenoms']) ?>"
                                    data-ecole="<?= htmlspecialchars((string) $e['ecole_desps']) ?>" data-classe="<?= htmlspecialchars((string) $e['classe_desps']) ?>"
                                    data-annee="<?= $e['annee_debut'] ? $e['annee_debut'] . '-' . ($e['annee_debut'] + 1) : '' ?>"
                                    data-introuvable="<?= (int) $e['introuvable'] ?>" data-conforme="<?= (int) $e['ecole_conforme'] ?>" title="Saisir / corriger les données DESPS"><i class="bi bi-pencil"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Modal import -->
<div class="modal fade" id="modalImportDesps" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <div class="modal-header bg-success text-white"><h5 class="modal-title">Importer les résultats DESPS</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small">Un fichier Excel (.xlsx, .xls) ou CSV avec une ligne par élève, d'après la <strong>dernière ligne du cursus primaire</strong> sur DESPS :</p>
                <ol class="small">
                    <li><strong>Matricule</strong> (doit correspondre à un candidat de l'année consultée)</li>
                    <li><strong>École</strong> trouvée sur DESPS</li>
                    <li><strong>Classe</strong> (CE2, CE 2, Cours Élémentaire 2…)</li>
                    <li><strong>Année scolaire</strong> (2024-2025, 2024/2025…)</li>
                </ol>
                <p class="small text-muted">Les colonnes sont reconnues par leur en-tête (sinon dans cet ordre). Écris <code>INTROUVABLE</code> sur la ligne d'un matricule absent de DESPS.
                    Les formats de classe et d'année sont normalisés automatiquement. Un nouvel import met à jour les élèves déjà présents.</p>
                <input type="file" name="fichier_desps" class="form-control" accept=".xlsx,.xls,.csv" required>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="importer_desps" class="btn btn-success" <?= $anneeLectureSeule ? 'disabled' : '' ?>>Importer</button></div>
        </form>
    </div>
</div>

<!-- Modal saisie manuelle -->
<div class="modal fade" id="modalDesps" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="candidat_id" id="despsCandidat">
            <div class="modal-header"><h5 class="modal-title">Données DESPS — <span id="despsNom"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-2"><label class="form-label small">École trouvée sur DESPS</label><input type="text" name="ecole_desps" id="despsEcole" class="form-control"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small">Classe</label><input type="text" name="classe_desps" id="despsClasse" class="form-control" placeholder="CE2"></div>
                    <div class="col-6"><label class="form-label small">Année scolaire</label><input type="text" name="annee_desps" id="despsAnnee" class="form-control" placeholder="2024-2025"></div>
                </div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="introuvable" id="despsIntrouvable"><label class="form-check-label" for="despsIntrouvable">Matricule introuvable sur DESPS</label></div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="ecole_conforme" id="despsConforme"><label class="form-check-label" for="despsConforme">C'est bien la même école (nom écrit différemment sur DESPS)</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="modifier_cursus" class="btn btn-primary" <?= $anneeLectureSeule ? 'disabled' : '' ?>>Enregistrer</button></div>
        </form>
    </div>
</div>
<script>
document.querySelectorAll('.btn-edit-desps').forEach(function (b) {
    b.addEventListener('click', function () {
        document.getElementById('despsCandidat').value = b.dataset.id;
        document.getElementById('despsNom').textContent = b.dataset.nom;
        document.getElementById('despsEcole').value = b.dataset.ecole;
        document.getElementById('despsClasse').value = b.dataset.classe;
        document.getElementById('despsAnnee').value = b.dataset.annee;
        document.getElementById('despsIntrouvable').checked = b.dataset.introuvable === '1';
        document.getElementById('despsConforme').checked = b.dataset.conforme === '1';
    });
});
</script>

<?php include '../views/layouts/footer.php'; ?>
