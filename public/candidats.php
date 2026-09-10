<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Gestion des Candidats CEPE';

// Les candidats sont rattachés à l'année scolaire consultée ($anneeId, résolu par
// config/database.php). Toute écriture est bloquée si cette année est archivée.
if ($anneeLectureSeule && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['supprimer']))) {
    die("Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.");
}

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL (Mise à jour intelligente)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_candidats'])) {
    if (isset($_FILES['fichier_candidats']) && $_FILES['fichier_candidats']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier_candidats']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); // Saute en-tête

            $nbAjouts = 0; $nbMajs = 0; $erreurs = [];

            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    // Colonnes attendues : A:Nom, B:Prénoms, C:Sexe, D:Nationalité, E:DateNaiss,
                    // F:LieuNaiss, G:Matricule, H:Acte(O/N), I:StatutDemande, J:NomÉcole (ou 'LIBRE'), K:CodeDSPSÉcole
                    $nom = trim($row[0] ?? '');
                    if (empty($nom)) continue;

                    $prenoms = trim($row[1] ?? '');
                    $sexe = (strtoupper(trim($row[2] ?? '')) === 'F') ? 'F' : 'M';
                    $nationalite = trim($row[3] ?? '') ?: null;

                    // Gestion Date Naissance (Excel serial ou string)
                    $dateNaissRaw = $row[4] ?? null;
                    $dateNaiss = null;
                    if (!empty($dateNaissRaw)) {
                        if (is_numeric($dateNaissRaw)) {
                            $dateNaiss = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateNaissRaw)->format('Y-m-d');
                        } else {
                            $dateNaiss = date('Y-m-d', strtotime($dateNaissRaw));
                        }
                    }

                    $lieuNaiss = trim($row[5] ?? '');
                    $matricule = !empty(trim($row[6] ?? '')) ? trim($row[6]) : null;

                    $aActe = (in_array(strtoupper(trim($row[7] ?? '')), ['O', 'OUI', '1'])) ? 1 : 0;

                    $statutRaw = strtolower(trim($row[8] ?? 'non_entamee'));
                    $statutDemande = in_array($statutRaw, ['en_cours', 'faite']) ? $statutRaw : 'non_entamee';

                    // Gestion École vs Candidat Libre
                    $nomEcole = preg_replace('/\s+/', ' ', trim($row[9] ?? ''));
                    $codeDspsEcole = trim($row[10] ?? '');
                    $ecoleId = null;
                    $estLibre = 0;

                    if (strtoupper($nomEcole) === 'LIBRE' || (empty($nomEcole) && empty($codeDspsEcole))) {
                        $estLibre = 1;
                    } else {
                        // Le code DSPS de l'école est plus fiable qu'un nom (fautes de frappe,
                        // écoles homonymes) : on l'essaie en priorité s'il est fourni.
                        $resE = null;
                        if (!empty($codeDspsEcole)) {
                            $stmtE = $pdo->prepare("SELECT id FROM ecoles WHERE LOWER(TRIM(code_dsps)) = LOWER(TRIM(?)) LIMIT 1");
                            $stmtE->execute([$codeDspsEcole]);
                            $resE = $stmtE->fetch();
                        }
                        if (!$resE && !empty($nomEcole)) {
                            $stmtE = $pdo->prepare("SELECT id FROM ecoles WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) LIMIT 1");
                            $stmtE->execute([$nomEcole]);
                            $resE = $stmtE->fetch();
                        }
                        if ($resE) {
                            $ecoleId = $resE['id'];
                        } else {
                            $erreurs[] = "Ligne $numLigne : École '$nomEcole' (code DSPS '$codeDspsEcole') introuvable (candidat : $nom $prenoms).";
                            continue;
                        }
                    }

                    // Vérification Doublon, dans l'année scolaire consultée : par Matricule DSPS si
                    // disponible (clé la plus fiable), sinon par Nom + Prénoms + Date de naissance + École/Libre
                    if (!empty($matricule)) {
                        $checkStmt = $pdo->prepare("SELECT id FROM candidats WHERE annee_id = ? AND matricule_dsps = ?");
                        $checkStmt->execute([$anneeId, $matricule]);
                    } else {
                        $checkSql = "SELECT id FROM candidats WHERE annee_id = ? AND nom = ? AND prenoms = ? AND date_naissance <=> ? AND ((ecole_id = ? AND est_candidat_libre = 0) OR (est_candidat_libre = 1 AND ? = 1))";
                        $checkStmt = $pdo->prepare($checkSql);
                        $checkStmt->execute([$anneeId, $nom, $prenoms, $dateNaiss, $ecoleId, $estLibre]);
                    }
                    $existing = $checkStmt->fetch();

                    if ($existing) {
                        // Mise à jour de l'existant (On ne crée pas de doublon, on met à jour le statut/matricule)
                        $upd = $pdo->prepare("UPDATE candidats SET matricule_dsps=?, a_acte_naissance=?, statut_demande=?, date_naissance=?, lieu_naissance=?, nationalite=? WHERE id=?");
                        $upd->execute([$matricule, $aActe, $statutDemande, $dateNaiss, $lieuNaiss, $nationalite, $existing['id']]);
                        $nbMajs++;
                    } else {
                        // Insertion Nouveau
                        $ins = $pdo->prepare("INSERT INTO candidats (annee_id, nom, prenoms, sexe, nationalite, date_naissance, lieu_naissance, matricule_dsps, a_acte_naissance, statut_demande, ecole_id, est_candidat_libre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$anneeId, $nom, $prenoms, $sexe, $nationalite, $dateNaiss, $lieuNaiss, $matricule, $aActe, $statutDemande, $ecoleId, $estLibre]);
                        $nbAjouts++;
                    }
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            $msg = "Import terminé : $nbAjouts nouveaux, $nbMajs mis à jour.";
            if (!empty($erreurs)) {
                $msg .= " <br><small>" . count($erreurs) . " erreurs (voir détail ci-dessous).</small>";
                $_SESSION['import_erreurs'] = $erreurs;
            } else {
                unset($_SESSION['import_erreurs']);
            }
            header("Location: candidats.php?msg=" . urlencode($msg));
            exit;

        } catch (Exception $e) {
            $error = "Erreur critique : " . $e->getMessage();
        }
    }
}

