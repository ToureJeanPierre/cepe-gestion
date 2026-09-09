<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Gestion du Personnel';

// Listes de référence utilisées à plusieurs endroits du fichier
const CATEGORIES_PERSONNEL = ['enseignant' => 'Enseignant', 'conseiller' => 'Conseiller', 'administratif' => 'Personnel Administratif'];
const SOUS_TYPES_CONSEILLER = ['Pédagogique', 'Extrascolaire'];
const FONCTIONS_ENSEIGNANT = ['Directeur (avec classe)', 'Directeur (sans classe)', 'Adjoint', 'Enseignant'];
const NIVEAUX = ['CP1', 'CP2', 'CE1', 'CE2', 'CM1', 'CM2'];
const DISPONIBILITES = ['En activité', 'Congé maternité', 'Congé maladie', 'Absent', 'Autre'];

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_personnel'])) {
    if (isset($_FILES['fichier_personnel']) && $_FILES['fichier_personnel']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier_personnel']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); // Saute l'en-tête

            $nbAjouts = 0;
            $nbMajs = 0;
            $erreurs = [];

            // Colonnes attendues :
            // A:Nom B:Prénoms C:Sexe D:Téléphone E:Catégorie F:Sous-type
            // G:NomÉcole(vide=Inspection) H:TypeÉcole I:Matricule
            // J:N°AutoEnseigner K:N°AutoDiriger L:NiveauTenu M:Emploi N:Grade
            // O:Fonction P:Disponibilité Q:PlusHautDiplôme R:PlusHautNiveauÉtude
            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    $nom = trim($row[0] ?? '');
                    if (empty($nom)) continue;
                    $prenoms = trim($row[1] ?? '');
                    $sexe = strtoupper(trim($row[2] ?? 'M')) === 'F' ? 'F' : 'M';
                    $telephone = trim($row[3] ?? '');

                    $categorieRaw = strtolower(trim($row[4] ?? 'enseignant'));
                    $categorie = array_key_exists($categorieRaw, CATEGORIES_PERSONNEL) ? $categorieRaw : 'enseignant';

                    $sousType = trim($row[5] ?? '') ?: null;

                    $nomEcole = trim($row[6] ?? '');
                    $ecoleId = null;
                    if (!empty($nomEcole)) {
                        $stmtEcole = $pdo->prepare("SELECT id, statut FROM ecoles WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) LIMIT 1");
                        $stmtEcole->execute([$nomEcole]);
                        $ecole = $stmtEcole->fetch();
                        if ($ecole) {
                            $ecoleId = (int) $ecole['id'];
                        } else {
                            $erreurs[] = "Ligne $numLigne : École '$nomEcole' introuvable (personnel enregistré sans école).";
                        }
                    }

                    $typeEcole = (strtolower(trim($row[7] ?? '')) === 'privé' || strtolower(trim($row[7] ?? '')) === 'prive') ? 'Privé' : 'Public';
                    $matricule = trim($row[8] ?? '') ?: null;
                    $numAutoEnseigner = trim($row[9] ?? '') ?: null;
                    $numAutoDiriger = trim($row[10] ?? '') ?: null;

                    $niveauVal = in_array(trim($row[11] ?? ''), NIVEAUX) ? trim($row[11]) : null;
                    $emploiVal = in_array(strtoupper(trim($row[12] ?? '')), ['IO', 'IA']) ? strtoupper(trim($row[12])) : null;
                    $gradeVal = trim($row[13] ?? '') ?: null;

                    $fonctionRaw = trim($row[14] ?? '');
                    $fonction = !empty($fonctionRaw) ? $fonctionRaw : ($categorie === 'enseignant' ? 'Adjoint' : ($categorie === 'conseiller' ? 'Conseiller' : 'Agent Administratif'));

                    $dispRaw = trim($row[15] ?? '');
                    $disponibilite = in_array($dispRaw, DISPONIBILITES) ? $dispRaw : 'En activité';

                    $diplome = trim($row[16] ?? '') ?: null;
                    $niveauEtude = trim($row[17] ?? '') ?: null;

                    // Vérification Doublon : par Matricule (Public) ou N° d'autorisation (Privé) si
                    // disponible — clés les plus fiables — sinon par Nom + Prénoms + École.
                    $existing = null;
                    if (!empty($matricule)) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE matricule = ?");
                        $checkStmt->execute([$matricule]);
                        $existing = $checkStmt->fetch();
                    }
                    if (!$existing && (!empty($numAutoEnseigner) || !empty($numAutoDiriger))) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE (numero_autorisation_enseigner IS NOT NULL AND numero_autorisation_enseigner = ?) OR (numero_autorisation_diriger IS NOT NULL AND numero_autorisation_diriger = ?)");
                        $checkStmt->execute([$numAutoEnseigner ?? '', $numAutoDiriger ?? '']);
                        $existing = $checkStmt->fetch();
                    }
                    if (!$existing) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) AND LOWER(TRIM(prenoms)) = LOWER(TRIM(?)) AND (ecole_id <=> ?)");
                        $checkStmt->execute([$nom, $prenoms, $ecoleId]);
                        $existing = $checkStmt->fetch();
                    }

                    if ($existing) {
                        $upd = $pdo->prepare("
                            UPDATE personnel SET ecole_id=?, categorie=?, sous_type=?, sexe=?, telephone=?, type_ecole=?, matricule=?,
                                numero_autorisation_enseigner=?, numero_autorisation_diriger=?, niveau_tenu=?, emploi=?, grade=?,
                                fonction=?, disponibilite=?, plus_haut_diplome=?, plus_haut_niveau_etude=?
                            WHERE id=?
                        ");
                        $upd->execute([
                            $ecoleId, $categorie, $sousType, $sexe, $telephone, $typeEcole, $matricule,
                            $numAutoEnseigner, $numAutoDiriger, $niveauVal, $emploiVal, $gradeVal, $fonction, $disponibilite,
                            $diplome, $niveauEtude, $existing['id'],
                        ]);
                        $nbMajs++;
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO personnel (annee_id, ecole_id, categorie, sous_type, nom, prenoms, sexe, telephone, type_ecole, matricule, numero_autorisation_enseigner, numero_autorisation_diriger, niveau_tenu, emploi, grade, fonction, disponibilite, plus_haut_diplome, plus_haut_niveau_etude)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $anneeActive['id'] ?? $anneeId, $ecoleId, $categorie, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule,
                            $numAutoEnseigner, $numAutoDiriger, $niveauVal, $emploiVal, $gradeVal, $fonction, $disponibilite,
                            $diplome, $niveauEtude,
                        ]);
                        $nbAjouts++;
                    }
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            $msg = "Import terminé : $nbAjouts ajouté(s), $nbMajs mis à jour.";
            if (!empty($erreurs)) {
                $msg .= " <br><small>" . count($erreurs) . " remarque(s) (voir détail ci-dessous).</small>";
                $_SESSION['import_erreurs_personnel'] = $erreurs;
            } else {
                unset($_SESSION['import_erreurs_personnel']);
            }
            header("Location: enseignants.php?msg=" . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $error = "Erreur fichier : " . $e->getMessage();
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $pdo->prepare("DELETE FROM personnel WHERE id = ?")->execute([(int) $_GET['supprimer']]);
    header("Location: enseignants.php");
    exit;
}

