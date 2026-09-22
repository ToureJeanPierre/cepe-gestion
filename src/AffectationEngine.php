<?php

namespace Iepp\CepeGestion;

use PDO;

/**
 * Moteur d'affectation de la surveillance (Module 6 du cahier des charges).
 *
 * Centralise :
 *  - le calcul des centres "interdits" pour un enseignant (anti-collusion
 *    École + Groupe Scolaire) ;
 *  - la génération automatique des surveillants pour les Examens Blancs
 *    (mobilisation à 100%, brassage Public/Privé et niveaux) ;
 *  - la génération automatique des surveillants pour l'Examen Final
 *    (nombre requis = nb salles + 2, ordre de priorité réglementaire) ;
 *  - la vérification de non-redondance (un acteur = un seul rôle par examen).
 */
class AffectationEngine
{
    /** Ordre de priorité décroissant pour l'auto-affectation à l'Examen Final. */
    private const PRIORITE_FINAL = [
        1  => ['niveaux' => ['CM1', 'CM2'], 'secteur' => 'Public'],
        2  => ['niveaux' => ['CM2'],         'secteur' => 'Privé'],
        3  => ['niveaux' => ['CE2'],         'secteur' => 'Public'],
        4  => ['niveaux' => ['CM1'],         'secteur' => 'Privé'],
        5  => ['niveaux' => ['CE1'],         'secteur' => 'Public'],
        6  => ['niveaux' => ['CE2'],         'secteur' => 'Privé'],
        7  => ['niveaux' => ['CP2'],         'secteur' => 'Public'],
        8  => ['niveaux' => ['CE1'],         'secteur' => 'Privé'],
        9  => ['niveaux' => ['CP1'],         'secteur' => 'Public'],
        10 => ['niveaux' => ['CP2', 'CP1'],  'secteur' => 'Privé'],
    ];

    public function __construct(
        private PDO $pdo,
        private int $anneeId
    ) {
    }

    /**
     * Convertit le code d'examen (BLANC_1, BLANC_2, CEPE_FINAL) en libellé
     * utilisé par la colonne affectations.type_examen.
     */
    public static function libelleTypeExamen(string $code): string
    {
        return match ($code) {
            'BLANC_1'    => 'Blanc 1',
            'BLANC_2'    => 'Blanc 2',
            'CEPE_FINAL' => 'CEPE Officiel',
            default      => $code,
        };
    }

    public static function estExamenFinal(string $code): bool
    {
        return $code === 'CEPE_FINAL';
    }

    /**
     * Rang de priorité (1 = prioritaire, 10 = dernier) d'un enseignant pour
     * l'auto-affectation à l'Examen Final, selon son niveau tenu et le
     * secteur de son école.
     */
    private function rangPriorite(?string $niveau, string $secteur): int
    {
        foreach (self::PRIORITE_FINAL as $rang => $regle) {
            if (in_array($niveau, $regle['niveaux'], true) && $regle['secteur'] === $secteur) {
                return $rang;
            }
        }

        // Niveau non renseigné ou combinaison hors table : en dernier.
        return 99;
    }

    /**
     * Retourne, pour chaque école, l'ensemble des écoles "sœurs" (même
     * Groupe Scolaire) + elle-même. Utilisé pour l'anti-collusion.
     *
     * @return array<int, int[]> ecole_id => liste d'ecole_id du même GS
     */
    private function ecolesParGroupeScolaire(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, groupe_scolaire
            FROM ecoles
        ");

        $toutes = $stmt->fetchAll();

        $parGroupe = [];
        foreach ($toutes as $e) {
            $cle = $e['groupe_scolaire'] !== null && $e['groupe_scolaire'] !== ''
                ? 'GS:' . $e['groupe_scolaire']
                : 'SOLO:' . $e['id'];
            $parGroupe[$cle][] = (int) $e['id'];
        }

        $resultat = [];
        foreach ($toutes as $e) {
            $cle = $e['groupe_scolaire'] !== null && $e['groupe_scolaire'] !== ''
                ? 'GS:' . $e['groupe_scolaire']
                : 'SOLO:' . $e['id'];
            $resultat[(int) $e['id']] = $parGroupe[$cle];
        }

        return $resultat;
    }