// ==========================================
// TRAITEMENT : AJOUT MANUEL (Y COMPRIS LIBRE)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter_candidat'])) {
    $nom = trim($_POST['nom']);
    $prenoms = trim($_POST['prenoms']);
    $sexe = $_POST['sexe'];
    $nationalite = trim($_POST['nationalite'] ?? '') ?: null;
    $dateNaiss = !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null;
    $lieuNaiss = trim($_POST['lieu_naissance']);
    $matricule = !empty(trim($_POST['matricule_dsps'])) ? trim($_POST['matricule_dsps']) : null;
    $aActe = isset($_POST['a_acte_naissance']) ? 1 : 0;
    $statutDemande = $_POST['statut_demande'];

    $estLibre = isset($_POST['est_candidat_libre']) ? 1 : 0;
    $ecoleId = ($estLibre == 0 && !empty($_POST['ecole_id'])) ? (int)$_POST['ecole_id'] : null;

    // Si libre, on peut optionally choisir un centre
    $centreId = ($estLibre == 1 && !empty($_POST['centre_examen_id'])) ? (int)$_POST['centre_examen_id'] : null;

    $stmt = $pdo->prepare("INSERT INTO candidats (annee_id, nom, prenoms, sexe, nationalite, date_naissance, lieu_naissance, matricule_dsps, a_acte_naissance, statut_demande, ecole_id, est_candidat_libre, centre_examen_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$anneeId, $nom, $prenoms, $sexe, $nationalite, $dateNaiss, $lieuNaiss, $matricule, $aActe, $statutDemande, $ecoleId, $estLibre, $centreId]);

    header("Location: candidats.php");
    exit;
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $pdo->prepare("DELETE FROM candidats WHERE id = ?")->execute([(int)$_GET['supprimer']]);
    header("Location: candidats.php");
    exit;
}

// ==========================================
// FILTRES & DONNÉES
// ==========================================
$filtreEcole = $_GET['ecole_id'] ?? '';
$filtreStatut = $_GET['statut'] ?? ''; // non_entamee, en_cours, faite
$filtreValidation = $_GET['validation'] ?? ''; // valide, non_valide
$filtreRecherche = $_GET['q'] ?? '';

