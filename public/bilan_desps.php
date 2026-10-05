<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/desps_site.php';

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

// ==========================================
// ACTIONS (la vérification sur le site se fait élève par élève via api_verifier_desps.php)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($anneeLectureSeule) {
        $error = "Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.";
    } elseif (isset($_POST['modifier_cursus'])) {
        $stmt = $pdo->prepare("SELECT id, nom, prenoms, date_naissance, matricule_dsps, ecole_id FROM candidats WHERE id = ? AND annee_id = ? AND est_candidat_libre = 0");
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
                    'introuvable' => $introuvable, 'source' => 'manuel',
                    'ecole_desps' => trim($_POST['ecole_desps'] ?? '') ?: null,
                    'classe_desps' => $classe, 'annee_debut' => $annee,
                    'ecole_conforme' => isset($_POST['ecole_conforme']) ? 1 : 0,
                ], $anneeExamenDebut, indexEcolesDfa($pdo));
                $success = "Données DESPS enregistrées (saisie manuelle), bilan recalculé.";
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

// Élèves à interroger sur le site : ceux qui n'ont encore AUCUN résultat (à vérifier)
// et l'ensemble de ceux qui ont un matricule (pour tout revérifier).
$sqlIds = "
    SELECT c.id, d.id AS cursus_id
    FROM candidats c LEFT JOIN desps_cursus d ON d.candidat_id = c.id
    WHERE c.annee_id = ? AND c.est_candidat_libre = 0 AND c.ecole_id IS NOT NULL AND TRIM(COALESCE(c.matricule_dsps, '')) <> ''
" . ($ecole ? " AND c.ecole_id = " . (int) $ecole['id'] : "") . " ORDER BY c.nom, c.prenoms";
$stmt = $pdo->prepare($sqlIds);
$stmt->execute([$anneeId]);
$tous = $stmt->fetchAll();
$idsTous = array_map(fn ($r) => (int) $r['id'], $tous);
$idsNonVerifies = array_map(fn ($r) => (int) $r['id'], array_filter($tous, fn ($r) => $r['cursus_id'] === null));

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><i class="bi bi-mortarboard"></i> Bilan DESPS / DFA — <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>
        <?php if ($ecole): ?><small class="text-muted">· <?= htmlspecialchars($ecole['nom']) ?></small><?php endif; ?></h2>
    <div class="d-flex gap-2 flex-wrap">
        <?php if ($ecole): ?><a href="bilan_desps.php" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Toutes les écoles</a><?php endif; ?>
        <a href="https://agcp.sigfne.net/edit/fiches-primaire/" target="_blank" rel="noopener" class="btn btn-outline-secondary" title="Ouvrir la page officielle de la fiche cursus"><i class="bi bi-box-arrow-up-right"></i> Site AGCP</a>
        <button type="button" class="btn btn-success" id="btnVerifierNouveaux" <?= ($anneeLectureSeule || !$idsNonVerifies) ? 'disabled' : '' ?>>
            <i class="bi bi-cloud-check"></i> Vérifier sur le site (<?= count($idsNonVerifies) ?> non vérifié<?= count($idsNonVerifies) > 1 ? 's' : '' ?>)
        </button>
        <button type="button" class="btn btn-outline-success" id="btnVerifierTous" <?= ($anneeLectureSeule || !$idsTous) ? 'disabled' : '' ?> title="Interroge à nouveau le site pour tous les élèves ayant un matricule">
            <i class="bi bi-arrow-clockwise"></i> Tout revérifier (<?= count($idsTous) ?>)
        </button>
        <form method="POST" class="d-inline"><button name="recalculer" class="btn btn-outline-primary" <?= $anneeLectureSeule ? 'disabled' : '' ?> title="Recalcule le bilan à partir des données déjà enregistrées (sans interroger le site)"><i class="bi bi-calculator"></i> Recalculer</button></form>
    </div>
</div>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- Progression de la vérification -->
<div class="card shadow-sm mb-3 d-none" id="panneauVerif">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <strong id="verifTitre">Vérification sur le site AGCP…</strong>
            <button type="button" class="btn btn-sm btn-outline-danger" id="btnArreter">Arrêter</button>
        </div>
        <div class="progress mb-2" style="height: 20px;"><div class="progress-bar progress-bar-striped progress-bar-animated" id="barreVerif" style="width:0%">0%</div></div>
        <div class="small" id="compteursVerif"></div>
        <div class="small text-danger" id="messageVerif"></div>
        <div class="small text-muted">Les élèves sont interrogés un par un, avec une courte pause entre chaque demande, pour ne pas surcharger le site. Garde cette page ouverte jusqu'à la fin ; tu peux arrêter et reprendre plus tard (les élèves déjà vérifiés sont conservés).</div>
    </div>
