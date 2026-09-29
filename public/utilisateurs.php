<?php
require_once '../config/database.php';

$pageTitle = 'Utilisateurs';

$success = null;
$error = null;

$utilisateurCourantId = (int) ($_SESSION['utilisateur_id'] ?? 0);

// ==========================================
// TRAITEMENT : AJOUT
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajouter'])) {
    $nom = trim($_POST['nom'] ?? '');
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

    if ($nom === '' || $identifiant === '' || $motDePasse === '') {
        $error = "Tous les champs sont obligatoires.";
    } elseif (strlen($motDePasse) < 8) {
        $error = "Le mot de passe doit contenir au moins 8 caractères.";
    } else {
        try {
            $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, identifiant, mot_de_passe_hash) VALUES (?, ?, ?)");
            $stmt->execute([$nom, $identifiant, password_hash($motDePasse, PASSWORD_DEFAULT)]);
            $success = "Utilisateur \"$nom\" créé.";
        } catch (\PDOException $e) {
            $error = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? "Cet identifiant est déjà utilisé par un autre compte."
                : "Erreur technique lors de la création.";
        }
    }

// ==========================================
// TRAITEMENT : ACTIVER / DÉSACTIVER
// ==========================================
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['basculer_actif'])) {
    $id = (int) ($_POST['id'] ?? 0);
    if ($id === $utilisateurCourantId) {
        $error = "Vous ne pouvez pas désactiver votre propre compte.";
    } elseif ($id > 0) {
        $pdo->prepare("UPDATE utilisateurs SET actif = NOT actif WHERE id = ?")->execute([$id]);
        $success = "Statut mis à jour.";
    }

// ==========================================
// TRAITEMENT : RÉINITIALISER LE MOT DE PASSE
// ==========================================
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['reinitialiser_mdp'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    if (strlen($motDePasse) < 8) {
        $error = "Le nouveau mot de passe doit contenir au moins 8 caractères.";
    } elseif ($id > 0) {
        $pdo->prepare("UPDATE utilisateurs SET mot_de_passe_hash = ? WHERE id = ?")
            ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), $id]);
        $success = "Mot de passe réinitialisé.";
    }

// ==========================================
// TRAITEMENT : MODIFIER NOM / IDENTIFIANT
// ==========================================
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['modifier'])) {
    $id = (int) ($_POST['id'] ?? 0);
    $nom = trim($_POST['nom'] ?? '');
    $identifiant = trim($_POST['identifiant'] ?? '');
    if ($nom === '' || $identifiant === '') {
        $error = "Nom et identifiant sont obligatoires.";
    } else {
        try {
            $pdo->prepare("UPDATE utilisateurs SET nom = ?, identifiant = ? WHERE id = ?")->execute([$nom, $identifiant, $id]);
            if ($id === $utilisateurCourantId) {
                $_SESSION['utilisateur_nom'] = $nom;
            }
            $success = "Utilisateur modifié.";
        } catch (\PDOException $e) {
            $error = ((int) ($e->errorInfo[1] ?? 0) === 1062)
                ? "Cet identifiant est déjà utilisé par un autre compte."
                : "Erreur technique lors de la modification.";
        }
    }
}

$utilisateurs = $pdo->query("SELECT id, nom, identifiant, actif, derniere_connexion FROM utilisateurs ORDER BY nom ASC")->fetchAll();

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-people"></i> Utilisateurs</h2>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjouter">
        <i class="bi bi-plus-lg"></i> Ajouter un utilisateur
    </button>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="alert alert-light border small">
    Chaque personne qui utilise l'application doit avoir son propre compte : en cas de problème (erreur de saisie, suppression accidentelle), on peut savoir qui a fait quoi. Désactiver un compte (plutôt que le supprimer) coupe l'accès immédiatement tout en gardant son historique.
</div>

<div class="card shadow-sm">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Nom</th>
                    <th>Identifiant</th>
                    <th>Statut</th>
                    <th>Dernière connexion</th>
                    <th class="text-center" style="width:220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($utilisateurs)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Aucun utilisateur.</td></tr>
                <?php endif; ?>
                <?php foreach ($utilisateurs as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['nom']) ?><?= (int) $u['id'] === $utilisateurCourantId ? ' <span class="badge bg-secondary">vous</span>' : '' ?></td>
                        <td><code><?= htmlspecialchars($u['identifiant']) ?></code></td>
                        <td>
                            <?php if ((int) $u['actif'] === 1): ?>
                                <span class="badge bg-success">Actif</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Désactivé</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $u['derniere_connexion'] ? date('d/m/Y à H:i', strtotime($u['derniere_connexion'])) : 'Jamais' ?></td>
                        <td class="text-center">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalModifier<?= $u['id'] ?>" title="Modifier"><i class="bi bi-pencil"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalMdp<?= $u['id'] ?>" title="Réinitialiser le mot de passe"><i class="bi bi-key"></i></button>
                            <form method="POST" class="d-inline" onsubmit="return confirm('<?= (int) $u['actif'] === 1 ? 'Désactiver' : 'Réactiver' ?> ce compte ?');">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" name="basculer_actif" class="btn btn-sm btn-outline-<?= (int) $u['actif'] === 1 ? 'danger' : 'success' ?>" title="<?= (int) $u['actif'] === 1 ? 'Désactiver' : 'Réactiver' ?>" <?= (int) $u['id'] === $utilisateurCourantId ? 'disabled' : '' ?>>
                                    <i class="bi bi-<?= (int) $u['actif'] === 1 ? 'slash-circle' : 'check-circle' ?>"></i>
                                </button>
                            </form>
                        </td>
                    </tr>

                    <!-- Modal Modifier -->
                    <div class="modal fade" id="modalModifier<?= $u['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <form method="POST" class="modal-content">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <div class="modal-header"><h5 class="modal-title">Modifier l'utilisateur</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Nom complet</label>
                                        <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($u['nom']) ?>" required>
                                    </div>
                                    <div class="mb-3">
                                        <label class="form-label">Identifiant</label>
                                        <input type="text" name="identifiant" class="form-control" value="<?= htmlspecialchars($u['identifiant']) ?>" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" name="modifier" class="btn btn-primary">Enregistrer</button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Modal Mot de passe -->
                    <div class="modal fade" id="modalMdp<?= $u['id'] ?>" tabindex="-1">
                        <div class="modal-dialog">
                            <form method="POST" class="modal-content">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <div class="modal-header"><h5 class="modal-title">Réinitialiser le mot de passe de <?= htmlspecialchars($u['nom']) ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                                <div class="modal-body">
                                    <div class="mb-3">
                                        <label class="form-label">Nouveau mot de passe (8 caractères minimum)</label>
                                        <input type="password" name="mot_de_passe" class="form-control" minlength="8" required>
                                    </div>
                                </div>
                                <div class="modal-footer">
                                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                                    <button type="submit" name="reinitialiser_mdp" class="btn btn-primary">Réinitialiser</button>
                                </div>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ajouter -->
<div class="modal fade" id="modalAjouter" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Ajouter un utilisateur</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom complet</label>
                    <input type="text" name="nom" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Identifiant</label>
                    <input type="text" name="identifiant" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mot de passe (8 caractères minimum)</label>
                    <input type="password" name="mot_de_passe" class="form-control" minlength="8" required>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="ajouter" class="btn btn-primary">Créer</button>
            </div>
        </form>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