$sql = "SELECT c.*, e.nom as nom_ecole, (c.matricule_verifie = 1 AND c.droits_payes = 1) as est_valide FROM candidats c LEFT JOIN ecoles e ON c.ecole_id = e.id WHERE c.annee_id = ?";
$params = [$anneeId];

if ($filtreEcole) {
    $sql .= " AND c.ecole_id = ?";
    $params[] = $filtreEcole;
}
if ($filtreStatut) {
    $sql .= " AND c.statut_demande = ?";
    $params[] = $filtreStatut;
}
if ($filtreValidation === 'valide') {
    $sql .= " AND c.matricule_verifie = 1 AND c.droits_payes = 1";
} elseif ($filtreValidation === 'non_valide') {
    $sql .= " AND (c.matricule_verifie = 0 OR c.droits_payes = 0)";
}
if ($filtreRecherche) {
    $sql .= " AND (c.nom LIKE ? OR c.prenoms LIKE ? OR c.matricule_dsps LIKE ?)";
    $t = "%$filtreRecherche%";
    $params[] = $t; $params[] = $t; $params[] = $t;
}

$whereFiltres = substr($sql, strpos($sql, 'WHERE c.annee_id')); // récupère la clause WHERE seule, réutilisée pour les stats

$sql .= " ORDER BY c.nom ASC";
$candidats = $pdo->prepare($sql);
$candidats->execute($params);
$candidats = $candidats->fetchAll();

// STATISTIQUES — suivent exactement le(s) même(s) filtre(s) que la liste ci-dessus
$sqlStats = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN c.sexe='F' THEN 1 ELSE 0 END) as filles,
    SUM(CASE WHEN c.sexe='M' THEN 1 ELSE 0 END) as garcons,
    SUM(CASE WHEN c.matricule_dsps IS NOT NULL THEN 1 ELSE 0 END) as immatricules,
    SUM(CASE WHEN c.matricule_dsps IS NULL THEN 1 ELSE 0 END) as non_immatricules,
    SUM(CASE WHEN c.a_acte_naissance=0 AND c.matricule_dsps IS NULL THEN 1 ELSE 0 END) as sans_acte,
    SUM(CASE WHEN c.statut_demande='non_entamee' AND c.matricule_dsps IS NULL THEN 1 ELSE 0 END) as demande_non_fait,
    SUM(c.est_candidat_libre) as candidats_libres,
    SUM(CASE WHEN c.matricule_verifie = 1 AND c.droits_payes = 1 THEN 1 ELSE 0 END) as candidats_valides
    FROM candidats c LEFT JOIN ecoles e ON c.ecole_id = e.id $whereFiltres";
$stmtStats = $pdo->prepare($sqlStats);
$stmtStats->execute($params);
$statsGlobal = $stmtStats->fetch();

// Contexte : effectif total tous filtres confondus (pour "X sur Y"), sur l'année consultée
$stmtTotalGeneral = $pdo->prepare("SELECT COUNT(*) FROM candidats WHERE annee_id = ?");
$stmtTotalGeneral->execute([$anneeId]);
$totalGeneralBase = (int) $stmtTotalGeneral->fetchColumn();

// Filtre actif ? et nom de l'école filtrée (pour affichage du bandeau)
$filtreActif = $filtreEcole || $filtreStatut || $filtreValidation || $filtreRecherche;
$nomEcoleFiltre = null;
if ($filtreEcole) {
    $stmtNomE = $pdo->prepare("SELECT nom FROM ecoles WHERE id = ?");
    $stmtNomE->execute([$filtreEcole]);
    $nomEcoleFiltre = $stmtNomE->fetchColumn();
}

// Liste Écoles pour Select
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();
// Liste Centres pour Candidats Libres
$centres = $pdo->query("SELECT c.id, e.nom FROM centres c JOIN ecoles e ON c.ecole_id = e.id ORDER BY e.nom")->fetchAll();

include '../views/layouts/header.php';
?>

