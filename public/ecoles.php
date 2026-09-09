<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Gestion des Écoles';

require_once __DIR__ . '/../src/groupe_scolaire_helpers.php';

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_ecoles'])) {
    if (isset($_FILES['fichier_ecoles']) && $_FILES['fichier_ecoles']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier_ecoles']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); // Sauter en-tête

            $nbAjouts = 0; $nbMajs = 0; $erreurs = [];

            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    // Ordre: A:Nom, B:Statut, C:Code, D:Tuteur, E:TypeRatt, F:Groupe, G:Directeur, H:Tel, I:Effectif, J:Centre
                    $nom = trim($row[0] ?? '');
                    if (empty($nom)) continue;

                    $statut = (stripos($row[1] ?? '', 'priv') !== false) ? 'Privé' : 'Public';
                    $codeDsps = !empty(trim($row[2] ?? '')) ? trim($row[2]) : null;
                    
                    // Gestion Tuteur
                    $nomTuteur = trim($row[3] ?? '');
                    $tuteurId = null;
                    if (!empty($nomTuteur)) {
                        $stmtT = $pdo->prepare("SELECT id FROM ecoles WHERE nom = ? LIMIT 1");
                        $stmtT->execute([$nomTuteur]);
                        $resT = $stmtT->fetch();
                        if ($resT) $tuteurId = $resT['id'];
                    }
                    
                    $typeRatt = !empty($tuteurId) ? (in_array(strtolower($row[4] ?? ''), ['arrimee']) ? 'arrimee' : 'sans_code_dsps') : 'aucun';

                    // Règle du cahier des charges : sans code DSPS, une école doit être rattachée à une tutrice.
                    if (empty($codeDsps) && !$tuteurId) {
                        $erreurs[] = "Ligne $numLigne : '$nom' n'a pas de code DSPS et aucune école tutrice '$nomTuteur' n'a été trouvée — ligne ignorée.";
                        continue;
                    }

                    // Gestion Groupe (une valeur donnée dans le fichier = ajustement manuel verrouillé)
                    $groupeManuel = trim($row[5] ?? '');
                    $groupeScolaire = !empty($groupeManuel) ? $groupeManuel : null;
                    $groupeVerrouille = !empty($groupeManuel) ? 1 : 0;

                    $directeurNom = trim($row[6] ?? '');
                    $directeurTel = trim($row[7] ?? '');
                    $effectif = !empty($row[8]) ? (int)$row[8] : 0;
                    $estCentre = (in_array(strtoupper($row[9] ?? ''), ['O', 'OUI', '1'])) ? 1 : 0;

                    // Vérification Doublon : par code DSPS si disponible (clé la plus fiable),
                    // sinon par nom (insensible à la casse et aux espaces superflus)
                    if (!empty($codeDsps)) {
                        $checkStmt = $pdo->prepare("SELECT id FROM ecoles WHERE code_dsps = ?");
                        $checkStmt->execute([$codeDsps]);
                    } else {
                        $checkStmt = $pdo->prepare("SELECT id FROM ecoles WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?))");
                        $checkStmt->execute([$nom]);
                    }
                    $existing = $checkStmt->fetch();

                    if ($existing) {
                        $upd = $pdo->prepare("UPDATE ecoles SET code_dsps=?, statut=?, ecole_tutrice_id=?, type_rattachement=?, groupe_scolaire=?, groupe_scolaire_manuel=?, directeur_nom=?, directeur_telephone=?, effectif_general=?, est_centre_examen=?, source='import' WHERE id=?");
                        $upd->execute([$codeDsps, $statut, $tuteurId, $typeRatt, $groupeScolaire, $groupeVerrouille, $directeurNom, $directeurTel, $effectif, $estCentre, $existing['id']]);
                        $nbMajs++;
                    } else {
                        $ins = $pdo->prepare("INSERT INTO ecoles (annee_id, nom, code_dsps, statut, ecole_tutrice_id, type_rattachement, groupe_scolaire, groupe_scolaire_manuel, directeur_nom, directeur_telephone, effectif_general, est_centre_examen, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'import')");
                        $ins->execute([$anneeActive['id'] ?? $anneeId, $nom, $codeDsps, $statut, $tuteurId, $typeRatt, $groupeScolaire, $groupeVerrouille, $directeurNom, $directeurTel, $effectif, $estCentre]);
                        $nbAjouts++;
                    }
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            recalculerGroupesScolaires($pdo);

            $msg = "Import terminé : $nbAjouts ajouts, $nbMajs mises à jour.";
            if (!empty($erreurs)) $msg .= "<br>Erreurs : " . implode(", ", array_slice($erreurs, 0, 5));
            header("Location: ecoles.php?msg=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            $error = "Erreur critique : " . $e->getMessage();
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $id = (int)$_GET['supprimer'];
    $pdo->prepare("UPDATE ecoles SET ecole_tutrice_id = NULL WHERE ecole_tutrice_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM ecoles WHERE id = ?")->execute([$id]);
    recalculerGroupesScolaires($pdo);
    header("Location: ecoles.php");
    exit;
}

// ==========================================
// TRAITEMENT : AJOUT / MODIF MANUELLE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajouter_ecole']) || isset($_POST['modifier_ecole']))) {
    $nom = trim($_POST['nom']);
    $codeDsps = !empty(trim($_POST['code_dsps'])) ? trim($_POST['code_dsps']) : null;
    $statut = $_POST['statut'];
    $tuteurId = !empty($_POST['ecole_tutrice_id']) ? (int)$_POST['ecole_tutrice_id'] : null;
    $typeRatt = $tuteurId ? ($_POST['type_rattachement'] ?? 'sans_code_dsps') : 'aucun';

    // Si l'utilisateur saisit un nom de groupe, c'est un ajustement manuel verrouillé.
    // S'il laisse vide, l'auto-détection reprendra la main au recalcul ci-dessous.
    $groupeSaisi = trim($_POST['groupe_scolaire'] ?? '');
    $groupe = !empty($groupeSaisi) ? $groupeSaisi : null;
    $groupeVerrouille = !empty($groupeSaisi) ? 1 : 0;

    $directeur = trim($_POST['directeur_nom']);
    $tel = trim($_POST['directeur_telephone']);
    $effectif = (int)($_POST['effectif_general'] ?? 0);
    $centre = isset($_POST['est_centre_examen']) ? 1 : 0;

    // Règle du cahier des charges : une école sans code DSPS doit obligatoirement
    // être rattachée à une école tutrice (elle ne peut pas rester "aucun").
    if (empty($codeDsps) && !$tuteurId) {
        $error = "Cette école n'a pas de code DSPS : elle doit obligatoirement être rattachée à une école tutrice.";
    } elseif (isset($_POST['ajouter_ecole'])) {
        $stmt = $pdo->prepare("INSERT INTO ecoles (annee_id, nom, code_dsps, statut, ecole_tutrice_id, type_rattachement, groupe_scolaire, groupe_scolaire_manuel, directeur_nom, directeur_telephone, effectif_general, est_centre_examen, source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manuel')");
        $stmt->execute([$anneeActive['id'] ?? $anneeId, $nom, $codeDsps, $statut, $tuteurId, $typeRatt, $groupe, $groupeVerrouille, $directeur, $tel, $effectif, $centre]);
    } elseif (isset($_POST['modifier_ecole'])) {
        $id = (int)$_POST['id'];
        if ($tuteurId == $id) { $tuteurId = null; $typeRatt = 'aucun'; }
        $stmt = $pdo->prepare("UPDATE ecoles SET nom=?, code_dsps=?, statut=?, ecole_tutrice_id=?, type_rattachement=?, groupe_scolaire=?, groupe_scolaire_manuel=?, directeur_nom=?, directeur_telephone=?, effectif_general=?, est_centre_examen=? WHERE id=?");
        $stmt->execute([$nom, $codeDsps, $statut, $tuteurId, $typeRatt, $groupe, $groupeVerrouille, $directeur, $tel, $effectif, $centre, $id]);
    }

    if (!isset($error)) {
        recalculerGroupesScolaires($pdo);
        header("Location: ecoles.php");
        exit;
    }
}

