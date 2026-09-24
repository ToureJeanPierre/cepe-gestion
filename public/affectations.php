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

// Les Compositions (COMPO_1/COMPO_2) sont des évaluations internes sans
// centre ni surveillance : elles n'ont pas leur place dans ce module.
$stmt = $pdo->prepare("SELECT id, code, libelle, ordre FROM examens WHERE annee_id = ? AND actif = 1 AND code NOT IN ('COMPO_1', 'COMPO_2') ORDER BY ordre");
$stmt->execute([$anneeId]);
$examens = $stmt->fetchAll();

if (count($examens) === 0) {
    die("Aucun examen configuré pour l'année scolaire {$ANNEE_SCOLAIRE}.");
}

// Examen actif partagé entre Centres / Plan de salle / Affectations / Résultats
// / Documents (mémorisé en session, cf. centres.php) : ce module exclut les
// Compositions de sa propre liste, donc si le choix mémorisé ne s'y trouve
// pas, on retombe localement sur le premier examen affiché ici SANS écraser
// la session partagée — sinon revenir sur Plan de salle perdrait le choix
// d'une Composition au profit de ce repli propre à cette page.
if (isset($_GET['examen_id']) && (int) $_GET['examen_id'] > 0) {
    $_SESSION['examen_actif_id'] = (int) $_GET['examen_id'];
}
$examenId = $_SESSION['examen_actif_id'] ?? (int) $examens[0]['id'];

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
        $forcer = isset($_POST['forcer_conflit']);

        $rolesAutorises = ['Président', 'Chef Secrétariat', 'Membre Secrétariat', 'Superviseur'];

        if ($centreId <= 0 || $personnelId <= 0 || !in_array($role, $rolesAutorises, true)) {
            $error = "Requête invalide.";
        } else {
            // Vérification anti-collusion, sauf si l'utilisateur force sciemment.
            $conflit = $engine->estEnConflitAvecCentre($personnelId, $centreId);

            if ($conflit && !$forcer) {
                $error = "⚠️ Conflit détecté : cette personne appartient à une école du même Groupe Scolaire que ce centre. Cochez \"Forcer malgré le conflit\" pour passer outre (déconseillé).";
            } else {
                $ok = $engine->enregistrerAffectation($typeExamenLibelle, $personnelId, $centreId, $role, true);
                if ($ok) {
                    $success = "Rôle \"{$role}\" attribué avec succès" . ($conflit ? " (conflit forcé manuellement)." : ".");
                } else {
                    $error = "Cette personne a déjà un rôle attribué pour cet examen (règle de non-redondance). Retirez d'abord son affectation existante.";
                }
            }
        }

    } elseif ($action === 'affecter_surveillant_manuel') {

        $centreId = (int) ($_POST['centre_id'] ?? 0);
        $personnelId = (int) ($_POST['personnel_id'] ?? 0);
        $role = 'Surveillant';
        $forcer = isset($_POST['forcer_conflit']);

        if ($centreId <= 0 || $personnelId <= 0) {
            $error = "Requête invalide.";
        } else {
            // Vérification anti-collusion, sauf si l'utilisateur force sciemment.
            $conflit = $engine->estEnConflitAvecCentre($personnelId, $centreId);

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
        $success = "Les affectations automatiques (surveillants) de cet examen ont été réinitialisées. Les rôles saisis manuellement sont conservés.";
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
    $sql = "SELECT id, nom, prenoms, categorie, sous_type, fonction, ecole_id FROM personnel WHERE categorie IN ($placeholders) AND disponibilite = 'En activité' ORDER BY nom, prenoms";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($categories);
    $lignes = array_values(array_filter($stmt->fetchAll(), fn ($p) => !in_array((int) $p['id'], $exclureIds, true)));

    // Regroupement affiché avant le nom (filtre "Catégorie" côté formulaire) :
    // Directeur / Adjoint pour les enseignants, Conseiller, Administratif.
    foreach ($lignes as &$p) {
        if ($p['categorie'] === 'enseignant') {
            $p['groupe_role'] = stripos((string) $p['fonction'], 'directeur') === 0 ? 'Directeur' : 'Adjoint';
        } elseif ($p['categorie'] === 'conseiller') {
            $p['groupe_role'] = 'Conseiller';
        } else {
            $p['groupe_role'] = 'Administratif';
        }
    }
    unset($p);

    return $lignes;
}

/**
 * Rend un <select> "personnel_id" filtrable par catégorie (Directeur, Adjoint,
 * Conseiller, Administratif...) : un select "Catégorie" au-dessus ne montre,
 * via JS, que les options du select des noms partageant ce data-groupe.
 */
function selectPersonnelFiltrable(array $vivier, string $nomChamp, string $idBase, string $texteVide, ?callable $formatLabel = null): void
{
    $formatLabel = $formatLabel ?? fn ($p) => $p['nom'] . ' ' . $p['prenoms'];

    $groupesPresents = [];
    foreach ($vivier as $p) {
        $groupesPresents[$p['groupe_role']] = true;
    }
    ?>
    <?php if (count($groupesPresents) > 1): ?>
        <select class="form-select form-select-sm mb-1 filtre-categorie-personnel" data-cible="<?= $idBase ?>">
            <option value="">Toutes catégories</option>
            <?php foreach (array_keys($groupesPresents) as $groupe): ?>
                <option value="<?= htmlspecialchars($groupe) ?>"><?= htmlspecialchars($groupe) ?>s</option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <select name="<?= $nomChamp ?>" id="<?= $idBase ?>" class="form-select form-select-sm" required>
        <option value="">— <?= htmlspecialchars($texteVide) ?> —</option>
        <?php foreach ($vivier as $p): ?>
            <option value="<?= $p['id'] ?>" data-groupe="<?= htmlspecialchars($p['groupe_role']) ?>"><?= htmlspecialchars($formatLabel($p)) ?></option>
        <?php endforeach; ?>
    </select>
    <?php
}

/**
 * Case à cocher "Forcer malgré un conflit" : permet de passer outre le
 * refus anti-collusion (École propre/Groupe Scolaire, règles 6.2.A.1/6.2.A.2).
 */
function checkboxForcerConflit(string $idBase): void
{
    ?>
    <div class="form-check mt-1">
        <input type="checkbox" name="forcer_conflit" class="form-check-input" id="<?= $idBase ?>">
        <label class="form-check-label small" for="<?= $idBase ?>">Forcer malgré un conflit</label>
    </div>
    <?php
}

// Verrouillage global : uniquement les rôles à présence physique unique. Le
// rôle Superviseur ne verrouille PAS globalement — un superviseur peut couvrir
// plusieurs centres sur le même examen (cf. AffectationEngine::enregistrerAffectation).
$dejaVerrouilles = array_keys(array_filter($rolesUniques, fn ($role) => $role !== 'Superviseur'));

$vivierPresidentChef = vivierParCategorie($pdo, ['enseignant', 'administratif', 'conseiller'], $dejaVerrouilles);
$vivierSecretariat    = vivierParCategorie($pdo, ['enseignant', 'administratif', 'conseiller'], $dejaVerrouilles);
$vivierSuperviseursBase = vivierParCategorie($pdo, ['enseignant', 'administratif', 'conseiller'], $dejaVerrouilles);
$vivierSurveillants   = $engine->viveirEnseignantsDisponibles($typeExamenLibelle);
foreach ($vivierSurveillants as &$p) {
    $p['groupe_role'] = stripos((string) $p['fonction'], 'directeur') === 0 ? 'Directeur' : 'Adjoint';
}
unset($p);

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

    <div id="messages-affectations">

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

    </div>

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

            <form method="post" class="d-inline" onsubmit="return confirm('Retirer TOUTES les affectations automatiques (Surveillants non manuelles) de cet examen ? Les rôles saisis à la main (Président, Secrétariat, Superviseur, et surveillants ajoutés manuellement) seront conservés.');">
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

        // Un superviseur déjà affecté à CE centre ne doit pas réapparaître dans le
        // select (mais reste sélectionnable pour les AUTRES centres, cf. plus haut).
        $superviseursIdsCentre = array_column($superviseurs, 'personnel_id');
        $vivierSuperviseurs = array_values(array_filter(
            $vivierSuperviseursBase,
            fn ($p) => !in_array((int) $p['id'], $superviseursIdsCentre, true)
        ));
    ?>
    <div class="card mb-4" id="centre-card-<?= $centreId ?>">
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
                            <div class="flex-grow-1">
                                <?php selectPersonnelFiltrable($vivierPresidentChef, 'personnel_id', 'selectPresident' . $centreId, 'Choisir'); ?>
                                <?php checkboxForcerConflit('forcerPresident' . $centreId); ?>
                            </div>
                            <button class="btn btn-sm btn-outline-primary align-self-start"><i class="bi bi-check"></i></button>
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
                            <div class="flex-grow-1">
                                <?php selectPersonnelFiltrable($vivierPresidentChef, 'personnel_id', 'selectChefSecretariat' . $centreId, 'Choisir'); ?>
                                <?php checkboxForcerConflit('forcerChefSecretariat' . $centreId); ?>
                            </div>
                            <button class="btn btn-sm btn-outline-primary align-self-start"><i class="bi bi-check"></i></button>
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
                        <div class="flex-grow-1">
                            <?php selectPersonnelFiltrable($vivierSecretariat, 'personnel_id', 'selectSecretariat' . $centreId, 'Ajouter'); ?>
                            <?php checkboxForcerConflit('forcerSecretariat' . $centreId); ?>
                        </div>
                        <button class="btn btn-sm btn-outline-primary align-self-start"><i class="bi bi-plus"></i></button>
                    </form>
                </div>

                <div class="col-md-3">
                    <label class="form-label small text-muted">Superviseur(s)</label>
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
                        <div class="flex-grow-1">
                            <?php
                            selectPersonnelFiltrable(
                                $vivierSuperviseurs,
                                'personnel_id',
                                'selectSuperviseur' . $centreId,
                                'Ajouter',
                                fn ($p) => $p['nom'] . ' ' . $p['prenoms'] . ($p['sous_type'] ? ' (' . $p['sous_type'] . ')' : '')
                            );
                            checkboxForcerConflit('forcerSuperviseur' . $centreId);
                            ?>
                        </div>
                        <button class="btn btn-sm btn-outline-primary align-self-start"><i class="bi bi-plus"></i></button>
                    </form>
                </div>

            </div>

            <hr>

            <div class="row">
                <div class="col-md-8">
                    <label class="form-label small text-muted">Surveillants (<?= $compteSurveillance ?>)</label>
                    <div class="table-responsive" style="max-height: 260px; overflow-y:auto;">
                        <table class="table table-sm table-striped align-middle mb-0">
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
                        <?php
                        selectPersonnelFiltrable(
                            $vivierSurveillants,
                            'personnel_id',
                            'selectSurveillant' . $centreId,
                            'Choisir',
                            fn ($p) => $p['nom'] . ' ' . $p['prenoms'] . ' (' . ($p['niveau_tenu'] ?: '—') . ', ' . $p['type_ecole'] . ')'
                        );
                        ?>
                        <div class="form-check mb-2 mt-2">
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

<script>
// Filtre "Catégorie" au-dessus d'un select de personnel (Président, Chef de
// Secrétariat, Membres du Secrétariat) : ne montre que les options dont
// data-groupe correspond à la catégorie choisie, pour retrouver un nom plus
// vite dans une longue liste. "Toutes catégories" réaffiche tout.
function optionVisibleSelonFiltre(select, option) {
    var filtre = document.querySelector('.filtre-categorie-personnel[data-cible="' + select.id + '"]');
    if (!filtre || !filtre.value) return true;
    return option.dataset.groupe === filtre.value;
}

function estSelectSuperviseur(select) {
    return select.id.indexOf('selectSuperviseur') === 0;
}

// Une même personne ne doit jamais rester sélectionnable pour deux rôles à
// la fois sur cette page — même si le serveur refuse déjà la deuxième
// affectation à l'enregistrement (règle de non-redondance), la choisir dans
// deux selects avant de valider prête à confusion sur celle qui "gagnera".
// Dès qu'elle est choisie quelque part, elle disparaît des autres selects
// tant qu'elle n'est pas retirée d'ici — sauf entre selects Superviseur,
// où une même personne peut légitimement couvrir plusieurs centres.
function actualiserExclusionsPersonnel() {
    var tousLesSelects = document.querySelectorAll('select[name="personnel_id"]');
    var choisisPar = {};
    tousLesSelects.forEach(function (s) {
        if (!s.value) return;
        choisisPar[s.value] = choisisPar[s.value] || [];
        choisisPar[s.value].push(s);
    });

    tousLesSelects.forEach(function (select) {
        var valeurActuelle = select.value;
        Array.prototype.forEach.call(select.options, function (option) {
            if (!option.value) return; // "— Choisir —"

            var exclue = false;
            (choisisPar[option.value] || []).forEach(function (autreSelect) {
                if (autreSelect === select) return;
                if (estSelectSuperviseur(select) && estSelectSuperviseur(autreSelect)) return;
                exclue = true;
            });

            var visible = optionVisibleSelonFiltre(select, option) && !exclue;
            option.hidden = !visible;
            option.disabled = !visible;
        });
    });
}

document.addEventListener('change', function (evenement) {
    var cible = evenement.target;

    if (cible.classList && cible.classList.contains('filtre-categorie-personnel')) {
        var select = document.getElementById(cible.dataset.cible);
        if (select) select.value = '';
        actualiserExclusionsPersonnel();
        return;
    }

    if (cible.matches && cible.matches('select[name="personnel_id"]')) {
        actualiserExclusionsPersonnel();
    }
});

document.addEventListener('DOMContentLoaded', actualiserExclusionsPersonnel);
</script>

<script>
// Chaque rôle (Président, Chef de Secrétariat, un Membre du Secrétariat,
// Superviseur, Surveillant manuel...) a son propre petit formulaire sur la
// page : sans interception, valider L'UN d'eux recharge toute la page et
// efface les choix pas encore validés dans les AUTRES selects (ex. avoir
// déjà choisi un nom pour le Président avant de cliquer "+" pour ajouter un
// Membre du Secrétariat). Même mécanisme que sur Plans de salle : seule la
// carte du centre concerné (et la zone de messages) est remplacée par sa
// version fraîchement rendue par le serveur, sans rechargement complet.
document.addEventListener('submit', async function (evenement) {

    if (evenement.defaultPrevented) {
        return;
    }

    var formulaire = evenement.target;
    var carte = formulaire.closest('[id^="centre-card-"]');

    if (!carte) {
        return;
    }

    evenement.preventDefault();

    var donnees = new FormData(formulaire);
    if (evenement.submitter && evenement.submitter.name) {
        donnees.append(evenement.submitter.name, evenement.submitter.value || '1');
    }

    var appliquerReponse = function (texteHtml) {
        var docFrais = new DOMParser().parseFromString(texteHtml, 'text/html');
        var messagesFrais = docFrais.getElementById('messages-affectations');
        var messagesActuels = document.getElementById('messages-affectations');

        if (!docFrais.getElementById(carte.id)) {
            // Réponse inattendue : on retombe sur un envoi classique plutôt
            // que de laisser l'action sans effet visible.
            formulaire.submit();
            return;
        }

        // Verrouiller quelqu'un sur CE centre le retire aussi des selects de
        // TOUS les autres centres (règle de non-redondance) : si on ne
        // rafraîchissait que la carte du formulaire soumis, les autres
        // cartes déjà affichées garderaient une liste périmée où cette
        // personne resterait sélectionnable jusqu'au prochain rechargement
        // complet. On remplace donc chaque carte actuellement affichée par
        // sa version fraîche — en capturant/réappliquant, pour chacune, les
        // choix pas encore validés dans ses autres selects (ex. un nom déjà
        // choisi pour Président pendant qu'on valide l'ajout d'un Membre du
        // Secrétariat sur la même carte), tant qu'ils désignent toujours une
        // option valide.
        document.querySelectorAll('[id^="centre-card-"]').forEach(function (carteActuelle) {
            var carteFraiche = docFrais.getElementById(carteActuelle.id);
            if (!carteFraiche) return;

            var valeursAvant = {};
            carteActuelle.querySelectorAll('select[name="personnel_id"]').forEach(function (s) {
                if (s.value) valeursAvant[s.id] = s.value;
            });

            carteActuelle.outerHTML = carteFraiche.outerHTML;

            var carteMiseAJour = document.getElementById(carteActuelle.id);
            if (carteMiseAJour) {
                Object.keys(valeursAvant).forEach(function (id) {
                    var s = document.getElementById(id);
                    if (!s) return;
                    var toujoursValide = Array.prototype.some.call(s.options, function (o) {
                        return o.value === valeursAvant[id];
                    });
                    if (toujoursValide) s.value = valeursAvant[id];
                });
            }
        });

        if (messagesFrais && messagesActuels) {
            messagesActuels.outerHTML = messagesFrais.outerHTML;
        }

        actualiserExclusionsPersonnel();
    };

    try {
        // Pas de "formulaire.action" ici : chaque formulaire de ce fichier a
        // un champ caché <input name="action"> (affecter_role, supprimer...)
        // qui masque la propriété native form.action du DOM (elle renvoie
        // alors cet élément au lieu de l'URL). Aucun de ces formulaires n'a
        // d'attribut HTML action= — ils visent donc tous la page courante.
        var urlCible = formulaire.getAttribute('action') || window.location.href;
        var reponse = await fetch(urlCible, {
            method: 'POST',
            body: donnees
        });

        if (!reponse.ok) {
            throw new Error('HTTP ' + reponse.status);
        }

        appliquerReponse(await reponse.text());

    } catch (erreur) {
        // Souci réseau ou serveur : on retombe sur le comportement classique
        // (rechargement complet) pour ne jamais bloquer l'utilisateur.
        formulaire.submit();
    }

}, false);
</script>

<?php include '../views/layouts/footer.php'; ?>
