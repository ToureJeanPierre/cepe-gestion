<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Gestion des Enseignants';

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL (CORRIGÉ)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_enseignants'])) {
    if (isset($_FILES['fichier_enseignants']) && $_FILES['fichier_enseignants']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier_enseignants']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); // Sauter l'en-tête

            $nbAjouts = 0;
            $erreurs = [];

            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    // Lecture sécurisée (évite les warnings null)
                    $nom = trim($row[0] ?? '');
                    $prenoms = trim($row[1] ?? '');
                    $sexe = strtoupper(trim($row[2] ?? 'M')) === 'F' ? 'F' : 'M';
                    $telephone = trim($row[3] ?? '');
                    
                    // Recherche ID école par nom exact
                    $nomEcole = trim($row[4] ?? '');
                    $stmtEcole = $pdo->prepare("SELECT id FROM ecoles WHERE nom = ? LIMIT 1");
                    $stmtEcole->execute([$nomEcole]);
                    $ecole = $stmtEcole->fetch();
                    // Si l'école n'existe pas, on prend la première par défaut pour éviter l'erreur FK
                    $ecoleId = $ecole ? (int)$ecole['id'] : 1; 

                    $typeEcole = in_array(trim($row[5] ?? ''), ['Privé']) ? 'Privé' : 'Public';
                    $matricule = trim($row[6] ?? '');
                    // Colonne 7 (N° Auto) supprimée, on décale : Niveau est maintenant index 7
                    $niveau = trim($row[7] ?? '');
                    $emploi = trim($row[8] ?? '');
                    $grade = trim($row[9] ?? '');
                    
                    // Gestion Fonction (Colonne 10 - Index 9) : Défaut 'Adjoint'
                    $fonctionRaw = trim($row[9] ?? ''); // Attention: si on a supprimé une col, les index changent. 
                    // Recalculons les index basés sur 12 colonnes : 
                    // 0:Nom, 1:Prenoms, 2:Sexe, 3:Tel, 4:Ecole, 5:Type, 6:Matricule, 7:Niveau, 8:Emploi, 9:Grade, 10:Fonction, 11:Disp
                    // Correction des index suite à la suppression de N°Auto :
                    // 0:Nom, 1:Prenoms, 2:Sexe, 3:Tel, 4:Ecole, 5:Type, 6:Matricule, 7:Niveau, 8:Emploi, 9:Grade, 10:Fonction, 11:Disp
                    // Attends, dans ton fichier Excel précédent : 
                    // A:Nom, B:Prenoms, C:Sexe, D:Tel, E:Ecole, F:Type, G:Matricule, H:Niveau, I:Emploi, J:Grade, K:Fonction, L:Disp
                    // Donc indices : 0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11
                    
                    $niveauVal = in_array(trim($row[7] ?? ''), ['CP1','CP2','CE1','CE2','CM1','CM2']) ? trim($row[7]) : null;
                    $emploiVal = in_array(strtoupper(trim($row[8] ?? '')), ['IO','IA']) ? strtoupper(trim($row[8])) : null;
                    $gradeVal = trim($row[9] ?? '');
                    
                    $fonctionRaw = trim($row[10] ?? '');
                    $fonction = in_array($fonctionRaw, ['Directeur', 'Adjoint', 'Enseignant']) ? $fonctionRaw : 'Adjoint'; // DÉFAUT ADJOINT
                    
                    $dispRaw = trim($row[11] ?? '');
                    $disponibilite = in_array($dispRaw, ['Disponible', 'Malade', 'Congé', 'Absent']) ? $dispRaw : 'Disponible';

                    if (empty($nom)) continue;

                    $stmt = $pdo->prepare("
                        INSERT INTO enseignants (ecole_id, nom, prenoms, sexe, telephone, type_ecole, matricule, niveau_tenu, emploi, grade, fonction, disponibilite)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $ecoleId, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, 
                        $niveauVal, $emploiVal, $gradeVal, $fonction, $disponibilite
                    ]);
                    $nbAjouts++;
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . htmlspecialchars($e->getMessage());
                }
            }

            if (empty($erreurs)) {
                header("Location: enseignants.php?msg=import_ok&count=$nbAjouts");
                exit;
            } else {
                $error = "⚠️ Import partiel : $nbAjouts ajout(s). Erreurs :<ul><li>" . implode('</li><li>', $erreurs) . "</li></ul>";
            }
        } catch (Exception $e) {
            $error = "Erreur fichier : " . htmlspecialchars($e->getMessage());
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $id = (int)$_GET['supprimer'];
    $pdo->prepare("DELETE FROM enseignants WHERE id = ?")->execute([$id]);
    header("Location: enseignants.php");
    exit;
}