// ==========================================
// DONNÉES & AFFICHAGE
// ==========================================
$filtreStatut = $_GET['filtre_statut'] ?? '';
$filtreRecherche = $_GET['recherche'] ?? '';

$sql = "SELECT e.*, t.nom as nom_tuteur FROM ecoles e LEFT JOIN ecoles t ON e.ecole_tutrice_id = t.id WHERE 1=1";
$params = [];

if ($filtreStatut) {
    $sql .= " AND e.statut = ?";
    $params[] = $filtreStatut;
}
if ($filtreRecherche) {
    $sql .= " AND (e.nom LIKE ? OR e.code_dsps LIKE ? OR e.groupe_scolaire LIKE ?)";
    $term = "%$filtreRecherche%";
    $params[] = $term; $params[] = $term; $params[] = $term;
}
$sql .= " ORDER BY e.nom ASC";

$ecoles = $pdo->prepare($sql);
$ecoles->execute($params);
$ecoles = $ecoles->fetchAll();

$stats = $pdo->query("SELECT COUNT(*) as total, SUM(CASE WHEN statut='Public' THEN 1 ELSE 0 END) as publics, SUM(CASE WHEN statut='Privé' THEN 1 ELSE 0 END) as prives, SUM(CASE WHEN code_dsps IS NULL THEN 1 ELSE 0 END) as sans_code, SUM(est_centre_examen) as centres FROM ecoles")->fetch();

