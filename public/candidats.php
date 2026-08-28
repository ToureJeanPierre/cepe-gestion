<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Gestion des Candidats CEPE';

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
                    // Colonnes attendues : A:Nom, B:Prénoms, C:Sexe, D:DateNaiss, E:LieuNaiss, F:Matricule, G:Acte(O/N), H:StatutDemande, I:NomÉcole (ou 'LIBRE')
                    $nom = trim($row[0] ?? '');
                    if (empty($nom)) continue;

                    $prenoms = trim($row[1] ?? '');
                    $sexe = (strtoupper(trim($row[2] ?? '')) === 'F') ? 'F' : 'M';
                    
                    // Gestion Date Naissance (Excel serial ou string)
                    $dateNaissRaw = $row[3] ?? null;
                    $dateNaiss = null;
                    if (!empty($dateNaissRaw)) {
                        if (is_numeric($dateNaissRaw)) {
                            $dateNaiss = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateNaissRaw)->format('Y-m-d');
                        } else {
                            $dateNaiss = date('Y-m-d', strtotime($dateNaissRaw));
                        }
                    }

                    $lieuNaiss = trim($row[4] ?? '');
                    $matricule = !empty(trim($row[5] ?? '')) ? trim($row[5]) : null;
                    
                    $aActe = (in_array(strtoupper(trim($row[6] ?? '')), ['O', 'OUI', '1'])) ? 1 : 0;
                    
                    $statutRaw = strtolower(trim($row[7] ?? 'non_entamee'));
                    $statutDemande = in_array($statutRaw, ['en_cours', 'faite']) ? $statutRaw : 'non_entamee';

                    // Gestion École vs Candidat Libre
                    $nomEcole = trim($row[8] ?? '');
                    $ecoleId = null;
                    $estLibre = 0;
                    
                    if (strtoupper($nomEcole) === 'LIBRE' || empty($nomEcole)) {
                        $estLibre = 1;
                    } else {
                        $stmtE = $pdo->prepare("SELECT id FROM ecoles WHERE nom = ? LIMIT 1");
                        $stmtE->execute([$nomEcole]);
                        $resE = $stmtE->fetch();
                        if ($resE) {
                            $ecoleId = $resE['id'];
                        } else {
                            $erreurs[] = "Ligne $numLigne : École '$nomEcole' introuvable.";
                            continue;
                        }
                    }

                    // Vérification Doublon (Sur Nom + Prénoms + École/Libre)
                    $checkSql = "SELECT id FROM candidats WHERE nom = ? AND prenoms = ? AND ((ecole_id = ? AND est_candidat_libre = 0) OR (est_candidat_libre = 1 AND ? = 1))";
                    $checkStmt = $pdo->prepare($checkSql);
                    $checkStmt->execute([$nom, $prenoms, $ecoleId, $estLibre]);
                    $existing = $checkStmt->fetch();

                    if ($existing) {
                        // Mise à jour de l'existant (On ne crée pas de doublon, on met à jour le statut/matricule)
                        $upd = $pdo->prepare("UPDATE candidats SET matricule_dsps=?, a_acte_naissance=?, statut_demande=?, date_naissance=?, lieu_naissance=? WHERE id=?");
                        $upd->execute([$matricule, $aActe, $statutDemande, $dateNaiss, $lieuNaiss, $existing['id']]);
                        $nbMajs++;
                    } else {
                        // Insertion Nouveau
                        $ins = $pdo->prepare("INSERT INTO candidats (nom, prenoms, sexe, date_naissance, lieu_naissance, matricule_dsps, a_acte_naissance, statut_demande, ecole_id, est_candidat_libre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                        $ins->execute([$nom, $prenoms, $sexe, $dateNaiss, $lieuNaiss, $matricule, $aActe, $statutDemande, $ecoleId, $estLibre]);
                        $nbAjouts++;
                    }
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            $msg = "Import terminé : $nbAjouts nouveaux, $nbMajs mis à jour.";
            if (!empty($erreurs)) $msg .= " <br><small>" . count($erreurs) . " erreurs (voir logs).</small>";
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
    $dateNaiss = !empty($_POST['date_naissance']) ? $_POST['date_naissance'] : null;
    $lieuNaiss = trim($_POST['lieu_naissance']);
    $matricule = !empty(trim($_POST['matricule_dsps'])) ? trim($_POST['matricule_dsps']) : null;
    $aActe = isset($_POST['a_acte_naissance']) ? 1 : 0;
    $statutDemande = $_POST['statut_demande'];
    
    $estLibre = isset($_POST['est_candidat_libre']) ? 1 : 0;
    $ecoleId = ($estLibre == 0 && !empty($_POST['ecole_id'])) ? (int)$_POST['ecole_id'] : null;
    
    // Si libre, on peut optionally choisir un centre
    $centreId = ($estLibre == 1 && !empty($_POST['centre_examen_id'])) ? (int)$_POST['centre_examen_id'] : null;

    $stmt = $pdo->prepare("INSERT INTO candidats (nom, prenoms, sexe, date_naissance, lieu_naissance, matricule_dsps, a_acte_naissance, statut_demande, ecole_id, est_candidat_libre, centre_examen_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$nom, $prenoms, $sexe, $dateNaiss, $lieuNaiss, $matricule, $aActe, $statutDemande, $ecoleId, $estLibre, $centreId]);

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
$filtreRecherche = $_GET['q'] ?? '';

$sql = "SELECT c.*, e.nom as nom_ecole FROM candidats c LEFT JOIN ecoles e ON c.ecole_id = e.id WHERE 1=1";
$params = [];

if ($filtreEcole) {
    $sql .= " AND c.ecole_id = ?";
    $params[] = $filtreEcole;
}
if ($filtreStatut) {
    $sql .= " AND c.statut_demande = ?";
    $params[] = $filtreStatut;
}
if ($filtreRecherche) {
    $sql .= " AND (c.nom LIKE ? OR c.prenoms LIKE ? OR c.matricule_dsps LIKE ?)";
    $t = "%$filtreRecherche%";
    $params[] = $t; $params[] = $t; $params[] = $t;
}

$sql .= " ORDER BY c.nom ASC";
$candidats = $pdo->prepare($sql);
$candidats->execute($params);
$candidats = $candidats->fetchAll();

// STATISTIQUES GLOBALES
$statsGlobal = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN sexe='F' THEN 1 ELSE 0 END) as filles,
    SUM(CASE WHEN sexe='M' THEN 1 ELSE 0 END) as garcons,
    SUM(CASE WHEN matricule_dsps IS NOT NULL THEN 1 ELSE 0 END) as immatricules,
    SUM(CASE WHEN matricule_dsps IS NULL THEN 1 ELSE 0 END) as non_immatricules,
    SUM(CASE WHEN a_acte_naissance=0 AND matricule_dsps IS NULL THEN 1 ELSE 0 END) as sans_acte,
    SUM(CASE WHEN statut_demande='non_entamee' AND matricule_dsps IS NULL THEN 1 ELSE 0 END) as demande_non_fait,
    SUM(est_candidat_libre) as candidats_libres
    FROM candidats")->fetch();

// Liste Écoles pour Select
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();
// Liste Centres pour Candidats Libres
$centres = $pdo->query("SELECT c.id, e.nom FROM centres c JOIN ecoles e ON c.ecole_id = e.id ORDER BY e.nom")->fetchAll();

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
    <h2>🎓 Candidats CEPE</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter Manuellement</button>
    </div>
</div>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-md-2"><div class="card text-center bg-primary text-white"><div class="card-body"><h6>Total Élèves</h6><h3><?= $statsGlobal['total'] ?></h3></div></div></div>
    <div class="col-md-2"><div class="card text-center bg-info text-white"><div class="card-body"><h6>Filles</h6><h3><?= $statsGlobal['filles'] ?></h3></div></div></div>
    <div class="col-md-2"><div class="card text-center bg-secondary text-white"><div class="card-body"><h6>Garçons</h6><h3><?= $statsGlobal['garcons'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card text-center bg-success text-white"><div class="card-body"><h6>Immatriculés</h6><h3><?= $statsGlobal['immatricules'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card text-center bg-danger text-white"><div class="card-body"><h6>Non Immatriculés</h6><h3><?= $statsGlobal['non_immatricules'] ?></h3></div></div></div>
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
<form method="GET" class="row g-3 mb-4 p-3 bg-light rounded">
    <div class="col-md-4">
        <label class="form-label">Rechercher (Nom/Matricule)</label>
        <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($filtreRecherche) ?>">
    </div>
    <div class="col-md-3">
        <label class="form-label">Filtrer par École</label>
        <select name="ecole_id" class="form-select">
            <option value="">Toutes les écoles</option>
            <?php foreach($ecoles as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $filtreEcole==$e['id']?'selected':'' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Statut Immatriculation</label>
        <select name="statut" class="form-select">
            <option value="">Tous</option>
            <option value="faite" <?= $filtreStatut=='faite'?'selected':'' ?>>Immatriculés</option>
            <option value="non_entamee" <?= $filtreStatut=='non_entamee'?'selected':'' ?>>Demande non faite</option>
            <option value="en_cours" <?= $filtreStatut=='en_cours'?'selected':'' ?>>En cours</option>
        </select>
    </div>
    <div class="col-md-2 d-flex align-items-end">
        <button type="submit" class="btn btn-outline-primary w-100">Filtrer</button>
    </div>
</form>

<!-- Tableau -->
<div class="card shadow-sm">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Identité</th>
                    <th>Sexe</th>
                    <th>École / Statut</th>
                    <th>Naissance</th>
                    <th>Matricule DSPS</th>
                    <th>Acte Naiss.</th>
                    <th>Statut Demande</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($candidats as $c): ?>
                <tr class="<?= $c['est_candidat_libre'] ? 'table-secondary' : '' ?>">
                    <td>
                        <strong><?= htmlspecialchars($c['nom']) ?> <?= htmlspecialchars($c['prenoms']) ?></strong>
                        <?php if($c['est_candidat_libre']): ?><br><span class="badge bg-dark">Candidat Libre</span><?php endif; ?>
                    </td>
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
                    <p>Le système mettra à jour les élèves existants (même nom/prénom/école) et ajoutera les nouveaux.</p>
                    <p class="small"><strong>Colonnes requises :</strong></p>
                    <ol class="small">
                        <li>Nom</li><li>Prénoms</li><li>Sexe (M/F)</li><li>Date Naissance (JJ/MM/AAAA)</li>
                        <li>Lieu Naissance</li><li>Matricule DSPS (laisser vide si aucun)</li>
                        <li>Acte Naissance (O/N)</li><li>Statut Demande (non_entamee/en_cours/faite)</li>
                        <li>Nom École (ou écrire <strong>LIBRE</strong> pour candidat libre)</li>
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
                        <div class="col-md-4 mb-2">
                            <label>Sexe *</label>
                            <select name="sexe" class="form-select"><option value="M">Garçon</option><option value="F">Fille</option></select>
                        </div>
                        <div class="col-md-4 mb-2"><label>Date Naissance</label><input type="date" name="date_naissance" class="form-control"></div>
                        <div class="col-md-4 mb-2"><label>Lieu Naissance</label><input type="text" name="lieu_naissance" class="form-control"></div>
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
</script>

<?php include '../views/layouts/footer.php'; ?>