// ==========================================
// TRAITEMENT : AJOUT / MODIFICATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajouter']) || isset($_POST['modifier']))) {
    $ecoleId = (int)$_POST['ecole_id'];
    $nom = trim($_POST['nom'] ?? '');
    $prenoms = trim($_POST['prenoms'] ?? '');
    $sexe = $_POST['sexe'] ?? 'M';
    $telephone = trim($_POST['telephone'] ?? '');
    $typeEcole = $_POST['type_ecole'] ?? 'Public';
    $matricule = trim($_POST['matricule'] ?? '');
    $niveau = $_POST['niveau_tenu'] ?: null;
    $emploi = $_POST['emploi'] ?: null;
    $grade = trim($_POST['grade'] ?? '');
    $fonction = $_POST['fonction'] ?? 'Adjoint'; // Défaut Adjoint
    $disponibilite = $_POST['disponibilite'] ?? 'Disponible';

    if (empty($nom) || empty($prenoms)) {
        $error = "Nom et Prénoms obligatoires.";
    } else {
        if (isset($_POST['ajouter'])) {
            $stmt = $pdo->prepare("
                INSERT INTO enseignants (ecole_id, nom, prenoms, sexe, telephone, type_ecole, matricule, niveau_tenu, emploi, grade, fonction, disponibilite)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ecoleId, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $niveau, $emploi, $grade, $fonction, $disponibilite]);
        } elseif (isset($_POST['modifier'])) {
            $id = (int)$_POST['id'];
            $stmt = $pdo->prepare("
                UPDATE enseignants SET ecole_id=?, nom=?, prenoms=?, sexe=?, telephone=?, type_ecole=?, matricule=?, niveau_tenu=?, emploi=?, grade=?, fonction=?, disponibilite=?
                WHERE id=?
            ");
            $stmt->execute([$ecoleId, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $niveau, $emploi, $grade, $fonction, $disponibilite, $id]);
        }
        header("Location: enseignants.php");
        exit;
    }
}

// ==========================================
// DONNÉES
// ==========================================
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll();

$stats = $pdo->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN sexe='M' THEN 1 ELSE 0 END) as hommes,
        SUM(CASE WHEN sexe='F' THEN 1 ELSE 0 END) as femmes,
        SUM(CASE WHEN fonction='Directeur' THEN 1 ELSE 0 END) as directeurs,
        SUM(CASE WHEN disponibilite='Disponible' THEN 1 ELSE 0 END) as disponibles
    FROM enseignants
")->fetch();

$enseignants = $pdo->query("
    SELECT e.*, ec.nom as ecole_nom 
    FROM enseignants e 
    JOIN ecoles ec ON e.ecole_id = ec.id 
    ORDER BY e.nom ASC
")->fetchAll();

$enseignantToEdit = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM enseignants WHERE id = ?");
    $stmt->execute([(int)$_GET['modifier']]);
    $enseignantToEdit = $stmt->fetch();
}

include '../views/layouts/header.php';
?>

