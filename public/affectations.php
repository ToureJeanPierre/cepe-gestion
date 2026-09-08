<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../vendor/autoload.php';
require_once '../config/database.php';

use Iepp\CepeGestion\AffectationEngine;

$pageTitle = 'Surveillance & Affectations';

$success = null;
$error = null;

// Année scolaire consultée : résolue globalement par config/database.php
// ($anneeId, $ANNEE_SCOLAIRE, $anneeSelectionnee, $anneeLectureSeule)
$annee = $anneeSelectionnee;

if (!$annee) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

if ($anneeLectureSeule && $_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.");
}


/*
|--------------------------------------------------------------------------
| EXAMENS DISPONIBLES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("SELECT id, code, libelle, ordre FROM examens WHERE annee_id = ? AND actif = 1 ORDER BY ordre");
$stmt->execute([$anneeId]);
$examens = $stmt->fetchAll();

if (count($examens) === 0) {
    die("Aucun examen configuré pour l'année scolaire {$ANNEE_SCOLAIRE}.");
}

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : (int) $examens[0]['id'];

$examenActif = null;
foreach ($examens as $e) {
    if ((int) $e['id'] === $examenId) {
        $examenActif = $e;
    }
}
if (!$examenActif) {
    $examenActif = $examens[0];
    $examenId = (int) $examenActif['id'];
}

$typeExamenLibelle = AffectationEngine::libelleTypeExamen($examenActif['code']);
$estFinal = AffectationEngine::estExamenFinal($examenActif['code']);

$engine = new AffectationEngine($pdo, $anneeId);


/*
|--------------------------------------------------------------------------
| TRAITEMENT DES ACTIONS (POST)
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $action = $_POST['action'] ?? '';

    if ($action === 'affecter_role') {

        $centreId = (int) ($_POST['centre_id'] ?? 0);
        $personnelId = (int) ($_POST['personnel_id'] ?? 0);
        $role = $_POST['role'] ?? '';

        $rolesAutorises = ['Président', 'Chef Secrétariat', 'Membre Secrétariat', 'Superviseur'];

        if ($centreId <= 0 || $personnelId <= 0 || !in_array($role, $rolesAutorises, true)) {
            $error = "Requête invalide.";
        } else {
            $ok = $engine->enregistrerAffectation($typeExamenLibelle, $personnelId, $centreId, $role, true);
            if ($ok) {
                $success = "Rôle \"{$role}\" attribué avec succès.";
            } else {
                $error = "Cette personne a déjà un rôle attribué pour cet examen (règle de non-redondance). Retirez d'abord son affectation existante.";
            }
        }

    } elseif ($action === 'affecter_surveillant_manuel') {

        $centreId = (int) ($_POST['centre_id'] ?? 0);
        $personnelId = (int) ($_POST['personnel_id'] ?? 0);
        $role = $_POST['role'] ?? 'Surveillant';
        $forcer = isset($_POST['forcer_conflit']);

        if ($centreId <= 0 || $personnelId <= 0 || !in_array($role, ['Surveillant', 'Suppléant'], true)) {
            $error = "Requête invalide.";
        } else {
            // Vérification anti-collusion, sauf si l'utilisateur force sciemment.
            $stmtEns = $pdo->prepare("SELECT id, ecole_id FROM personnel WHERE id = ?");
            $stmtEns->execute([$personnelId]);
            $ens = $stmtEns->fetch();

            $conflit = false;
            if ($ens) {
                $interdits = $engine->calculerCentresInterdits([$ens]);
                $conflit = in_array($centreId, $interdits[$personnelId] ?? [], true);
            }

            if ($conflit && !$forcer) {
                $error = "⚠️ Conflit détecté : cet enseignant appartient à une école du même Groupe Scolaire que ce centre. Cochez \"Forcer malgré le conflit\" pour passer outre (déconseillé).";
            } else {
                $ok = $engine->enregistrerAffectation($typeExamenLibelle, $personnelId, $centreId, $role, true);
                $success = $ok
                    ? "Surveillant affecté avec succès" . ($conflit ? " (conflit forcé manuellement)." : ".")
                    : null;
                if (!$ok) {
                    $error = "Cette personne a déjà un rôle attribué pour cet examen (règle de non-redondance).";
                }
            }
        }

    } elseif ($action === 'supprimer') {

        $affectationId = (int) ($_POST['affectation_id'] ?? 0);
        if ($affectationId > 0) {
            $stmt = $pdo->prepare("DELETE FROM affectations WHERE id = ? AND annee_id = ?");
            $stmt->execute([$affectationId, $anneeId]);
            $success = "Affectation retirée.";
        }

    } elseif ($action === 'generer_auto') {

        $resultat = $estFinal
            ? $engine->genererSurveillantsFinal($typeExamenLibelle, $examenId)
            : $engine->genererSurveillantsBlancs($typeExamenLibelle, $examenId);

        $success = "{$resultat['affectes']} surveillant(s) affecté(s) automatiquement.";
        if (!empty($resultat['warnings'])) {
            $_SESSION['affectations_warnings'] = $resultat['warnings'];
        }

    } elseif ($action === 'reinitialiser_auto') {

        $stmt = $pdo->prepare("
            DELETE FROM affectations
            WHERE annee_id = ? AND type_examen = ? AND est_manuel = 0
        ");
        $stmt->execute([$anneeId, $typeExamenLibelle]);
        $success = "Les affectations automatiques (surveillants/suppléants) de cet examen ont été réinitialisées. Les rôles saisis manuellement sont conservés.";
        unset($_SESSION['affectations_warnings']);
    }
}

$warnings = $_SESSION['affectations_warnings'] ?? [];
unset($_SESSION['affectations_warnings']);


/*
|--------------------------------------------------------------------------
| DONNÉES POUR L'AFFICHAGE
|--------------------------------------------------------------------------
*/