// ==========================================
// TRAITEMENT : AJOUT / MODIFICATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajouter']) || isset($_POST['modifier']))) {
    $categorie = array_key_exists($_POST['categorie'] ?? '', CATEGORIES_PERSONNEL) ? $_POST['categorie'] : 'enseignant';
    $sousType = trim($_POST['sous_type'] ?? '') ?: null;
    $ecoleId = !empty($_POST['ecole_id']) ? (int) $_POST['ecole_id'] : null;
    $nom = trim($_POST['nom'] ?? '');
    $prenoms = trim($_POST['prenoms'] ?? '');
    $sexe = $_POST['sexe'] ?? 'M';
    $telephone = trim($_POST['telephone'] ?? '');
    $typeEcole = $_POST['type_ecole'] ?? 'Public';
    $matricule = trim($_POST['matricule'] ?? '') ?: null;
    $numAutoEnseigner = trim($_POST['numero_autorisation_enseigner'] ?? '') ?: null;
    $numAutoDiriger = trim($_POST['numero_autorisation_diriger'] ?? '') ?: null;
    $niveau = $_POST['niveau_tenu'] ?: null;
    $emploi = $_POST['emploi'] ?: null;
    $grade = trim($_POST['grade'] ?? '') ?: null;
    $fonction = trim($_POST['fonction'] ?? '') ?: 'Adjoint';
    $disponibilite = $_POST['disponibilite'] ?? 'En activité';
    $diplome = trim($_POST['plus_haut_diplome'] ?? '') ?: null;
    $niveauEtude = trim($_POST['plus_haut_niveau_etude'] ?? '') ?: null;

    if (empty($nom) || empty($prenoms)) {
        $error = "Nom et Prénoms obligatoires.";
    } else {
        if (isset($_POST['ajouter'])) {
            $stmt = $pdo->prepare("
                INSERT INTO personnel (annee_id, ecole_id, categorie, sous_type, nom, prenoms, sexe, telephone, type_ecole, matricule, numero_autorisation_enseigner, numero_autorisation_diriger, niveau_tenu, emploi, grade, fonction, disponibilite, plus_haut_diplome, plus_haut_niveau_etude)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$anneeActive['id'] ?? $anneeId, $ecoleId, $categorie, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $numAutoEnseigner, $numAutoDiriger, $niveau, $emploi, $grade, $fonction, $disponibilite, $diplome, $niveauEtude]);
        } elseif (isset($_POST['modifier'])) {
            $id = (int) $_POST['id'];
            $stmt = $pdo->prepare("
                UPDATE personnel SET ecole_id=?, categorie=?, sous_type=?, nom=?, prenoms=?, sexe=?, telephone=?, type_ecole=?, matricule=?, numero_autorisation_enseigner=?, numero_autorisation_diriger=?, niveau_tenu=?, emploi=?, grade=?, fonction=?, disponibilite=?, plus_haut_diplome=?, plus_haut_niveau_etude=?
                WHERE id=?
            ");
            $stmt->execute([$ecoleId, $categorie, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $numAutoEnseigner, $numAutoDiriger, $niveau, $emploi, $grade, $fonction, $disponibilite, $diplome, $niveauEtude, $id]);
        }
        header("Location: enseignants.php");
        exit;
    }
}

