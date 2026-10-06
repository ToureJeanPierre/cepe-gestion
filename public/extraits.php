<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/extraits_helpers.php';

$pageTitle = 'Extraits de naissance';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$cfg = extraitsConfig();
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll();

// ==========================================
// FILTRES
// ==========================================
$ecoleId = (int) ($_GET['ecole_id'] ?? 0);
$statut = $_GET['statut'] ?? '';
$statutsValides = ['a_lire', 'lu', 'valide', 'erreur'];
if (!in_array($statut, $statutsValides, true)) {
    $statut = '';
}
$parPage = 40;
$page = max(1, (int) ($_GET['page'] ?? 1));

$whereScope = "x.annee_id = ?" . ($ecoleId ? " AND x.ecole_id = " . $ecoleId : "");
$stmt = $pdo->prepare("SELECT x.statut, COUNT(*) AS n FROM extraits_naissance x WHERE $whereScope GROUP BY x.statut");
$stmt->execute([$anneeId]);
$compte = array_fill_keys($statutsValides, 0);
foreach ($stmt->fetchAll() as $r) {
    $compte[$r['statut']] = (int) $r['n'];
}
$total = array_sum($compte);

$where = $whereScope . ($statut ? " AND x.statut = " . $pdo->quote($statut) : "");
$stmt = $pdo->prepare("SELECT COUNT(*) FROM extraits_naissance x WHERE $where");
$stmt->execute([$anneeId]);
$nbLignes = (int) $stmt->fetchColumn();
$nbPages = max(1, (int) ceil($nbLignes / $parPage));
$page = min($page, $nbPages);