$consolide = $pdo->query("SELECT groupe_scolaire as nom_groupe, COUNT(*) as nb_ecoles, SUM(effectif_general) as total_effectif FROM ecoles WHERE groupe_scolaire IS NOT NULL GROUP BY groupe_scolaire ORDER BY nom_groupe ASC")->fetchAll();

$ecoleAModifier = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM ecoles WHERE id = ?");
    $stmt->execute([(int)$_GET['modifier']]);
    $ecoleAModifier = $stmt->fetch();
}

$tuteursPotentiels = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll();

include '../views/layouts/header.php';
?>

<!-- Alertes -->
<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🏫 Module Écoles</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Nouvelle École</button>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-2"><?php statCard('bi-building', 'navy', (string) $stats['total'], 'Total'); ?></div>
    <div class="col-md-2"><?php statCard('bi-bank', 'blue', (string) $stats['publics'], 'Publics'); ?></div>
    <div class="col-md-2"><?php statCard('bi-mortarboard', 'purple', (string) $stats['prives'], 'Privés'); ?></div>
    <div class="col-md-3"><?php statCard('bi-exclamation-triangle', 'red', (string) $stats['sans_code'], 'Sans Code DSPS'); ?></div>
    <div class="col-md-3"><?php statCard('bi-geo-alt', 'green', (string) $stats['centres'], 'Centres Examen'); ?></div>
</div>

<!-- Filtres -->
<form method="GET" id="formFiltresEcoles" class="row g-3 mb-4">
    <div class="col-md-6"><input type="text" name="recherche" id="inputRechercheEcoles" class="form-control" placeholder="Rechercher (Nom, Code, Groupe)..." value="<?= htmlspecialchars($filtreRecherche) ?>" autocomplete="off"></div>
    <div class="col-md-4">
        <select name="filtre_statut" class="form-select" onchange="this.form.submit()">
            <option value="">Tous les statuts</option>
            <option value="Public" <?= $filtreStatut=='Public'?'selected':'' ?>>Public</option>
            <option value="Privé" <?= $filtreStatut=='Privé'?'selected':'' ?>>Privé</option>
        </select>
    </div>
    <div class="col-md-2"><a href="ecoles.php" class="btn btn-outline-secondary w-100">Réinitialiser</a></div>
</form>
<script>
(function () {
    var champ = document.getElementById('inputRechercheEcoles');
    var minuteur;
    champ.addEventListener('input', function () {
        clearTimeout(minuteur);
        minuteur = setTimeout(function () {
            document.getElementById('formFiltresEcoles').submit();
        }, 600);
    });
})();
</script>

<!-- Onglets -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#detail">📋 Liste Détaillée</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#groupes">🏘️ Par Groupes Scolaires</button></li>
</ul>

<!-- Barre d'actions en masse (apparaît dès qu'au moins 1 école est sélectionnée) -->
<div class="alert alert-primary d-none align-items-center justify-content-between py-2 mb-3" id="barreActionsMasseEcoles">
    <span><strong id="nbSelectionnesEcoles">0</strong> école(s) sélectionnée(s)</span>
    <button type="button" class="btn btn-sm btn-danger" onclick="actionMasseEcoles('supprimer')"><i class="bi bi-trash"></i> Supprimer</button>
</div>