// ==========================================
// FILTRES & DONNÉES
// ==========================================
$filtreCategorie = $_GET['categorie'] ?? '';
$filtreEcole = $_GET['ecole_id'] ?? '';
$filtreDispo = $_GET['disponibilite'] ?? '';
$filtreRecherche = $_GET['q'] ?? '';

$sql = "SELECT p.*, ec.nom as ecole_nom FROM personnel p LEFT JOIN ecoles ec ON p.ecole_id = ec.id WHERE 1=1";
$params = [];

if ($filtreCategorie) {
    $sql .= " AND p.categorie = ?";
    $params[] = $filtreCategorie;
}
if ($filtreEcole) {
    $sql .= " AND p.ecole_id = ?";
    $params[] = $filtreEcole;
}
if ($filtreDispo) {
    $sql .= " AND p.disponibilite = ?";
    $params[] = $filtreDispo;
}
if ($filtreRecherche) {
    $sql .= " AND (p.nom LIKE ? OR p.prenoms LIKE ? OR p.matricule LIKE ?)";
    $t = "%$filtreRecherche%";
    $params[] = $t; $params[] = $t; $params[] = $t;
}

$sql .= " ORDER BY p.nom ASC, p.prenoms ASC";
$stmtListe = $pdo->prepare($sql);
$stmtListe->execute($params);
$personnels = $stmtListe->fetchAll();

$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN categorie='enseignant' THEN 1 ELSE 0 END) as nb_enseignants,
        SUM(CASE WHEN categorie='conseiller' THEN 1 ELSE 0 END) as nb_conseillers,
        SUM(CASE WHEN categorie='administratif' THEN 1 ELSE 0 END) as nb_administratifs,
        SUM(CASE WHEN fonction LIKE 'Directeur%' THEN 1 ELSE 0 END) as nb_directeurs,
        SUM(CASE WHEN disponibilite='En activité' THEN 1 ELSE 0 END) as nb_actifs
    FROM personnel
")->fetch();

$personnelAModifier = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([(int) $_GET['modifier']]);
    $personnelAModifier = $stmt->fetch();
}

include '../views/layouts/header.php';
?>

<!-- Alertes -->
<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $_GET['msg'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($_SESSION['import_erreurs_personnel'])): ?>
    <div class="alert alert-warning">
        <button class="btn btn-sm btn-outline-dark mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#detailErreursPersonnel">
            <i class="bi bi-list-ul"></i> Voir le détail (<?= count($_SESSION['import_erreurs_personnel']) ?>)
        </button>
        <div class="collapse" id="detailErreursPersonnel">
            <div style="max-height: 300px; overflow-y: auto;">
                <ul class="mb-0 small">
                    <?php foreach ($_SESSION['import_erreurs_personnel'] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['import_erreurs_personnel']); ?>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>👥 Gestion du Personnel</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter</button>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-2"><?php statCard('bi-person-badge', 'navy', (string) $stats['total'], 'Total'); ?></div>
    <div class="col-md-2"><?php statCard('bi-easel', 'blue', (string) $stats['nb_enseignants'], 'Enseignants'); ?></div>
    <div class="col-md-2"><?php statCard('bi-person-lines-fill', 'purple', (string) $stats['nb_conseillers'], 'Conseillers'); ?></div>
    <div class="col-md-3"><?php statCard('bi-briefcase', 'orange', (string) $stats['nb_administratifs'], 'Personnel Admin.'); ?></div>
    <div class="col-md-3"><?php statCard('bi-check-circle', 'green', (string) $stats['nb_actifs'], 'En activité'); ?></div>
