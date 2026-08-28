<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Centres d\'Examen';

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer'])) {
    if (isset($_FILES['fichier']) && $_FILES['fichier']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); 

            $count = 0;
            foreach ($rows as $row) {
                $nomCentre = trim($row[0] ?? '');
                $nomEcole  = trim($row[1] ?? '');
                if (empty($nomCentre) || empty($nomEcole)) continue;

                // Trouver ou créer le centre
                $stmtC = $pdo->prepare("SELECT c.id FROM centres c JOIN ecoles e ON c.ecole_id = e.id WHERE e.nom = ? LIMIT 1");
                $stmtC->execute([$nomCentre]);
                $centreData = $stmtC->fetch();

                if (!$centreData) {
                    $stmtFind = $pdo->prepare("SELECT id FROM ecoles WHERE nom = ? LIMIT 1");
                    $stmtFind->execute([$nomCentre]);
                    $obj = $stmtFind->fetch();
                    if ($obj) {
                        $pdo->prepare("INSERT INTO centres (ecole_id) VALUES (?)")->execute([$obj['id']]);
                        $centreId = $pdo->lastInsertId();
                    } else { continue; }
                } else {
                    $centreId = $centreData['id'];
                }

                // Trouver l'école rattachée
                $stmtE = $pdo->prepare("SELECT id FROM ecoles WHERE nom = ? LIMIT 1");
                $stmtE->execute([$nomEcole]);
                $objEcole = $stmtE->fetch();

                if ($objEcole) {
                    try {
                        $pdo->prepare("INSERT IGNORE INTO ecole_centre (ecole_id, centre_id, annee_id) VALUES (?, ?, 1)")
                            ->execute([$objEcole['id'], $centreId]);
                        $count++;
                    } catch (Exception $e) {}
                }
            }
            header("Location: centres.php?msg=ok&count=$count");
            exit;
        } catch (Exception $e) {
            $error = "Erreur import : " . $e->getMessage();
        }
    }
}

// ==========================================
// TRAITEMENT : CRÉER UN NOUVEAU CENTRE
// ==========================================
if (isset($_POST['creer_centre'])) {
    $ecoleId = (int)$_POST['ecole_id'];
    try {
        $pdo->prepare("INSERT INTO centres (ecole_id) VALUES (?)")->execute([$ecoleId]);
        header("Location: centres.php");
        exit;
    } catch (Exception $e) {
        $error = "Cette école est déjà un centre.";
    }
}