<div class="tab-content">
    <!-- ONGLET 1 : LISTE DÉTAILLÉE -->
    <div class="tab-pane fade show active" id="detail">
        <div class="card shadow-sm">
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><input type="checkbox" id="checkToutEcoles" onclick="toggleTousEcoles(this)"></th>
                            <th>Groupe</th>
                            <th>Nom École</th>
                            <th>Code DSPS</th>
                            <th>Statut</th>
                            <th>Rattachement</th>
                            <th>Directeur</th>
                            <th>Centre?</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ecoles as $e): ?>
                        <tr class="<?= $e['est_centre_examen'] ? 'table-success' : '' ?>">
                            <td><input type="checkbox" class="check-ecole" value="<?= $e['id'] ?>" onchange="majBarreActionsEcoles()"></td>
                            <td><small class="text-muted"><?= htmlspecialchars($e['groupe_scolaire'] ?? '-') ?></small></td>
                            <td><strong><a href="candidats.php?ecole_id=<?= $e['id'] ?>" title="Voir les candidats de cette école"><?= htmlspecialchars($e['nom']) ?></a></strong></td>
                            <td><?= $e['code_dsps'] ? '<code>'.htmlspecialchars($e['code_dsps']).'</code>' : '<span class="badge bg-danger">Aucun</span>' ?></td>
                            <td><span class="badge bg-<?= $e['statut']=='Public'?'info':'warning' ?>"><?= $e['statut'] ?></span></td>
                            <td>
                                <?php if ($e['type_rattachement'] != 'aucun'): ?>
                                    <small class="text-primary"><i class="bi bi-link-45deg"></i> <?= htmlspecialchars($e['nom_tuteur']) ?></small>
                                    <br><span class="badge bg-secondary" style="font-size:0.7em"><?= $e['type_rattachement'] ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($e['directeur_nom']) ?><br><small class="text-muted"><?= htmlspecialchars($e['directeur_telephone']) ?></small></td>
                            <td class="text-center"><?= $e['est_centre_examen'] ? '✅' : '-' ?></td>
                            <td>
                                <a href="candidats.php?ecole_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-success" title="Candidats de cette école"><i class="bi bi-people"></i></a>
                                <a href="enseignants.php?ecole_id=<?= $e['id'] ?>" class="btn btn-sm btn-outline-info" title="Personnel de cette école"><i class="bi bi-person-badge"></i></a>
                                <a href="?modifier=<?= $e['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                                <a href="?supprimer=<?= $e['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer ?')"><i class="bi bi-trash"></i></a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ONGLET 2 : GROUPES SCOLAIRES -->
    <div class="tab-pane fade" id="groupes">
        <div class="row">
            <?php foreach ($consolide as $g): ?>
            <div class="col-md-4 mb-4">
                <div class="card h-100 border-primary">
                    <div class="card-header bg-primary text-white"><?= htmlspecialchars($g['nom_groupe']) ?></div>
                    <div class="card-body">
                        <h5 class="card-title text-center"><?= $g['nb_ecoles'] ?> Écoles</h5>
                        <p class="text-center">Effectif Total : <strong><?= $g['total_effectif'] ?></strong></p>
                        <hr>
                        <ul class="list-unstyled small">
                            <?php 
                            $sousEc = $pdo->prepare("SELECT nom, code_dsps FROM ecoles WHERE groupe_scolaire = ?");
                            $sousEc->execute([$g['nom_groupe']]);
                            foreach($sousEc->fetchAll() as $se): 
                            ?>
                            <li>• <?= htmlspecialchars($se['nom']) ?> <?= $se['code_dsps'] ? '('.$se['code_dsps'].')' : '' ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Modal IMPORT -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white"><h5>Importer Excel</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p>Colonnes requises (Ordre strict) :</p>
                    <ol class="small">
                        <li>Nom École</li><li>Statut (Public/Privé)</li><li>Code DSPS</li>
                        <li>Nom École Tutrice (si rattachée)</li><li>Type Ratt. (Arrimée/Sans Code)</li>
                        <li>Groupe Scolaire (laisser vide pour détection auto)</li>
                        <li>Directeur</li><li>Téléphone</li><li>Effectif Global</li><li>Centre Examen (O/N)</li>
                    </ol>
                    <input type="file" name="fichier_ecoles" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer"><button type="submit" name="importer_ecoles" class="btn btn-success">Lancer Import</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Modal AJOUT/MODIF -->