<!-- Alertes -->
<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $_GET['msg'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (!empty($_SESSION['import_erreurs'])): ?>
    <div class="alert alert-warning">
        <button class="btn btn-sm btn-outline-dark mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#detailErreursImport">
            <i class="bi bi-list-ul"></i> Voir le détail des <?= count($_SESSION['import_erreurs']) ?> erreurs
        </button>
        <div class="collapse" id="detailErreursImport">
            <div style="max-height: 300px; overflow-y: auto;">
                <ul class="mb-0 small">
                    <?php foreach ($_SESSION['import_erreurs'] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['import_erreurs']); ?>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🎓 Candidats CEPE</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter Manuellement</button>
    </div>
</div>

<!-- Bandeau de contexte : indique si les stats ci-dessous sont globales ou filtrées -->
<?php if ($filtreActif): ?>
    <div class="alert alert-primary d-flex justify-content-between align-items-center">
        <div>
            <i class="bi bi-funnel-fill"></i>
            Statistiques filtrées
            <?php if ($nomEcoleFiltre): ?> — École : <strong><?= htmlspecialchars($nomEcoleFiltre) ?></strong><?php endif; ?>
            <?php if ($filtreRecherche): ?> — Recherche : « <?= htmlspecialchars($filtreRecherche) ?> »<?php endif; ?>
            <?php if ($filtreStatut): ?> — Statut demande : <?= htmlspecialchars($filtreStatut) ?><?php endif; ?>
            <?php if ($filtreValidation): ?> — Candidature : <?= $filtreValidation === 'valide' ? 'Validés' : 'Non validés' ?><?php endif; ?>
            (<?= $statsGlobal['total'] ?> sur <?= $totalGeneralBase ?> candidats au total)
        </div>
        <a href="candidats.php" class="btn btn-sm btn-outline-primary">Réinitialiser les filtres</a>
    </div>
<?php else: ?>
    <div class="alert alert-light border text-muted mb-3">
        <i class="bi bi-list-ul"></i> Statistiques sur l'ensemble des <?= $totalGeneralBase ?> candidats. Utilisez les filtres ci-dessous pour voir les statistiques d'une école précise.
    </div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <div class="col-md-2"><?php statCard('bi-people', 'navy', (string) $statsGlobal['total'], 'Total Élèves'); ?></div>
    <div class="col-md-2"><?php statCard('bi-gender-female', 'purple', (string) $statsGlobal['filles'], 'Filles'); ?></div>
    <div class="col-md-2"><?php statCard('bi-gender-male', 'blue', (string) $statsGlobal['garcons'], 'Garçons'); ?></div>
    <div class="col-md-3"><?php statCard('bi-check-circle', 'green', (string) $statsGlobal['immatricules'], 'Immatriculés'); ?></div>
    <div class="col-md-3"><?php statCard('bi-exclamation-triangle', 'red', (string) $statsGlobal['non_immatricules'], 'Non Immatriculés'); ?></div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="stat-card h-100">
            <div class="stat-card-icon stat-icon-green"><i class="bi bi-patch-check"></i></div>
            <div class="stat-card-body">
                <div class="stat-card-value" id="compteur-valides"><span id="nb-valides"><?= $statsGlobal['candidats_valides'] ?></span> / <?= $statsGlobal['total'] ?></div>
                <div class="stat-card-label">Candidats Validés</div>
                <div class="stat-card-sub">Matricule vérifié + Droits payés</div>
            </div>
        </div>
    </div>
    <div class="col-md-8 d-flex align-items-center">
        <div class="alert alert-secondary mb-0 w-100">
            <strong>Rappel :</strong> un candidat est <strong>Validé</strong> uniquement lorsque l'IEPP a coché
            <em>« Matricule vérifié sur DSPS »</em> ET <em>« Droits d'examen payés »</em>.
            Cliquez sur les icônes du tableau ci-dessous pour basculer chaque case.
        </div>
    </div>
</div>

<!-- Détails Problèmes -->
<div class="row mb-4">
    <div class="col-md-6">
        <div class="alert alert-warning mb-0">
            <strong>⚠️ Action Requise (Directeurs) :</strong> <?= $statsGlobal['demande_non_fait'] ?> élèves dont la demande n'a pas été faite.
        </div>
    </div>
    <div class="col-md-6">
        <div class="alert alert-dark mb-0">
            <strong>📄 Pièces Manquantes (Parents) :</strong> <?= $statsGlobal['sans_acte'] ?> élèves sans acte de naissance.
        </div>
    </div>
</div>

<!-- Filtres -->
<form method="GET" id="formFiltresCandidats" class="row g-3 mb-4 p-3 bg-light rounded">
    <div class="col-md-4">
        <label class="form-label">Rechercher (Nom/Matricule)</label>
        <input type="text" name="q" id="inputRechercheCandidats" class="form-control" value="<?= htmlspecialchars($filtreRecherche) ?>" autocomplete="off">
    </div>
    <div class="col-md-3">
        <label class="form-label">Filtrer par École</label>
        <select name="ecole_id" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les écoles</option>
            <?php foreach($ecoles as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $filtreEcole==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Statut Immatriculation</label>
        <select name="statut" class="form-select" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="faite" <?= $filtreStatut=='faite'?'selected':'' ?>>Immatriculés</option>
            <option value="non_entamee" <?= $filtreStatut=='non_entamee'?'selected':'' ?>>Demande non faite</option>
            <option value="en_cours" <?= $filtreStatut=='en_cours'?'selected':'' ?>>En cours</option>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Statut Candidature</label>
        <select name="validation" class="form-select" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="valide" <?= $filtreValidation=='valide'?'selected':'' ?>>Validés</option>
            <option value="non_valide" <?= $filtreValidation=='non_valide'?'selected':'' ?>>Non validés</option>
        </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <a href="candidats.php" class="btn btn-outline-secondary w-100">Réinitialiser</a>
    </div>
</form>
<script>
// Recherche texte : filtre automatiquement 600ms après la dernière frappe (évite de soumettre à chaque lettre)
(function () {
    var champRecherche = document.getElementById('inputRechercheCandidats');
    var minuteur;
    champRecherche.addEventListener('input', function () {
        clearTimeout(minuteur);
        minuteur = setTimeout(function () {
            document.getElementById('formFiltresCandidats').submit();
        }, 600);
    });
})();
</script>

<!-- Barre d'actions en masse (apparaît dès qu'au moins 1 candidat est sélectionné) -->
<div class="alert alert-primary d-none align-items-center justify-content-between py-2 mb-3" id="barreActionsMasse">
    <span><strong id="nbSelectionnes">0</strong> candidat(s) sélectionné(s)</span>
    <div>
        <button type="button" class="btn btn-sm btn-success" onclick="actionMasseCandidats('matricule_on')"><i class="bi bi-check-square"></i> Vérifier matricule</button>
        <button type="button" class="btn btn-sm btn-success" onclick="actionMasseCandidats('droits_on')"><i class="bi bi-check-square"></i> Valider droits payés</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="actionMasseCandidats('matricule_off')">Annuler vérif.</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="actionMasseCandidats('droits_off')">Annuler paiement</button>
        <button type="button" class="btn btn-sm btn-danger" onclick="actionMasseCandidats('supprimer')"><i class="bi bi-trash"></i> Supprimer</button>
    </div>
</div>

<!-- Tableau -->
<div class="card shadow-sm">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><input type="checkbox" id="checkToutCandidats" onclick="toggleTousCandidats(this)"></th>
                    <th>Nom</th>
                    <th>Prénoms</th>
                    <th>Sexe</th>
                    <th>École / Statut</th>
                    <th>Naissance</th>
                    <th>Matricule DSPS</th>
                    <th>Acte Naiss.</th>
                    <th>Statut Demande</th>
                    <th class="text-center">Matricule vérifié</th>
                    <th class="text-center">Droits payés</th>
                    <th>Candidature</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($candidats as $c): ?>
                <tr class="<?= $c['est_candidat_libre'] ? 'table-secondary' : '' ?>">
                    <td><input type="checkbox" class="check-candidat" value="<?= $c['id'] ?>" onchange="majBarreActionsCandidats()"></td>
                    <td>
                        <strong><?= htmlspecialchars($c['nom']) ?></strong>
                        <?php if($c['est_candidat_libre']): ?><br><span class="badge bg-dark">Candidat Libre</span><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($c['prenoms']) ?></td>
                    <td><span class="badge bg-<?= $c['sexe']=='M'?'primary':'danger' ?>"><?= $c['sexe'] ?></span></td>
                    <td>
                        <?php if($c['est_candidat_libre']): ?>
                            <small class="text-muted">Indépendant</small>
                        <?php else: ?>
                            <?= htmlspecialchars($c['nom_ecole'] ?? 'Inconnu') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= $c['date_naissance'] ? date('d/m/Y', strtotime($c['date_naissance'])) : '-' ?></td>
                    <td>
                        <?php if($c['matricule_dsps']): ?>
                            <code class="text-success fw-bold"><?= htmlspecialchars($c['matricule_dsps']) ?></code>
                        <?php else: ?>
                            <span class="text-muted small">Non attribué</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $c['a_acte_naissance'] ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-x-circle-fill text-danger"></i>' ?>
                    </td>
                    <td>
                        <?php if($c['statut_demande'] == 'faite'): ?>
                            <span class="badge bg-success">Faite</span>
                        <?php elseif($c['statut_demande'] == 'en_cours'): ?>
                            <span class="badge bg-warning text-dark">En cours</span>
                        <?php else: ?>
                            <span class="badge bg-danger">Non entamée</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <a href="#" onclick="toggleValidation(event, <?= $c['id'] ?>, 'matricule_verifie', this)" title="Cliquer pour basculer">
                            <span class="icone-validation"><?= $c['matricule_verifie'] ? '<i class="bi bi-check-square-fill text-success fs-5"></i>' : '<i class="bi bi-square text-muted fs-5"></i>' ?></span>
                        </a>
                    </td>
                    <td class="text-center">
                        <a href="#" onclick="toggleValidation(event, <?= $c['id'] ?>, 'droits_payes', this)" title="Cliquer pour basculer">
                            <span class="icone-validation"><?= $c['droits_payes'] ? '<i class="bi bi-check-square-fill text-success fs-5"></i>' : '<i class="bi bi-square text-muted fs-5"></i>' ?></span>
                        </a>
                    </td>
                    <td>
                        <span class="badge-validation">
                        <?php if ($c['est_valide']): ?>
                            <span class="badge bg-success">Validé</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">En attente</span>
                        <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <a href="?supprimer=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer ce candidat ?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal IMPORT -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white"><h5>Importer Liste Élèves (Excel)</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p>Le système mettra à jour les élèves existants (même matricule, ou même nom/prénom/date de naissance/école) et ajoutera les nouveaux.</p>
                    <p class="small"><strong>Colonnes requises :</strong></p>
                    <ol class="small">
                        <li>Nom</li><li>Prénoms</li><li>Sexe (M/F)</li><li>Nationalité</li>
                        <li>Date Naissance (JJ/MM/AAAA)</li>
                        <li>Lieu Naissance</li><li>Matricule DSPS (laisser vide si aucun)</li>
                        <li>Acte Naissance (O/N)</li><li>Statut Demande (non_entamee/en_cours/faite)</li>
                        <li>Nom École (ou écrire <strong>LIBRE</strong> pour candidat libre)</li>
                        <li>Code DSPS de l'école (optionnel — utilisé en priorité pour retrouver l'école si renseigné, plus fiable qu'un nom)</li>
                    </ol>
                    <input type="file" name="fichier_candidats" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer"><button type="submit" name="importer_candidats" class="btn btn-success">Lancer Import</button></div>
            </div>
        </form>
    </div>
</div>

<!-- Modal AJOUT MANUEL -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white"><h5>Ajouter un Candidat</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" id="checkLibre" onchange="toggleEcoleSelect()">
                        <label class="form-check-label fw-bold" for="checkLibre">Candidat Libre (Hors école)</label>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-2"><label>Nom *</label><input type="text" name="nom" class="form-control" required></div>
                        <div class="col-md-6 mb-2"><label>Prénoms *</label><input type="text" name="prenoms" class="form-control" required></div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-2">
                            <label>Sexe *</label>
                            <select name="sexe" class="form-select"><option value="M">Garçon</option><option value="F">Fille</option></select>
                        </div>
                        <div class="col-md-3 mb-2"><label>Nationalité</label><input type="text" name="nationalite" class="form-control" placeholder="Ivoirienne"></div>
                        <div class="col-md-3 mb-2"><label>Date Naissance</label><input type="date" name="date_naissance" class="form-control"></div>
                        <div class="col-md-3 mb-2"><label>Lieu Naissance</label><input type="text" name="lieu_naissance" class="form-control"></div>
                    </div>
                    
                    <hr>
                    <h6>Situation Administrative</h6>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Matricule DSPS</label><input type="text" name="matricule_dsps" class="form-control" placeholder="Vide si pas encore"></div>
                        <div class="col-md-4 mb-2">
                            <label>Statut Demande</label>
                            <select name="statut_demande" class="form-select">
                                <option value="non_entamee">Non entamée</option>
                                <option value="en_cours">En cours</option>
                                <option value="faite">Faite</option>
                            </select>
                        </div>
                        <div class="col-md-4 mb-2 d-flex align-items-end">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="a_acte_naissance" id="checkActe" checked>
                                <label class="form-check-label" for="checkActe">Acte de naissance fourni</label>
                            </div>
                        </div>
                    </div>

                    <hr>
                    <div id="divEcole">
                        <label>École d'origine *</label>
                        <select name="ecole_id" class="form-select">
                            <option value="">-- Choisir --</option>
                            <?php foreach($ecoles as $e): ?><option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div id="divCentre" style="display:none;">
                        <label>Centre d'examen rattaché (Optionnel)</label>
                        <select name="centre_examen_id" class="form-select">
                            <option value="">-- Aucun --</option>
                            <?php foreach($centres as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['nom']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <input type="hidden" name="est_candidat_libre" id="inputLibre" value="0">
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button><button type="submit" name="ajouter_candidat" class="btn btn-primary">Enregistrer</button></div>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEcoleSelect() {
    const isLibre = document.getElementById('checkLibre').checked;
    document.getElementById('inputLibre').value = isLibre ? '1' : '0';
    document.getElementById('divEcole').style.display = isLibre ? 'none' : 'block';
    document.getElementById('divCentre').style.display = isLibre ? 'block' : 'none';
}

// Bascule "Matricule vérifié" / "Droits payés" sans recharger la page
async function toggleValidation(event, id, champ, lienEl) {
    event.preventDefault();

    try {
        const reponse = await fetch('api_toggle_candidat.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${id}&champ=${champ}`
        });
        const data = await reponse.json();

        if (!data.success) {
            alert('Erreur : ' + (data.error || 'inconnue'));
            return;
        }

        // Met à jour l'icône cliquée
        const icone = lienEl.querySelector('.icone-validation');
        const estCoche = (champ === 'matricule_verifie') ? data.matricule_verifie : data.droits_payes;
        icone.innerHTML = estCoche
            ? '<i class="bi bi-check-square-fill text-success fs-5"></i>'
            : '<i class="bi bi-square text-muted fs-5"></i>';

        // Met à jour le badge de statut sur la même ligne
        const ligne = lienEl.closest('tr');
        const badgeSpan = ligne.querySelector('.badge-validation');
        const ancienEtaitValide = badgeSpan.querySelector('.bg-success') !== null;
        badgeSpan.innerHTML = data.est_valide
            ? '<span class="badge bg-success">Validé</span>'
            : '<span class="badge bg-secondary">En attente</span>';

        // Met à jour le compteur global "Candidats Validés"
        if (data.est_valide && !ancienEtaitValide) {
            const compteur = document.getElementById('nb-valides');
            compteur.textContent = parseInt(compteur.textContent) + 1;
        } else if (!data.est_valide && ancienEtaitValide) {
            const compteur = document.getElementById('nb-valides');
            compteur.textContent = parseInt(compteur.textContent) - 1;
        }
    } catch (e) {
        alert('Erreur réseau : impossible de mettre à jour.');
    }
}
// Sélection multiple et actions en masse (candidats)
function toggleTousCandidats(caseTete) {
    document.querySelectorAll('.check-candidat').forEach(function (c) { c.checked = caseTete.checked; });
    majBarreActionsCandidats();
}

function majBarreActionsCandidats() {
    var coches = document.querySelectorAll('.check-candidat:checked');
    var barre = document.getElementById('barreActionsMasse');
    document.getElementById('nbSelectionnes').textContent = coches.length;
    barre.classList.toggle('d-none', coches.length === 0);
    barre.classList.toggle('d-flex', coches.length > 0);
}

async function actionMasseCandidats(action) {
    var ids = Array.from(document.querySelectorAll('.check-candidat:checked')).map(function (c) { return c.value; });
    if (ids.length === 0) return;

    if (action === 'supprimer' && !confirm('Supprimer ' + ids.length + ' candidat(s) ? Cette action est irréversible.')) {
        return;
    }

    try {
        var reponse = await fetch('api_bulk_candidats.php', {
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