</div>

<!-- Filtres -->
<form method="GET" id="formFiltresPersonnel" class="row g-3 mb-4 p-3 bg-light rounded">
    <div class="col-md-3">
        <label class="form-label">Rechercher (Nom/Matricule)</label>
        <input type="text" name="q" id="inputReecherchePersonnel" class="form-control" value="<?= htmlspecialchars($filtreRecherche) ?>" autocomplete="off">
    </div>
    <div class="col-md-3">
        <label class="form-label">Catégorie</label>
        <select name="categorie" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php foreach (CATEGORIES_PERSONNEL as $val => $label): ?>
                <option value="<?= $val ?>" <?= $filtreCategorie === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">École</label>
        <select name="ecole_id" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les écoles</option>
            <?php foreach ($ecoles as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $filtreEcole == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Disponibilité</label>
        <select name="disponibilite" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php foreach (DISPONIBILITES as $d): ?>
                <option value="<?= $d ?>" <?= $filtreDispo === $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <a href="enseignants.php" class="btn btn-outline-secondary w-100" title="Réinitialiser"><i class="bi bi-x-lg"></i></a>
    </div>
</form>
<script>
(function () {
    var champ = document.getElementById('inputReecherchePersonnel');
    var minuteur;
    champ.addEventListener('input', function () {
        clearTimeout(minuteur);
        minuteur = setTimeout(function () {
            document.getElementById('formFiltresPersonnel').submit();
        }, 600);
    });
})();
</script>

<!-- Barre d'actions en masse (apparaît dès qu'au moins 1 personnel est sélectionné) -->
<div class="alert alert-primary d-none align-items-center justify-content-between py-2 mb-3" id="barreActionsMassePersonnel">
    <span><strong id="nbSelectionnesPersonnel">0</strong> personnel(s) sélectionné(s)</span>
    <button type="button" class="btn btn-sm btn-danger" onclick="actionMassePersonnel('supprimer')"><i class="bi bi-trash"></i> Supprimer</button>
</div>