<div class="modal fade <?= $ecoleAModifier ? 'show' : '' ?>" id="modalAjout" tabindex="-1" style="<?= $ecoleAModifier ? 'display:block;background:rgba(0,0,0,0.5)' : '' ?>">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5><?= $ecoleAModifier ? 'Modifier' : 'Nouvelle École' ?></h5>
                    <a href="ecoles.php" class="btn-close btn-close-white" aria-label="Fermer"></a>
                </div>
                <div class="modal-body">
                    <?php if ($ecoleAModifier): ?><input type="hidden" name="id" value="<?= $ecoleAModifier['id'] ?>"><?php endif; ?>
                    
                    <div class="row">
                        <div class="col-md-8 mb-2"><label>Nom *</label><input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($ecoleAModifier['nom'] ?? '') ?>" required></div>
                        <div class="col-md-4 mb-2"><label>Code DSPS</label><input type="text" name="code_dsps" class="form-control" value="<?= htmlspecialchars($ecoleAModifier['code_dsps'] ?? '') ?>"></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-2">
                            <label>Statut</label>
                            <select name="statut" class="form-select">
                                <option value="Public" <?= ($ecoleAModifier['statut'] ?? 'Public') == 'Public' ? 'selected' : '' ?>>Public</option>
                                <option value="Privé" <?= ($ecoleAModifier['statut'] ?? '') == 'Privé' ? 'selected' : '' ?>>Privé</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Groupe Scolaire</label><input type="text" name="groupe_scolaire" class="form-control" value="<?= htmlspecialchars($ecoleAModifier['groupe_scolaire'] ?? '') ?>" placeholder="Laisser vide = calcul automatique"><small class="text-muted">Rempli automatiquement si 2+ écoles partagent le même nom de base (ex: EPP Azito 1/2/3). Laissez vide pour laisser le système décider.</small></div>
                        <div class="col-md-4 mb-2"><label>Effectif Global</label><input type="number" name="effectif_general" class="form-control" value="<?= $ecoleAModifier['effectif_general'] ?? 0 ?>"></div>
                    </div>
                    
                    <hr>
                    <h6>Rattachement (Optionnel)</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2">
                            <label>École Tutrice</label>
                            <select name="ecole_tutrice_id" class="form-select">
                                <option value="">-- Aucune --</option>
                                <?php foreach($tuteursPotentiels as $t): 
                                    $sel = ($ecoleAModifier['ecole_tutrice_id'] ?? 0) == $t['id'] ? 'selected' : '';
                                    $dis = ($ecoleAModifier && $ecoleAModifier['id'] == $t['id']) ? 'disabled' : '';
                                ?>
                                <option value="<?= $t['id'] ?>" <?= $sel ?> <?= $dis ?>><?= htmlspecialchars($t['nom']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 mb-2">
                            <label>Type de Rattachement</label>
                            <select name="type_rattachement" class="form-select">
                                <option value="aucun">Aucun</option>
                                <option value="sans_code_dsps" <?= ($ecoleAModifier['type_rattachement'] ?? '') == 'sans_code_dsps' ? 'selected' : '' ?>>Sans Code DSPS</option>
                                <option value="arrimee" <?= ($ecoleAModifier['type_rattachement'] ?? '') == 'arrimee' ? 'selected' : '' ?>>École Arrimée</option>
                            </select>
                        </div>
                    </div>

                    <hr>
                    <h6>Direction & Divers</h6>
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Directeur</label><input type="text" name="directeur_nom" class="form-control" value="<?= htmlspecialchars($ecoleAModifier['directeur_nom'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-2"><label>Téléphone</label><input type="text" name="directeur_telephone" class="form-control" value="<?= htmlspecialchars($ecoleAModifier['directeur_telephone'] ?? '') ?>"></div>
                        <div class="col-md-3 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="est_centre_examen" id="checkCentre" <?= ($ecoleAModifier['est_centre_examen'] ?? 0) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="checkCentre">Centre d'Examen</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <a href="ecoles.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="<?= $ecoleAModifier ? 'modifier_ecole' : 'ajouter_ecole' ?>" class="btn btn-primary">Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleTousEcoles(caseTete) {
    document.querySelectorAll('.check-ecole').forEach(function (c) { c.checked = caseTete.checked; });
    majBarreActionsEcoles();
}

function majBarreActionsEcoles() {
    var coches = document.querySelectorAll('.check-ecole:checked');
    var barre = document.getElementById('barreActionsMasseEcoles');
    document.getElementById('nbSelectionnesEcoles').textContent = coches.length;
    barre.classList.toggle('d-none', coches.length === 0);
    barre.classList.toggle('d-flex', coches.length > 0);
}

async function actionMasseEcoles(action) {
    var ids = Array.from(document.querySelectorAll('.check-ecole:checked')).map(function (c) { return c.value; });
    if (ids.length === 0) return;

    if (action === 'supprimer' && !confirm('Supprimer ' + ids.length + ' école(s) ? Cette action est irréversible et détachera leurs éventuelles écoles rattachées.')) {
        return;
    }

    try {
        var reponse = await fetch('api_bulk_ecoles.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + action + '&ids=' + ids.join(',')
        });
        var data = await reponse.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Erreur : ' + (data.error || 'inconnue'));
        }
    } catch (e) {
        alert('Erreur réseau : impossible d\'effectuer cette action.');
    }
}
</script>

<?php include '../views/layouts/footer.php'; ?>