// ==========================================
// TRAITEMENT : AJOUTER UNE ÉCOLE À UN CENTRE (MANUEL)
// ==========================================
if (isset($_POST['ajouter_ecole_manuel'])) {
    $centreId = (int)$_POST['centre_id'];
    $ecoleId = (int)$_POST['ecole_affectee_id'];
    
    if ($centreId && $ecoleId) {
        try {
            $pdo->prepare("INSERT IGNORE INTO ecole_centre (ecole_id, centre_id, annee_id) VALUES (?, ?, 1)")
                ->execute([$ecoleId, $centreId]);
            header("Location: centres.php#tous");
            exit;
        } catch (Exception $e) {
            $error = "Erreur lors de l'affectation.";
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRIMER UN LIEN
// ==========================================
if (isset($_GET['supprimer_lien'])) {
    $ecoleId = (int)$_GET['supprimer_lien'];
    // On supprime le lien pour cette école spécifique dans tous les centres (ou on pourrait cibler un centre précis)
    $pdo->prepare("DELETE FROM ecole_centre WHERE ecole_id = ?")->execute([$ecoleId]);
    header("Location: centres.php");
    exit;
}

// ==========================================
// DONNÉES
// ==========================================
$centres = $pdo->query("
    SELECT c.id as centre_id, e.nom as nom_centre, e.code_dsps as code_centre
    FROM centres c
    JOIN ecoles e ON c.ecole_id = e.id
    ORDER BY e.nom ASC
")->fetchAll();

$toutesLesEcoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🏛️ Centres d'Examen & Composition</h2>
    <div>
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#modalNouveauCentre">
            <i class="bi bi-plus-circle"></i> Nouveau Centre
        </button>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#modalImport">
            <i class="bi bi-file-earmark-excel"></i> Importer Excel
        </button>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] == 'ok'): ?>
    <div class="alert alert-success alert-dismissible fade show">
        Importation réussie ! <?= $_GET['count'] ?> écoles rattachées.
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Onglets -->
<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tous" type="button">🏫 Toutes les écoles</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#codes" type="button">✅ Écoles avec Code Officiel</button></li>
</ul>

<div class="tab-content">
    <!-- VOLET 1 : TOUTES LES ÉCOLES -->
    <div class="tab-pane fade show active" id="tous">
        <?php if (count($centres) > 0): ?>
            <?php foreach ($centres as $centre): 
                $stmtEcoles = $pdo->prepare("
                    SELECT e.id, e.nom, e.code_dsps,
                           (SELECT COUNT(*) FROM candidats WHERE ecole_origine_id = e.id) as effectif_candidats
                    FROM ecole_centre ec
                    JOIN ecoles e ON ec.ecole_id = e.id
                    WHERE ec.centre_id = ?
                    ORDER BY e.nom ASC
                ");
                $stmtEcoles->execute([$centre['centre_id']]);
                $ecolesRattachees = $stmtEcoles->fetchAll();
                $totalCandidats = array_sum(array_column($ecolesRattachees, 'effectif_candidats'));
            ?>
            <div class="card shadow-sm mb-4 border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">📍 CENTRE : <?= htmlspecialchars($centre['nom_centre']) ?> 
                        <?php if($centre['code_centre']): ?>
                            <span class="badge bg-light text-dark ms-2"><?= htmlspecialchars($centre['code_centre']) ?></span>
                        <?php endif; ?>
                    </h5>
                    <div>
                        <span class="badge bg-light text-dark fs-6 me-2">Total candidats : <?= $totalCandidats ?></span>
                        <!-- Bouton pour ajouter manuellement une école à CE centre -->
                        <button class="btn btn-sm btn-light text-primary" onclick="openAddSchoolModal(<?= $centre['centre_id'] ?>, '<?= htmlspecialchars($centre['nom_centre']) ?>')">
                            <i class="bi bi-person-plus"></i> Ajouter école
                        </button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>École Composante</th>
                                    <th>Code DSPS</th>
                                    <th class="text-center">Candidats</th>
                                    <th class="text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (count($ecolesRattachees) > 0): ?>
                                    <?php foreach ($ecolesRattachees as $ecole): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($ecole['nom']) ?></strong></td>
                                        <td><?= $ecole['code_dsps'] ? '<code>'.htmlspecialchars($ecole['code_dsps']).'</code>' : '<span class="text-muted">Aucun</span>' ?></td>
                                        <td class="text-center fw-bold"><?= $ecole['effectif_candidats'] ?></td>
                                        <td class="text-center">
                                            <a href="?supprimer_lien=<?= $ecole['id'] ?>" 
                                               class="btn btn-sm btn-outline-danger" 
                                               onclick="return confirm('Détacher cette école du centre ?');">
                                               <i class="bi bi-unlink"></i> Détacher
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center text-muted py-3">Aucune école rattachée. Cliquez sur "Ajouter école" pour commencer.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="alert alert-info text-center">
                <i class="bi bi-info-circle"></i> Aucun centre d'examen désigné.<br>
                Cliquez sur "Nouveau Centre" pour commencer.
            </div>
        <?php endif; ?>
    </div>

    <!-- VOLET 2 : CODES OFFICIELS -->
    <div class="tab-pane fade" id="codes">
        <div class="alert alert-info"><i class="bi bi-filter"></i> Affiche uniquement les écoles avec Code DSPS officiel.</div>
        <?php 
        $hasData = false;
        foreach ($centres as $centre): 
            $stmtOff = $pdo->prepare("
                SELECT e.id, e.nom, e.code_dsps,
                       (SELECT COUNT(*) FROM candidats WHERE ecole_origine_id = e.id) as effectif_candidats
                FROM ecole_centre ec
                JOIN ecoles e ON ec.ecole_id = e.id
                WHERE ec.centre_id = ? AND e.code_dsps IS NOT NULL AND e.code_dsps != ''
                ORDER BY e.nom ASC
            ");
            $stmtOff->execute([$centre['centre_id']]);
            $ecolesOff = $stmtOff->fetchAll();
            if (count($ecolesOff) == 0) continue;
            $hasData = true;
            $totalOff = array_sum(array_column($ecolesOff, 'effectif_candidats'));
        ?>
        <div class="card shadow-sm mb-4 border-success">
            <div class="card-header bg-success text-white d-flex justify-content-between">
                <h5 class="mb-0">📍 CENTRE : <?= htmlspecialchars($centre['nom_centre']) ?> (Officiel)</h5>
                <span class="badge bg-light text-dark">Total : <?= $totalOff ?></span>
            </div>
            <div class="card-body p-0">
                <table class="table table-bordered mb-0">
                    <thead><tr><th>École</th><th>Code</th><th class="text-center">Candidats</th></tr></thead>
                    <tbody>
                        <?php foreach ($ecolesOff as $ecole): ?>
                        <tr>
                            <td><?= htmlspecialchars($ecole['nom']) ?></td>
                            <td><code><?= htmlspecialchars($ecole['code_dsps']) ?></code></td>
                            <td class="text-center fw-bold"><?= $ecole['effectif_candidats'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (!$hasData): ?><div class="alert alert-warning">Aucune donnée officielle.</div><?php endif; ?>
    </div>
</div>

<!-- Modal : NOUVEAU CENTRE -->
<div class="modal fade" id="modalNouveauCentre" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Désigner un nouveau centre</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Choisissez l'école qui servira de centre :</label>
                    <select name="ecole_id" class="form-select" required>
                        <option value="">-- Sélectionner --</option>
                        <?php foreach($toutesLesEcoles as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="creer_centre" class="btn btn-primary">Valider</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal : AJOUTER UNE ÉCOLE À UN CENTRE (Dynamique) -->
<div class="modal fade" id="modalAjoutEcole" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST">
            <div class="modal-content">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">Ajouter une école au centre : <span id="nomCentreSpan"></span></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="centre_id" id="inputCentreId">
                    <label class="form-label">Sélectionnez l'école candidate à rattacher :</label>
                    <select name="ecole_affectee_id" class="form-select" required>
                        <option value="">-- Sélectionner une école --</option>
                        <?php foreach($toutesLesEcoles as $e): ?>
                            <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Cette école enverra ses candidats vers ce centre.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="ajouter_ecole_manuel" class="btn btn-info text-white">Rattacher</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal : IMPORT EXCEL -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Importer les rattachements (Excel)</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="mb-2"><strong>Format requis :</strong></p>
                    <ul class="small">
                        <li><strong>Colonne A :</strong> Nom du Centre</li>
                        <li><strong>Colonne B :</strong> Nom de l'École candidate</li>
                    </ul>
                    <input type="file" name="fichier" class="form-control" accept=".xlsx,.xls" required>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                    <button type="submit" name="importer" class="btn btn-success">Lancer l'import</button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
// Fonction pour ouvrir le modal d'ajout avec le bon centre pré-rempli
function openAddSchoolModal(centreId, centreNom) {
    document.getElementById('inputCentreId').value = centreId;
    document.getElementById('nomCentreSpan').textContent = centreNom;
    var myModal = new bootstrap.Modal(document.getElementById('modalAjoutEcole'));
    myModal.show();
}
</script>

<?php include '../views/layouts/footer.php'; ?>