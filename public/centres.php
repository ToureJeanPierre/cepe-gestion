<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Centres d\'Examen';

$ANNEE_SCOLAIRE = '2026-2027';

$success = null;
$error = null;


/*
|--------------------------------------------------------------------------
| RÉCUPÉRER L'ANNÉE SCOLAIRE
|--------------------------------------------------------------------------
*/

$stmtAnnee = $pdo->prepare("
    SELECT id, annee_scolaire, statut
    FROM annees
    WHERE annee_scolaire = ?
    LIMIT 1
");

$stmtAnnee->execute([$ANNEE_SCOLAIRE]);

$annee = $stmtAnnee->fetch();

if (!$annee) {
    die("L'année scolaire 2026-2027 n'existe pas dans la base de données.");
}

$anneeId = (int) $annee['id'];


/*
|--------------------------------------------------------------------------
| MESSAGES
|--------------------------------------------------------------------------
*/

if (isset($_GET['msg'])) {

    switch ($_GET['msg']) {

        case 'centre_cree':
            $success = "Le centre a été créé pour l'année 2026-2027.";
            break;

        case 'ecole_affectee':
            $success = "L'école a été affectée au centre avec succès.";
            break;

        case 'ecole_retiree':
            $success = "L'affectation a été supprimée.";
            break;

        case 'import_termine':
            $success = "Importation Excel terminée.";
            break;

        case 'effectif_enregistre':
            $success = "L'effectif retenu a été enregistré manuellement.";
            break;

        case 'effectif_auto':
            $success = "Le centre est de nouveau en mode de calcul automatique.";
            break;
    }
}


/*
|--------------------------------------------------------------------------
| CRÉER UN NOUVEAU CENTRE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['creer_centre'])
) {

    $ecoleId = isset($_POST['ecole_id'])
        ? (int) $_POST['ecole_id']
        : 0;

    if ($ecoleId <= 0) {

        $error = "Veuillez sélectionner une école.";

    } else {

        try {

            $stmt = $pdo->prepare("
                SELECT id, nom
                FROM ecoles
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$ecoleId]);

            $ecole = $stmt->fetch();

            if (!$ecole) {
                throw new Exception("École introuvable.");
            }


            /*
             * Vérifier que cette école n'est pas déjà
             * un centre pour 2026-2027.
             */

            $stmt = $pdo->prepare("
                SELECT id
                FROM centres
                WHERE ecole_id = ?
                  AND annee_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $ecoleId,
                $anneeId
            ]);

            if ($stmt->fetch()) {

                throw new Exception(
                    "Cette école est déjà désignée comme centre pour 2026-2027."
                );
            }


            /*
             * Créer le centre.
             */

            $stmt = $pdo->prepare("
                INSERT INTO centres
                    (ecole_id, annee_id, annee_scolaire)
                VALUES
                    (?, ?, ?)
            ");

            $stmt->execute([
                $ecoleId,
                $anneeId,
                $ANNEE_SCOLAIRE
            ]);

            header("Location: centres.php?msg=centre_cree");
            exit;

        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| AFFECTER UNE ÉCOLE À UN CENTRE
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['ajouter_ecole'])
) {

    $centreId = isset($_POST['centre_id'])
        ? (int) $_POST['centre_id']
        : 0;

    $ecoleId = isset($_POST['ecole_id'])
        ? (int) $_POST['ecole_id']
        : 0;


    if ($centreId <= 0 || $ecoleId <= 0) {

        $error = "Veuillez sélectionner le centre et l'école.";

    } else {

        try {

            /*
             * Vérifier le centre.
             */

            $stmt = $pdo->prepare("
                SELECT id
                FROM centres
                WHERE id = ?
                  AND annee_id = ?
                LIMIT 1
            ");

            $stmt->execute([
                $centreId,
                $anneeId
            ]);

            if (!$stmt->fetch()) {

                throw new Exception(
                    "Centre invalide pour l'année 2026-2027."
                );
            }


            /*
             * Vérifier l'école.
             */

            $stmt = $pdo->prepare("
                SELECT id, nom
                FROM ecoles
                WHERE id = ?
                LIMIT 1
            ");

            $stmt->execute([$ecoleId]);

            $ecole = $stmt->fetch();

            if (!$ecole) {

                throw new Exception(
                    "École introuvable."
                );
            }


            /*
             * Vérifier si l'école est déjà affectée.
             */

            $stmt = $pdo->prepare("
                SELECT
                    ec.centre_id,
                    centre_ecole.nom AS nom_centre

                FROM ecole_centre AS ec

                INNER JOIN centres AS c
                    ON c.id = ec.centre_id

                INNER JOIN ecoles AS centre_ecole
                    ON centre_ecole.id = c.ecole_id

                WHERE ec.ecole_composante_id = ?
                  AND c.annee_id = ?

                LIMIT 1
            ");

            $stmt->execute([
                $ecoleId,
                $anneeId
            ]);

            $affectationExistante = $stmt->fetch();


            if ($affectationExistante) {

                if (
                    (int) $affectationExistante['centre_id']
                    === $centreId
                ) {

                    throw new Exception(
                        "Cette école est déjà affectée à ce centre."
                    );

                } else {

                    throw new Exception(
                        "Cette école est déjà affectée au centre : "
                        . $affectationExistante['nom_centre']
                        . ". Retirez d'abord cette affectation avant de la déplacer."
                    );
                }
            }


            /*
             * Enregistrer.
             */

            $stmt = $pdo->prepare("
                INSERT INTO ecole_centre
                    (centre_id, ecole_composante_id)
                VALUES
                    (?, ?)
            ");

            $stmt->execute([
                $centreId,
                $ecoleId
            ]);


            header("Location: centres.php?msg=ecole_affectee");
            exit;

        } catch (Exception $e) {

            $error = $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| SUPPRIMER UNE AFFECTATION
|--------------------------------------------------------------------------
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['supprimer_affectation'])
) {

    $centreId = isset($_POST['centre_id'])
        ? (int) $_POST['centre_id']
        : 0;

    $ecoleId = isset($_POST['ecole_id'])
        ? (int) $_POST['ecole_id']
        : 0;


    if ($centreId <= 0 || $ecoleId <= 0) {

        $error = "Affectation invalide.";

    } else {

        try {

            $stmt = $pdo->prepare("
                DELETE FROM ecole_centre
                WHERE centre_id = ?
                  AND ecole_composante_id = ?
            ");

            $stmt->execute([
                $centreId,
                $ecoleId
            ]);


            header("Location: centres.php?msg=ecole_retiree");
            exit;

        } catch (Exception $e) {

            $error = "Impossible de supprimer cette affectation.";
        }
    }
}



/*
|--------------------------------------------------------------------------
| ENREGISTRER / MODIFIER L'EFFECTIF D'UN CENTRE
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['enregistrer_effectif'])
) {
    $centreId = isset($_POST['centre_id']) ? (int) $_POST['centre_id'] : 0;
    $examenId = isset($_POST['examen_id']) ? (int) $_POST['examen_id'] : 0;
    $effectif = isset($_POST['effectif_retenu'])
        ? trim($_POST['effectif_retenu'])
        : '';
    $commentaire = trim($_POST['commentaire'] ?? '');

    if ($centreId <= 0 || $examenId <= 0 || $effectif === '') {
        $error = "Veuillez renseigner un effectif valide.";
    } elseif (!ctype_digit($effectif) || (int) $effectif < 0) {
        $error = "L'effectif doit être un nombre entier positif ou nul.";
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT c.id
                FROM centres AS c
                INNER JOIN examens AS ex
                    ON ex.annee_id = c.annee_id
                WHERE c.id = ?
                  AND ex.id = ?
                  AND c.annee_id = ?
                LIMIT 1
            ");
            $stmt->execute([$centreId, $examenId, $anneeId]);

            if (!$stmt->fetch()) {
                throw new Exception("Centre ou examen invalide pour 2026-2027.");
            }

            $stmt = $pdo->prepare("
                INSERT INTO centre_effectifs
                    (
                        centre_id,
                        examen_id,
                        effectif_calcule,
                        effectif_retenu,
                        est_manuel,
                        commentaire
                    )
                VALUES
                    (?, ?, 0, ?, 1, ?)
                ON DUPLICATE KEY UPDATE
                    effectif_retenu = VALUES(effectif_retenu),
                    est_manuel = 1,
                    commentaire = VALUES(commentaire)
            ");

            $stmt->execute([
                $centreId,
                $examenId,
                (int) $effectif,
                $commentaire !== '' ? $commentaire : null
            ]);

            header("Location: centres.php?msg=effectif_enregistre");
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| REMETTRE L'EFFECTIF EN MODE AUTOMATIQUE
|--------------------------------------------------------------------------
*/
if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['reinitialiser_effectif'])
) {
    $centreId = isset($_POST['centre_id']) ? (int) $_POST['centre_id'] : 0;
    $examenId = isset($_POST['examen_id']) ? (int) $_POST['examen_id'] : 0;

    if ($centreId <= 0 || $examenId <= 0) {
        $error = "Effectif invalide.";
    } else {
        try {
            $stmt = $pdo->prepare("
                UPDATE centre_effectifs
                SET
                    effectif_retenu = effectif_calcule,
                    est_manuel = 0,
                    commentaire = NULL
                WHERE centre_id = ?
                  AND examen_id = ?
            ");
            $stmt->execute([$centreId, $examenId]);

            header("Location: centres.php?msg=effectif_auto");
            exit;
        } catch (Exception $e) {
            $error = "Impossible de rétablir le calcul automatique.";
        }
    }
}

/*
|--------------------------------------------------------------------------
| IMPORT EXCEL
|--------------------------------------------------------------------------
|
| COLONNE A = ÉCOLE
| COLONNE B = CENTRE
|
*/

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['importer_excel'])
) {

    if (
        !isset($_FILES['fichier'])
        || $_FILES['fichier']['error'] !== UPLOAD_ERR_OK
    ) {

        $error = "Veuillez sélectionner un fichier Excel valide.";

    } else {

        try {

            $extension = strtolower(
                pathinfo(
                    $_FILES['fichier']['name'],
                    PATHINFO_EXTENSION
                )
            );


            if (
                !in_array(
                    $extension,
                    ['xlsx', 'xls'],
                    true
                )
            ) {

                throw new Exception(
                    "Le fichier doit être au format Excel .xlsx ou .xls."
                );
            }


            $spreadsheet = IOFactory::load(
                $_FILES['fichier']['tmp_name']
            );


            $rows = $spreadsheet
                ->getActiveSheet()
                ->toArray(
                    null,
                    true,
                    true,
                    true
                );


            if (count($rows) <= 1) {

                throw new Exception(
                    "Le fichier Excel ne contient aucune donnée."
                );
            }


            /*
             * Retirer l'en-tête.
             */

            array_shift($rows);


            $importees = 0;
            $dejaAffectees = 0;
            $ecolesIntrouvables = 0;
            $centresIntrouvables = 0;

            $erreurs = [];


            foreach ($rows as $numeroLigne => $row) {

                /*
                 * A = École
                 * B = Centre
                 */

                $nomEcole = trim(
                    $row['A'] ?? ''
                );

                $nomCentre = trim(
                    $row['B'] ?? ''
                );


                /*
                 * Ignorer une ligne totalement vide.
                 */

                if (
                    $nomEcole === ''
                    && $nomCentre === ''
                ) {
                    continue;
                }


                /*
                 * Ligne partiellement remplie.
                 */

                if (
                    $nomEcole === ''
                    || $nomCentre === ''
                ) {

                    $erreurs[] =
                        "Ligne "
                        . ($numeroLigne + 2)
                        . " : école ou centre manquant.";

                    continue;
                }


                /*
                 * Rechercher le centre.
                 */

                $stmt = $pdo->prepare("
                    SELECT
                        c.id,
                        e.nom
                    FROM centres AS c

                    INNER JOIN ecoles AS e
                        ON e.id = c.ecole_id

                    WHERE c.annee_id = ?
                      AND LOWER(TRIM(e.nom))
                          = LOWER(TRIM(?))

                    LIMIT 1
                ");

                $stmt->execute([
                    $anneeId,
                    $nomCentre
                ]);

                $centre = $stmt->fetch();


                if (!$centre) {

                    $centresIntrouvables++;

                    $erreurs[] =
                        "Ligne "
                        . ($numeroLigne + 2)
                        . " : centre introuvable pour 2026-2027 : "
                        . $nomCentre;

                    continue;
                }


                /*
                 * Rechercher l'école.
                 */

                $stmt = $pdo->prepare("
                    SELECT
                        id,
                        nom
                    FROM ecoles

                    WHERE LOWER(TRIM(nom))
                          = LOWER(TRIM(?))

                    LIMIT 1
                ");

                $stmt->execute([
                    $nomEcole
                ]);

                $ecole = $stmt->fetch();


                if (!$ecole) {

                    $ecolesIntrouvables++;

                    $erreurs[] =
                        "Ligne "
                        . ($numeroLigne + 2)
                        . " : école introuvable : "
                        . $nomEcole;

                    continue;
                }


                /*
                 * Vérifier si l'école est déjà affectée.
                 */

                $stmt = $pdo->prepare("
                    SELECT
                        ec.centre_id,
                        centre_ecole.nom AS nom_centre

                    FROM ecole_centre AS ec

                    INNER JOIN centres AS c
                        ON c.id = ec.centre_id

                    INNER JOIN ecoles AS centre_ecole
                        ON centre_ecole.id = c.ecole_id

                    WHERE ec.ecole_composante_id = ?
                      AND c.annee_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $ecole['id'],
                    $anneeId
                ]);

                $existante = $stmt->fetch();


                if ($existante) {

                    $dejaAffectees++;


                    if (
                        (int) $existante['centre_id']
                        === (int) $centre['id']
                    ) {

                        continue;
                    }


                    $erreurs[] =
                        "Ligne "
                        . ($numeroLigne + 2)
                        . " : "
                        . $nomEcole
                        . " est déjà affectée au centre "
                        . $existante['nom_centre']
                        . ".";

                    continue;
                }


                /*
                 * Enregistrer.
                 */

                $stmt = $pdo->prepare("
                    INSERT INTO ecole_centre
                        (centre_id, ecole_composante_id)
                    VALUES
                        (?, ?)
                ");

                $stmt->execute([
                    $centre['id'],
                    $ecole['id']
                ]);


                $importees++;
            }


            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }


            $_SESSION['import_centres_result'] = [

                'importees' =>
                    $importees,

                'dejaAffectees' =>
                    $dejaAffectees,

                'ecolesIntrouvables' =>
                    $ecolesIntrouvables,

                'centresIntrouvables' =>
                    $centresIntrouvables,

                'erreurs' =>
                    $erreurs
            ];


            header(
                "Location: centres.php?msg=import_termine"
            );

            exit;


        } catch (Exception $e) {

            $error =
                "Erreur lors de l'import Excel : "
                . $e->getMessage();
        }
    }
}


/*
|--------------------------------------------------------------------------
| RÉSULTAT DU DERNIER IMPORT
|--------------------------------------------------------------------------
*/

$importResult = null;


if (session_status() === PHP_SESSION_NONE) {
    session_start();
}


if (isset($_SESSION['import_centres_result'])) {

    $importResult =
        $_SESSION['import_centres_result'];

    unset(
        $_SESSION['import_centres_result']
    );
}


/*
|--------------------------------------------------------------------------
| LISTE DES CENTRES 2026-2027
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        c.id AS centre_id,
        c.ecole_id AS ecole_centre_id,
        e.nom AS nom_centre,
        e.code_dsps AS code_centre

    FROM centres AS c

    INNER JOIN ecoles AS e
        ON e.id = c.ecole_id

    WHERE c.annee_id = ?

    ORDER BY e.nom ASC
");

$stmt->execute([
    $anneeId
]);

$centres = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| LISTE DE TOUTES LES ÉCOLES
|--------------------------------------------------------------------------
*/

$stmt = $pdo->query("
    SELECT
        id,
        nom,
        code_dsps

    FROM ecoles

    ORDER BY nom ASC
");

$toutesLesEcoles = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| CONTRÔLE AUTOMATIQUE :
| ÉCOLES SANS AFFECTATION
|--------------------------------------------------------------------------
|
| On cherche toutes les écoles qui ne possèdent
| aucune affectation dans ecole_centre pour
| un centre de l'année 2026-2027.
|
| Aucune affectation n'est créée ici.
|
*/

$stmt = $pdo->prepare("
    SELECT
        e.id,
        e.nom,
        e.code_dsps

    FROM ecoles AS e

    WHERE NOT EXISTS (

        SELECT 1

        FROM ecole_centre AS ec

        INNER JOIN centres AS c
            ON c.id = ec.centre_id

        WHERE ec.ecole_composante_id = e.id
          AND c.annee_id = ?

    )

    ORDER BY e.nom ASC
");

$stmt->execute([
    $anneeId
]);

$ecolesSansAffectation =
    $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| COMPTER LES ÉCOLES AFFECTÉES
|--------------------------------------------------------------------------
*/

$nombreEcolesSansAffectation =
    count($ecolesSansAffectation);


$nombreEcolesTotal =
    count($toutesLesEcoles);


$nombreEcolesAffectees =
    $nombreEcolesTotal
    - $nombreEcolesSansAffectation;


/*
|--------------------------------------------------------------------------
| RÉCUPÉRER LES ÉCOLES DE CHAQUE CENTRE
|--------------------------------------------------------------------------
*/

foreach ($centres as &$centre) {

    $stmt = $pdo->prepare("
        SELECT
            e.id,
            e.nom,
            e.code_dsps

        FROM ecole_centre AS ec

        INNER JOIN ecoles AS e
            ON e.id = ec.ecole_composante_id

        WHERE ec.centre_id = ?

        ORDER BY e.nom ASC
    ");

    $stmt->execute([
        $centre['centre_id']
    ]);

    $centre['ecoles_affectees'] =
        $stmt->fetchAll();

    $centre['nombre_ecoles'] =
        count(
            $centre['ecoles_affectees']
        );
}

unset($centre);

/*
|--------------------------------------------------------------------------
| EXAMENS DE 2026-2027
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("
    SELECT id, code, libelle, ordre
    FROM examens
    WHERE annee_id = ?
      AND actif = 1
    ORDER BY ordre
");
$stmt->execute([$anneeId]);
$examens = $stmt->fetchAll();

/*
|--------------------------------------------------------------------------
| CALCUL AUTOMATIQUE DES EFFECTIFS
|--------------------------------------------------------------------------
|
| Candidats scolaires :
|   école -> centre via ecole_centre.
|
| Candidats libres :
|   uniquement pour le CEPE final, via centre_examen_id.
|
| L'effectif retenu n'est modifié automatiquement que si
| l'utilisateur n'a pas imposé une valeur manuelle.
|--------------------------------------------------------------------------
*/
foreach ($centres as &$centre) {
    $centre['effectifs'] = [];

    foreach ($examens as $examen) {
        $centreId = (int) $centre['centre_id'];
        $examenId = (int) $examen['id'];

        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM candidats AS ca
            INNER JOIN ecole_centre AS ec
                ON ec.ecole_composante_id = ca.ecole_id
               AND ec.centre_id = ?
            WHERE ca.est_candidat_libre = 0
        ");
        $stmt->execute([$centreId]);
        $effectifScolaire = (int) $stmt->fetchColumn();

        $effectifLibre = 0;

        if ($examen['code'] === 'CEPE_FINAL') {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM candidats
                WHERE est_candidat_libre = 1
                  AND centre_examen_id = ?
            ");
            $stmt->execute([$centreId]);
            $effectifLibre = (int) $stmt->fetchColumn();
        }

        $effectifCalcule = $effectifScolaire + $effectifLibre;

        $stmt = $pdo->prepare("
            SELECT
                id,
                effectif_retenu,
                est_manuel,
                commentaire
            FROM centre_effectifs
            WHERE centre_id = ?
              AND examen_id = ?
            LIMIT 1
        ");
        $stmt->execute([$centreId, $examenId]);
        $effectifExistant = $stmt->fetch();

        if (!$effectifExistant) {
            $stmt = $pdo->prepare("
                INSERT INTO centre_effectifs
                    (
                        centre_id,
                        examen_id,
                        effectif_calcule,
                        effectif_retenu,
                        est_manuel
                    )
                VALUES
                    (?, ?, ?, ?, 0)
            ");
            $stmt->execute([
                $centreId,
                $examenId,
                $effectifCalcule,
                $effectifCalcule
            ]);

            $effectifRetenu = $effectifCalcule;
            $estManuel = 0;
            $commentaire = null;
        } else {
            $stmt = $pdo->prepare("
                UPDATE centre_effectifs
                SET effectif_calcule = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $effectifCalcule,
                $effectifExistant['id']
            ]);

            $effectifRetenu = (int) $effectifExistant['effectif_retenu'];
            $estManuel = (int) $effectifExistant['est_manuel'];
            $commentaire = $effectifExistant['commentaire'];

            if ($estManuel === 0) {
                $effectifRetenu = $effectifCalcule;

                $stmt = $pdo->prepare("
                    UPDATE centre_effectifs
                    SET effectif_retenu = ?
                    WHERE id = ?
                ");
                $stmt->execute([
                    $effectifCalcule,
                    $effectifExistant['id']
                ]);
            }
        }

        $centre['effectifs'][$examenId] = [
            'effectif_calcule' => $effectifCalcule,
            'effectif_retenu' => $effectifRetenu,
            'est_manuel' => $estManuel,
            'commentaire' => $commentaire
        ];
    }
}
unset($centre);


/*
|--------------------------------------------------------------------------
| AFFICHAGE
|--------------------------------------------------------------------------
*/

include '../views/layouts/header.php';

?>


<div class="d-flex justify-content-between align-items-center mb-4">

    <div>

        <h2 class="mb-1">
            🏛️ Centres d'examen
        </h2>

        <div class="text-muted">

            Année scolaire :

            <strong>
                <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>
            </strong>

        </div>

    </div>


    <div>

        <button
            class="btn btn-primary me-2"
            data-bs-toggle="modal"
            data-bs-target="#modalNouveauCentre"
        >

            <i class="bi bi-plus-circle"></i>

            Nouveau centre

        </button>


        <button
            class="btn btn-success"
            data-bs-toggle="modal"
            data-bs-target="#modalImport"
        >

            <i class="bi bi-file-earmark-excel"></i>

            Importer Excel

        </button>

    </div>

</div>


<?php if ($success): ?>

    <div class="alert alert-success alert-dismissible fade show">

        <i class="bi bi-check-circle"></i>

        <?= htmlspecialchars($success) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<?php if ($error): ?>

    <div class="alert alert-danger alert-dismissible fade show">

        <i class="bi bi-exclamation-triangle"></i>

        <?= htmlspecialchars($error) ?>

        <button
            type="button"
            class="btn-close"
            data-bs-dismiss="alert"
        ></button>

    </div>

<?php endif; ?>


<?php if ($importResult): ?>

    <div class="alert alert-info">

        <h5 class="alert-heading">
            Résultat de l'import Excel
        </h5>


        <div>
            <strong>
                <?= (int) $importResult['importees'] ?>
            </strong>
            affectation(s) créée(s).
        </div>


        <div>
            <strong>
                <?= (int) $importResult['dejaAffectees'] ?>
            </strong>
            école(s) déjà affectée(s).
        </div>


        <div>
            <strong>
                <?= (int) $importResult['ecolesIntrouvables'] ?>
            </strong>
            école(s) introuvable(s).
        </div>


        <div>
            <strong>
                <?= (int) $importResult['centresIntrouvables'] ?>
            </strong>
            centre(s) introuvable(s).
        </div>


        <?php if (!empty($importResult['erreurs'])): ?>

            <hr>

            <strong>
                Détails :
            </strong>


            <ul class="mb-0 mt-2">

                <?php foreach (
                    $importResult['erreurs']
                    as $ligneErreur
                ): ?>

                    <li>
                        <?= htmlspecialchars($ligneErreur) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        <?php endif; ?>

    </div>

<?php endif; ?>


<!-- ==========================================================
     CONTRÔLE DES AFFECTATIONS
=========================================================== -->

<div class="row mb-4">


    <div class="col-md-4">

        <div class="card shadow-sm border-success h-100">

            <div class="card-body text-center">

                <div class="text-success fs-2">
                    <i class="bi bi-check-circle"></i>
                </div>

                <h6 class="text-muted">
                    Écoles affectées
                </h6>

                <div class="fs-3 fw-bold text-success">

                    <?= (int) $nombreEcolesAffectees ?>

                </div>

            </div>

        </div>

    </div>


    <div class="col-md-4">

        <div class="card shadow-sm border-warning h-100">

            <div class="card-body text-center">

                <div class="text-warning fs-2">
                    <i class="bi bi-exclamation-circle"></i>
                </div>

                <h6 class="text-muted">
                    Écoles sans affectation
                </h6>

                <div class="fs-3 fw-bold text-warning">

                    <?= (int) $nombreEcolesSansAffectation ?>

                </div>

            </div>

        </div>

    </div>


    <div class="col-md-4">

        <div class="card shadow-sm h-100">

            <div class="card-body text-center">

                <div class="text-primary fs-2">
                    <i class="bi bi-building"></i>
                </div>

                <h6 class="text-muted">
                    Total des écoles
                </h6>

                <div class="fs-3 fw-bold">

                    <?= (int) $nombreEcolesTotal ?>

                </div>

            </div>

        </div>

    </div>


</div>


<?php if (
    $nombreEcolesSansAffectation > 0
): ?>


    <div class="card shadow-sm border-warning mb-4">


        <div class="card-header bg-warning">


            <div class="d-flex justify-content-between align-items-center">


                <strong>

                    <i class="bi bi-exclamation-triangle"></i>

                    Écoles sans affectation

                </strong>


                <span class="badge bg-dark">

                    <?= (int)
                        $nombreEcolesSansAffectation ?>

                </span>


            </div>


        </div>


        <div class="card-body">


            <p class="mb-3">

                Les écoles ci-dessous n'ont pas encore
                de centre d'examen attribué pour
                <strong>2026-2027</strong>.

            </p>


            <div class="table-responsive">


                <table class="table table-sm table-hover mb-0">


                    <thead>

                        <tr>

                            <th>
                                École
                            </th>

                            <th>
                                Code DSPS
                            </th>

                            <th class="text-center">
                                État
                            </th>

                        </tr>

                    </thead>


                    <tbody>


                    <?php foreach (
                        $ecolesSansAffectation
                        as $ecole
                    ): ?>


                        <tr>


                            <td>

                                <strong>

                                    <?= htmlspecialchars(
                                        $ecole['nom']
                                    ) ?>

                                </strong>

                            </td>


                            <td>

                                <?php if (
                                    !empty(
                                        $ecole['code_dsps']
                                    )
                                ): ?>

                                    <code>

                                        <?= htmlspecialchars(
                                            $ecole['code_dsps']
                                        ) ?>

                                    </code>

                                <?php else: ?>

                                    <span
                                        class="text-muted"
                                    >
                                        Aucun
                                    </span>

                                <?php endif; ?>

                            </td>


                            <td class="text-center">

                                <span
                                    class="badge bg-warning text-dark"
                                >

                                    En attente

                                </span>

                            </td>


                        </tr>


                    <?php endforeach; ?>


                    </tbody>


                </table>


            </div>


            <div class="alert alert-secondary mt-3 mb-0">

                <i class="bi bi-info-circle"></i>

                <strong>
                    Aucune affectation automatique n'est effectuée.
                </strong>

                Ces écoles resteront en attente jusqu'à
                ce que tu connaisses leur centre.

            </div>


        </div>


    </div>


<?php else: ?>


    <div class="alert alert-success mb-4">

        <i class="bi bi-check-circle"></i>

        <strong>
            Toutes les écoles sont affectées à un centre
            pour 2026-2027.
        </strong>

    </div>


<?php endif; ?>


<div class="alert alert-primary">

    <strong>
        Règle d'affectation :
    </strong>

    une école n'est jamais affectée automatiquement
    au centre qu'elle héberge.

    <br>

    L'affectation est déterminée uniquement par :

    <strong>
        École → Centre
    </strong>

</div>



<!-- ==========================================================
     EFFECTIFS PAR CENTRE ET PAR EXAMEN
=========================================================== -->
<div class="card shadow-sm mb-4">
    <div class="card-header bg-dark text-white">
        <strong>
            <i class="bi bi-people-fill"></i>
            Effectifs des centres — 2026-2027
        </strong>
    </div>

    <div class="card-body">
        <div class="alert alert-info">
            <strong>Principe :</strong>
            l'effectif est calculé automatiquement à partir des candidats.
            Tu peux toutefois le remplacer manuellement si nécessaire.
            Les candidats libres ne sont comptés que pour le
            <strong>CEPE session 2027</strong>.
        </div>

        <?php foreach ($centres as $centre): ?>
            <div class="border rounded p-3 mb-3">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <strong>
                        <?= htmlspecialchars($centre['nom_centre']) ?>
                    </strong>

                    <span class="badge bg-secondary">
                        <?= (int) $centre['nombre_ecoles'] ?> école(s)
                    </span>
                </div>

                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Examen</th>
                                <th class="text-center">Calculé</th>
                                <th class="text-center">Retenu</th>
                                <th class="text-center">Mode</th>
                                <th style="min-width: 320px;">Modification</th>
                            </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($examens as $examen): ?>
                            <?php
                                $eff = $centre['effectifs'][(int) $examen['id']]
                                    ?? [
                                        'effectif_calcule' => 0,
                                        'effectif_retenu' => 0,
                                        'est_manuel' => 0,
                                        'commentaire' => null
                                    ];
                            ?>

                            <tr>
                                <td>
                                    <strong>
                                        <?= htmlspecialchars($examen['libelle']) ?>
                                    </strong>
                                </td>

                                <td class="text-center fw-bold">
                                    <?= (int) $eff['effectif_calcule'] ?>
                                </td>

                                <td class="text-center">
                                    <span class="badge bg-primary fs-6">
                                        <?= (int) $eff['effectif_retenu'] ?>
                                    </span>
                                </td>

                                <td class="text-center">
                                    <?php if ((int) $eff['est_manuel'] === 1): ?>
                                        <span class="badge bg-warning text-dark">
                                            Manuel
                                        </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">
                                            Automatique
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <form method="POST" class="row g-2">
                                        <input
                                            type="hidden"
                                            name="centre_id"
                                            value="<?= (int) $centre['centre_id'] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="examen_id"
                                            value="<?= (int) $examen['id'] ?>"
                                        >

                                        <div class="col-4">
                                            <input
                                                type="number"
                                                name="effectif_retenu"
                                                class="form-control form-control-sm"
                                                min="0"
                                                value="<?= (int) $eff['effectif_retenu'] ?>"
                                                required
                                            >
                                        </div>

                                        <div class="col-5">
                                            <input
                                                type="text"
                                                name="commentaire"
                                                class="form-control form-control-sm"
                                                maxlength="500"
                                                placeholder="Motif (facultatif)"
                                                value="<?= htmlspecialchars($eff['commentaire'] ?? '') ?>"
                                            >
                                        </div>

                                        <div class="col-3 d-grid">
                                            <button
                                                type="submit"
                                                name="enregistrer_effectif"
                                                class="btn btn-sm btn-primary"
                                            >
                                                Enregistrer
                                            </button>
                                        </div>
                                    </form>

                                    <?php if ((int) $eff['est_manuel'] === 1): ?>
                                        <form method="POST" class="mt-2">
                                            <input
                                                type="hidden"
                                                name="centre_id"
                                                value="<?= (int) $centre['centre_id'] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="examen_id"
                                                value="<?= (int) $examen['id'] ?>"
                                            >

                                            <button
                                                type="submit"
                                                name="reinitialiser_effectif"
                                                class="btn btn-sm btn-outline-secondary"
                                            >
                                                Revenir au calcul automatique
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if (count($centres) > 0): ?>


    <?php foreach ($centres as $centre): ?>


        <div class="card shadow-sm mb-4">


            <div class="card-header bg-primary text-white">


                <div class="d-flex justify-content-between align-items-center">


                    <div>

                        <strong>

                            📍

                            <?= htmlspecialchars(
                                $centre['nom_centre']
                            ) ?>

                        </strong>


                        <?php if (
                            !empty($centre['code_centre'])
                        ): ?>

                            <span
                                class="badge bg-light text-dark ms-2"
                            >

                                <?= htmlspecialchars(
                                    $centre['code_centre']
                                ) ?>

                            </span>

                        <?php endif; ?>

                    </div>


                    <div>


                        <span
                            class="badge bg-light text-dark me-2"
                        >

                            <?= (int)
                                $centre['nombre_ecoles']
                            ?>

                            école(s) affectée(s)

                        </span>


                        <button
                            type="button"
                            class="btn btn-sm btn-light text-primary"
                            onclick="ouvrirModalAffectation(
                                <?= (int)
                                    $centre['centre_id'] ?>,
                                <?= htmlspecialchars(
                                    json_encode(
                                        $centre['nom_centre'],
                                        JSON_HEX_TAG |
                                        JSON_HEX_APOS |
                                        JSON_HEX_QUOT |
                                        JSON_HEX_AMP
                                    ),
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            )"
                        >

                            <i class="bi bi-plus-circle"></i>

                            Affecter une école

                        </button>


                    </div>


                </div>


            </div>


            <div class="card-body p-0">


                <div class="table-responsive">


                    <table
                        class="table table-striped table-hover mb-0"
                    >


                        <thead class="table-light">

                            <tr>

                                <th>
                                    École composante
                                </th>

                                <th>
                                    Code DSPS
                                </th>

                                <th class="text-center">
                                    Action
                                </th>

                            </tr>

                        </thead>


                        <tbody>


                        <?php if (
                            !empty(
                                $centre['ecoles_affectees']
                            )
                        ): ?>


                            <?php foreach (
                                $centre['ecoles_affectees']
                                as $ecole
                            ): ?>


                                <tr>


                                    <td>

                                        <strong>

                                            <?= htmlspecialchars(
                                                $ecole['nom']
                                            ) ?>

                                        </strong>

                                    </td>


                                    <td>

                                        <?php if (
                                            !empty(
                                                $ecole['code_dsps']
                                            )
                                        ): ?>

                                            <code>

                                                <?= htmlspecialchars(
                                                    $ecole['code_dsps']
                                                ) ?>

                                            </code>

                                        <?php else: ?>

                                            <span
                                                class="text-muted"
                                            >
                                                Aucun
                                            </span>

                                        <?php endif; ?>

                                    </td>


                                    <td class="text-center">


                                        <form
                                            method="POST"
                                            class="d-inline"
                                            onsubmit="return confirm(
                                                'Voulez-vous vraiment retirer cette affectation ?'
                                            );"
                                        >


                                            <input
                                                type="hidden"
                                                name="centre_id"
                                                value="<?= (int)
                                                    $centre['centre_id'] ?>"
                                            >


                                            <input
                                                type="hidden"
                                                name="ecole_id"
                                                value="<?= (int)
                                                    $ecole['id'] ?>"
                                            >


                                            <button
                                                type="submit"
                                                name="supprimer_affectation"
                                                class="btn btn-sm btn-outline-danger"
                                            >

                                                <i
                                                    class="bi bi-unlink"
                                                ></i>

                                                Retirer

                                            </button>


                                        </form>


                                    </td>


                                </tr>


                            <?php endforeach; ?>


                        <?php else: ?>


                            <tr>

                                <td
                                    colspan="3"
                                    class="text-center text-muted py-4"
                                >

                                    <i
                                        class="bi bi-info-circle"
                                    ></i>

                                    Aucune école n'est encore
                                    affectée à ce centre.

                                </td>

                            </tr>


                        <?php endif; ?>


                        </tbody>


                    </table>


                </div>


            </div>


        </div>


    <?php endforeach; ?>


<?php else: ?>


    <div class="alert alert-warning text-center">

        <i class="bi bi-exclamation-circle"></i>

        <strong>
            Aucun centre d'examen n'est enregistré
            pour 2026-2027.
        </strong>

    </div>


<?php endif; ?>


<!-- ==========================================================
     MODAL : NOUVEAU CENTRE
=========================================================== -->

<div
    class="modal fade"
    id="modalNouveauCentre"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog">


        <form method="POST">


            <div class="modal-content">


                <div class="modal-header bg-primary text-white">


                    <h5 class="modal-title">

                        Désigner un nouveau centre

                    </h5>


                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>


                </div>


                <div class="modal-body">


                    <div class="alert alert-info">

                        Choisis l'école qui hébergera
                        le centre pour l'année
                        <strong>2026-2027</strong>.

                        <br><br>

                        <strong>
                            Important :
                        </strong>

                        cette opération ne signifie pas que
                        cette école composera automatiquement
                        dans ce centre.

                    </div>


                    <label class="form-label">

                        École hébergeant le centre

                    </label>


                    <select
                        name="ecole_id"
                        class="form-select"
                        required
                    >


                        <option value="">

                            -- Sélectionner une école --

                        </option>


                        <?php foreach (
                            $toutesLesEcoles
                            as $ecole
                        ): ?>


                            <option
                                value="<?= (int)
                                    $ecole['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $ecole['nom']
                                ) ?>

                            </option>


                        <?php endforeach; ?>


                    </select>


                </div>


                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Annuler

                    </button>


                    <button
                        type="submit"
                        name="creer_centre"
                        class="btn btn-primary"
                    >

                        <i
                            class="bi bi-check-circle"
                        ></i>

                        Créer le centre

                    </button>


                </div>


            </div>


        </form>


    </div>


</div>



<!-- ==========================================================
     MODAL : AFFECTER UNE ÉCOLE
=========================================================== -->

<div
    class="modal fade"
    id="modalAffectation"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog">


        <form method="POST">


            <div class="modal-content">


                <div class="modal-header bg-info text-white">


                    <h5 class="modal-title">

                        Affecter une école au centre :

                        <span
                            id="nomCentreAffectation"
                        ></span>

                    </h5>


                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>


                </div>


                <div class="modal-body">


                    <input
                        type="hidden"
                        name="centre_id"
                        id="centreIdAffectation"
                    >


                    <label class="form-label">

                        École qui composera dans ce centre

                    </label>


                    <select
                        name="ecole_id"
                        class="form-select"
                        required
                    >


                        <option value="">

                            -- Sélectionner une école --

                        </option>


                        <?php foreach (
                            $toutesLesEcoles
                            as $ecole
                        ): ?>


                            <option
                                value="<?= (int)
                                    $ecole['id'] ?>"
                            >

                                <?= htmlspecialchars(
                                    $ecole['nom']
                                ) ?>

                            </option>


                        <?php endforeach; ?>


                    </select>


                    <div class="form-text mt-2">

                        L'affectation indique que les candidats
                        de cette école composeront dans le centre
                        sélectionné.

                    </div>


                </div>


                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Annuler

                    </button>


                    <button
                        type="submit"
                        name="ajouter_ecole"
                        class="btn btn-info text-white"
                    >

                        <i
                            class="bi bi-check-circle"
                        ></i>

                        Enregistrer l'affectation

                    </button>


                </div>


            </div>


        </form>


    </div>


</div>



<!-- ==========================================================
     MODAL : IMPORT EXCEL
=========================================================== -->

<div
    class="modal fade"
    id="modalImport"
    tabindex="-1"
    aria-hidden="true"
>


    <div class="modal-dialog">


        <form
            method="POST"
            enctype="multipart/form-data"
        >


            <div class="modal-content">


                <div class="modal-header bg-success text-white">


                    <h5 class="modal-title">

                        Importer les affectations Excel

                    </h5>


                    <button
                        type="button"
                        class="btn-close btn-close-white"
                        data-bs-dismiss="modal"
                    ></button>


                </div>


                <div class="modal-body">


                    <div class="alert alert-info">

                        <strong>
                            Format obligatoire :
                        </strong>

                        <br><br>


                        <table
                            class="table table-bordered table-sm mb-0"
                        >


                            <thead>

                                <tr>

                                    <th>
                                        Colonne A
                                    </th>

                                    <th>
                                        Colonne B
                                    </th>

                                </tr>

                            </thead>


                            <tbody>

                                <tr>

                                    <td>
                                        <strong>
                                            École
                                        </strong>
                                    </td>

                                    <td>
                                        <strong>
                                            Centre
                                        </strong>
                                    </td>

                                </tr>


                                <tr>

                                    <td>
                                        EPP ECOLE A
                                    </td>

                                    <td>
                                        CENTRE AZITO 1
                                    </td>

                                </tr>


                                <tr>

                                    <td>
                                        EPP ECOLE B
                                    </td>

                                    <td>
                                        CENTRE AZITO 1
                                    </td>

                                </tr>


                            </tbody>


                        </table>

                    </div>


                    <div class="alert alert-warning">

                        <strong>
                            Attention :
                        </strong>

                        les centres doivent déjà être créés
                        dans l'application pour 2026-2027.

                        <br>

                        L'import Excel ne crée pas automatiquement
                        de centre.

                    </div>


                    <label class="form-label">

                        Fichier Excel

                    </label>


                    <input
                        type="file"
                        name="fichier"
                        class="form-control"
                        accept=".xlsx,.xls"
                        required
                    >


                </div>


                <div class="modal-footer">


                    <button
                        type="button"
                        class="btn btn-secondary"
                        data-bs-dismiss="modal"
                    >

                        Annuler

                    </button>


                    <button
                        type="submit"
                        name="importer_excel"
                        class="btn btn-success"
                    >

                        <i
                            class="bi bi-upload"
                        ></i>

                        Lancer l'import

                    </button>


                </div>


            </div>


        </form>


    </div>


</div>



<script>

function ouvrirModalAffectation(
    centreId,
    centreNom
) {

    document.getElementById(
        'centreIdAffectation'
    ).value = centreId;


    document.getElementById(
        'nomCentreAffectation'
    ).textContent = centreNom;


    const modalElement =
        document.getElementById(
            'modalAffectation'
        );


    const modal =
        new bootstrap.Modal(
            modalElement
        );


    modal.show();
}

</script>


<?php

include '../views/layouts/footer.php';

?>