$stmt = $pdo->prepare("
    SELECT x.id, x.ecole_id, x.candidat_id, x.nom_original, x.extension, x.statut, x.nom, x.prenoms, x.sexe, x.date_naissance,
           x.lieu_naissance, x.sous_prefecture, x.pere, x.mere, x.contact, x.numero_acte, x.date_acte, x.lieu_acte, x.nationalite,
           x.confiance, x.alertes, x.erreur_message, x.modele, x.cout_usd, x.nb_lectures,
           e.nom AS ecole_nom, c.nom AS cand_nom, c.prenoms AS cand_prenoms
    FROM extraits_naissance x
    LEFT JOIN ecoles e ON e.id = x.ecole_id
    LEFT JOIN candidats c ON c.id = x.candidat_id
    WHERE $where
    ORDER BY x.ecole_id, x.id
    LIMIT $parPage OFFSET " . (($page - 1) * $parPage));
$stmt->execute([$anneeId]);
$lignes = $stmt->fetchAll();
$rangs = rangsExtraitsParEcole($pdo, (int) $anneeId);

// Extraits à lire (tout le périmètre filtré par école, pas seulement la page affichée).
$stmt = $pdo->prepare("SELECT x.id FROM extraits_naissance x WHERE $whereScope AND x.statut = ? ORDER BY x.id");
$stmt->execute([$anneeId, 'a_lire']);
$idsALire = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
$stmt->execute([$anneeId, 'erreur']);
$idsErreur = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

$depense = extraitsDepenseUsd($pdo);
$plafond = extraitsPlafondUsd($pdo);
$fcfa = (float) $cfg['fcfa_par_dollar'];
$coutMoyen = 0.005; // ≈ 0,5 cent par extrait avec le modèle économique (mesuré : ~2300 jetons d'image + ~350 en sortie)
$nbLus = (int) $pdo->query("SELECT COUNT(*) FROM extraits_naissance WHERE nb_lectures > 0")->fetchColumn();
if ($nbLus >= 5) {
    $coutMoyen = max(0.0005, $depense / $nbLus); // dès qu'on a de la vraie donnée, on utilise le coût réel moyen
}

$badgesStatut = [
    'a_lire' => ['À lire', 'bg-secondary'],
    'lu' => ['À valider', 'bg-warning text-dark'],
    'valide' => ['Validé', 'bg-success'],
    'erreur' => ['Erreur', 'bg-danger'],
];
$badgesConfiance = ['haute' => 'bg-success', 'moyenne' => 'bg-warning text-dark', 'basse' => 'bg-danger'];

$lienExtraits = function (array $surcharge = []) use ($ecoleId, $statut) {
    $p = array_filter(array_merge(['ecole_id' => $ecoleId ?: null, 'statut' => $statut ?: null], $surcharge), fn ($v) => $v !== null && $v !== '');
    return 'extraits.php' . ($p ? '?' . http_build_query($p) : '');
};

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2><i class="bi bi-person-vcard"></i> Extraits de naissance — <?= htmlspecialchars($ANNEE_SCOLAIRE) ?></h2>
    <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-success" id="btnLire" <?= ($anneeLectureSeule || !$idsALire || !$cfg['cle_presente']) ? 'disabled' : '' ?>>
            <i class="bi bi-magic"></i> Lire les extraits en attente (<?= count($idsALire) ?>)
        </button>
        <?php if ($idsErreur): ?>
            <button type="button" class="btn btn-outline-danger" id="btnRelancer" <?= ($anneeLectureSeule || !$cfg['cle_presente']) ? 'disabled' : '' ?>><i class="bi bi-arrow-repeat"></i> Relancer les erreurs (<?= count($idsErreur) ?>)</button>
        <?php endif; ?>
        <div class="btn-group">
            <button type="button" class="btn btn-outline-primary dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-download"></i> Exporter</button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php $exp = $ecoleId ? '&ecole_id=' . $ecoleId : ''; ?>
                <li><a class="dropdown-item" href="export_extraits.php?format=xlsx<?= $exp ?>"><i class="bi bi-file-earmark-excel me-2"></i>Excel — extraits validés</a></li>
                <li><a class="dropdown-item" href="export_extraits.php?format=zip<?= $exp ?>"><i class="bi bi-file-zip me-2"></i>ZIP des fichiers renommés — validés</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="export_extraits.php?format=xlsx&inclure=tous<?= $exp ?>"><i class="bi bi-file-earmark-excel me-2"></i>Excel — y compris non validés</a></li>
                <li><a class="dropdown-item" href="export_extraits.php?format=zip&inclure=tous<?= $exp ?>"><i class="bi bi-file-zip me-2"></i>ZIP — y compris non validés</a></li>
            </ul>
        </div>
    </div>
</div>

<?php if (!$cfg['cle_presente']): ?>
    <div class="alert alert-warning">
        <strong><i class="bi bi-key"></i> Lecture automatique non activée.</strong> Il manque la clé Anthropic : copier
        <code>config/anthropic.example.php</code> en <code>config/anthropic.php</code> et y coller la clé (créée sur console.anthropic.com).
        En attendant, tu peux déjà envoyer les extraits et les saisir / renommer à la main (bouton <i class="bi bi-pencil"></i>).
    </div>
<?php endif; ?>

<!-- Compteurs -->
<div class="row g-2 mb-3">
    <?php foreach ([['', 'Tous', $total, 'bg-dark'], ['a_lire', 'À lire', $compte['a_lire'], 'bg-secondary'], ['lu', 'À valider', $compte['lu'], 'bg-warning text-dark'], ['valide', 'Validés', $compte['valide'], 'bg-success'], ['erreur', 'Erreurs', $compte['erreur'], 'bg-danger']] as [$code, $lib, $n, $classe]): ?>
        <div class="col-6 col-md">
            <a href="<?= $lienExtraits(['statut' => $code ?: null, 'page' => null]) ?>" class="text-decoration-none">
                <div class="card shadow-sm <?= $statut === $code ? 'border-primary border-2' : '' ?>"><div class="card-body py-2 text-center">
                    <div class="fs-4 fw-bold"><?= $n ?></div><span class="badge <?= $classe ?>"><?= $lib ?></span>
                </div></div>
            </a>
        </div>
    <?php endforeach; ?>
    <div class="col-12 col-md-4">
        <div class="card shadow-sm h-100"><div class="card-body py-2">
            <div class="d-flex justify-content-between small">
                <span><i class="bi bi-cash-coin"></i> Dépensé</span>
                <strong id="depenseAffichee"><?= number_format($depense, 3, ',', ' ') ?> $</strong>
            </div>
            <div class="progress my-1" style="height: 6px;"><div class="progress-bar <?= $depense >= $plafond ? 'bg-danger' : 'bg-success' ?>" id="barreDepense" style="width: <?= $plafond > 0 ? min(100, round($depense * 100 / $plafond)) : 100 ?>%"></div></div>
            <form class="d-flex align-items-center gap-1 small" id="formPlafond">
                <label class="mb-0" for="plafondInput">Plafond</label>
                <input type="number" step="0.5" min="0" class="form-control form-control-sm" style="width: 80px;" id="plafondInput" value="<?= htmlspecialchars((string) $plafond) ?>" <?= $anneeLectureSeule ? 'disabled' : '' ?>> $
                <button class="btn btn-sm btn-outline-secondary" <?= $anneeLectureSeule ? 'disabled' : '' ?>>OK</button>
            </form>
            <div class="text-muted" style="font-size: .75em;">≈ <?= number_format($depense * $fcfa, 0, ',', ' ') ?> FCFA · ~<?= number_format($coutMoyen * 100, 2, ',', '') ?> ¢ / extrait</div>
        </div></div>
    </div>
</div>

<!-- Envoi -->
<div class="card shadow-sm mb-3">
    <div class="card-body">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">École concernée par ces extraits</label>
                <select class="form-select" id="ecoleEnvoi">
                    <option value="">— pas d'école (à préciser plus tard) —</option>
                    <?php foreach ($ecoles as $e): ?><option value="<?= $e['id'] ?>" <?= $ecoleId === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-8">
                <div id="zoneDepot" class="border border-2 rounded p-3 text-center text-muted" style="border-style: dashed !important; cursor: pointer;">
                    <i class="bi bi-cloud-arrow-up fs-3"></i><br>
                    Glisser ici les photos / PDF d'extraits, ou
                    <button type="button" class="btn btn-sm btn-primary" id="btnChoisirFichiers" <?= $anneeLectureSeule ? 'disabled' : '' ?>>choisir des fichiers</button>
                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnChoisirDossier" <?= $anneeLectureSeule ? 'disabled' : '' ?>>choisir un dossier</button>
                    <input type="file" id="inputFichiers" accept="image/jpeg,image/png,image/webp,application/pdf,.jpg,.jpeg,.png,.webp,.pdf" multiple hidden>
                    <input type="file" id="inputDossier" webkitdirectory hidden>
                    <div class="small mt-1">JPG, PNG, WebP ou PDF. Un fichier déjà reçu (même contenu) est ignoré : il ne sera jamais payé deux fois. Envoyer ne coûte rien : la lecture se lance ensuite, sur confirmation.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Progression (envoi / lecture) -->
<div class="card shadow-sm mb-3 d-none" id="panneau">
    <div class="card-body">
        <div class="d-flex justify-content-between mb-1">
            <strong id="panneauTitre">…</strong>
            <button type="button" class="btn btn-sm btn-outline-danger" id="btnArreter">Arrêter</button>
        </div>
        <div class="progress mb-2" style="height: 20px;"><div class="progress-bar progress-bar-striped progress-bar-animated" id="panneauBarre" style="width:0%">0%</div></div>
        <div class="small" id="panneauCompteurs"></div>
        <div class="small text-danger" id="panneauMessage"></div>
        <div class="small text-muted">Garde cette page ouverte jusqu'à la fin ; tu peux arrêter et reprendre plus tard : ce qui est déjà fait est conservé.</div>
    </div>
</div>

<!-- Filtre école -->
<form method="GET" class="d-flex gap-2 align-items-center mb-2 flex-wrap">
    <?php if ($statut): ?><input type="hidden" name="statut" value="<?= htmlspecialchars($statut) ?>"><?php endif; ?>
    <select name="ecole_id" class="form-select form-select-sm" style="max-width: 320px;" onchange="this.form.submit()">
        <option value="">Toutes les écoles</option>
        <?php foreach ($ecoles as $e): ?><option value="<?= $e['id'] ?>" <?= $ecoleId === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option><?php endforeach; ?>
    </select>
    <span class="text-muted small"><?= $nbLignes ?> extrait(s)</span>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0 table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead class="table-light"><tr>
                <th>Aperçu</th><th>Nom lu sur l'extrait</th><th>Naissance</th><th>École</th><th>Candidat rattaché</th><th>Statut</th><th>Fichier renommé</th><th></th>
            </tr></thead>
            <tbody>
                <?php if (!$lignes): ?><tr><td colspan="8" class="text-center text-muted py-4">Aucun extrait<?= ($statut || $ecoleId) ? ' pour ce filtre' : ' pour le moment : commence par en envoyer ci-dessus' ?>.</td></tr><?php endif; ?>
                <?php foreach ($lignes as $l): ?>
                    <?php
                    [$libStatut, $classeStatut] = $badgesStatut[$l['statut']];
                    $nomFinal = ($l['nom'] || $l['prenoms']) ? nomFichierExtrait($l['nom'], $l['prenoms'], $l['extension'], $rangs[(int) $l['id']] ?? null) : null;
                    $donnees = $l + ['candidat_libelle' => $l['candidat_id'] ? trim($l['cand_nom'] . ' ' . $l['cand_prenoms']) : ''];
                    ?>
                    <tr data-id="<?= (int) $l['id'] ?>" data-statut="<?= $l['statut'] ?>">
                        <td style="width: 70px;">
                            <?php if ($l['extension'] === 'pdf'): ?>
                                <a href="api_extraits.php?action=fichier&id=<?= (int) $l['id'] ?>" target="_blank" class="fs-2 text-danger"><i class="bi bi-file-earmark-pdf"></i></a>
                            <?php else: ?>
                                <img src="api_extraits.php?action=fichier&id=<?= (int) $l['id'] ?>" loading="lazy" alt="" class="rounded border btn-ouvrir" style="width: 56px; height: 56px; object-fit: cover; cursor: zoom-in;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($l['nom'] || $l['prenoms']): ?>
                                <strong><?= htmlspecialchars((string) $l['nom']) ?></strong> <?= htmlspecialchars((string) $l['prenoms']) ?>
                                <?php if ($l['confiance']): ?><span class="badge <?= $badgesConfiance[$l['confiance']] ?>" title="Confiance de la lecture"><?= $l['confiance'] ?></span><?php endif; ?>
                            <?php else: ?><span class="text-muted small"><?= htmlspecialchars($l['nom_original']) ?></span><?php endif; ?>
                            <?php if ($l['alertes']): ?><div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($l['alertes']) ?></div><?php endif; ?>
                            <?php if ($l['statut'] === 'erreur' && $l['erreur_message']): ?><div class="small text-danger"><?= htmlspecialchars($l['erreur_message']) ?></div><?php endif; ?>
                        </td>
                        <td class="small"><?= $l['date_naissance'] ? date('d/m/Y', strtotime($l['date_naissance'])) : '<span class="text-muted">—</span>' ?><div class="text-muted"><?= htmlspecialchars((string) $l['lieu_naissance']) ?></div></td>
                        <td class="small"><?= htmlspecialchars((string) $l['ecole_nom']) ?: '<span class="text-muted">—</span>' ?></td>
                        <td class="small"><?= $l['candidat_id'] ? htmlspecialchars($donnees['candidat_libelle']) : '<span class="text-muted">non rattaché</span>' ?></td>
                        <td><span class="badge <?= $classeStatut ?>"><?= $libStatut ?></span></td>
                        <td class="small"><?= $nomFinal ? '<code>' . htmlspecialchars($nomFinal) . '</code>' : '<span class="text-muted">—</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($l['statut'] !== 'valide' && $cfg['cle_presente'] && !$anneeLectureSeule): ?>
                                <button class="btn btn-sm btn-outline-success btn-lire-un" data-id="<?= (int) $l['id'] ?>" title="<?= $l['statut'] === 'a_lire' ? 'Lire cet extrait' : 'Relire (payant à nouveau)' ?>"><i class="bi bi-magic"></i></button>
                            <?php endif; ?>
                            <button class="btn btn-sm btn-outline-secondary btn-ouvrir" title="Voir, corriger, valider"><i class="bi bi-pencil"></i></button>
                            <script type="application/json" class="donnees-ligne"><?= json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($nbPages > 1): ?>
        <div class="card-footer d-flex justify-content-between align-items-center">
            <span class="small text-muted">Page <?= $page ?> / <?= $nbPages ?></span>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-secondary <?= $page <= 1 ? 'disabled' : '' ?>" href="<?= $lienExtraits(['page' => $page - 1]) ?>">&laquo; Précédent</a>
                <a class="btn btn-outline-secondary <?= $page >= $nbPages ? 'disabled' : '' ?>" href="<?= $lienExtraits(['page' => $page + 1]) ?>">Suivant &raquo;</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- Fenêtre : vérifier / corriger / valider -->
<div class="modal fade" id="modalExtrait" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Extrait n° <span id="mId"></span> <span class="badge" id="mStatut"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div id="mApercu" class="border rounded bg-light text-center" style="min-height: 300px;"></div>
                        <div class="small text-muted mt-1">Fichier d'origine : <span id="mNomOriginal"></span> · <span id="mMeta"></span></div>
                    </div>
                    <div class="col-lg-6">
                        <div class="alert alert-warning py-2 small d-none" id="mAlertes"></div>
                        <div class="alert alert-danger py-2 small d-none" id="mErreur"></div>
                        <div class="alert alert-danger py-2 small d-none" id="mMessage"></div>
                        <div class="row g-2">
                            <div class="col-6"><label class="form-label small mb-0">Nom</label><input class="form-control form-control-sm text-uppercase" id="fNom"></div>
                            <div class="col-6"><label class="form-label small mb-0">Prénoms</label><input class="form-control form-control-sm text-uppercase" id="fPrenoms"></div>
                            <div class="col-4"><label class="form-label small mb-0">Sexe</label><select class="form-select form-select-sm" id="fSexe"><option value="">?</option><option value="M">M</option><option value="F">F</option></select></div>
                            <div class="col-4"><label class="form-label small mb-0">Date de naissance</label><input type="date" class="form-control form-control-sm" id="fDateNaissance"></div>
                            <div class="col-4"><label class="form-label small mb-0">Lieu de naissance</label><input class="form-control form-control-sm" id="fLieuNaissance"></div>
                            <div class="col-6"><label class="form-label small mb-0">Père</label><input class="form-control form-control-sm" id="fPere"></div>
                            <div class="col-6"><label class="form-label small mb-0">Mère</label><input class="form-control form-control-sm" id="fMere"></div>
                            <div class="col-4"><label class="form-label small mb-0">N° de l'acte</label><input class="form-control form-control-sm" id="fNumeroActe"></div>
                            <div class="col-4"><label class="form-label small mb-0">Date de l'acte</label><input type="date" class="form-control form-control-sm" id="fDateActe"></div>
                            <div class="col-4"><label class="form-label small mb-0">Lieu de l'acte</label><input class="form-control form-control-sm" id="fLieuActe"></div>
                            <div class="col-4"><label class="form-label small mb-0">Sous-préfecture</label><input class="form-control form-control-sm" id="fSousPrefecture"></div>
                            <div class="col-4"><label class="form-label small mb-0">Nationalité</label><input class="form-control form-control-sm" id="fNationalite"></div>
                            <div class="col-4"><label class="form-label small mb-0">Contact</label><input class="form-control form-control-sm" id="fContact"></div>
                            <div class="col-12">
                                <label class="form-label small mb-0">École</label>
                                <select class="form-select form-select-sm" id="fEcole"><option value="">—</option>
                                    <?php foreach ($ecoles as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option><?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small mb-0">Candidat rattaché</label>
                                <div class="d-flex gap-1">
                                    <input class="form-control form-control-sm" id="fCandidatLibelle" readonly placeholder="aucun">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="btnDetacher" title="Détacher">&times;</button>
                                </div>
                                <input type="hidden" id="fCandidatId">
                                <div class="small mt-1" id="mSuggestions"></div>
                                <input class="form-control form-control-sm mt-1" id="fChercher" placeholder="Chercher un autre candidat par nom…" autocomplete="off">
                                <div class="list-group list-group-flush small" id="mResultats"></div>
                            </div>
                        </div>
                        <div class="mt-2 p-2 bg-light rounded small">Nom du fichier renommé : <code id="mNomFinal">—</code></div>
                    </div>
                </div>
            </div>
            <div class="modal-footer justify-content-between">
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-outline-danger btn-sm" id="btnRetirer" <?= $anneeLectureSeule ? 'disabled' : '' ?>><i class="bi bi-trash"></i> Retirer de la liste</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnFort" <?= (!$cfg['cle_presente'] || $anneeLectureSeule || trim((string) $cfg['modele_secours']) === '') ? 'disabled' : '' ?> title="Relit avec le modèle le plus fort (coûte un peu plus)"><i class="bi bi-eyeglasses"></i> Relire avec le modèle fort</button>
                </div>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Fermer</button>
                    <button type="button" class="btn btn-outline-primary" id="btnEnregistrer" <?= $anneeLectureSeule ? 'disabled' : '' ?>>Enregistrer</button>
                    <button type="button" class="btn btn-success" id="btnValider" <?= $anneeLectureSeule ? 'disabled' : '' ?>><i class="bi bi-check-lg"></i> Valider et suivant</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var IDS_A_LIRE = <?= json_encode($idsALire) ?>;
var IDS_ERREUR = <?= json_encode($idsErreur) ?>;
var COUT_MOYEN = <?= json_encode($coutMoyen) ?>;
var FCFA = <?= json_encode($fcfa) ?>;
var PLAFOND = <?= json_encode($plafond) ?>;
var DEPENSE = <?= json_encode($depense) ?>;
var enCours = false, arretDemande = false, aChange = false;

function pause(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }
function el(id) { return document.getElementById(id); }
function post(action, data) {
    var corps = data instanceof FormData ? data : new URLSearchParams(data);
    corps.append('action', action);
    return fetch('api_extraits.php', { method: 'POST', body: corps }).then(function (r) { return r.json(); });
}
function majDepense(d) {
    if (typeof d !== 'number') return;
    DEPENSE = d;
    el('depenseAffichee').textContent = d.toFixed(3).replace('.', ',') + ' $';
    el('barreDepense').style.width = (PLAFOND > 0 ? Math.min(100, Math.round(d * 100 / PLAFOND)) : 100) + '%';
}

// ---------- Panneau de progression commun
function ouvrirPanneau(titre) {
    el('panneau').classList.remove('d-none');
    el('panneauTitre').textContent = titre;
    el('panneauMessage').textContent = '';
    el('panneauCompteurs').textContent = '';
    el('panneauBarre').style.width = '0%'; el('panneauBarre').textContent = '0%';
    el('panneauBarre').classList.add('progress-bar-animated');
}
function avancer(faits, total) {
    var b = el('panneauBarre');
    b.style.width = Math.round(faits * 100 / total) + '%'; b.textContent = faits + ' / ' + total;
}
function terminer(titre, delai) {
    enCours = false;
    el('panneauBarre').classList.remove('progress-bar-animated');
    el('panneauTitre').textContent = titre;
    setTimeout(function () { window.location.reload(); }, delai);
}
el('btnArreter').addEventListener('click', function () { arretDemande = true; });

// ---------- Envoi des fichiers (un par un)
var EXT_OK = /\.(jpe?g|png|webp|pdf)$/i;
async function envoyer(fichiers) {
    fichiers = Array.prototype.filter.call(fichiers, function (f) { return EXT_OK.test(f.name); });
    if (enCours || !fichiers.length) { if (!fichiers.length) alert('Aucun fichier JPG, PNG, WebP ou PDF dans la sélection.'); return; }
    enCours = true; arretDemande = false;
    ouvrirPanneau('Envoi de ' + fichiers.length + ' fichier(s)…');
    var c = { ajoute: 0, doublon: 0, refuse: 0 }, refus = [];
    for (var i = 0; i < fichiers.length && !arretDemande; i++) {
        var fd = new FormData();
        fd.append('fichier', fichiers[i]); fd.append('ecole_id', el('ecoleEnvoi').value);
        try {
            var j = await post('envoyer', fd);
            if (j.etat === 'ajoute') c.ajoute++; else if (j.etat === 'doublon') c.doublon++; else { c.refuse++; refus.push(fichiers[i].name + ' : ' + (j.message || 'refusé')); }
        } catch (e) { c.refuse++; refus.push(fichiers[i].name + ' : connexion interrompue'); }
        avancer(i + 1, fichiers.length);
        el('panneauCompteurs').textContent = c.ajoute + ' ajouté(s) · ' + c.doublon + ' déjà reçu(s) (ignorés) · ' + c.refuse + ' refusé(s)';
        if (refus.length) el('panneauMessage').textContent = refus.slice(-3).join(' — ');
    }
    terminer(arretDemande ? 'Envoi arrêté' : 'Envoi terminé', refus.length ? 5000 : 1200);
}
el('btnChoisirFichiers').addEventListener('click', function () { el('inputFichiers').click(); });
el('btnChoisirDossier').addEventListener('click', function () { el('inputDossier').click(); });
el('inputFichiers').addEventListener('change', function () { envoyer(this.files); this.value = ''; });
el('inputDossier').addEventListener('change', function () { envoyer(this.files); this.value = ''; });
var zone = el('zoneDepot');
['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('bg-light'); }); });
['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('bg-light'); }); });
zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) envoyer(e.dataTransfer.files); });

// ---------- Lecture par Claude (un extrait à la fois, avec compteur de dépense)
async function lire(ids, titre) {
    if (enCours || !ids.length) return;
    var estime = ids.length * COUT_MOYEN;
    var reste = Math.max(0, PLAFOND - DEPENSE);
    var msg = 'Lire ' + ids.length + ' extrait(s) avec Claude ?\n\nCoût estimé : environ ' + estime.toFixed(estime < 1 ? 3 : 2).replace('.', ',') + ' $ (≈ ' + Math.round(estime * FCFA).toLocaleString('fr-FR') + ' FCFA).\n' +
        'Plafond restant : ' + reste.toFixed(2).replace('.', ',') + ' $.' + (estime > reste ? '\n\nATTENTION : le plafond sera atteint avant la fin, la lecture s\'arrêtera toute seule.' : '');
    if (!confirm(msg)) return;
    enCours = true; arretDemande = false;
    ouvrirPanneau(titre);
    var c = { lu: 0, fort: 0, erreur: 0 }, cout = 0, erreursDeSuite = 0, derniere = '';
    for (var i = 0; i < ids.length && !arretDemande; i++) {
        var j;
        try { j = await post('lire', { id: ids[i] }); } catch (e) { j = { etat: 'erreur', message: 'Connexion interrompue', arreter: true }; }
        if (j.etat === 'lu') { c.lu++; if (j.escalade) c.fort++; cout += j.cout || 0; erreursDeSuite = 0; majDepense(j.depense); }
        else { c.erreur++; erreursDeSuite++; derniere = j.message || 'erreur'; el('panneauMessage').textContent = 'Dernière erreur : ' + derniere; majDepense(j.depense); }
        avancer(i + 1, ids.length);
        el('panneauCompteurs').textContent = c.lu + ' lu(s) (dont ' + c.fort + ' relu(s) par le modèle fort) · ' + c.erreur + ' erreur(s) · dépense de ce lot : ' + cout.toFixed(3).replace('.', ',') + ' $';
        if (j.arreter) { el('panneauMessage').textContent = 'Arrêt : ' + derniere; terminer('Lecture interrompue', 6000); return; }
        if (erreursDeSuite >= 5) { terminer('Lecture interrompue (5 erreurs de suite)', 6000); return; }
        await pause(150);
    }
    terminer(arretDemande ? 'Lecture arrêtée' : 'Lecture terminée', 1500);
}
el('btnLire').addEventListener('click', function () { lire(IDS_A_LIRE, 'Lecture des extraits en attente…'); });
if (el('btnRelancer')) el('btnRelancer').addEventListener('click', function () { lire(IDS_ERREUR, 'Nouvelle lecture des extraits en erreur…'); });
document.querySelectorAll('.btn-lire-un').forEach(function (b) {
    b.addEventListener('click', function () { lire([parseInt(b.dataset.id, 10)], 'Lecture de l\'extrait…'); });
});

// ---------- Plafond
el('formPlafond').addEventListener('submit', async function (e) {
    e.preventDefault();
    var j = await post('plafond', { plafond: el('plafondInput').value });
    if (j.etat === 'ok') { PLAFOND = j.plafond; majDepense(DEPENSE); } else { alert(j.message || 'Erreur'); }
});

// ---------- Fenêtre de vérification
var modalObj = null;
// Bootstrap est chargé par le pied de page, après ce script : on crée la fenêtre à la première utilisation.
var modal = { show: function () { (modalObj = modalObj || new bootstrap.Modal(el('modalExtrait'))).show(); }, hide: function () { if (modalObj) modalObj.hide(); } };
var courant = null;
var champs = { nom: 'fNom', prenoms: 'fPrenoms', sexe: 'fSexe', date_naissance: 'fDateNaissance', lieu_naissance: 'fLieuNaissance', pere: 'fPere', mere: 'fMere',
    numero_acte: 'fNumeroActe', date_acte: 'fDateActe', lieu_acte: 'fLieuActe', sous_prefecture: 'fSousPrefecture', nationalite: 'fNationalite', contact: 'fContact', ecole_id: 'fEcole' };
var BADGES = { a_lire: ['À lire', 'bg-secondary'], lu: ['À valider', 'bg-warning text-dark'], valide: ['Validé', 'bg-success'], erreur: ['Erreur', 'bg-danger'] };

function nomFinalApercu() {
    var t = ((el('fNom').value || '') + ' ' + (el('fPrenoms').value || '')).toUpperCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
    el('mNomFinal').textContent = (t || 'SANS_NOM') + '.' + (courant ? courant.extension : '') + '  (le numéro d\'ordre est ajouté à l\'export)';
}
function messageModal(t) { var m = el('mMessage'); m.textContent = t || ''; m.classList.toggle('d-none', !t); }

function ouvrir(tr) {
    courant = JSON.parse(tr.querySelector('.donnees-ligne').textContent);
    el('mId').textContent = courant.id;
    var b = BADGES[courant.statut]; el('mStatut').className = 'badge ' + b[1]; el('mStatut').textContent = b[0];
    el('mNomOriginal').textContent = courant.nom_original;
    el('mMeta').textContent = courant.modele ? 'lu par ' + courant.modele + ' · ' + (courant.cout_usd * 1).toFixed(4) + ' $' : 'pas encore lu';
    var url = 'api_extraits.php?action=fichier&id=' + courant.id;
    el('mApercu').innerHTML = courant.extension === 'pdf'
        ? '<iframe src="' + url + '" style="width:100%;height:520px;border:0;"></iframe>'
        : '<img src="' + url + '" alt="" style="max-width:100%;max-height:520px;cursor:zoom-in;" onclick="window.open(this.src)">';
    Object.keys(champs).forEach(function (k) { el(champs[k]).value = courant[k] == null ? '' : courant[k]; });
    el('fCandidatId').value = courant.candidat_id || '';
    el('fCandidatLibelle').value = courant.candidat_libelle || '';
    el('mAlertes').textContent = courant.alertes || ''; el('mAlertes').classList.toggle('d-none', !courant.alertes);
    el('mErreur').textContent = courant.erreur_message || ''; el('mErreur').classList.toggle('d-none', !(courant.statut === 'erreur' && courant.erreur_message));
    el('btnRetirer').classList.toggle('d-none', courant.statut === 'valide');
    messageModal('');
    el('mSuggestions').textContent = ''; el('mResultats').innerHTML = ''; el('fChercher').value = '';
    nomFinalApercu();
    chargerSuggestions();
    modal.show();
}
document.querySelectorAll('tr[data-id] .btn-ouvrir').forEach(function (b) {
    b.addEventListener('click', function () { ouvrir(b.closest('tr')); });
});
['fNom', 'fPrenoms'].forEach(function (id) { el(id).addEventListener('input', nomFinalApercu); });

function choisirCandidat(id, libelle) { el('fCandidatId').value = id; el('fCandidatLibelle').value = libelle; }
el('btnDetacher').addEventListener('click', function () { choisirCandidat('', ''); });

function chargerSuggestions() {
    var id = courant.id;
    fetch('api_extraits.php?action=candidats&id=' + id).then(function (r) { return r.json(); }).then(function (j) {
        if (!courant || courant.id !== id) return;
        var box = el('mSuggestions'); box.innerHTML = '';
        if (!j.propositions || !j.propositions.length) { box.innerHTML = '<span class="text-muted">Aucun candidat ne correspond clairement à ce nom.</span>'; return; }
        box.appendChild(document.createTextNode('Candidats qui correspondent : '));
        j.propositions.forEach(function (p) {
            var btn = document.createElement('button'); btn.type = 'button';
            btn.className = 'btn btn-sm ' + (p.differences.length ? 'btn-outline-warning' : 'btn-outline-primary') + ' me-1 mb-1';
            btn.textContent = p.nom + ' ' + p.prenoms + (p.ecole ? ' (' + p.ecole + ')' : '');
            btn.title = p.differences.length ? p.differences.join('\n') : 'Nom, prénom et date concordent';
            btn.addEventListener('click', function () { choisirCandidat(p.id, p.nom + ' ' + p.prenoms); });
            box.appendChild(btn);
        });
    });
}
var minuteur = null;
el('fChercher').addEventListener('input', function () {
    clearTimeout(minuteur);
    var q = this.value.trim();
    minuteur = setTimeout(function () {
        if (q.length < 2) { el('mResultats').innerHTML = ''; return; }
        fetch('api_extraits.php?action=chercher&q=' + encodeURIComponent(q)).then(function (r) { return r.json(); }).then(function (j) {
            var liste = el('mResultats'); liste.innerHTML = '';
            (j.candidats || []).forEach(function (c) {
                var a = document.createElement('button'); a.type = 'button'; a.className = 'list-group-item list-group-item-action py-1';
                a.textContent = c.nom + ' ' + c.prenoms + (c.date_naissance ? ' — né(e) le ' + c.date_naissance.split('-').reverse().join('/') : '') + (c.ecole ? ' — ' + c.ecole : '');
                a.addEventListener('click', function () { choisirCandidat(c.id, c.nom + ' ' + c.prenoms); liste.innerHTML = ''; el('fChercher').value = ''; });
                liste.appendChild(a);
            });
            if (!liste.children.length) liste.innerHTML = '<div class="list-group-item py-1 text-muted">Aucun résultat</div>';
        });
    }, 250);
});

function formulaire(valider) {
    var d = { id: courant.id, valider: valider ? 1 : 0, candidat_id: el('fCandidatId').value };
    Object.keys(champs).forEach(function (k) { d[k] = el(champs[k]).value; });
    return d;
}
async function enregistrer(valider) {
    messageModal('');
    var j = await post('enregistrer', formulaire(valider));
    if (j.etat !== 'ok') { messageModal(j.message || 'Erreur'); return false; }
    aChange = true;
    return true;
}
el('btnEnregistrer').addEventListener('click', async function () { if (await enregistrer(false)) { modal.hide(); } });
el('btnValider').addEventListener('click', async function () {
    if (!(await enregistrer(true))) return;
    // passe directement à l'extrait suivant à valider
    var lignes = Array.prototype.slice.call(document.querySelectorAll('tr[data-id]'));
    var idx = lignes.findIndex(function (tr) { return parseInt(tr.dataset.id, 10) === courant.id; });
    var suivant = lignes.slice(idx + 1).find(function (tr) { return tr.dataset.statut === 'lu'; });
    var courantId = courant.id;
    var tr = lignes[idx]; if (tr) tr.dataset.statut = 'valide';
    if (suivant) { ouvrir(suivant); } else { modal.hide(); }
});
el('btnFort').addEventListener('click', async function () {
    if (!confirm('Relire cet extrait avec le modèle le plus fort ? Cela coûte un peu plus (environ 1 cent).')) return;
    messageModal('Lecture en cours…');
    var j = await post('lire', { id: courant.id, fort: 1 });
    if (j.etat === 'lu') { aChange = true; window.location.reload(); } else { messageModal(j.message || 'Erreur'); }
});
el('btnRetirer').addEventListener('click', async function () {
    if (!confirm('Retirer cet extrait de la liste ? (Le fichier d\'origine, dans tes dossiers, n\'est pas touché.)')) return;
    var j = await post('retirer', { id: courant.id });
    if (j.etat === 'ok') { window.location.reload(); } else { messageModal(j.message || 'Erreur'); }
});
el('modalExtrait').addEventListener('hidden.bs.modal', function () { if (aChange) window.location.reload(); });
</script>

<?php include '../views/layouts/footer.php'; ?>