$centres = $engine->centresPourExamen($examenId, $estFinal);

// Toutes les affectations existantes pour cet examen, groupées par centre.
$stmt = $pdo->prepare("
    SELECT a.id, a.centre_id, a.role, a.est_manuel,
           p.id AS personnel_id, p.nom, p.prenoms, p.sexe, p.ecole_id, p.niveau_tenu, p.type_ecole, p.categorie
    FROM affectations a
    JOIN personnel p ON p.id = a.enseignant_id
    WHERE a.annee_id = ? AND a.type_examen = ?
    ORDER BY p.nom, p.prenoms
");
$stmt->execute([$anneeId, $typeExamenLibelle]);

$affectationsParCentre = [];
$rolesUniques = []; // Président/Chef Secrétariat/Membres/Superviseurs, tous centres confondus (verrouillage global)
foreach ($stmt->fetchAll() as $a) {
    $affectationsParCentre[(int) $a['centre_id']][] = $a;
    $rolesUniques[(int) $a['personnel_id']] = $a['role'];
}

// Viviers pour les selects de rôles manuels (on exclut ceux déjà verrouillés).
function vivierParCategorie(PDO $pdo, array $categories, array $exclureIds): array
{
    $placeholders = implode(',', array_fill(0, count($categories), '?'));
    $sql = "SELECT id, nom, prenoms, categorie, sous_type, ecole_id FROM personnel WHERE categorie IN ($placeholders) AND disponibilite = 'En activité' ORDER BY nom, prenoms";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($categories);
    return array_values(array_filter($stmt->fetchAll(), fn ($p) => !in_array((int) $p['id'], $exclureIds, true)));
}

$dejaVerrouilles = array_keys($rolesUniques);
$vivierPresidentChef = vivierParCategorie($pdo, ['enseignant', 'administratif'], $dejaVerrouilles);
$vivierSecretariat    = vivierParCategorie($pdo, ['enseignant', 'administratif', 'conseiller'], $dejaVerrouilles);
$vivierSuperviseurs   = vivierParCategorie($pdo, ['conseiller'], $dejaVerrouilles);
$vivierSurveillants   = $engine->viveirEnseignantsDisponibles($typeExamenLibelle);

$nomsEcoles = [];
$stmt = $pdo->query("SELECT id, nom FROM ecoles");
foreach ($stmt->fetchAll() as $e) {
    $nomsEcoles[(int) $e['id']] = $e['nom'];
}

include '../views/layouts/header.php';

?>

<div class="container-fluid">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="mb-1">
                🛡️ Surveillance & Affectations
            </h2>
            <div class="text-muted">
                Année scolaire : <strong><?= htmlspecialchars($ANNEE_SCOLAIRE) ?></strong>
            </div>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="alert alert-warning alert-dismissible fade show">
            <i class="bi bi-exclamation-diamond"></i> <strong>À vérifier :</strong>
            <ul class="mb-0">
                <?php foreach ($warnings as $w): ?>
                    <li><?= htmlspecialchars($w) ?></li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- =========================================================
         SÉLECTEUR D'EXAMEN
    ========================================================== -->

    <ul class="nav nav-pills mb-4">
        <?php foreach ($examens as $ex): ?>
            <li class="nav-item">
                <a class="nav-link <?= (int) $ex['id'] === $examenId ? 'active' : '' ?>"
                   href="affectations.php?examen_id=<?= (int) $ex['id'] ?>">
                    <?= htmlspecialchars($ex['libelle']) ?>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>

    <!-- =========================================================
         ACTIONS GLOBALES
    ========================================================== -->

    <div class="card mb-4">
        <div class="card-body d-flex gap-2 flex-wrap align-items-center">

            <form method="post" class="d-inline" onsubmit="return confirm('Lancer la génération automatique des surveillants pour cet examen ? Les personnes déjà affectées manuellement ne seront pas touchées.');">
                <input type="hidden" name="action" value="generer_auto">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-magic"></i> Générer les surveillants automatiquement
                </button>
            </form>

            <form method="post" class="d-inline" onsubmit="return confirm('Retirer TOUTES les affectations automatiques (Surveillant/Suppléant non manuelles) de cet examen ? Les rôles saisis à la main (Président, Secrétariat, Superviseur, et surveillants ajoutés manuellement) seront conservés.');">
                <input type="hidden" name="action" value="reinitialiser_auto">
                <button type="submit" class="btn btn-outline-danger">
                    <i class="bi bi-arrow-counterclockwise"></i> Réinitialiser les affectations automatiques
                </button>
            </form>

            <span class="text-muted ms-auto">
                <?= count($vivierSurveillants) ?> enseignant(s) encore disponible(s) pour un rôle sur cet examen
            </span>

        </div>
    </div>

    <!-- =========================================================
         VUE D'ENSEMBLE PAR CENTRE
    ========================================================== -->

    <?php foreach ($centres as $centreId => $c):
        $lignes = $affectationsParCentre[$centreId] ?? [];
        $president = null; $chefSecretariat = null; $membresSecretariat = []; $superviseurs = []; $surveillants = []; $suppleants = [];
        foreach ($lignes as $l) {
            switch ($l['role']) {
                case 'Président': $president = $l; break;
                case 'Chef Secrétariat': $chefSecretariat = $l; break;
                case 'Membre Secrétariat': $membresSecretariat[] = $l; break;
                case 'Superviseur': $superviseurs[] = $l; break;
                case 'Surveillant': $surveillants[] = $l; break;
                case 'Suppléant': $suppleants[] = $l; break;
            }
        }
        $quota = $c['quota_surveillants'];
        $compteSurveillance = count($surveillants) + count($suppleants);
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong><?= htmlspecialchars($c['ecole_nom']) ?></strong>
                <span class="text-muted">— <?= $c['effectif'] ?> candidat(s), <?= $c['nb_salles'] ?> salle(s)</span>
            </div>
            <?php if ($estFinal && $quota !== null): ?>
                <span class="badge <?= $compteSurveillance >= $quota ? 'bg-success' : 'bg-warning text-dark' ?>">
                    <?= $compteSurveillance ?> / <?= $quota ?> surveillants requis
                </span>
            <?php else: ?>
                <span class="badge bg-secondary"><?= $compteSurveillance ?> surveillant(s)</span>
            <?php endif; ?>
        </div>
        <div class="card-body">

            <div class="row g-3 mb-3">

                <div class="col-md-3">
                    <label class="form-label small text-muted">Président de centre</label>
                    <?php if ($president): ?>
                        <div class="d-flex justify-content-between align-items-center border rounded p-2">
                            <span><?= htmlspecialchars($president['nom'] . ' ' . $president['prenoms']) ?></span>
                            <form method="post" onsubmit="return confirm('Retirer ce président ?');">
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="affectation_id" value="<?= $president['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="action" value="affecter_role">
                            <input type="hidden" name="centre_id" value="<?= $centreId ?>">
                            <input type="hidden" name="role" value="Président">
                            <select name="personnel_id" class="form-select form-select-sm" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($vivierPresidentChef as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom'] . ' ' . $p['prenoms']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Chef de Secrétariat</label>
                    <?php if ($chefSecretariat): ?>
                        <div class="d-flex justify-content-between align-items-center border rounded p-2">
                            <span><?= htmlspecialchars($chefSecretariat['nom'] . ' ' . $chefSecretariat['prenoms']) ?></span>
                            <form method="post" onsubmit="return confirm('Retirer ce chef de secrétariat ?');">
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="affectation_id" value="<?= $chefSecretariat['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
                            </form>
                        </div>
                    <?php else: ?>
                        <form method="post" class="d-flex gap-1">
                            <input type="hidden" name="action" value="affecter_role">
                            <input type="hidden" name="centre_id" value="<?= $centreId ?>">
                            <input type="hidden" name="role" value="Chef Secrétariat">
                            <select name="personnel_id" class="form-select form-select-sm" required>
                                <option value="">— Choisir —</option>
                                <?php foreach ($vivierPresidentChef as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom'] . ' ' . $p['prenoms']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-check"></i></button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Membres du Secrétariat</label>
                    <?php foreach ($membresSecretariat as $m): ?>
                        <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
                            <span class="small"><?= htmlspecialchars($m['nom'] . ' ' . $m['prenoms']) ?></span>
                            <form method="post" onsubmit="return confirm('Retirer ce membre ?');">
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="affectation_id" value="<?= $m['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <form method="post" class="d-flex gap-1">
                        <input type="hidden" name="action" value="affecter_role">
                        <input type="hidden" name="centre_id" value="<?= $centreId ?>">
                        <input type="hidden" name="role" value="Membre Secrétariat">
                        <select name="personnel_id" class="form-select form-select-sm" required>
                            <option value="">— Ajouter —</option>
                            <?php foreach ($vivierSecretariat as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom'] . ' ' . $p['prenoms']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i></button>
                    </form>
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Superviseur(s) <span class="text-muted">(Conseillers)</span></label>
                    <?php foreach ($superviseurs as $s): ?>
                        <div class="d-flex justify-content-between align-items-center border rounded p-2 mb-1">
                            <span class="small"><?= htmlspecialchars($s['nom'] . ' ' . $s['prenoms']) ?></span>
                            <form method="post" onsubmit="return confirm('Retirer ce superviseur ?');">
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="affectation_id" value="<?= $s['id'] ?>">
                                <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    <form method="post" class="d-flex gap-1">
                        <input type="hidden" name="action" value="affecter_role">
                        <input type="hidden" name="centre_id" value="<?= $centreId ?>">
                        <input type="hidden" name="role" value="Superviseur">
                        <select name="personnel_id" class="form-select form-select-sm" required>
                            <option value="">— Ajouter —</option>
                            <?php foreach ($vivierSuperviseurs as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom'] . ' ' . $p['prenoms']) ?> <?= $p['sous_type'] ? '(' . htmlspecialchars($p['sous_type']) . ')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus"></i></button>
                    </form>
                </div>

            </div>

            <hr>

            <div class="row">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Surveillants / Suppléants (<?= $compteSurveillance ?>)</label>
                    <div class="table-responsive" style="max-height: 260px; overflow-y:auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                                <tr><th>Nom</th><th>École</th><th>Niveau</th><th>Rôle</th><th>Origine</th><th></th></tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_merge($surveillants, $suppleants) as $s): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($s['nom'] . ' ' . $s['prenoms']) ?></td>
                                        <td class="small text-muted"><?= htmlspecialchars($nomsEcoles[(int) $s['ecole_id']] ?? '—') ?></td>
                                        <td><?= htmlspecialchars($s['niveau_tenu'] ?? '—') ?></td>
                                        <td><span class="badge <?= $s['role'] === 'Surveillant' ? 'bg-primary' : 'bg-secondary' ?>"><?= $s['role'] ?></span></td>
                                        <td class="small text-muted"><?= $s['est_manuel'] ? 'Manuel' : 'Auto' ?></td>
                                        <td>
                                            <form method="post" onsubmit="return confirm('Retirer cette personne ?');">
                                                <input type="hidden" name="action" value="supprimer">
                                                <input type="hidden" name="affectation_id" value="<?= $s['id'] ?>">
                                                <button class="btn btn-sm btn-link text-danger p-0"><i class="bi bi-x-circle"></i></button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if (empty($surveillants) && empty($suppleants)): ?>
                                    <tr><td colspan="6" class="text-center text-muted">Aucun surveillant affecté pour ce centre.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label small text-muted">Ajouter un surveillant manuellement</label>
                    <form method="post">
                        <input type="hidden" name="action" value="affecter_surveillant_manuel">
                        <input type="hidden" name="centre_id" value="<?= $centreId ?>">
                        <select name="personnel_id" class="form-select form-select-sm mb-2" required>
                            <option value="">— Choisir un enseignant —</option>
                            <?php foreach ($vivierSurveillants as $p): ?>
                                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['nom'] . ' ' . $p['prenoms']) ?> (<?= htmlspecialchars($p['niveau_tenu'] ?? '') ?>, <?= htmlspecialchars($p['type_ecole']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <select name="role" class="form-select form-select-sm mb-2">
                            <option value="Surveillant">Surveillant</option>
                            <option value="Suppléant">Suppléant (réserve)</option>
                        </select>
                        <div class="form-check mb-2">
                            <input type="checkbox" name="forcer_conflit" class="form-check-input" id="forcer_<?= $centreId ?>">
                            <label class="form-check-label small" for="forcer_<?= $centreId ?>">Forcer malgré un conflit détecté</label>
                        </div>
                        <button class="btn btn-sm btn-outline-primary w-100"><i class="bi bi-plus-circle"></i> Ajouter</button>
                    </form>
                </div>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($centres)): ?>
        <div class="alert alert-info">
            Aucun centre n'a d'effectif calculé pour cet examen. Rendez-vous d'abord dans <a href="centres.php">Centres d'examen</a> puis <a href="plans.php">Plans de salle</a>.
        </div>
    <?php endif; ?>

</div>

<?php include '../views/layouts/footer.php'; ?>
