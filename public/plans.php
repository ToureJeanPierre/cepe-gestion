<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';

$pageTitle = 'Plans de salle';

$success = null;
$error = null;
$avertissements = [];

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
| FONCTION : VÉRIFIER L'EXAMEN
|--------------------------------------------------------------------------
*/

function recupererExamen(PDO $pdo, int $examenId, int $anneeId): ?array
{
    $stmt = $pdo->prepare("
        SELECT
            id,
            code,
            libelle,
            ordre
        FROM examens
        WHERE id = ?
          AND annee_id = ?
          AND actif = 1
        LIMIT 1
    ");

    $stmt->execute([
        $examenId,
        $anneeId
    ]);

    $resultat = $stmt->fetch();

    return $resultat ?: null;
}


/*
|--------------------------------------------------------------------------
| TRAITEMENT DES ACTIONS
|--------------------------------------------------------------------------
*/

$examenIdPost = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    /*
    |--------------------------------------------------------------------------
    | EXAMEN SÉLECTIONNÉ
    |--------------------------------------------------------------------------
    */

    $examenIdPost = isset($_POST['examen_id'])
        ? (int) $_POST['examen_id']
        : 0;

    // Génération ciblée sur un seul centre (bouton par centre) : si absent,
    // la génération porte sur tous les centres de l'examen (bouton global).
    $centreEffectifIdCible = isset($_POST['centre_effectif_id'])
        ? (int) $_POST['centre_effectif_id']
        : 0;

    // Autorise explicitement (case cochée / confirmation cliquée) à écraser
    // une répartition manuelle existante, au lieu de l'ignorer silencieusement.
    $ecraserManuel = isset($_POST['ecraser_manuel']) && $_POST['ecraser_manuel'] == '1';


    /*
    |--------------------------------------------------------------------------
    | 1. GÉNÉRATION AUTOMATIQUE
    |--------------------------------------------------------------------------
    */

    if (isset($_POST['generer_plan'])) {

        if ($examenIdPost <= 0) {

            $error = "Veuillez sélectionner un examen.";

        } else {

            $examen = recupererExamen(
                $pdo,
                $examenIdPost,
                $anneeId
            );

            if (!$examen) {

                $error = "Examen invalide pour l'année 2026-2027.";

            } else {

                try {

                    /*
                    |--------------------------------------------------------------------------
                    | RÉCUPÉRER LES EFFECTIFS DES CENTRES
                    |--------------------------------------------------------------------------
                    */

                    $stmt = $pdo->prepare("
                        SELECT
                            ce.id AS centre_effectif_id,
                            ce.centre_id,
                            ce.effectif_calcule,
                            ce.effectif_retenu,
                            ce.est_manuel AS effectif_manuel,

                            c.ecole_id AS centre_ecole_id,

                            e.nom AS nom_centre

                        FROM centre_effectifs AS ce

                        INNER JOIN centres AS c
                            ON c.id = ce.centre_id

                        INNER JOIN ecoles AS e
                            ON e.id = c.ecole_id

                        WHERE ce.examen_id = ?
                          AND c.annee_id = ?
                          " . ($centreEffectifIdCible > 0 ? "AND ce.id = ?" : "") . "

                        ORDER BY e.nom ASC
                    ");

                    $paramsEffectifs = [
                        $examenIdPost,
                        $anneeId
                    ];

                    if ($centreEffectifIdCible > 0) {
                        $paramsEffectifs[] = $centreEffectifIdCible;
                    }

                    $stmt->execute($paramsEffectifs);

                    $centresEffectifs = $stmt->fetchAll();

                    if (!$centresEffectifs) {

                        throw new Exception(
                            "Aucun effectif n'a été trouvé pour cet examen."
                        );
                    }


                    $pdo->beginTransaction();

                    $nombrePlans = 0;
                    $nombreProblemes = 0;


                    foreach ($centresEffectifs as $centre) {

                        $centreEffectifId =
                            (int) $centre['centre_effectif_id'];


                        /*
                        |--------------------------------------------------------------------------
                        | EFFECTIF RETENU
                        |--------------------------------------------------------------------------
                        */

                        if ($centre['effectif_retenu'] !== null) {

                            $effectif =
                                (int) $centre['effectif_retenu'];

                        } else {

                            $effectif =
                                (int) $centre['effectif_calcule'];
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | AUCUN CANDIDAT
                        |--------------------------------------------------------------------------
                        */

                        if ($effectif <= 0) {

                            $stmtDelete = $pdo->prepare("
                                DELETE FROM plan_salles
                                WHERE centre_effectif_id = ?
                                  AND est_manuel = 0
                            ");

                            $stmtDelete->execute([
                                $centreEffectifId
                            ]);

                            continue;
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | VÉRIFIER LES SALLES MANUELLES
                        |--------------------------------------------------------------------------
                        */

                        $stmtManuel = $pdo->prepare("
                            SELECT COUNT(*)
                            FROM plan_salles
                            WHERE centre_effectif_id = ?
                              AND est_manuel = 1
                        ");

                        $stmtManuel->execute([
                            $centreEffectifId
                        ]);

                        $aDesSallesManuelles =
                            (int) $stmtManuel->fetchColumn() > 0;


                        /*
                        |--------------------------------------------------------------------------
                        | NE JAMAIS ÉCRASER UNE RÉPARTITION MANUELLE
                        |--------------------------------------------------------------------------
                        */

                        if ($aDesSallesManuelles && !$ecraserManuel) {

                            $avertissements[] =
                                $centre['nom_centre']
                                . " : une répartition manuelle existe déjà. "
                                . "Elle n'a pas été modifiée (cochez « écraser les répartitions "
                                . "manuelles » ou utilisez le bouton du centre pour la remplacer).";

                            continue;
                        }

                        // Écrasement explicitement demandé : supprime aussi les salles
                        // manuelles de CE centre pour repartir sur une base propre.
                        if ($aDesSallesManuelles && $ecraserManuel) {

                            $pdo->prepare("
                                DELETE FROM plan_salles
                                WHERE centre_effectif_id = ?
                                  AND est_manuel = 1
                            ")->execute([$centreEffectifId]);
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | NOMBRE MINIMUM DE SALLES
                        |--------------------------------------------------------------------------
                        |
                        | Minimum normal : 25
                        | Maximum normal : 30
                        |--------------------------------------------------------------------------
                        */

                        $nombreSalles =
                            (int) ceil($effectif / 30);


                        /*
                        |--------------------------------------------------------------------------
                        | VÉRIFICATION DE LA CONTRAINTE MINIMUM DE 25
                        |--------------------------------------------------------------------------
                        |
                        | Si la répartition à 30 maximum tombe sous le minimum de 25 par salle,
                        | on essaie un repli avec MOINS de salles en tolérant jusqu'à 31 par
                        | salle (dépassement exceptionnel) plutôt que d'abandonner directement —
                        | ce dépassement est ensuite signalé en rouge dans le tableau, avec un
                        | commentaire, pour que la répartition reste visible et modifiable
                        | manuellement si besoin.
                        |--------------------------------------------------------------------------
                        */

                        if ($effectif < ($nombreSalles * 25)) {

                            $nombreSallesRepli =
                                (int) ceil($effectif / 31);

                            if (
                                $nombreSallesRepli < $nombreSalles
                                && $effectif >= ($nombreSallesRepli * 25)
                            ) {

                                $nombreSalles = $nombreSallesRepli;

                            } else {

                                $nombreProblemes++;

                                $avertissements[] =
                                    $centre['nom_centre']
                                    . " : "
                                    . $effectif
                                    . " candidats. "
                                    . "Aucune répartition normale entre 25 et 30 "
                                    . "(ni même 31 en dépassement exceptionnel) "
                                    . "n'est possible avec le nombre minimal de salles. "
                                    . "Une décision manuelle est nécessaire.";


                                $stmtDelete = $pdo->prepare("
                                    DELETE FROM plan_salles
                                    WHERE centre_effectif_id = ?
                                      AND est_manuel = 0
                                ");

                                $stmtDelete->execute([
                                    $centreEffectifId
                                ]);

                                continue;
                            }
                        }


                        /*
                        |--------------------------------------------------------------------------
                        | RÉPARTITION ÉQUILIBRÉE
                        |--------------------------------------------------------------------------
                        */

                        $base =
                            intdiv(
                                $effectif,
                                $nombreSalles
                            );

                        $reste =
                            $effectif % $nombreSalles;


                        /*
                        |--------------------------------------------------------------------------
                        | SUPPRIMER L'ANCIEN PLAN AUTOMATIQUE
                        |--------------------------------------------------------------------------
                        */

                        $stmtDelete = $pdo->prepare("
                            DELETE FROM plan_salles
                            WHERE centre_effectif_id = ?
                              AND est_manuel = 0
                        ");

                        $stmtDelete->execute([
                            $centreEffectifId
                        ]);


                        /*
                        |--------------------------------------------------------------------------
                        | INSÉRER LES SALLES
                        |--------------------------------------------------------------------------
                        */

                        $stmtInsert = $pdo->prepare("
                            INSERT INTO plan_salles
                            (
                                centre_effectif_id,
                                numero_salle,
                                effectif_calcule,
                                effectif_retenu,
                                est_manuel,
                                commentaire
                            )
                            VALUES
                            (
                                ?,
                                ?,
                                ?,
                                ?,
                                0,
                                ?
                            )
                        ");

                        $auMoinsUneSalleForcee = false;

                        for (
                            $numeroSalle = 1;
                            $numeroSalle <= $nombreSalles;
                            $numeroSalle++
                        ) {

                            $effectifSalle =
                                $base
                                + (
                                    $numeroSalle <= $reste
                                        ? 1
                                        : 0
                                );

                            $commentaireSalle = null;
                            if ($effectifSalle > 30) {
                                $commentaireSalle =
                                    "Effectif porté à $effectifSalle : dépassement "
                                    . "exceptionnel du maximum normal de 30, nécessaire "
                                    . "pour éviter une salle sous le minimum de 25.";
                                $auMoinsUneSalleForcee = true;
                            }

                            $stmtInsert->execute([
                                $centreEffectifId,
                                $numeroSalle,
                                $effectifSalle,
                                $effectifSalle,
                                $commentaireSalle
                            ]);
                        }

                        if ($auMoinsUneSalleForcee) {
                            $avertissements[] =
                                $centre['nom_centre']
                                . " : répartition générée avec au moins une salle "
                                . "portée à 31 (dépassement exceptionnel, signalé en "
                                . "rouge) — vérifiez et ajustez manuellement si besoin.";
                        }


                        $nombrePlans++;
                    }


                    $pdo->commit();


                    if ($nombreProblemes > 0) {

                        $success =
                            $nombrePlans
                            . " centre(s) ont été répartis automatiquement. "
                            . $nombreProblemes
                            . " centre(s) nécessitent une décision manuelle.";

                    } else {

                        $success =
                            "Le plan de salle de "
                            . $examen['libelle']
                            . " a été généré avec succès.";
                    }


                } catch (Exception $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error =
                        "Erreur lors de la génération du plan : "
                        . $e->getMessage();
                }
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 2. MODIFICATION MANUELLE D'UNE SALLE
    |--------------------------------------------------------------------------
    */

    elseif (isset($_POST['modifier_salle'])) {

        $planId =
            isset($_POST['plan_id'])
                ? (int) $_POST['plan_id']
                : 0;

        $effectifSalle =
            isset($_POST['effectif_salle'])
                ? (int) $_POST['effectif_salle']
                : 0;

        $commentaire =
            isset($_POST['commentaire'])
                ? trim($_POST['commentaire'])
                : '';


        if ($planId <= 0) {

            $error = "Salle invalide.";

        } elseif ($effectifSalle <= 0) {

            $error =
                "L'effectif de la salle doit être supérieur à zéro.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | VÉRIFIER QUE LA SALLE EXISTE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ps.id,
                        ps.numero_salle,
                        ps.centre_effectif_id,
                        ce.examen_id,
                        c.annee_id
                    FROM plan_salles AS ps

                    INNER JOIN centre_effectifs AS ce
                        ON ce.id = ps.centre_effectif_id

                    INNER JOIN centres AS c
                        ON c.id = ce.centre_id

                    WHERE ps.id = ?
                      AND ce.examen_id = ?
                      AND c.annee_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $planId,
                    $examenIdPost,
                    $anneeId
                ]);

                $salle = $stmt->fetch();


                if (!$salle) {

                    throw new Exception(
                        "La salle n'existe pas ou n'appartient pas à l'examen sélectionné."
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | MARQUER LA SALLE COMME MANUELLE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    UPDATE plan_salles
                    SET
                        effectif_retenu = ?,
                        est_manuel = 1,
                        commentaire = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $effectifSalle,
                    $commentaire !== ''
                        ? $commentaire
                        : null,
                    $planId
                ]);


                $success =
                    "Salle "
                    . $salle['numero_salle']
                    . " modifiée manuellement avec succès.";

            } catch (Exception $e) {

                $error =
                    "Erreur lors de la modification : "
                    . $e->getMessage();
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 3. AJOUT MANUEL D'UNE SALLE
    |--------------------------------------------------------------------------
    */

    elseif (isset($_POST['ajouter_salle'])) {

        $centreEffectifId =
            isset($_POST['centre_effectif_id'])
                ? (int) $_POST['centre_effectif_id']
                : 0;

        $effectifSalle =
            isset($_POST['nouvel_effectif'])
                ? (int) $_POST['nouvel_effectif']
                : 0;

        $commentaire =
            isset($_POST['nouveau_commentaire'])
                ? trim($_POST['nouveau_commentaire'])
                : '';


        if ($centreEffectifId <= 0) {

            $error = "Centre invalide.";

        } elseif ($effectifSalle <= 0) {

            $error =
                "L'effectif de la nouvelle salle doit être supérieur à zéro.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | VÉRIFIER LE CENTRE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ce.id,
                        ce.examen_id,
                        c.annee_id,
                        e.nom AS nom_centre
                    FROM centre_effectifs AS ce

                    INNER JOIN centres AS c
                        ON c.id = ce.centre_id

                    INNER JOIN ecoles AS e
                        ON e.id = c.ecole_id

                    WHERE ce.id = ?
                      AND ce.examen_id = ?
                      AND c.annee_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $centreEffectifId,
                    $examenIdPost,
                    $anneeId
                ]);

                $centre = $stmt->fetch();


                if (!$centre) {

                    throw new Exception(
                        "Centre invalide pour l'examen sélectionné."
                    );
                }


                /*
                |--------------------------------------------------------------------------
                | NUMÉRO DE LA PROCHAINE SALLE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT COALESCE(MAX(numero_salle), 0) + 1
                    FROM plan_salles
                    WHERE centre_effectif_id = ?
                ");

                $stmt->execute([
                    $centreEffectifId
                ]);

                $numeroSalle =
                    (int) $stmt->fetchColumn();


                /*
                |--------------------------------------------------------------------------
                | AJOUT DE LA SALLE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    INSERT INTO plan_salles
                    (
                        centre_effectif_id,
                        numero_salle,
                        effectif_calcule,
                        effectif_retenu,
                        est_manuel,
                        commentaire
                    )
                    VALUES
                    (
                        ?,
                        ?,
                        ?,
                        ?,
                        1,
                        ?
                    )
                ");

                $stmt->execute([
                    $centreEffectifId,
                    $numeroSalle,
                    $effectifSalle,
                    $effectifSalle,
                    $commentaire !== ''
                        ? $commentaire
                        : null
                ]);


                $success =
                    "Salle "
                    . $numeroSalle
                    . " ajoutée manuellement au centre "
                    . $centre['nom_centre']
                    . ".";

            } catch (Exception $e) {

                $error =
                    "Erreur lors de l'ajout de la salle : "
                    . $e->getMessage();
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 4. SUPPRESSION D'UNE SALLE MANUELLE
    |--------------------------------------------------------------------------
    */

    elseif (isset($_POST['supprimer_salle'])) {

        $planId =
            isset($_POST['plan_id'])
                ? (int) $_POST['plan_id']
                : 0;


        if ($planId <= 0) {

            $error = "Salle invalide.";

        } else {

            try {

                /*
                |--------------------------------------------------------------------------
                | SEULEMENT UNE SALLE MANUELLE PEUT ÊTRE SUPPRIMÉE
                |--------------------------------------------------------------------------
                */

                $stmt = $pdo->prepare("
                    SELECT
                        ps.id,
                        ps.numero_salle,
                        ps.est_manuel,
                        ce.examen_id,
                        c.annee_id
                    FROM plan_salles AS ps

                    INNER JOIN centre_effectifs AS ce
                        ON ce.id = ps.centre_effectif_id

                    INNER JOIN centres AS c
                        ON c.id = ce.centre_id

                    WHERE ps.id = ?
                      AND ce.examen_id = ?
                      AND c.annee_id = ?

                    LIMIT 1
                ");

                $stmt->execute([
                    $planId,
                    $examenIdPost,
                    $anneeId
                ]);

                $salle = $stmt->fetch();


                if (!$salle) {

                    throw new Exception(
                        "La salle n'existe pas."
                    );
                }


                if ((int) $salle['est_manuel'] !== 1) {

                    throw new Exception(
                        "Une salle automatique ne peut pas être supprimée manuellement. "
                        . "Générez à nouveau le plan si nécessaire."
                    );
                }


                $stmt = $pdo->prepare("
                    DELETE FROM plan_salles
                    WHERE id = ?
                ");

                $stmt->execute([
                    $planId
                ]);


                $success =
                    "Salle "
                    . $salle['numero_salle']
                    . " supprimée.";

            } catch (Exception $e) {

                $error =
                    "Erreur lors de la suppression : "
                    . $e->getMessage();
            }
        }
    }


    /*
    |--------------------------------------------------------------------------
    | 4. GÉNÉRATION DE LA LISTE D'ÉMARGEMENT (candidat -> salle, règle 5.3)
    |--------------------------------------------------------------------------
    | Liste alphabétique globale du centre, affectation séquentielle au numéro
    | de salle (candidats 1-30 -> Salle 1, 31-60 -> Salle 2, etc.), à partir des
    | salles déjà calculées par la génération automatique ci-dessus.
    |--------------------------------------------------------------------------
    */

    elseif (isset($_POST['generer_emargement'])) {

        if ($examenIdPost <= 0) {

            $error = "Veuillez sélectionner un examen.";

        } else {

            $examen = recupererExamen($pdo, $examenIdPost, $anneeId);

            if (!$examen) {

                $error = "Examen invalide pour l'année scolaire consultée.";

            } else {

                try {

                    $stmt = $pdo->prepare("
                        SELECT ce.id AS centre_effectif_id, ce.centre_id, e.nom AS nom_centre
                        FROM centre_effectifs ce
                        INNER JOIN centres c ON c.id = ce.centre_id
                        INNER JOIN ecoles e ON e.id = c.ecole_id
                        WHERE ce.examen_id = ? AND c.annee_id = ?
                        ORDER BY e.nom ASC
                    ");
                    $stmt->execute([$examenIdPost, $anneeId]);
                    $centresEffectifs = $stmt->fetchAll();

                    $pdo->beginTransaction();

                    $nbCentresTraites = 0;
                    $avertissementsEmargement = [];

                    foreach ($centresEffectifs as $centreEff) {

                        $centreEffectifId = (int) $centreEff['centre_effectif_id'];
                        $centreId = (int) $centreEff['centre_id'];

                        // Salles de ce centre, dans l'ordre numérique
                        $stmtSalles = $pdo->prepare("
                            SELECT id, numero_salle, COALESCE(effectif_retenu, effectif_calcule) AS capacite
                            FROM plan_salles
                            WHERE centre_effectif_id = ?
                            ORDER BY numero_salle ASC
                        ");
                        $stmtSalles->execute([$centreEffectifId]);
                        $salles = $stmtSalles->fetchAll();

                        if (!$salles) {
                            continue; // Pas encore de plan de salle pour ce centre
                        }

                        // Effectif scolaire = candidats OFFICIELS validés de l'école du centre
                        // + de ses rattachées (même règle que le calcul d'effectif de centres.php)
                        $condEligibleCEPE = conditionCandidatEligibleCEPE('ca');
                        $stmtCand = $pdo->prepare("
                            SELECT ca.id, ca.nom, ca.prenoms
                            FROM candidats ca
                            INNER JOIN ecoles e ON e.id = ca.ecole_id
                            WHERE ca.annee_id = ?
                              AND $condEligibleCEPE
                              AND (
                                    e.id IN (SELECT ecole_composante_id FROM ecole_centre WHERE centre_id = ?)
                                 OR e.ecole_tutrice_id IN (SELECT ecole_composante_id FROM ecole_centre WHERE centre_id = ?)
                              )
                        ");
                        $stmtCand->execute([$anneeId, $centreId, $centreId]);
                        $candidats = $stmtCand->fetchAll();

                        // Candidats libres : uniquement pour l'Examen Final
                        if ($examen['code'] === 'CEPE_FINAL') {
                            $stmtLibres = $pdo->prepare("
                                SELECT id, nom, prenoms
                                FROM candidats
                                WHERE annee_id = ? AND est_candidat_libre = 1 AND centre_examen_id = ?
                            ");
                            $stmtLibres->execute([$anneeId, $centreId]);
                            $candidats = array_merge($candidats, $stmtLibres->fetchAll());
                        }

                        // Tri alphabétique global (nom, prénoms) sur l'ensemble des candidats du centre
                        usort($candidats, function ($a, $b) {
                            return [$a['nom'], $a['prenoms']] <=> [$b['nom'], $b['prenoms']];
                        });

                        $candidatIds = array_column($candidats, 'id');
                        $capaciteTotale = array_sum(array_column($salles, 'capacite'));

                        if (count($candidatIds) !== $capaciteTotale) {
                            $avertissementsEmargement[] =
                                $centreEff['nom_centre']
                                . " : effectif candidats (" . count($candidatIds) . ") différent "
                                . "de la capacité des salles (" . $capaciteTotale . "). "
                                . "Régénérez le plan de salle si l'effectif a changé.";

                            // Au-delà de la capacité des salles, les candidats excédentaires ne
                            // seront pas insérés dans plan_salle_candidats (boucle bornée par
                            // salle['capacite'] ci-dessous) : on les nomme explicitement pour que
                            // le personnel sache exactement qui manquera sur la feuille d'émargement.
                            if (count($candidatIds) > $capaciteTotale) {
                                $candidatsNonPlaces = array_slice($candidats, $capaciteTotale);
                                $nomsNonPlaces = array_map(
                                    fn ($c) => $c['nom'] . ' ' . $c['prenoms'],
                                    $candidatsNonPlaces
                                );
                                $avertissementsEmargement[] =
                                    $centreEff['nom_centre'] . " : " . count($candidatsNonPlaces)
                                    . " candidat(s) NE FIGURERONT PAS sur la feuille d'émargement faute de place : "
                                    . implode(', ', $nomsNonPlaces) . ".";
                            }
                        }

                        // Repart de zéro pour ce centre (une régénération recalcule toute l'affectation)
                        $salleIds = array_column($salles, 'id');
                        $ph = implode(',', array_fill(0, count($salleIds), '?'));
                        $pdo->prepare("DELETE FROM plan_salle_candidats WHERE plan_salle_id IN ($ph)")->execute($salleIds);

                        $stmtIns = $pdo->prepare("
                            INSERT INTO plan_salle_candidats (plan_salle_id, candidat_id, examen_id, numero_ordre)
                            VALUES (?, ?, ?, ?)
                        ");

                        $curseur = 0;
                        $ordreGlobal = 1;
                        foreach ($salles as $salle) {
                            $capacite = (int) $salle['capacite'];
                            for ($i = 0; $i < $capacite && $curseur < count($candidatIds); $i++, $curseur++) {
                                $stmtIns->execute([$salle['id'], $candidatIds[$curseur], $examenIdPost, $ordreGlobal]);
                                $ordreGlobal++;
                            }
                        }

                        $nbCentresTraites++;
                    }

                    $pdo->commit();

                    $success = "Liste d'émargement générée pour $nbCentresTraites centre(s) — " . $examen['libelle'] . ".";
                    if ($avertissementsEmargement) {
                        $avertissements = array_merge($avertissements, $avertissementsEmargement);
                    }

                } catch (Exception $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    $error = "Erreur lors de la génération de l'émargement : " . $e->getMessage();
                }
            }
        }
    }
}


/*
|--------------------------------------------------------------------------
| RÉCUPÉRER LES EXAMENS
|--------------------------------------------------------------------------
*/

$stmt = $pdo->prepare("
    SELECT
        id,
        code,
        libelle,
        ordre
    FROM examens
    WHERE annee_id = ?
      AND actif = 1
    ORDER BY ordre ASC
");

$stmt->execute([
    $anneeId
]);

$examens = $stmt->fetchAll();


/*
|--------------------------------------------------------------------------
| EXAMEN SÉLECTIONNÉ
|--------------------------------------------------------------------------
*/

$examenSelectionneId =
    $examenIdPost > 0
        ? $examenIdPost
        : (
            isset($_GET['examen_id'])
                ? (int) $_GET['examen_id']
                : (
                    isset($_SESSION['examen_actif_id'])
                        ? (int) $_SESSION['examen_actif_id']
                        : (
                            isset($examens[0]['id'])
                                ? (int) $examens[0]['id']
                                : 0
                        )
                )
        );

// Garde la sélection en session pour qu'elle soit reprise sur la page Centres
if ($examenSelectionneId > 0) {
    $_SESSION['examen_actif_id'] = $examenSelectionneId;
}


/*
|--------------------------------------------------------------------------
| VÉRIFIER QUE L'EXAMEN SÉLECTIONNÉ EST VALIDE
|--------------------------------------------------------------------------
*/

if (
    $examenSelectionneId > 0
    && !recupererExamen(
        $pdo,
        $examenSelectionneId,
        $anneeId
    )
) {

    $examenSelectionneId =
        isset($examens[0]['id'])
            ? (int) $examens[0]['id']
            : 0;
}


/*
|--------------------------------------------------------------------------
| RÉCUPÉRER LE PLAN EXISTANT
|--------------------------------------------------------------------------
*/

$plans = [];

if ($examenSelectionneId > 0) {

    $stmt = $pdo->prepare("
        SELECT

            ce.id AS centre_effectif_id,

            ce.effectif_calcule,
            ce.effectif_retenu,
            ce.est_manuel AS effectif_est_manuel,

            c.id AS centre_id,

            e.nom AS nom_centre,

            ex.libelle AS examen_libelle,

            ps.id AS plan_id,
            ps.numero_salle,
            ps.effectif_calcule AS salle_effectif_calcule,
            ps.effectif_retenu AS salle_effectif_retenu,
            ps.est_manuel AS salle_est_manuel,
            ps.commentaire AS salle_commentaire

        FROM centre_effectifs AS ce

        INNER JOIN centres AS c
            ON c.id = ce.centre_id

        INNER JOIN ecoles AS e
            ON e.id = c.ecole_id

        INNER JOIN examens AS ex
            ON ex.id = ce.examen_id

        LEFT JOIN plan_salles AS ps
            ON ps.centre_effectif_id = ce.id

        WHERE ce.examen_id = ?
          AND c.annee_id = ?

        ORDER BY
            e.nom ASC,
            ps.numero_salle ASC
    ");

    $stmt->execute([
        $examenSelectionneId,
        $anneeId
    ]);

    $lignes = $stmt->fetchAll();


    /*
    |--------------------------------------------------------------------------
    | REGROUPER PAR CENTRE
    |--------------------------------------------------------------------------
    */

    foreach ($lignes as $ligne) {

        $centreId =
            (int) $ligne['centre_id'];


        if (!isset($plans[$centreId])) {

            $effectif =
                $ligne['effectif_retenu'] !== null
                    ? (int) $ligne['effectif_retenu']
                    : (int) $ligne['effectif_calcule'];


            $plans[$centreId] = [

                'centre_effectif_id' =>
                    (int) $ligne['centre_effectif_id'],

                'centre_id' =>
                    $centreId,

                'nom_centre' =>
                    $ligne['nom_centre'],

                'effectif_calcule' =>
                    (int) $ligne['effectif_calcule'],

                'effectif_retenu' =>
                    $effectif,

                'effectif_est_manuel' =>
                    (int) $ligne['effectif_est_manuel'],

                'salles' => []

            ];
        }


        if ($ligne['plan_id'] !== null) {

            $plans[$centreId]['salles'][] = [

                'id' =>
                    (int) $ligne['plan_id'],

                'numero_salle' =>
                    (int) $ligne['numero_salle'],

                'effectif_calcule' =>
                    (int) $ligne['salle_effectif_calcule'],

                'effectif_retenu' =>
                    $ligne['salle_effectif_retenu'] !== null
                        ? (int) $ligne['salle_effectif_retenu']
                        : (int) $ligne['salle_effectif_calcule'],

                'est_manuel' =>
                    (int) $ligne['salle_est_manuel'],

                'commentaire' =>
                    $ligne['salle_commentaire']

            ];
        }
    }
}

/*
|--------------------------------------------------------------------------
| ÉCARTS EFFECTIF / RÉPARTITION — détection proactive, avant la répartition
|--------------------------------------------------------------------------
| Deux origines distinctes pour un même symptôme (un total qui "ne colle
| pas") :
|   - écart manuel  : l'effectif retenu (onglet Centres, modifiable à la
|     main) ne correspond plus au décompte réel des candidats (onglet
|     Candidats, recalculé en continu) — l'effectif retenu est resté figé
|     après une saisie manuelle pendant que les candidats évoluaient.
|   - écart plan    : un plan de salle a déjà été généré, mais l'effectif
|     retenu a changé depuis (nouveaux candidats, correction) sans que le
|     plan soit régénéré — les salles reflètent alors un total périmé.
| Affiché en permanence (pas seulement après une génération) pour que
| l'écart soit visible avant de lancer/valider une répartition.
*/
$centresEcartEffectif = [];
foreach ($plans as $p) {
    $sommeSalles = array_sum(array_column($p['salles'], 'effectif_retenu'));
    $nbSalles = count($p['salles']);

    $ecartManuel = $p['effectif_est_manuel'] && $p['effectif_calcule'] !== $p['effectif_retenu'];
    $ecartPlan = $nbSalles > 0 && $sommeSalles !== $p['effectif_retenu'];

    if ($ecartManuel || $ecartPlan) {
        $centresEcartEffectif[] = [
            'nom' => $p['nom_centre'],
            'effectif_calcule' => $p['effectif_calcule'],
            'effectif_retenu' => $p['effectif_retenu'],
            'somme_salles' => $sommeSalles,
            'ecart_manuel' => $ecartManuel,
            'ecart_plan' => $ecartPlan,
        ];
    }
}

include '../views/layouts/header.php';

?>

<div class="container-fluid">

    <!-- =========================================================
         EN-TÊTE
    ========================================================== -->

    <div class="d-flex justify-content-between align-items-center mb-4">

        <div>

            <h2 class="mb-1">
                🪑 Plans de salle
            </h2>

            <div class="text-muted">

                Année scolaire :

                <strong>
                    <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>
                </strong>

            </div>

        </div>

    </div>


    <!-- =========================================================
         MESSAGES
    ========================================================== -->

    <div id="messages-plan">

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


    <!-- =========================================================
         AVERTISSEMENTS
    ========================================================== -->

    <?php if (!empty($avertissements)): ?>

        <div class="alert alert-warning">

            <h5 class="mb-2">

                <i class="bi bi-exclamation-triangle"></i>

                Situations nécessitant une attention

            </h5>

            <ul class="mb-0">

                <?php foreach ($avertissements as $message): ?>

                    <li>
                        <?= htmlspecialchars($message) ?>
                    </li>

                <?php endforeach; ?>

            </ul>

        </div>

    <?php endif; ?>

    </div>


    <!-- =========================================================
         ÉCARTS EFFECTIF / RÉPARTITION (toujours visible, avant la
         répartition — pas seulement après une génération)
    ========================================================== -->

    <?php if (!empty($centresEcartEffectif)): ?>

        <div class="alert alert-danger">

            <h5 class="mb-2">
                <i class="bi bi-exclamation-octagon"></i>
                <?= count($centresEcartEffectif) ?> centre(s) à vérifier avant la répartition
            </h5>

            <p class="mb-2">
                Pour ces centres, l'effectif retenu ne correspond plus soit au
                décompte réel des candidats, soit au plan de salle déjà
                généré. Vérifiez et choisissez la valeur à retenir avant de
                vous fier à la répartition.
            </p>

            <ul class="mb-0">
                <?php foreach ($centresEcartEffectif as $ec): ?>
                    <li>
                        <strong><?= htmlspecialchars($ec['nom']) ?></strong> :
                        <?php if ($ec['ecart_manuel']): ?>
                            effectif retenu manuellement (<?= $ec['effectif_retenu'] ?>)
                            ≠ décompte actuel des candidats (<?= $ec['effectif_calcule'] ?>).
                        <?php endif; ?>
                        <?php if ($ec['ecart_manuel'] && $ec['ecart_plan']): ?> Et <?php endif; ?>
                        <?php if ($ec['ecart_plan']): ?>
                            le plan de salle généré (<?= $ec['somme_salles'] ?> au total)
                            ne correspond plus à l'effectif retenu (<?= $ec['effectif_retenu'] ?>)
                            — régénérez ce centre.
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>

        </div>

    <?php endif; ?>


    <!-- =========================================================
         SÉLECTION DE L'EXAMEN
    ========================================================== -->

    <div class="card shadow-sm mb-4">

        <div class="card-header bg-primary text-white">

            <strong>

                <i class="bi bi-calendar-event"></i>

                Sélection de l'examen

            </strong>

        </div>


        <div class="card-body">

            <form
                method="POST"
                class="row g-3 align-items-end"
            >

                <div class="col-md-8">

                    <label class="form-label">

                        Examen

                    </label>

                    <select
                        name="examen_id"
                        class="form-select"
                        required
                    >

                        <option value="">
                            -- Sélectionner un examen --
                        </option>

                        <?php foreach ($examens as $examen): ?>

                            <option
                                value="<?= (int) $examen['id'] ?>"
                                <?= (
                                    $examenSelectionneId
                                    === (int) $examen['id']
                                )
                                    ? 'selected'
                                    : ''
                                ?>
                            >

                                <?= htmlspecialchars(
                                    $examen['libelle']
                                ) ?>

                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>


                <div class="col-md-4">

                    <button
                        type="submit"
                        name="generer_plan"
                        class="btn btn-primary w-100"
                    >

                        <i class="bi bi-magic"></i>

                        Générer / actualiser le plan

                    </button>

                </div>


                <div class="col-12">

                    <div class="form-check">

                        <input
                            type="checkbox"
                            class="form-check-input"
                            id="ecraserManuelGlobal"
                            name="ecraser_manuel"
                            value="1"
                            onchange="if (this.checked) { this.checked = confirm('Ceci va aussi écraser les répartitions manuelles existantes, pour tous les centres de cet examen. Continuer ?'); }"
                        >

                        <label class="form-check-label" for="ecraserManuelGlobal">
                            Écraser aussi les répartitions manuelles existantes (sinon elles sont conservées telles quelles)
                        </label>

                    </div>

                </div>

            </form>

            <?php if ($examenSelectionneId): ?>
                <form method="POST" class="mt-2">
                    <input type="hidden" name="examen_id" value="<?= (int) $examenSelectionneId ?>">
                    <button type="submit" name="generer_emargement" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-list-ol"></i>
                        Générer la liste d'émargement (candidat → salle)
                    </button>
                    <small class="text-muted d-block mt-1">
                        À lancer après le calcul des salles ci-dessus : affecte chaque candidat, par ordre alphabétique, à sa salle.
                    </small>
                    <a href="pdf_liste_candidats_salles.php?examen_id=<?= (int) $examenSelectionneId ?>" target="_blank" class="btn btn-outline-secondary btn-sm mt-2">
                        <i class="bi bi-file-earmark-pdf"></i>
                        Liste des candidats par salle (PDF, ordre alphabétique)
                    </a>
                </form>
            <?php endif; ?>

        </div>

    </div>


    <!-- =========================================================
         RÈGLES
    ========================================================== -->

    <div class="alert alert-info">

        <strong>

            <i class="bi bi-info-circle"></i>

            Règles de répartition automatique

        </strong>

        <ul class="mb-0 mt-2">

            <li>
                Une salle normale contient entre
                <strong>25 et 30 candidats</strong>.
            </li>

            <li>
                L'application recherche d'abord
                <strong>le nombre minimal de salles</strong>.
            </li>

            <li>
                Lorsque plusieurs répartitions sont possibles,
                elle privilégie la répartition la plus équilibrée.
            </li>

            <li>
                Si aucune répartition entre 25 et 30 n'est possible avec le nombre
                minimal de salles, l'application essaie avec moins de salles en
                tolérant <strong class="text-danger">exceptionnellement jusqu'à 31</strong>
                par salle — ces salles sont alors signalées en rouge, avec un
                commentaire, pour permettre une correction manuelle si besoin.
            </li>

            <li>
                Les situations impossibles (même avec 31) sont signalées
                pour permettre une décision manuelle.
            </li>

            <li>
                Une modification manuelle n'est
                <strong>jamais écrasée</strong> par une nouvelle génération automatique.
            </li>

        </ul>

    </div>


    <!-- =========================================================
         PLANS DES CENTRES
    ========================================================== -->

    <?php if (!empty($plans)): ?>

        <?php foreach ($plans as $plan): ?>

            <?php

            $totalSalles = count($plan['salles']);

            $totalCandidats = 0;

            foreach ($plan['salles'] as $salle) {

                $totalCandidats +=
                    (int) $salle['effectif_retenu'];
            }

            $effectifCentre =
                (int) $plan['effectif_retenu'];

            $difference =
                $totalCandidats - $effectifCentre;

            $centreARepartitionManuelle = false;
            foreach ($plan['salles'] as $salle) {
                if ($salle['est_manuel']) {
                    $centreARepartitionManuelle = true;
                    break;
                }
            }

            // Regroupe les salles par effectif identique (ex: "10 salles de 30,
            // 5 salles de 29"), du plus grand effectif au plus petit, pour un
            // coup d'œil rapide avant le détail salle par salle.
            $repartitionParEffectif = [];
            foreach ($plan['salles'] as $salle) {
                $eff = (int) $salle['effectif_retenu'];
                $repartitionParEffectif[$eff] = ($repartitionParEffectif[$eff] ?? 0) + 1;
            }
            krsort($repartitionParEffectif);

            ?>

            <div class="card shadow-sm mb-4" id="centre-card-<?= (int) $plan['centre_effectif_id'] ?>">

                <!-- =================================================
                     EN-TÊTE DU CENTRE
                ================================================== -->

                <div class="card-header">

                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">

                        <div>

                            <strong class="fs-5">

                                📍

                                <?= htmlspecialchars(
                                    $plan['nom_centre']
                                ) ?>

                            </strong>

                        </div>


                        <div>

                            <span class="badge bg-primary">

                                Effectif centre :

                                <?= $effectifCentre ?>

                            </span>


                            <span class="badge bg-secondary">

                                <?= $totalSalles ?>

                                salle(s)

                            </span>


                            <?php if (
                                $plan['effectif_est_manuel']
                            ): ?>

                                <span class="badge bg-warning text-dark">

                                    Effectif manuel

                                </span>

                            <?php endif; ?>


                            <form method="POST" class="d-inline">

                                <input type="hidden" name="examen_id" value="<?= (int) $examenSelectionneId ?>">
                                <input type="hidden" name="centre_effectif_id" value="<?= (int) $plan['centre_effectif_id'] ?>">
                                <?php if ($centreARepartitionManuelle): ?>
                                    <input type="hidden" name="ecraser_manuel" value="1">
                                <?php endif; ?>

                                <button
                                    type="submit"
                                    name="generer_plan"
                                    class="btn btn-outline-primary btn-sm"
                                    <?php if ($centreARepartitionManuelle): ?>
                                        onclick="return confirm('Ce centre a une répartition manuelle existante. Voulez-vous l\'écraser et régénérer automatiquement ?');"
                                    <?php endif; ?>
                                >
                                    <i class="bi bi-magic"></i>
                                    Générer ce centre
                                </button>

                            </form>

                        </div>

                    </div>

                </div>


                <!-- =================================================
                     CORPS DU CENTRE
                ================================================== -->

                <div class="card-body">


                    <?php if (empty($plan['salles'])): ?>

                        <div class="alert alert-warning">

                            <i class="bi bi-exclamation-triangle"></i>

                            Aucun plan de salle n'est actuellement
                            enregistré pour ce centre.

                            <?php if ($effectifCentre > 0): ?>

                                <br>

                                Une intervention manuelle peut être
                                nécessaire.

                            <?php endif; ?>

                        </div>


                    <?php else: ?>


                        <!-- =============================================
                             TABLEAU DES SALLES
                        ============================================== -->

                        <div class="table-responsive">

                            <table class="table table-bordered table-hover align-middle">

                                <thead class="table-light">

                                    <tr>

                                        <th style="width: 90px;">
                                            Salle
                                        </th>

                                        <th class="text-center">
                                            Effectif calculé
                                        </th>

                                        <th class="text-center">
                                            Effectif retenu
                                        </th>

                                        <th class="text-center">
                                            Statut
                                        </th>

                                        <th>
                                            Commentaire / PV
                                        </th>

                                        <th
                                            class="text-center"
                                            style="width: 260px;"
                                        >
                                            Actions
                                        </th>

                                    </tr>

                                </thead>


                                <tbody>

                                    <?php foreach (
                                        $plan['salles']
                                        as $salle
                                    ): ?>

                                        <tr>

                                            <!-- SALLE -->

                                            <td>

                                                <strong>
                                                    Salle
                                                    <?= (int)
                                                        $salle[
                                                            'numero_salle'
                                                        ] ?>
                                                </strong>

                                            </td>


                                            <!-- EFFECTIF CALCULÉ -->

                                            <td class="text-center">

                                                <span class="badge bg-secondary">

                                                    <?= (int)
                                                        $salle[
                                                            'effectif_calcule'
                                                        ] ?>

                                                </span>

                                            </td>


                                            <!-- EFFECTIF RETENU -->

                                            <td class="text-center">

                                                <span
                                                    class="badge fs-6
                                                    <?= (
                                                        $salle['effectif_retenu'] > 30
                                                            ? 'bg-danger'
                                                            : ($salle['est_manuel']
                                                                ? 'bg-warning text-dark'
                                                                : 'bg-primary')
                                                    ) ?>"
                                                    <?= $salle['effectif_retenu'] > 30
                                                        ? 'title="Dépassement exceptionnel du maximum normal de 30"'
                                                        : '' ?>
                                                >

                                                    <?= (int)
                                                        $salle[
                                                            'effectif_retenu'
                                                        ] ?>

                                                    <?= $salle['effectif_retenu'] > 30
                                                        ? ' <i class="bi bi-exclamation-triangle-fill"></i>'
                                                        : '' ?>

                                                </span>

                                            </td>


                                            <!-- STATUT -->

                                            <td class="text-center">

                                                <?php if (
                                                    $salle['est_manuel']
                                                ): ?>

                                                    <span class="badge bg-warning text-dark">

                                                        <i class="bi bi-pencil"></i>

                                                        Manuel

                                                    </span>

                                                <?php else: ?>

                                                    <span class="badge bg-success">

                                                        <i class="bi bi-check-circle"></i>

                                                        Automatique

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- COMMENTAIRE -->

                                            <td>

                                                <?php if (
                                                    !empty(
                                                        $salle['commentaire']
                                                    )
                                                ): ?>

                                                    <small>

                                                        <?= nl2br(
                                                            htmlspecialchars(
                                                                $salle[
                                                                    'commentaire'
                                                                ]
                                                            )
                                                        ) ?>

                                                    </small>

                                                <?php else: ?>

                                                    <span class="text-muted">

                                                        Aucun commentaire

                                                    </span>

                                                <?php endif; ?>

                                            </td>


                                            <!-- ACTIONS -->

                                            <td>

                                                <div class="d-flex gap-2">

                                                    <!-- MODIFIER -->

                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-outline-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#modalModifier<?= (int) $salle['id'] ?>"
                                                    >

                                                        <i class="bi bi-pencil"></i>

                                                        Modifier

                                                    </button>


                                                    <!-- SUPPRIMER SI MANUEL -->

                                                    <?php if (
                                                        $salle['est_manuel']
                                                    ): ?>

                                                        <form
                                                            method="POST"
                                                            onsubmit="return confirm('Voulez-vous vraiment supprimer cette salle manuelle ?');"
                                                        >

                                                            <input
                                                                type="hidden"
                                                                name="examen_id"
                                                                value="<?= $examenSelectionneId ?>"
                                                            >

                                                            <input
                                                                type="hidden"
                                                                name="plan_id"
                                                                value="<?= (int) $salle['id'] ?>"
                                                            >

                                                            <button
                                                                type="submit"
                                                                name="supprimer_salle"
                                                                class="btn btn-sm btn-outline-danger"
                                                            >

                                                                <i class="bi bi-trash"></i>

                                                            </button>

                                                        </form>

                                                    <?php endif; ?>

                                                </div>

                                            </td>

                                        </tr>

                                    <?php endforeach; ?>

                                </tbody>


                                <!-- =====================================
                                     TOTAL
                                ====================================== -->

                                <tfoot>

                                    <?php if (!empty($repartitionParEffectif)): ?>
                                        <tr class="table-light">
                                            <td colspan="6" class="small text-muted">
                                                <i class="bi bi-bar-chart-steps"></i>
                                                Répartition :
                                                <?php
                                                $morceaux = [];
                                                foreach ($repartitionParEffectif as $eff => $nb) {
                                                    $morceaux[] = "$nb salle" . ($nb > 1 ? 's' : '') . " de $eff";
                                                }
                                                echo htmlspecialchars(implode(', ', $morceaux));
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>

                                    <tr class="table-light">

                                        <th>
                                            Total
                                        </th>

                                        <th class="text-center">
                                            —
                                        </th>

                                        <th class="text-center">

                                            <?= $totalCandidats ?>

                                            candidat(s)

                                        </th>

                                        <th class="text-center">

                                            <?= $totalSalles ?>

                                            salle(s)

                                        </th>

                                        <th>

                                            <?php if ($difference === 0): ?>

                                                <span class="badge bg-success">

                                                    Total conforme

                                                </span>

                                            <?php else: ?>

                                                <span class="badge bg-danger">

                                                    Écart :
                                                    <?= $difference > 0 ? '+' : '' ?>
                                                    <?= $difference ?>

                                                </span>

                                            <?php endif; ?>

                                        </th>

                                        <th></th>

                                    </tr>

                                </tfoot>

                            </table>

                        </div>


                        <!-- =============================================
                             MODALES DE MODIFICATION (une par salle)
                             Rendues ICI, en dehors du tableau : un <div>
                             placé à l'intérieur de <tbody> est invalide en
                             HTML et le navigateur y supprime silencieusement
                             le <form> qu'il contient (bouton "Enregistrer"
                             alors sans effet).
                        ============================================== -->

                        <?php foreach ($plan['salles'] as $salle): ?>

                            <div
                                class="modal fade"
                                id="modalModifier<?= (int) $salle['id'] ?>"
                                tabindex="-1"
                                aria-hidden="true"
                            >

                                <div class="modal-dialog">

                                    <div class="modal-content">

                                        <form method="POST">

                                            <div class="modal-header">

                                                <h5 class="modal-title">

                                                    Modifier Salle
                                                    <?= (int)
                                                        $salle[
                                                            'numero_salle'
                                                        ] ?>

                                                </h5>

                                                <button
                                                    type="button"
                                                    class="btn-close"
                                                    data-bs-dismiss="modal"
                                                ></button>

                                            </div>


                                            <div class="modal-body">

                                                <input
                                                    type="hidden"
                                                    name="examen_id"
                                                    value="<?= $examenSelectionneId ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="plan_id"
                                                    value="<?= (int) $salle['id'] ?>"
                                                >


                                                <div class="mb-3">

                                                    <label class="form-label">

                                                        Effectif de la salle

                                                    </label>

                                                    <input
                                                        type="number"
                                                        name="effectif_salle"
                                                        class="form-control"
                                                        min="1"
                                                        value="<?= (int) $salle['effectif_retenu'] ?>"
                                                        required
                                                    >

                                                    <div class="form-text">

                                                        Tu peux dépasser
                                                        exceptionnellement
                                                        la règle de 30 candidats
                                                        si une décision officielle
                                                        le justifie.

                                                    </div>

                                                </div>


                                                <div class="mb-3">

                                                    <label class="form-label">

                                                        Commentaire / PV

                                                    </label>

                                                    <textarea
                                                        name="commentaire"
                                                        class="form-control"
                                                        rows="4"
                                                        placeholder="Exemple : Répartition exceptionnelle décidée par le conseil. PV n°..."
                                                    ><?= htmlspecialchars(
                                                        $salle[
                                                            'commentaire'
                                                        ] ?? ''
                                                    ) ?></textarea>

                                                </div>


                                                <div class="alert alert-warning mb-0">

                                                    <i class="bi bi-exclamation-triangle"></i>

                                                    Cette modification
                                                    deviendra
                                                    <strong>manuelle</strong>
                                                    et ne sera plus
                                                    écrasée par la
                                                    génération automatique.

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
                                                    name="modifier_salle"
                                                    class="btn btn-primary"
                                                >

                                                    <i class="bi bi-save"></i>

                                                    Enregistrer

                                                </button>

                                            </div>

                                        </form>

                                    </div>

                                </div>

                            </div>

                        <?php endforeach; ?>


                    <?php endif; ?>


                    <!-- =================================================
                         AJOUT MANUEL D'UNE SALLE
                    ================================================== -->

                    <div class="mt-3">

                        <button
                            type="button"
                            class="btn btn-outline-primary"
                            data-bs-toggle="modal"
                            data-bs-target="#modalAjouter<?= (int) $plan['centre_effectif_id'] ?>"
                        >

                            <i class="bi bi-plus-circle"></i>

                            Ajouter une salle manuellement

                        </button>

                    </div>


                    <!-- =================================================
                         MODAL AJOUT SALLE
                    ================================================== -->

                    <div
                        class="modal fade"
                        id="modalAjouter<?= (int) $plan['centre_effectif_id'] ?>"
                        tabindex="-1"
                        aria-hidden="true"
                    >

                        <div class="modal-dialog">

                            <div class="modal-content">

                                <form method="POST">

                                    <div class="modal-header">

                                        <h5 class="modal-title">

                                            Ajouter une salle

                                        </h5>

                                        <button
                                            type="button"
                                            class="btn-close"
                                            data-bs-dismiss="modal"
                                        ></button>

                                    </div>


                                    <div class="modal-body">

                                        <input
                                            type="hidden"
                                            name="examen_id"
                                            value="<?= $examenSelectionneId ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="centre_effectif_id"
                                            value="<?= (int) $plan['centre_effectif_id'] ?>"
                                        >


                                        <div class="mb-3">

                                            <label class="form-label">

                                                Effectif de la nouvelle salle

                                            </label>

                                            <input
                                                type="number"
                                                name="nouvel_effectif"
                                                class="form-control"
                                                min="1"
                                                value="30"
                                                required
                                            >

                                        </div>


                                        <div class="mb-3">

                                            <label class="form-label">

                                                Commentaire / PV

                                            </label>

                                            <textarea
                                                name="nouveau_commentaire"
                                                class="form-control"
                                                rows="4"
                                                placeholder="Exemple : Salle supplémentaire décidée par le conseil..."
                                            ></textarea>

                                        </div>


                                        <div class="alert alert-warning mb-0">

                                            <i class="bi bi-info-circle"></i>

                                            Cette nouvelle salle sera
                                            automatiquement considérée comme
                                            <strong>manuelle</strong>.

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
                                            name="ajouter_salle"
                                            class="btn btn-primary"
                                        >

                                            <i class="bi bi-plus-circle"></i>

                                            Ajouter la salle

                                        </button>

                                    </div>

                                </form>

                            </div>

                        </div>

                    </div>


                    <!-- =================================================
                         AVERTISSEMENT SUR L'ÉQUILIBRE
                    ================================================== -->

                    <?php if (
                        $totalCandidats !== $effectifCentre
                    ): ?>

                        <div class="alert alert-danger mt-3 mb-0">

                            <i class="bi bi-exclamation-triangle"></i>

                            <strong>Attention :</strong>

                            le total des effectifs des salles
                            (<?= $totalCandidats ?>)
                            ne correspond pas à l'effectif du centre
                            (<?= $effectifCentre ?>).

                            <br>

                            Écart :
                            <strong>
                                <?= abs($difference) ?>
                                candidat(s)
                            </strong>.

                            <br>

                            Vérifiez la répartition avant validation
                            définitive du plan.

                        </div>

                    <?php endif; ?>


                </div>

            </div>

        <?php endforeach; ?>


    <?php else: ?>

        <div class="alert alert-secondary">

            <i class="bi bi-info-circle"></i>

            Sélectionnez un examen pour afficher
            les effectifs et le plan de salle.

        </div>

    <?php endif; ?>

</div>


<script>
// Les formulaires d'ajout/modification/suppression de salle et de génération
// par centre restent de simples formulaires POST classiques côté serveur
// (rien ne change si JS est désactivé). Ce script les intercepte pour éviter
// le rechargement complet de la page (et le saut en haut de page qui en
// résulte) : seule la carte du centre concerné, et la zone de messages, sont
// remplacées par leur version fraîchement rendue par le serveur.
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

    var modalOuvert = formulaire.closest('.modal');

    var appliquerReponse = function (texteHtml) {
        var docFrais = new DOMParser().parseFromString(texteHtml, 'text/html');
        var carteFraiche = docFrais.getElementById(carte.id);
        var messagesFrais = docFrais.getElementById('messages-plan');
        var messagesActuels = document.getElementById('messages-plan');

        if (!carteFraiche) {
            // Réponse inattendue : on retombe sur un envoi classique plutôt
            // que de laisser l'action sans effet visible.
            formulaire.submit();
            return;
        }

        carte.outerHTML = carteFraiche.outerHTML;

        if (messagesFrais && messagesActuels) {
            messagesActuels.outerHTML = messagesFrais.outerHTML;
        }
    };

    try {
        var reponse = await fetch(formulaire.action, {
            method: 'POST',
            body: donnees
        });

        if (!reponse.ok) {
            throw new Error('HTTP ' + reponse.status);
        }

        var texte = await reponse.text();

        if (modalOuvert && window.bootstrap) {
            var instance = bootstrap.Modal.getInstance(modalOuvert);
            if (instance) {
                modalOuvert.addEventListener('hidden.bs.modal', function () {
                    appliquerReponse(texte);
                }, { once: true });
                instance.hide();
            } else {
                appliquerReponse(texte);
            }
        } else {
            appliquerReponse(texte);
        }

    } catch (erreur) {
        // Souci réseau ou serveur : on retombe sur le comportement classique
        // (rechargement complet) pour ne jamais bloquer l'utilisateur.
        formulaire.submit();
    }

}, false);
</script>


<?php

include '../views/layouts/footer.php';

?>