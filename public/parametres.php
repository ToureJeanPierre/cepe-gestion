<?php
require_once '../config/database.php';

$pageTitle = 'Paramètres';

$success = null;
$error = null;

// ==========================================
// TRAITEMENT : CLÔTURE DE L'ANNÉE EN COURS + OUVERTURE D'UNE NOUVELLE CAMPAGNE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cloturer_annee'])) {
    $nouvelleAnnee = trim($_POST['nouvelle_annee_scolaire'] ?? '');

    if (!$anneeActive) {
        $error = "Aucune année 'en cours' à clôturer.";
    } elseif (!preg_match('/^\d{4}-\d{4}$/', $nouvelleAnnee)) {
        $error = "Format attendu pour la nouvelle année scolaire : AAAA-AAAA (ex: 2027-2028).";
    } else {
        [$anneeDebut, $anneeFin] = explode('-', $nouvelleAnnee);
        if ((int) $anneeFin !== (int) $anneeDebut + 1) {
            $error = "L'année scolaire doit être deux années consécutives (ex: 2027-2028).";
        } else {
            $stmtExiste = $pdo->prepare("SELECT id FROM annees WHERE annee_scolaire = ?");
            $stmtExiste->execute([$nouvelleAnnee]);
            if ($stmtExiste->fetch()) {
                $error = "L'année scolaire $nouvelleAnnee existe déjà.";
            } else {
                $pdo->beginTransaction();
                try {
                    $pdo->prepare("UPDATE annees SET statut = 'archive' WHERE id = ?")->execute([$anneeActive['id']]);

                    $pdo->prepare("INSERT INTO annees (annee_scolaire, statut) VALUES (?, 'en_cours')")->execute([$nouvelleAnnee]);
                    $nouvelleAnneeId = (int) $pdo->lastInsertId();

                    // Amorce les 5 examens standards de la nouvelle campagne (nécessaires
                    // aux modules Résultats/Centres/Plans de salle/Affectations dès l'ouverture)
                    $sessionLibelle = (int) $anneeFin;
                    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'COMPO_1', ?, 1, 1)")
                        ->execute([$nouvelleAnneeId, "Composition n°1 - année scolaire $nouvelleAnnee"]);
                    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'COMPO_2', ?, 2, 1)")
                        ->execute([$nouvelleAnneeId, "Composition n°2 - année scolaire $nouvelleAnnee"]);
                    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'BLANC_1', ?, 3, 1)")
                        ->execute([$nouvelleAnneeId, "Examen blanc n°1 CEPE session $sessionLibelle"]);
                    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'BLANC_2', ?, 4, 1)")
                        ->execute([$nouvelleAnneeId, "Examen blanc n°2 CEPE session $sessionLibelle"]);
                    $pdo->prepare("INSERT INTO examens (annee_id, code, libelle, ordre, actif) VALUES (?, 'CEPE_FINAL', ?, 5, 1)")
                        ->execute([$nouvelleAnneeId, "CEPE session $sessionLibelle"]);

                    $pdo->commit();

                    // Bascule la vue de l'utilisateur sur la nouvelle campagne
                    $_SESSION['annee_id'] = $nouvelleAnneeId;

                    header("Location: parametres.php?msg=" . urlencode("Année $nouvelleAnnee ouverte. L'année " . $anneeActive['annee_scolaire'] . " est désormais archivée (lecture seule)."));
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = "Erreur lors de la clôture : " . $e->getMessage();
                }
            }
        }
    }
}

// ==========================================
// DONNÉES : LISTE DES ANNÉES + COMPTEURS
// ==========================================
$annees = $pdo->query("
    SELECT a.id, a.annee_scolaire, a.statut, a.created_at,
        (SELECT COUNT(*) FROM candidats c WHERE c.annee_id = a.id) AS nb_candidats,
        (SELECT COUNT(*) FROM centres ce WHERE ce.annee_id = a.id) AS nb_centres,
        (SELECT COUNT(*) FROM affectations af WHERE af.annee_id = a.id) AS nb_affectations
    FROM annees a
    ORDER BY a.annee_scolaire DESC
")->fetchAll();

// Suggestion automatique pour le champ "nouvelle année"
$suggestionNouvelleAnnee = '';
if ($anneeActive && preg_match('/^(\d{4})-(\d{4})$/', $anneeActive['annee_scolaire'], $m)) {
    $suggestionNouvelleAnnee = ((int) $m[1] + 1) . '-' . ((int) $m[2] + 1);
}

include '../views/layouts/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>⚙️ Paramètres — Années scolaires</h2>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-7">
        <div class="card shadow-sm h-100">
            <div class="card-header">Campagnes existantes</div>
            <div class="card-body p-0 table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Année scolaire</th>
                            <th>Statut</th>
                            <th class="text-center">Candidats</th>
                            <th class="text-center">Centres</th>
                            <th class="text-center">Affectations</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($annees as $a): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($a['annee_scolaire']) ?></strong></td>
                                <td>
                                    <?php if ($a['statut'] === 'en_cours'): ?>
                                        <span class="badge bg-success">En cours</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="bi bi-lock-fill"></i> Archivée</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center"><?= (int) $a['nb_candidats'] ?></td>
                                <td class="text-center"><?= (int) $a['nb_centres'] ?></td>
                                <td class="text-center"><?= (int) $a['nb_affectations'] ?></td>
                                <td>
                                    <a href="select_annee.php?annee_id=<?= (int) $a['id'] ?>&retour=parametres.php" class="btn btn-sm btn-outline-primary">Consulter</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card shadow-sm h-100">
            <div class="card-header">Clôturer la campagne en cours</div>
            <div class="card-body">
                <?php if (!$anneeActive): ?>
                    <p class="text-muted mb-0">Aucune année « en cours » — impossible de clôturer.</p>
                <?php else: ?>
                    <p>
                        Cette action archive définitivement (lecture seule)
                        <strong><?= htmlspecialchars($anneeActive['annee_scolaire']) ?></strong>
                        et ouvre une nouvelle campagne pour les Candidats, Centres, Plans de salle et Affectations.
                        <br><small class="text-muted">Les Écoles et le Personnel restent un référentiel permanent, non affecté par la clôture.</small>
                    </p>
                    <form method="POST" onsubmit="return confirm('Clôturer <?= htmlspecialchars($anneeActive['annee_scolaire']) ?> et ouvrir une nouvelle campagne ? Cette action est irréversible.');">
                        <div class="mb-3">
                            <label class="form-label">Nouvelle année scolaire</label>
                            <input type="text" name="nouvelle_annee_scolaire" class="form-control" pattern="\d{4}-\d{4}" placeholder="ex: <?= htmlspecialchars($suggestionNouvelleAnnee) ?>" value="<?= htmlspecialchars($suggestionNouvelleAnnee) ?>" required>
                        </div>
                        <button type="submit" name="cloturer_annee" class="btn btn-danger">
                            <i class="bi bi-archive"></i> Clôturer <?= htmlspecialchars($anneeActive['annee_scolaire']) ?> et ouvrir la nouvelle campagne
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
