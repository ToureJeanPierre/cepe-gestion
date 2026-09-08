<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

use Iepp\CepeGestion\AffectationEngine;

$pageTitle = 'Accueil';

// Année scolaire consultée : résolue globalement par config/database.php
// ($anneeId, $ANNEE_SCOLAIRE, $anneeSelectionnee, $anneeLectureSeule)
$annee = $anneeSelectionnee;

// ==========================================
// KPI 1 : ÉCOLES (référentiel permanent, non filtré par année)
// ==========================================
$totalEcoles = (int) $pdo->query("SELECT COUNT(*) FROM ecoles")->fetchColumn();

$stmtEcolesStatut = $pdo->query("
    SELECT statut, COUNT(*) AS nb
    FROM ecoles
    GROUP BY statut
");
$ecolesParStatut = ['Public' => 0, 'Privé' => 0];
foreach ($stmtEcolesStatut->fetchAll() as $row) {
    $ecolesParStatut[$row['statut']] = (int) $row['nb'];
}

$stmtEcolesRattachement = $pdo->query("
    SELECT
        SUM(CASE WHEN ecole_tutrice_id IS NULL THEN 1 ELSE 0 END) AS autonomes,
        SUM(CASE WHEN ecole_tutrice_id IS NOT NULL THEN 1 ELSE 0 END) AS rattachees
    FROM ecoles
");
$rowEcolesRattachement = $stmtEcolesRattachement->fetch();
$nbEcolesAutonomes = (int) ($rowEcolesRattachement['autonomes'] ?? 0);
$nbEcolesRattachees = (int) ($rowEcolesRattachement['rattachees'] ?? 0);

if ($anneeId) {
    $stmtCentres = $pdo->prepare("SELECT COUNT(*) FROM centres WHERE annee_id = ?");
    $stmtCentres->execute([$anneeId]);
    $totalCentres = (int) $stmtCentres->fetchColumn();
} else {
    $totalCentres = 0;
}

// ==========================================
// KPI 2 : PERSONNEL ENSEIGNANT
// ==========================================
$totalEnseignants = (int) $pdo->query("SELECT COUNT(*) FROM personnel WHERE categorie = 'enseignant'")->fetchColumn();

$stmtEnsFonction = $pdo->query("
    SELECT
        SUM(CASE WHEN fonction LIKE 'Directeur%' THEN 1 ELSE 0 END) AS directeurs,
        SUM(CASE WHEN fonction = 'Adjoint' THEN 1 ELSE 0 END) AS adjoints
    FROM personnel
    WHERE categorie = 'enseignant'
");
$rowEnsFonction = $stmtEnsFonction->fetch();
$enseignantsParFonction = [
    'Directeur' => (int) ($rowEnsFonction['directeurs'] ?? 0),
    'Adjoint' => (int) ($rowEnsFonction['adjoints'] ?? 0),
];

// ==========================================
// KPI 3 : CANDIDATS (rattachés à l'année scolaire consultée)
// ==========================================
$stmtTotalCand = $pdo->prepare("SELECT COUNT(*) FROM candidats WHERE annee_id = ?");
$stmtTotalCand->execute([$anneeId]);
$totalCandidats = (int) $stmtTotalCand->fetchColumn();

$stmtCandidatsType = $pdo->prepare("
    SELECT
        SUM(CASE WHEN est_candidat_libre = 1 THEN 1 ELSE 0 END) AS libres,
        SUM(CASE WHEN est_candidat_libre = 0 THEN 1 ELSE 0 END) AS officiels
    FROM candidats
    WHERE annee_id = ?
");
$stmtCandidatsType->execute([$anneeId]);
$rowCandType = $stmtCandidatsType->fetch();
$nbCandidatsLibres = (int) ($rowCandType['libres'] ?? 0);
$nbCandidatsOfficiels = (int) ($rowCandType['officiels'] ?? 0);

// Couverture immatriculation : Immatriculés / Non-immatriculés (extrait en cours) / Sans extrait
$stmtImmat = $pdo->prepare("
    SELECT
        SUM(CASE WHEN matricule_dsps IS NOT NULL AND matricule_dsps != '' THEN 1 ELSE 0 END) AS immatricules,
        SUM(CASE WHEN (matricule_dsps IS NULL OR matricule_dsps = '') AND a_acte_naissance = 1 THEN 1 ELSE 0 END) AS non_immatricules,
        SUM(CASE WHEN (matricule_dsps IS NULL OR matricule_dsps = '') AND a_acte_naissance = 0 THEN 1 ELSE 0 END) AS sans_extrait
    FROM candidats
    WHERE annee_id = ?
");
$stmtImmat->execute([$anneeId]);
$rowImmat = $stmtImmat->fetch();
$nbImmatricules = (int) ($rowImmat['immatricules'] ?? 0);
$nbNonImmatricules = (int) ($rowImmat['non_immatricules'] ?? 0);
$nbSansExtrait = (int) ($rowImmat['sans_extrait'] ?? 0);

$tauxImmatriculation = $totalCandidats > 0
    ? round(($nbImmatricules / $totalCandidats) * 100, 1)
    : 0;

// ==========================================
// KPI 4 : TAUX D'AFFECTATION DES SURVEILLANTS PAR CENTRE
// ==========================================
// Calculé sur l'examen le plus avancé de l'année (le plus souvent l'Examen Final) :
// proportion des centres ayant au moins un surveillant affecté.
$stmtExamenRef = $pdo->prepare("SELECT code, libelle FROM examens WHERE annee_id = ? AND actif = 1 ORDER BY ordre DESC LIMIT 1");
$stmtExamenRef->execute([$anneeId]);
$examenRef = $stmtExamenRef->fetch();

$tauxAffectationSurveillants = null;
$nbCentresAffectes = 0;
if ($examenRef && $totalCentres > 0) {
    $typeExamenRef = AffectationEngine::libelleTypeExamen($examenRef['code']);
    $stmtCentresAffectes = $pdo->prepare("
        SELECT COUNT(DISTINCT centre_id) FROM affectations
        WHERE annee_id = ? AND type_examen = ? AND role = 'Surveillant'
    ");
    $stmtCentresAffectes->execute([$anneeId, $typeExamenRef]);
    $nbCentresAffectes = (int) $stmtCentresAffectes->fetchColumn();
    $tauxAffectationSurveillants = round(($nbCentresAffectes / $totalCentres) * 100, 1);
}

include '../views/layouts/header.php';
?>

<div class="card shadow">
    <div class="card-header">
        <h3>Bienvenue - Application de Gestion du CEPE</h3>
        <p class="mb-0">
            IEPP Yopougon-Niangon - Service Examens et Concours
            <?php if ($annee): ?>
                &mdash; Année scolaire <strong><?= htmlspecialchars($annee['annee_scolaire']) ?></strong>
            <?php endif; ?>
        </p>
    </div>
    <div class="card-body">

        <div class="row g-3">
            <div class="col-md-3">
                <a href="ecoles.php" class="text-decoration-none">
                    <div class="card text-center bg-primary text-white h-100">
                        <div class="card-body">
                            <h5>🏫 Écoles</h5>
                            <h2><?= $totalEcoles ?></h2>
                            <small><?= $ecolesParStatut['Public'] ?> Public · <?= $ecolesParStatut['Privé'] ?> Privé<br><?= $nbEcolesAutonomes ?> Autonomes · <?= $nbEcolesRattachees ?> Tutrices/Rattachées</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="enseignants.php" class="text-decoration-none">
                    <div class="card text-center bg-success text-white h-100">
                        <div class="card-body">
                            <h5>👨‍🏫 Enseignants</h5>
                            <h2><?= $totalEnseignants ?></h2>
                            <small><?= $enseignantsParFonction['Directeur'] ?? 0 ?> Directeurs · <?= $enseignantsParFonction['Adjoint'] ?? 0 ?> Adjoints</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="candidats.php" class="text-decoration-none">
                    <div class="card text-center bg-warning text-white h-100">
                        <div class="card-body">
                            <h5>👥 Candidats</h5>
                            <h2><?= $totalCandidats ?></h2>
                            <small><?= $nbCandidatsOfficiels ?> Officiels · <?= $nbCandidatsLibres ?> Libres</small>
                        </div>
                    </div>
                </a>
            </div>
            <div class="col-md-3">
                <a href="centres.php" class="text-decoration-none">
                    <div class="card text-center bg-info text-white h-100">
                        <div class="card-body">
                            <h5>📍 Centres</h5>
                            <h2><?= $totalCentres ?></h2>
                            <small>Année <?= htmlspecialchars($ANNEE_SCOLAIRE) ?></small>
                        </div>
                    </div>
                </a>
            </div>
        </div>

        <div class="row g-3 mt-1">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">📋 Couverture de l'immatriculation</h6>
                        <?php if ($totalCandidats > 0): ?>
                            <div class="progress mb-2" style="height: 22px;">
                                <div class="progress-bar bg-success" style="width: <?= $tauxImmatriculation ?>%">
                                    <?= $tauxImmatriculation ?>%
                                </div>
                            </div>
                            <ul class="list-unstyled mb-0 small">
                                <li>✅ Immatriculés : <strong><?= $nbImmatricules ?></strong></li>
                                <li>⏳ Non-immatriculés (extrait en cours) : <strong><?= $nbNonImmatricules ?></strong></li>
                                <li>⚠️ Sans extrait : <strong><?= $nbSansExtrait ?></strong></li>
                            </ul>
                        <?php else: ?>
                            <p class="text-muted mb-0">Aucun candidat enregistré pour le moment.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h6 class="card-title">🎯 Affectation des surveillants par centre</h6>
                        <?php if ($tauxAffectationSurveillants !== null): ?>
                            <div class="progress mb-2" style="height: 22px;">
                                <div class="progress-bar bg-info" style="width: <?= $tauxAffectationSurveillants ?>%">
                                    <?= $tauxAffectationSurveillants ?>%
                                </div>
                            </div>
                            <p class="mb-0 small">
                                <strong><?= $nbCentresAffectes ?></strong> / <?= $totalCentres ?> centres pourvus
                                — <?= htmlspecialchars($examenRef['libelle']) ?>
                            </p>
                        <?php else: ?>
                            <p class="text-muted mb-0">Aucun centre configuré pour cette année.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="alert alert-info mt-4 mb-0">
            <strong>💡 Accès rapides :</strong>
            <a href="candidats.php" class="btn btn-sm btn-outline-primary ms-2">📥 Importer un fichier</a>
            <a href="plans.php" class="btn btn-sm btn-outline-primary ms-2">🧮 Calculer les plans de salle</a>
            <a href="affectations.php" class="btn btn-sm btn-outline-primary ms-2">🎯 Générer les surveillants</a>
            <a href="ecoles.php" class="btn btn-sm btn-outline-secondary ms-2">Gérer les écoles</a>
        </div>

    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