    /**
     * Pour chaque centre, liste des ecole_id qui y composent : l'école hôte
     * (centres.ecole_id — le cas le plus évident de conflit, un enseignant
     * ne doit jamais surveiller sa propre école) + les écoles rattachées
     * (ecole_centre).
     *
     * @return array<int, int[]> ecole_id => liste de centre_id où cette école compose
     */
    private function centresParEcole(): array
    {
        // Filtré par l'année en cours via `centres` : les écoles ne sont pas
        // dupliquées par année, mais la composition école→centre peut
        // changer d'une année à l'autre, donc seuls les centres de l'année
        // courante doivent nourrir le calcul anti-collusion.
        $stmt = $this->pdo->prepare("
            SELECT c.id AS centre_id, c.ecole_id AS ecole_composante_id
            FROM centres c
            WHERE c.annee_id = ?
            UNION
            SELECT ecc.centre_id, ecc.ecole_composante_id
            FROM ecole_centre ecc
            JOIN centres c ON c.id = ecc.centre_id
            WHERE c.annee_id = ?
        ");
        $stmt->execute([$this->anneeId, $this->anneeId]);

        $resultat = [];
        foreach ($stmt->fetchAll() as $ligne) {
            $resultat[(int) $ligne['ecole_composante_id']][] = (int) $ligne['centre_id'];
        }

        return $resultat;
    }

    /**
     * Calcule, pour chaque enseignant du vivier, la liste des centre_id où
     * il ne peut JAMAIS être affecté (règles 6.2.A.1 et 6.2.A.2 :
     * anti-collusion École propre + Groupe Scolaire).
     *
     * @param array<int,array> $enseignants lignes personnel (avec ecole_id)
     * @return array<int, int[]> personnel_id => liste de centre_id interdits
     */
    public function calculerCentresInterdits(array $enseignants): array
    {
        $ecolesGS = $this->ecolesParGroupeScolaire();
        $centresParEcole = $this->centresParEcole();

        $interdits = [];

        foreach ($enseignants as $ens) {
            $ecoleId = $ens['ecole_id'] !== null ? (int) $ens['ecole_id'] : null;

            if ($ecoleId === null) {
                $interdits[$ens['id']] = [];
                continue;
            }

            $ecolesDuGroupe = $ecolesGS[$ecoleId] ?? [$ecoleId];

            $centres = [];
            foreach ($ecolesDuGroupe as $ecId) {
                foreach ($centresParEcole[$ecId] ?? [] as $centreId) {
                    $centres[$centreId] = true;
                }
            }

            $interdits[$ens['id']] = array_keys($centres);
        }

        return $interdits;
    }

    /**
     * Vérifie, pour UNE personne (tout rôle : Président, Secrétariat,
     * Superviseur, Surveillant), si elle est en conflit anti-collusion
     * (règles 6.2.A.1/6.2.A.2) avec un centre donné. Utilisé par les
     * affectations manuelles avant enregistrement.
     */
    public function estEnConflitAvecCentre(int $personnelId, int $centreId): bool
    {
        $stmt = $this->pdo->prepare("SELECT id, ecole_id FROM personnel WHERE id = ?");
        $stmt->execute([$personnelId]);
        $personne = $stmt->fetch();

        if (!$personne) {
            return false;
        }

        $interdits = $this->calculerCentresInterdits([$personne]);

        return in_array($centreId, $interdits[$personnelId] ?? [], true);
    }

    /**
     * Vivier des enseignants éligibles à la surveillance (Directeurs +
     * Adjoints uniquement, jamais conseillers/administratifs — règle
     * 6.1.5), disponibles, et pas déjà verrouillés sur un rôle pour cet
     * examen (règle de non-redondance 6.2.A.3).
     */
    public function viveirEnseignantsDisponibles(string $typeExamenLibelle): array
    {
        $stmt = $this->pdo->prepare("
            SELECT p.id, p.ecole_id, p.nom, p.prenoms, p.sexe, p.niveau_tenu, p.type_ecole, p.fonction
            FROM personnel p
            WHERE p.categorie = 'enseignant'
              AND p.disponibilite = 'En activité'
              AND NOT EXISTS (
                    SELECT 1 FROM affectations a
                    WHERE a.enseignant_id = p.id
                      AND a.annee_id = ?
                      AND a.type_examen = ?
              )
            ORDER BY p.nom, p.prenoms
        ");

        $stmt->execute([$this->anneeId, $typeExamenLibelle]);

        return $stmt->fetchAll();
    }

    /**
     * Centres actifs pour un examen donné, avec effectif retenu et nombre
     * de salles déjà calculées dans le Module Plans de salle.
     *
     * @return array<int,array> centre_id => ['centre_id'=>,'ecole_nom'=>,'effectif'=>,'nb_salles'=>,'quota_surveillants'=>]
     */
    public function centresPourExamen(int $examenId, bool $estFinal): array
    {
        $stmt = $this->pdo->prepare("
            SELECT
                c.id AS centre_id,
                e.nom AS ecole_nom,
                ce.id AS centre_effectif_id,
                COALESCE(ce.effectif_retenu, ce.effectif_calcule, 0) AS effectif,
                (SELECT COUNT(*) FROM plan_salles ps WHERE ps.centre_effectif_id = ce.id) AS nb_salles
            FROM centres c
            JOIN ecoles e ON e.id = c.ecole_id
            LEFT JOIN centre_effectifs ce ON ce.centre_id = c.id AND ce.examen_id = ?
            WHERE c.annee_id = ?
            ORDER BY e.nom
        ");

        $stmt->execute([$examenId, $this->anneeId]);

        $centres = [];
        foreach ($stmt->fetchAll() as $ligne) {
            $nbSalles = (int) $ligne['nb_salles'];
            $centres[(int) $ligne['centre_id']] = [
                'centre_id'          => (int) $ligne['centre_id'],
                'ecole_nom'          => $ligne['ecole_nom'],
                'effectif'           => (int) $ligne['effectif'],
                'nb_salles'          => $nbSalles,
                // Examen Final : nb salles + 2 surveillants de réserve.
                // Blancs : pas de quota fixe, on mobilise 100% du vivier.
                // Un centre sans ligne centre_effectifs (ce.id NULL, effectif
                // jamais calculé) doit tomber dans le même cas que "0 salle"
                // pour rester visible avec un avertissement plutôt que de
                // disparaître silencieusement de l'écran d'affectation.
                'quota_surveillants' => $estFinal ? ($nbSalles > 0 ? $nbSalles + 2 : 0) : null,
            ];
        }

        return $centres;
    }

    /**
     * Enregistre une affectation (rôle manuel ou automatique). Retourne
     * true si l'insertion a réussi, false si l'acteur ne peut pas être
     * affecté (déjà un rôle sur cet examen).
     *
     * Non-redondance : tous les rôles à présence physique unique (Président,
     * Chef Secrétariat, Membre Secrétariat, Surveillant) limitent la
     * personne à UN SEUL centre par examen. Le rôle Superviseur fait
     * exception : un superviseur couvre en pratique plusieurs centres de sa
     * zone sur un même examen (confirmé par le document réel "Mission de
     * Supervision"), donc plusieurs lignes lui sont autorisées — la
     * contrainte unique en base (annee_id, type_examen, enseignant_id,
     * centre_id) empêche seulement un doublon exact sur le même centre.
     */
    public function enregistrerAffectation(
        string $typeExamenLibelle,
        int $enseignantId,
        int $centreId,
        string $role,
        bool $estManuel,
        ?int $planSalleId = null
    ): bool {
        $stmtCentre = $this->pdo->prepare("SELECT COUNT(*) FROM centres WHERE id = ? AND annee_id = ?");
        $stmtCentre->execute([$centreId, $this->anneeId]);
        if ((int) $stmtCentre->fetchColumn() === 0) {
            return false;
        }

        if ($role !== 'Superviseur') {
            $stmt = $this->pdo->prepare("
                SELECT COUNT(*) FROM affectations
                WHERE annee_id = ? AND type_examen = ? AND enseignant_id = ?
            ");
            $stmt->execute([$this->anneeId, $typeExamenLibelle, $enseignantId]);
            if ((int) $stmt->fetchColumn() > 0) {
                return false;
            }
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO affectations
                    (annee_id, type_examen, enseignant_id, centre_id, role, plan_salle_id, est_manuel)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $this->anneeId,
                $typeExamenLibelle,
                $enseignantId,
                $centreId,
                $role,
                $planSalleId,
                $estManuel ? 1 : 0,
            ]);

            return true;
        } catch (\PDOException $e) {
            // Code 23000 = violation de contrainte unique (déjà affecté sur ce centre).
            return false;
        }
    }

    /**
     * Génération automatique des surveillants pour un Examen Blanc (1 ou 2) :
     * mobilisation à 100% du vivier disponible, réparti proportionnellement
     * à l'effectif des centres, en respectant l'anti-collusion et en
     * brassant Public/Privé + niveaux autant que possible.
     *
     * @return array{affectes:int, non_affectes:array, warnings:string[]}
     */
    public function genererSurveillantsBlancs(string $typeExamenLibelle, int $examenId): array
    {
        $centres = $this->centresPourExamen($examenId, false);

        if (count($centres) === 0) {
            return ['affectes' => 0, 'non_affectes' => [], 'warnings' => ["Aucun centre configuré pour cet examen."]];
        }

        $pool = $this->viveirEnseignantsDisponibles($typeExamenLibelle);

        if (count($pool) === 0) {
            return ['affectes' => 0, 'non_affectes' => [], 'warnings' => ["Aucun enseignant disponible (déjà tous affectés, ou aucun en activité)."]];
        }

        $interdits = $this->calculerCentresInterdits($pool);

        // Répartition cible proportionnelle à l'effectif de chaque centre.
        $effectifTotal = array_sum(array_column($centres, 'effectif')) ?: 1;
        $centreIds = array_keys($centres);

        $cibles = [];
        $restant = count($pool);
        foreach ($centreIds as $cid) {
            $part = (int) round($pool ? ($centres[$cid]['effectif'] / $effectifTotal) * count($pool) : 0);
            $cibles[$cid] = max(0, $part);
        }

        // Brassage : on alterne niveau/secteur en triant le pool avant
        // distribution round-robin, ce qui mécaniquement mixe les profils
        // dans chaque centre plutôt que de les regrouper par bloc.
        usort($pool, function ($a, $b) {
            $clefA = ($a['niveau_tenu'] ?? '') . '|' . $a['type_ecole'];
            $clefB = ($b['niveau_tenu'] ?? '') . '|' . $b['type_ecole'];
            return $clefA <=> $clefB;
        });
        // Entrelacement simple pour éviter les blocs homogènes consécutifs.
        $entrelace = [];
        $paquets = [];
        foreach ($pool as $p) {
            $paquets[($p['niveau_tenu'] ?? '') . '|' . $p['type_ecole']][] = $p;
        }
        while (!empty($paquets)) {
            foreach ($paquets as $clef => &$liste) {
                $entrelace[] = array_shift($liste);
                if (empty($liste)) {
                    unset($paquets[$clef]);
                }
            }
            unset($liste);
        }
        $pool = $entrelace;

        $affectes = 0;
        $nonAffectes = [];
        $compteParCentre = array_fill_keys($centreIds, 0);

        foreach ($pool as $enseignant) {
            $eid = (int) $enseignant['id'];
            $interditsEns = $interdits[$eid] ?? [];

            // On cherche le centre autorisé le plus "en retard" par rapport
            // à sa cible proportionnelle.
            $meilleurCentre = null;
            $meilleurEcart = -INF;

            foreach ($centreIds as $cid) {
                if (in_array($cid, $interditsEns, true)) {
                    continue;
                }
                $ecart = $cibles[$cid] - $compteParCentre[$cid];
                if ($ecart > $meilleurEcart) {
                    $meilleurEcart = $ecart;
                    $meilleurCentre = $cid;
                }
            }

            if ($meilleurCentre === null) {
                // Interdit partout (cas extrême) : à affecter manuellement.
                $nonAffectes[] = $enseignant;
                continue;
            }

            $ok = $this->enregistrerAffectation($typeExamenLibelle, $eid, $meilleurCentre, 'Surveillant', false);
            if ($ok) {
                $affectes++;
                $compteParCentre[$meilleurCentre]++;
            } else {
                $nonAffectes[] = $enseignant;
            }
        }

        $warnings = [];
        if (!empty($nonAffectes)) {
            $warnings[] = count($nonAffectes) . " enseignant(s) n'ont pas pu être affectés automatiquement (conflit d'école/Groupe Scolaire dans tous les centres disponibles) et nécessitent un placement manuel.";
        }

        return ['affectes' => $affectes, 'non_affectes' => $nonAffectes, 'warnings' => $warnings];
    }

    /**
     * Génération automatique des surveillants pour l'Examen Final :
     * quota = nb salles + 2 (réserve) par centre, ordre de priorité
     * réglementaire strict (table PRIORITE_FINAL), anti-collusion
     * respectée. Tous portent le même rôle "Surveillant" — la réserve
     * n'est qu'un quota interne, pas une distinction de rôle affichée.
     *
     * @return array{affectes:int, non_affectes:array, centres_incomplets:array, warnings:string[]}
     */
    public function genererSurveillantsFinal(string $typeExamenLibelle, int $examenId): array
    {
        $centres = $this->centresPourExamen($examenId, true);

        if (count($centres) === 0) {
            return ['affectes' => 0, 'non_affectes' => [], 'centres_incomplets' => [], 'warnings' => ["Aucun centre configuré pour cet examen."]];
        }

        $pool = $this->viveirEnseignantsDisponibles($typeExamenLibelle);

        // Tri par ordre de priorité réglementaire (1 = prioritaire).
        usort($pool, function ($a, $b) {
            $ra = $this->rangPriorite($a['niveau_tenu'], $a['type_ecole']);
            $rb = $this->rangPriorite($b['niveau_tenu'], $b['type_ecole']);
            if ($ra !== $rb) {
                return $ra <=> $rb;
            }
            return strcmp($a['nom'] . $a['prenoms'], $b['nom'] . $b['prenoms']);
        });

        $interdits = $this->calculerCentresInterdits($pool);

        $affectes = 0;
        $nonAffectes = [];
        $compteParCentre = array_fill_keys(array_keys($centres), 0);

        // On distribue centre par centre (dans l'ordre croissant du
        // quota restant à combler) pour éviter qu'un seul centre "aspire"
        // tout le vivier prioritaire au détriment des autres.
        $centreIds = array_keys($centres);
        usort($centreIds, fn ($a, $b) => $centres[$a]['quota_surveillants'] <=> $centres[$b]['quota_surveillants']);

        $indexPool = 0;
        foreach ($centreIds as $cid) {
            $quota = $centres[$cid]['quota_surveillants'];
            if ($quota <= 0) {
                continue;
            }

            $affectesPourCeCentre = 0;
            $i = 0;
            while ($affectesPourCeCentre < $quota && $i < count($pool)) {
                $enseignant = $pool[$i];
                $eid = (int) $enseignant['id'];

                if (isset($enseignant['_affecte'])) {
                    $i++;
                    continue;
                }

                if (in_array($cid, $interdits[$eid] ?? [], true)) {
                    $i++;
                    continue;
                }

                $ok = $this->enregistrerAffectation($typeExamenLibelle, $eid, $cid, 'Surveillant', false);
                if ($ok) {
                    $pool[$i]['_affecte'] = true;
                    $affectes++;
                    $affectesPourCeCentre++;
                }
                $i++;
            }

            if ($affectesPourCeCentre < $quota) {
                $centres[$cid]['manque'] = $quota - $affectesPourCeCentre;
            }
        }

        foreach ($pool as $enseignant) {
            if (!isset($enseignant['_affecte'])) {
                $nonAffectes[] = $enseignant;
            }
        }

        $centresIncomplets = array_filter($centres, fn ($c) => isset($c['manque']));
        $centresSansPlan = array_filter($centres, fn ($c) => $c['quota_surveillants'] === 0 || $c['quota_surveillants'] === null);

        $warnings = [];
        if (!empty($centresSansPlan)) {
            $noms = array_map(fn ($c) => $c['ecole_nom'], $centresSansPlan);
            $warnings[] = count($centresSansPlan) . " centre(s) n'ont aucun plan de salle calculé pour cet examen, donc aucun quota de surveillants (" . implode(', ', $noms) . ") — générez d'abord leur plan de salle dans le module dédié.";
        }
        if (!empty($centresIncomplets)) {
            foreach ($centresIncomplets as $c) {
                $warnings[] = "Centre \"{$c['ecole_nom']}\" : il manque {$c['manque']} surveillant(s) (vivier insuffisant ou anti-collusion trop restrictive) — à compléter manuellement.";
            }
        }
        if (!empty($nonAffectes) && empty($centresSansPlan)) {
            $warnings[] = count($nonAffectes) . " enseignant(s) du vivier n'ont pas été utilisés (quotas déjà atteints partout où ils sont autorisés).";
        }

        return [
            'affectes' => $affectes,
            'non_affectes' => $nonAffectes,
            'centres_incomplets' => $centresIncomplets,
            'warnings' => $warnings,
        ];
    }
}