</div>

<?php if (!$ecole): ?>
    <?php $recap = recapBilanParEcole($pdo, (int) $anneeId); ?>
    <div class="alert alert-light border small">
        Pour chaque élève, l'application interroge la <strong>fiche cursus primaire</strong> du site AGCP / DSPS-MEN à partir de son matricule, prend la
        <strong>dernière ligne</strong> (école, classe, année) et la compare à ce qui est <strong>déclaré</strong> : <span class="badge bg-success">OK</span>
        <span class="badge bg-warning text-dark">DFA à remonter</span> <span class="badge bg-primary">changement d'école</span> ou un cas à traiter à la main.
        Elle contrôle aussi que le nom, les prénoms et la date de naissance du site correspondent à l'élève déclaré (matricule saisi avec une erreur).
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
    $nbSansMatricule = count(array_filter($eleves, fn ($e) => trim((string) $e['matricule_dsps']) === ''));
    ?>
    <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
        <?php foreach ($badges as $code => [$lib, $classe]): if (!$compte[$code]) continue; ?>
            <span class="badge <?= $classe ?> fs-6"><?= $lib ?> : <?= $compte[$code] ?></span>
        <?php endforeach; ?>
        <span class="text-muted small"><?= count($eleves) ?> élève(s)<?= $nbSansMatricule ? ' · ' . $nbSansMatricule . ' sans matricule' : '' ?></span>
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
                <thead class="table-light"><tr><th>Élève</th><th>Matricule</th><th>Site AGCP (dernière ligne)</th><th>Statut</th><th>Lignes DFA calculées</th><th>Avertissements</th><th></th></tr></thead>
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
                                <?php elseif ($e['introuvable']): ?><span class="text-danger">introuvable sur le site</span>
                                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                <?php if ($e['cursus_id']): ?>
                                    <div class="text-muted" style="font-size:.8em;">
                                        <?= $e['source'] === 'site' ? '<i class="bi bi-cloud-check text-success"></i> vérifié sur le site' : '<i class="bi bi-pencil"></i> saisie manuelle' ?>
                                        <?= $e['verifie_le'] ? ' le ' . date('d/m/Y H:i', strtotime($e['verifie_le'])) : '' ?>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($e['cursus'])): ?>
                                    <details class="mt-1"><summary style="cursor:pointer; font-size:.85em;">cursus complet</summary>
                                        <table class="table table-sm mb-0" style="font-size:.8em;">
                                            <?php foreach ($e['cursus'] as $c): ?>
                                                <tr class="<?= ($c['ecole'] === '' && $c['niveau'] === '') ? 'text-muted' : '' ?>"><td><?= htmlspecialchars($c['annee']) ?></td><td><?= htmlspecialchars($c['ecole']) ?></td><td><?= htmlspecialchars($c['niveau']) ?></td><td><?= htmlspecialchars($c['dfa']) ?></td><td><?= htmlspecialchars($c['ce_sn']) ?></td></tr>
                                            <?php endforeach; ?>
                                        </table>
                                    </details>
                                <?php endif; ?>
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
                            <td class="text-end text-nowrap">
                                <?php if (trim((string) $e['matricule_dsps']) !== ''): ?>
                                    <button class="btn btn-sm btn-outline-success btn-verifier-un" data-id="<?= (int) $e['id'] ?>" <?= $anneeLectureSeule ? 'disabled' : '' ?> title="Interroger le site pour cet élève"><i class="bi bi-cloud-arrow-down"></i></button>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-secondary btn-edit-desps" data-bs-toggle="modal" data-bs-target="#modalDesps"
                                    data-id="<?= $e['id'] ?>" data-nom="<?= htmlspecialchars($e['nom'] . ' ' . $e['prenoms']) ?>"
                                    data-ecole="<?= htmlspecialchars((string) $e['ecole_desps']) ?>" data-classe="<?= htmlspecialchars((string) $e['classe_desps']) ?>"
                                    data-annee="<?= $e['annee_debut'] ? $e['annee_debut'] . '-' . ($e['annee_debut'] + 1) : '' ?>"
                                    data-introuvable="<?= (int) $e['introuvable'] ?>" data-conforme="<?= (int) $e['ecole_conforme'] ?>" title="Saisir / corriger à la main (si le site est indisponible, ou nom d'école différent)"><i class="bi bi-pencil"></i></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<!-- Modal saisie manuelle -->