<!-- Tableau -->
<div class="card shadow">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><input type="checkbox" id="checkToutPersonnel" onclick="toggleTousPersonnel(this)"></th>
                    <th>Nom</th>
                    <th>Prénoms</th>
                    <th>Sexe</th>
                    <th>Catégorie</th>
                    <th>Fonction</th>
                    <th>École / Rattachement</th>
                    <th>Niveau tenu</th>
                    <th>Disponibilité</th>
                    <th>Téléphone</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($personnels as $p): ?>
                <tr>
                    <td><input type="checkbox" class="check-personnel" value="<?= $p['id'] ?>" onchange="majBarreActionsPersonnel()"></td>
                    <td><strong><?= htmlspecialchars($p['nom']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($p['matricule'] ?? '') ?></small></td>
                    <td><?= htmlspecialchars($p['prenoms']) ?></td>
                    <td><span class="badge bg-<?= $p['sexe'] == 'M' ? 'primary' : 'danger' ?>"><?= $p['sexe'] ?></span></td>
                    <td>
                        <?php
                        $badgeCat = ['enseignant' => 'info', 'conseiller' => 'secondary', 'administratif' => 'warning'];
                        ?>
                        <span class="badge bg-<?= $badgeCat[$p['categorie']] ?? 'secondary' ?> <?= $p['categorie'] === 'administratif' ? 'text-dark' : '' ?>">
                            <?= htmlspecialchars(CATEGORIES_PERSONNEL[$p['categorie']] ?? $p['categorie']) ?>
                        </span>
                        <?php if ($p['sous_type']): ?><br><small class="text-muted"><?= htmlspecialchars($p['sous_type']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['fonction']) ?></td>
                    <td>
                        <?php if ($p['ecole_nom']): ?>
                            <?= htmlspecialchars($p['ecole_nom']) ?>
                            <br><small class="text-muted"><?= htmlspecialchars($p['type_ecole']) ?></small>
                        <?php else: ?>
                            <span class="text-muted">Inspection (IEPP)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['niveau_tenu'] ? htmlspecialchars($p['niveau_tenu']) : '<span class="text-muted">Sans classe</span>' ?></td>
                    <td>
                        <?php
                        $badgeDispo = $p['disponibilite'] === 'En activité' ? 'success' : (in_array($p['disponibilite'], ['Absent']) ? 'danger' : 'secondary');
                        ?>
                        <span class="badge bg-<?= $badgeDispo ?>"><?= htmlspecialchars($p['disponibilite']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($p['telephone']) ?></td>
                    <td>
                        <a href="?modifier=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="?supprimer=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer cette personne ?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($personnels)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Aucune personne ne correspond à ces filtres.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal IMPORT -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Importer le Personnel (Excel)</h5>
                    <a href="enseignants.php" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body">
                    <p class="small mb-1">Colonnes attendues, dans cet ordre :</p>
                    <ol class="small">
                        <li>Nom</li><li>Prénoms</li><li>Sexe (M/F)</li><li>Téléphone</li>
                        <li>Catégorie (Enseignant / Conseiller / Administratif — vide = Enseignant)</li>
                        <li>Sous-type (Pédagogique/Extrascolaire pour un Conseiller ; intitulé libre pour un Administratif)</li>
                        <li>Nom de l'école exact (laisser vide si rattaché à l'Inspection)</li>
                        <li>Type École (Public/Privé)</li>
                        <li>Matricule (agent de l'État)</li>
                        <li>N° Autorisation d'Enseigner (Privé, Adjoints)</li>
                        <li>N° Autorisation de Diriger (Privé, Directeurs)</li>
                        <li>Niveau tenu (CP1 à CM2 — vide = Sans classe)</li>
                        <li>Emploi (IO/IA)</li>
                        <li>Grade</li>
                        <li>Fonction (ex : Directeur (avec classe), Adjoint, Conseiller Pédagogique...)</li>
                        <li>Disponibilité (En activité / Congé maternité / Congé maladie / Absent / Autre)</li>
                        <li>Plus haut diplôme obtenu</li>
                        <li>Plus haut niveau d'étude atteint</li>
                    </ol>
                    <input type="file" name="fichier_personnel" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="importer_personnel" class="btn btn-success">Importer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal AJOUT -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="formAjoutPersonnel">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Ajouter une personne</h5>
                    <a href="enseignants.php" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body">
                    <?php include 'partials/personnel_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="ajouter" class="btn btn-primary">Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal MODIFICATION -->
<?php if ($personnelAModifier): $p = $personnelAModifier; ?>
<div class="modal fade show" id="modalEdit" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5)">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="formEditPersonnel">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Modifier</h5>
                    <a href="enseignants.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <?php include 'partials/personnel_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="modifier" class="btn btn-warning">Mettre à jour</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Affiche/masque ET active/désactive les champs selon la catégorie choisie
// (Enseignant / Conseiller / Administratif). Désactiver les champs masqués
// évite les conflits quand deux champs partagent le même name="" (ex: Fonction).
function ajusterChampsCategorie(select) {
    var form = select.closest('form');
    var categorie = select.value;
    form.querySelectorAll('[data-categorie]').forEach(function (bloc) {
        var categoriesAutorisees = bloc.getAttribute('data-categorie').split(',');
        var visible = categoriesAutorisees.includes(categorie);
        bloc.style.display = visible ? '' : 'none';
        bloc.querySelectorAll('input, select, textarea').forEach(function (champ) {
            champ.disabled = !visible;
        });
    });
}
// Applique l'état initial de chaque formulaire déjà présent dans la page (édition)
document.querySelectorAll('select[name="categorie"]').forEach(ajusterChampsCategorie);

function toggleTousPersonnel(caseTete) {
    document.querySelectorAll('.check-personnel').forEach(function (c) { c.checked = caseTete.checked; });
    majBarreActionsPersonnel();
}

function majBarreActionsPersonnel() {
    var coches = document.querySelectorAll('.check-personnel:checked');
    var barre = document.getElementById('barreActionsMassePersonnel');
    document.getElementById('nbSelectionnesPersonnel').textContent = coches.length;
    barre.classList.toggle('d-none', coches.length === 0);
    barre.classList.toggle('d-flex', coches.length > 0);
}

async function actionMassePersonnel(action) {
    var ids = Array.from(document.querySelectorAll('.check-personnel:checked')).map(function (c) { return c.value; });
    if (ids.length === 0) return;

    if (action === 'supprimer' && !confirm('Supprimer ' + ids.length + ' membre(s) du personnel ? Cette action est irréversible.')) {
        return;
    }

    try {
        var reponse = await fetch('api_bulk_personnel.php', {
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