<!-- Alertes -->
<?php if (isset($_GET['msg']) && $_GET['msg'] == 'import_ok'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Importation réussie ! <?= $_GET['count'] ?? 0 ?> enseignant(s) ajouté(s).
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>👨‍🏫 Gestion des Enseignants</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter</button>
    </div>
</div>

<!-- Stats -->
<div class="row mb-4">
    <div class="col-md-2"><div class="card text-center bg-primary text-white"><div class="card-body"><h6>Total</h6><h3><?= $stats['total'] ?></h3></div></div></div>
    <div class="col-md-2"><div class="card text-center bg-info text-white"><div class="card-body"><h6>Hommes</h6><h3><?= $stats['hommes'] ?></h3></div></div></div>
    <div class="col-md-2"><div class="card text-center bg-danger text-white"><div class="card-body"><h6>Femmes</h6><h3><?= $stats['femmes'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card text-center bg-warning text-dark"><div class="card-body"><h6>DIRECTEURS</h6><h3><?= $stats['directeurs'] ?></h3></div></div></div>
    <div class="col-md-3"><div class="card text-center bg-success text-white"><div class="card-body"><h6>Disponibles</h6><h3><?= $stats['disponibles'] ?></h3></div></div></div>
</div>

<!-- Tableau -->
<div class="card shadow">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nom & Prénoms</th>
                    <th>Sexe</th>
                    <th>École</th>
                    <th>Type</th>
                    <th>Fonction</th>
                    <th>Disponibilité</th>
                    <th>Téléphone</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($enseignants as $ens): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($ens['nom']) ?> <?= htmlspecialchars($ens['prenoms']) ?></strong><br>
                        <small class="text-muted"><?= htmlspecialchars($ens['matricule'] ?? '') ?></small>
                    </td>
                    <td><span class="badge bg-<?= $ens['sexe']=='M'?'primary':'danger' ?>"><?= $ens['sexe'] ?></span></td>
                    <td><?= htmlspecialchars($ens['ecole_nom']) ?></td>
                    <td><span class="badge bg-<?= $ens['type_ecole']=='Public'?'success':'warning' ?>"><?= $ens['type_ecole'] ?></span></td>
                    <td>
                        <?php if($ens['fonction']=='Directeur'): ?>
                            <span class="badge bg-primary">🏛️ Directeur</span>
                        <?php else: ?>
                            <span class="badge bg-info">📎 Adjoint/Ens.</span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge bg-<?= $ens['disponibilite']=='Disponible'?'success':'secondary' ?>"><?= $ens['disponibilite'] ?></span></td>
                    <td><?= htmlspecialchars($ens['telephone']) ?></td>
                    <td>
                        <a href="?modifier=<?= $ens['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="?supprimer=<?= $ens['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer ?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal IMPORT -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Importer Enseignants (Excel)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small">Colonnes requises (12 colonnes) :</p>
                    <ol class="small">
                        <li>Nom</li><li>Prénoms</li><li>Sexe (M/F)</li><li>Téléphone</li>
                        <li>Nom de l'école (Exact)</li><li>Type École (Public/Privé)</li>
                        <li>Matricule</li><li>Niveau (CP1..CM2)</li><li>Emploi (IO/IA)</li>
                        <li>Grade</li><li>Fonction (Directeur/Adjoint - Vide=Adjoint)</li>
                        <li>Disponibilité (Disponible/Malade/Congé)</li>
                    </ol>
                    <div class="alert alert-info py-1 small">Note : Si la colonne "Fonction" est vide, l'enseignant sera enregistré comme <strong>Adjoint</strong>.</div>
                    <input type="file" name="fichier_enseignants" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="importer_enseignants" class="btn btn-success">Importer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal AJOUT -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Ajouter un enseignant</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Nom *</label><input type="text" name="nom" class="form-control" required></div>
                        <div class="col-md-4 mb-2"><label>Prénoms *</label><input type="text" name="prenoms" class="form-control" required></div>
                        <div class="col-md-4 mb-2"><label>Sexe *</label><select name="sexe" class="form-select"><option value="M">M</option><option value="F">F</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Téléphone</label><input type="text" name="telephone" class="form-control"></div>
                        <div class="col-md-4 mb-2"><label>Type École *</label><select name="type_ecole" class="form-select"><option value="Public">Public</option><option value="Privé">Privé</option></select></div>
                        <div class="col-md-4 mb-2"><label>École *</label>
                            <select name="ecole_id" class="form-select" required>
                                <option value="">-- Choisir --</option>
                                <?php foreach($ecoles as $ec): ?><option value="<?= $ec['id'] ?>"><?= htmlspecialchars($ec['nom']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Matricule</label><input type="text" name="matricule" class="form-control"></div>
                        <div class="col-md-4 mb-2"><label>Grade</label><input type="text" name="grade" class="form-control"></div>
                        <div class="col-md-4 mb-2"><label>Fonction *</label>
                            <select name="fonction" class="form-select">
                                <option value="Adjoint" selected>Adjoint (Défaut)</option>
                                <option value="Directeur">Directeur</option>
                                <option value="Enseignant">Enseignant</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-2"><label>Niveau tenu</label>
                            <select name="niveau_tenu" class="form-select">
                                <option value="">-- Aucun --</option>
                                <?php foreach(['CP1','CP2','CE1','CE2','CM1','CM2'] as $n): ?><option value="<?= $n ?>"><?= $n ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2"><label>Emploi</label>
                            <select name="emploi" class="form-select">
                                <option value="">-- Aucun --</option>
                                <option value="IO">IO</option><option value="IA">IA</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2"><label>Disponibilité</label>
                            <select name="disponibilite" class="form-select">
                                <option value="Disponible" selected>Disponible</option>
                                <option value="Malade">Malade</option>
                                <option value="Congé">Congé</option>
                                <option value="Absent">Absent</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="ajouter" class="btn btn-primary">Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal MODIFICATION -->
<?php if ($enseignantToEdit): ?>
<div class="modal fade show" id="modalEdit" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5)">
    <div class="modal-dialog modal-lg">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Modifier</h5>
                    <a href="enseignants.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $enseignantToEdit['id'] ?>">
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Nom</label><input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($enseignantToEdit['nom']) ?>" required></div>
                        <div class="col-md-4 mb-2"><label>Prénoms</label><input type="text" name="prenoms" class="form-control" value="<?= htmlspecialchars($enseignantToEdit['prenoms']) ?>" required></div>
                        <div class="col-md-4 mb-2"><label>Sexe</label><select name="sexe" class="form-select"><option value="M" <?= $enseignantToEdit['sexe']=='M'?'selected':'' ?>>M</option><option value="F" <?= $enseignantToEdit['sexe']=='F'?'selected':'' ?>>F</option></select></div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Téléphone</label><input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($enseignantToEdit['telephone']) ?>"></div>
                        <div class="col-md-4 mb-2"><label>Type École</label><select name="type_ecole" class="form-select"><option value="Public" <?= $enseignantToEdit['type_ecole']=='Public'?'selected':'' ?>>Public</option><option value="Privé" <?= $enseignantToEdit['type_ecole']=='Privé'?'selected':'' ?>>Privé</option></select></div>
                        <div class="col-md-4 mb-2"><label>École</label>
                            <select name="ecole_id" class="form-select" required>
                                <?php foreach($ecoles as $ec): ?><option value="<?= $ec['id'] ?>" <?= $ec['id']==$enseignantToEdit['ecole_id']?'selected':'' ?>><?= htmlspecialchars($ec['nom']) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <hr>
                    <div class="row">
                        <div class="col-md-4 mb-2"><label>Matricule</label><input type="text" name="matricule" class="form-control" value="<?= htmlspecialchars($enseignantToEdit['matricule']) ?>"></div>
                        <div class="col-md-4 mb-2"><label>Grade</label><input type="text" name="grade" class="form-control" value="<?= htmlspecialchars($enseignantToEdit['grade']) ?>"></div>
                        <div class="col-md-4 mb-2"><label>Fonction</label>
                            <select name="fonction" class="form-select">
                                <option value="Directeur" <?= $enseignantToEdit['fonction']=='Directeur'?'selected':'' ?>>Directeur</option>
                                <option value="Adjoint" <?= $enseignantToEdit['fonction']=='Adjoint'?'selected':'' ?>>Adjoint</option>
                                <option value="Enseignant" <?= $enseignantToEdit['fonction']=='Enseignant'?'selected':'' ?>>Enseignant</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-3 mb-2"><label>Niveau</label>
                            <select name="niveau_tenu" class="form-select">
                                <option value="">-- Aucun --</option>
                                <?php foreach(['CP1','CP2','CE1','CE2','CM1','CM2'] as $n): ?><option value="<?= $n ?>" <?= $enseignantToEdit['niveau_tenu']==$n?'selected':'' ?>><?= $n ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2"><label>Emploi</label>
                            <select name="emploi" class="form-select">
                                <option value="">-- Aucun --</option>
                                <option value="IO" <?= $enseignantToEdit['emploi']=='IO'?'selected':'' ?>>IO</option><option value="IA" <?= $enseignantToEdit['emploi']=='IA'?'selected':'' ?>>IA</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-2"><label>Disponibilité</label>
                            <select name="disponibilite" class="form-select">
                                <option value="Disponible" <?= $enseignantToEdit['disponibilite']=='Disponible'?'selected':'' ?>>Disponible</option>
                                <option value="Malade" <?= $enseignantToEdit['disponibilite']=='Malade'?'selected':'' ?>>Malade</option>
                                <option value="Congé" <?= $enseignantToEdit['disponibilite']=='Congé'?'selected':'' ?>>Congé</option>
                                <option value="Absent" <?= $enseignantToEdit['disponibilite']=='Absent'?'selected':'' ?>>Absent</option>
                            </select>
                        </div>
                    </div>
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

<?php include '../views/layouts/footer.php'; ?>