<div class="modal fade" id="modalDesps" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <input type="hidden" name="candidat_id" id="despsCandidat">
            <div class="modal-header"><h5 class="modal-title">Données du site — <span id="despsNom"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Normalement renseigné automatiquement par « Vérifier sur le site ». Ne saisis à la main que si le site est indisponible ou pour confirmer qu'un nom d'école est bien la même école.</p>
                <div class="mb-2"><label class="form-label small">École trouvée sur le site</label><input type="text" name="ecole_desps" id="despsEcole" class="form-control"></div>
                <div class="row g-2 mb-2">
                    <div class="col-6"><label class="form-label small">Classe</label><input type="text" name="classe_desps" id="despsClasse" class="form-control" placeholder="CE2"></div>
                    <div class="col-6"><label class="form-label small">Année scolaire</label><input type="text" name="annee_desps" id="despsAnnee" class="form-control" placeholder="2024-2025"></div>
                </div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="introuvable" id="despsIntrouvable"><label class="form-check-label" for="despsIntrouvable">Matricule introuvable sur le site</label></div>
                <div class="form-check"><input type="checkbox" class="form-check-input" name="ecole_conforme" id="despsConforme"><label class="form-check-label" for="despsConforme">C'est bien la même école (nom écrit différemment sur le site)</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="modifier_cursus" class="btn btn-primary" <?= $anneeLectureSeule ? 'disabled' : '' ?>>Enregistrer</button></div>
        </form>
    </div>
</div>

<script>
// ---- Saisie manuelle
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

// ---- Vérification sur le site : un élève à la fois, avec une pause
var idsNonVerifies = <?= json_encode($idsNonVerifies) ?>;
var idsTous = <?= json_encode($idsTous) ?>;
var enCours = false, arretDemande = false;

function pause(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

async function verifier(ids, titre) {
    if (enCours || !ids.length) return;
    enCours = true; arretDemande = false;
    var panneau = document.getElementById('panneauVerif');
    panneau.classList.remove('d-none');
    document.getElementById('verifTitre').textContent = titre;
    var barre = document.getElementById('barreVerif'), compteurs = document.getElementById('compteursVerif'), msg = document.getElementById('messageVerif');
    msg.textContent = '';
    var c = { ok: 0, introuvable: 0, erreur: 0 }, erreursDeSuite = 0, faits = 0, arretPourPanne = false;

    for (var i = 0; i < ids.length && !arretDemande; i++) {
        var etat = 'erreur', detail = '';
        try {
            var rep = await fetch('api_verifier_desps.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'candidat_id=' + ids[i] });
            var j = await rep.json();
            etat = j.etat; detail = j.message || '';
        } catch (e) { detail = 'Connexion impossible'; }

        if (etat === 'ok') { c.ok++; erreursDeSuite = 0; }
        else if (etat === 'introuvable') { c.introuvable++; erreursDeSuite = 0; }
        else if (etat === 'sans_matricule') { erreursDeSuite = 0; }
        else { c.erreur++; erreursDeSuite++; msg.textContent = 'Dernière erreur : ' + detail; }
        faits++;
        var pct = Math.round(faits * 100 / ids.length);
        barre.style.width = pct + '%'; barre.textContent = faits + ' / ' + ids.length;
        compteurs.textContent = c.ok + ' vérifié(s) · ' + c.introuvable + ' matricule(s) introuvable(s) · ' + c.erreur + ' erreur(s)';

        if (erreursDeSuite >= 3) { arretPourPanne = true; msg.textContent = 'Le site ne répond pas correctement (3 erreurs de suite) : arrêt. Réessaie dans quelques minutes. ' + detail; break; }
        await pause(400);
    }
    enCours = false;
    barre.classList.remove('progress-bar-animated');
    if (!arretPourPanne) { document.getElementById('verifTitre').textContent = arretDemande ? 'Vérification arrêtée' : 'Vérification terminée'; }
    setTimeout(function () { window.location.reload(); }, arretPourPanne ? 4000 : 1500);
}

document.getElementById('btnArreter').addEventListener('click', function () { arretDemande = true; });
var bn = document.getElementById('btnVerifierNouveaux'), bt = document.getElementById('btnVerifierTous');
if (bn) bn.addEventListener('click', function () { verifier(idsNonVerifies, 'Vérification des élèves non vérifiés…'); });
if (bt) bt.addEventListener('click', function () { if (confirm('Interroger à nouveau le site pour ' + idsTous.length + ' élève(s) ? Cela peut prendre plusieurs minutes.')) verifier(idsTous, 'Nouvelle vérification de tous les élèves…'); });
document.querySelectorAll('.btn-verifier-un').forEach(function (b) {
    b.addEventListener('click', function () { verifier([parseInt(b.dataset.id, 10)], 'Vérification de l\'élève…'); });
});
</script>

<?php include '../views/layouts/footer.php'; ?>
