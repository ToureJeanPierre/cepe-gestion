<?php

// ==========================================
// BILAN DESPS PAR ÉCOLE + FICHIER DFA
// ==========================================
// Règles métier de l'IEPP (cf. SKILL_dfa-cepe-bilan-ecoles.md) : un élève doit
// apparaître au CM2, dans la bonne école, pour l'année d'examen. S'il est resté
// dans une classe antérieure (DFA non faite par le directeur), on génère les
// lignes DFA à téléverser sur la plateforme. Les données DESPS (école, classe,
// année de la dernière ligne du cursus) sont stockées dans desps_cursus.

require_once __DIR__ . '/depots_helpers.php';

if (!defined('CLASSES_DFA')) {
    define('CLASSES_DFA', ['CP1', 'CP2', 'CE1', 'CE2', 'CM1', 'CM2']);
    define('PRIORITE_R_DFA', ['CM1', 'CE2', 'CE1', 'CP2', 'CP1']);
}

if (!function_exists('codeAnnee')) {
    function codeAnnee(int $debut): string
    {
        return sprintf('%02d%02d', $debut % 100, ($debut + 1) % 100); // 2025 -> "2526"
    }
}

if (!function_exists('calculerDfa')) {
    /**
     * Règle exacte de calcul de la DFA (année d'examen paramétrable).
     *
     * @param string $matricule        matricule de l'élève
     * @param string $classe           classe trouvée sur DESPS (CP1..CM2)
     * @param int    $anneeDebut       année de début trouvée sur DESPS (2024 pour 2024-2025)
     * @param int    $anneeExamenDebut année de début de l'année d'examen (2026 pour 2026-2027)
     * @return array{statut:string, motif:string, lignes:array, avertissements:array}
     */
    function calculerDfa(string $matricule, string $classe, int $anneeDebut, int $anneeExamenDebut): array
    {
        $matricule = strtoupper(preg_replace('/\s+/', '', trim($matricule)));
        $classe = strtoupper(str_replace(' ', '', trim($classe)));
        $idx = array_search($classe, CLASSES_DFA, true);
        if ($idx === false) {
            return ['statut' => 'A_VERIFIER', 'motif' => "Classe inconnue : $classe", 'lignes' => [], 'avertissements' => []];
        }

        $N = $anneeExamenDebut - $anneeDebut;          // années à combler
        $K = 5 - $idx;                                 // classes à monter jusqu'au CM2
        $avert = [];

        if ($N <= 0) {
            if ($classe === 'CM2' && $N === 0) {
                return ['statut' => 'OK', 'motif' => '', 'lignes' => [], 'avertissements' => []];
            }
            return ['statut' => 'A_VERIFIER', 'motif' => "Inscrit en $classe pour l'année en cours ou année incohérente", 'lignes' => [], 'avertissements' => []];
        }

        $R = $N - $K;
        if ($R < 0) {
            return ['statut' => 'SAUT_DE_NIVEAU', 'motif' => "Il manque " . (-$R) . " année(s) pour atteindre le CM2 sans saut de niveau", 'lignes' => [], 'avertissements' => []];
        }

        // Répartition des redoublements
        $quota = array_fill_keys(CLASSES_DFA, 0);
        if ($classe === 'CM2') {
            $quota['CM2'] = $R;
            if ($R >= 2) {
                $avert[] = "Redoublant du CM2 $R fois : à confirmer avec le directeur";
            }
        } else {
            $dispo = array_values(array_filter(PRIORITE_R_DFA, fn ($c) => array_search($c, CLASSES_DFA, true) >= $idx));
            if ($R > count($dispo)) {
                return ['statut' => 'A_VERIFIER', 'motif' => "$R redoublements pour seulement " . count($dispo) . " classe(s) possible(s)", 'lignes' => [], 'avertissements' => []];
            }
            for ($i = 0; $i < $R; $i++) {
                $quota[$dispo[$i]]++;
            }
        }

        // Construction année par année
        $lignes = [];
        $c = $idx;
        for ($a = $anneeDebut; $a < $anneeExamenDebut; $a++) {
            $nom = CLASSES_DFA[$c];
            if ($quota[$nom] > 0) {
                $quota[$nom]--;
                $dfa = 'R';
            } else {
                $dfa = 'A';
                $c++;
            }
            $lignes[] = ['annee' => codeAnnee($a), 'matricule' => $matricule, 'dfa' => $dfa, 'classe' => $nom];
        }

        // Contrôle croisé matricule (non bloquant)
        if (preg_match('/^(\d{2})/', $matricule, $m)) {
            $redoublMatricule = ($anneeExamenDebut + 1) % 100 - (int) $m[1];
            if ($R > $redoublMatricule) {
                $avert[] = "Le matricule ({$m[1]}) suggère $redoublMatricule redoublement(s) mais le calcul en donne $R";
            }
        }
        return ['statut' => 'DFA', 'motif' => '', 'lignes' => $lignes, 'avertissements' => $avert];
    }
}

// ------------------------------------------
// Normalisation des données DESPS (formats variables selon la plateforme)
// ------------------------------------------

if (!function_exists('normaliserClasseDesps')) {
    // "CE 2", "ce2", "Cours Élémentaire 2", "Cours Moyen 2ème année" -> CE2 ; null si non reconnue.
    function normaliserClasseDesps(?string $brut): ?string
    {
        $t = retirerAccents(mb_strtoupper(trim((string) $brut)));
        if ($t === '') {
            return null;
        }
        if (preg_match('/^(CP|CE|CM)\s*([12])\b/', $t, $m)) {
            return $m[1] . $m[2];
        }
        $niveau = null;
        if (str_contains($t, 'PREPARATOIRE')) {
            $niveau = 'CP';
        } elseif (str_contains($t, 'ELEMENTAIRE')) {
            $niveau = 'CE';
        } elseif (str_contains($t, 'MOYEN')) {
            $niveau = 'CM';
        }
        if ($niveau === null) {
            return null;
        }
        if (preg_match('/\b([12])\s*(?:ERE|ER|E|EME|IERE|IEME)?\b/', $t, $m)) {
            return $niveau . $m[1];
        }
        if (str_contains($t, 'PREMIER') || str_contains($t, 'PREMIERE')) {
            return $niveau . '1';
        }
        if (str_contains($t, 'DEUXIEME') || str_contains($t, 'SECOND')) {
            return $niveau . '2';
        }
        return null;
    }
}

if (!function_exists('normaliserAnneeDesps')) {
    // "2024-2025", "2024/2025", "2024 - 2025", "24-25", "2425" -> 2024 ; null si non reconnue.
    function normaliserAnneeDesps($brut): ?int
    {
        $t = trim((string) $brut);
        if (preg_match('/(20\d{2})\s*[-\/–]\s*(20\d{2})/', $t, $m)) {
            return (int) $m[1];
        }
        if (preg_match('/^(\d{2})\s*[-\/–]\s*(\d{2})$/', $t, $m)) {
            return 2000 + (int) $m[1];
        }
        if (preg_match('/^(\d{2})(\d{2})$/', $t, $m) && (int) $m[2] === ((int) $m[1] + 1) % 100) {
            return 2000 + (int) $m[1];
        }
        if (preg_match('/^(20\d{2})$/', $t, $m)) {
            return (int) $m[1];
        }
        return null;
    }
}

if (!function_exists('anneeDebutDepuisLibelle')) {
    // "2026-2027" -> 2026
    function anneeDebutDepuisLibelle(?string $libelle): ?int
    {
        return preg_match('/^(\d{4})/', (string) $libelle, $m) ? (int) $m[1] : null;
    }
}

if (!function_exists('sansAccentsDfa')) {
    function sansAccentsDfa(string $t): string
    {
        return strtr($t, [
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c',
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ç' => 'C',
        ]);
    }
}

if (!function_exists('nomFichierDfa')) {
    // DFA_<nom_ecole_sans_accents_ni_espaces>.xls
    function nomFichierDfa(string $nomEcole): string
    {
        return 'DFA_' . preg_replace('/[^A-Za-z0-9_\-]/', '', sansAccentsDfa($nomEcole)) . '.xls';
    }
}

// ------------------------------------------
// École DESPS = école déclarée ?
// ------------------------------------------

if (!function_exists('indexEcolesDfa')) {
    /** @return array<int, array{id:int, nom:string, jetons:string[], tutrice:?int}> */
    function indexEcolesDfa(PDO $pdo): array
    {
        $index = [];
        foreach ($pdo->query("SELECT id, nom, ecole_tutrice_id FROM ecoles")->fetchAll() as $e) {
            $index[(int) $e['id']] = ['id' => (int) $e['id'], 'nom' => $e['nom'], 'jetons' => jetonsNomEcole($e['nom']),
                'tutrice' => $e['ecole_tutrice_id'] ? (int) $e['ecole_tutrice_id'] : null];
        }
        return $index;
    }
}

if (!function_exists('ecolesCorrespondantesDfa')) {
    /** Écoles dont le nom contient tous les mots distinctifs du nom DESPS (variantes tolérées). @return int[] */
    function ecolesCorrespondantesDfa(array $index, string $nomDesps): array
    {
        $jetons = jetonsNomEcole($nomDesps);
        if (!$jetons) {
            return [];
        }
        $ids = [];
        foreach ($index as $e) {
            $tous = true;
            foreach ($jetons as $j) {
                $trouve = false;
                foreach ($e['jetons'] as $k) {
                    if (jetonsCorrespondent($j, $k)) {
                        $trouve = true;
                        break;
                    }
                }
                if (!$trouve) {
                    $tous = false;
                    break;
                }
            }
            if ($tous) {
                $ids[] = $e['id'];
            }
        }
        return $ids;
    }
}

if (!function_exists('ecoleDespsConforme')) {
    /**
     * L'école trouvée sur DESPS est-elle l'école qui a déclaré l'élève ? Une école
     * rattachée (ex. Cité SODEFOR) est considérée conforme si DESPS donne son école
     * tutrice, ou une autre école rattachée à la même tutrice : la DFA se rattache
     * à l'école officielle telle qu'elle apparaît sur DESPS.
     *
     * @return array{conforme: bool, motif: string}
     */
    function ecoleDespsConforme(array $index, int $ecoleDeclareeId, string $nomDesps): array
    {
        $ids = ecolesCorrespondantesDfa($index, $nomDesps);
        if (!$ids) {
            return ['conforme' => false, 'motif' => "École DESPS non reconnue dans l'application : « $nomDesps »"];
        }
        $declaree = $index[$ecoleDeclareeId] ?? null;
        $famille = [$ecoleDeclareeId];
        if ($declaree && $declaree['tutrice']) {
            $famille[] = $declaree['tutrice'];
            foreach ($index as $e) {
                if ($e['tutrice'] === $declaree['tutrice']) {
                    $famille[] = $e['id'];
                }
            }
        }
        foreach ($index as $e) {
            if ($e['tutrice'] === $ecoleDeclareeId) {
                $famille[] = $e['id'];
            }
        }
        if (array_intersect($ids, $famille)) {
            return ['conforme' => true, 'motif' => ''];
        }
        return ['conforme' => false, 'motif' => "École DESPS : « $nomDesps »"];
    }
}

// ------------------------------------------
// Bilan d'un élève
// ------------------------------------------

if (!function_exists('calculerBilanEleve')) {
    /**
     * @param array $cursus introuvable, ecole_desps, classe_desps, annee_debut, ecole_conforme
     * @return array{statut:string, changement_ecole:bool, motif:string, lignes:array, avertissements:array}
     */
    function calculerBilanEleve(string $matricule, int $ecoleDeclareeId, array $cursus, int $anneeExamenDebut, array $indexEcoles): array
    {
        if (!empty($cursus['introuvable'])) {
            return ['statut' => 'MATRICULE_INTROUVABLE', 'changement_ecole' => false, 'motif' => "Matricule introuvable sur DESPS : à reprendre", 'lignes' => [], 'avertissements' => []];
        }
        if (empty($cursus['classe_desps']) || empty($cursus['annee_debut'])) {
            return ['statut' => 'A_VERIFIER', 'changement_ecole' => false, 'motif' => "Données DESPS incomplètes (classe ou année manquante)", 'lignes' => [], 'avertissements' => []];
        }

        $base = calculerDfa($matricule, $cursus['classe_desps'], (int) $cursus['annee_debut'], $anneeExamenDebut);
        $avert = $base['avertissements'];

        $changement = false;
        $ecoleDesps = trim((string) ($cursus['ecole_desps'] ?? ''));
        $motifEcole = '';
        if ($ecoleDesps !== '' && empty($cursus['ecole_conforme'])) {
            $cmp = ecoleDespsConforme($indexEcoles, $ecoleDeclareeId, $ecoleDesps);
            if (!$cmp['conforme']) {
                $changement = true;
                $motifEcole = $cmp['motif'];
            }
        }

        $statut = $base['statut'];
        $motif = $base['motif'];
        if ($changement) {
            if ($statut === 'OK') {
                $statut = 'CHANGEMENT_ECOLE';
                $motif = $motifEcole . " : faire le changement d'école (agcp.sigfne.net)";
            } elseif ($statut === 'DFA') {
                $avert[] = "Faire le changement d'école avant de téléverser la DFA. " . $motifEcole;
            } else {
                $avert[] = "École différente de celle déclarée. " . $motifEcole;
            }
        }
        return ['statut' => $statut, 'changement_ecole' => $changement, 'motif' => $motif, 'lignes' => $base['lignes'], 'avertissements' => $avert];
    }
}

if (!function_exists('identiteDifferente')) {
    /**
     * Compare l'identité renvoyée par le site à celle déclarée par l'école :
     * nom, prénoms (au moins un prénom en commun, accents/casse ignorés) et date de
     * naissance. Un matricule saisi avec une faute peut désigner un AUTRE élève.
     *
     * @return string[] différences constatées (vide = concordant)
     */
    function identiteDifferente(array $identiteSite, array $candidat): array
    {
        $mots = function (?string $t): array {
            $t = retirerAccents(mb_strtoupper(trim((string) $t)));
            $t = preg_replace('/[^A-Z0-9]+/', ' ', $t);
            $m = array_filter(explode(' ', $t), fn ($x) => $x !== '');
            sort($m);
            return array_values($m);
        };
        $diff = [];
        $nomSite = $identiteSite['Nom'] ?? '';
        if ($nomSite !== '' && $mots($nomSite) !== $mots($candidat['nom'] ?? '')) {
            $diff['nom'] = "nom : site « $nomSite » ≠ déclaré « " . ($candidat['nom'] ?? '') . " »";
        }
        $prenomsSite = $identiteSite['Prénom(s)'] ?? '';
        if ($prenomsSite !== '' && !array_intersect($mots($prenomsSite), $mots($candidat['prenoms'] ?? ''))) {
            $diff['prenoms'] = "prénoms : site « $prenomsSite » ≠ déclarés « " . ($candidat['prenoms'] ?? '') . " »";
        }
        $naissanceSite = $identiteSite['Date et lieu de naissance'] ?? '';
        if (preg_match('#(\d{1,2})/(\d{1,2})/(\d{4})#', $naissanceSite, $m) && !empty($candidat['date_naissance'])) {
            $iso = sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
            if ($iso !== $candidat['date_naissance']) {
                $diff['naissance'] = "date de naissance : site " . sprintf('%02d/%02d/%04d', (int) $m[1], (int) $m[2], (int) $m[3])
                    . " ≠ déclarée " . date('d/m/Y', strtotime($candidat['date_naissance']));
            }
        }
        return $diff;
    }
}

if (!function_exists('appliquerControleIdentite')) {
    /**
     * Toute différence d'identité est signalée. Si le nom ET les prénoms du site ne
     * correspondent pas à l'élève déclaré, le matricule est très probablement celui
     * d'un autre enfant : traitement manuel, AUCUNE ligne DFA (on ne téléverse jamais
     * une DFA pour un matricule qui n'est peut-être pas le bon).
     *
     * @param string[] $diffs résultat de identiteDifferente() (clés nom / prenoms / naissance)
     */
    function appliquerControleIdentite(array $bilan, array $diffs): array
    {
        foreach ($diffs as $d) {
            $bilan['avertissements'][] = "Identité différente sur le site (matricule d'un autre élève ?) — $d";
        }
        if (isset($diffs['nom'], $diffs['prenoms'])) {
            $bilan['statut'] = 'A_VERIFIER';
            $bilan['lignes'] = [];
            $bilan['changement_ecole'] = false;
            $bilan['motif'] = "Le nom et les prénoms du site ne correspondent pas à l'élève déclaré : matricule probablement erroné, à vérifier";
        }
        return $bilan;
    }
}

if (!function_exists('enregistrerCursusEtBilan')) {
    /**
     * Enregistre (ou met à jour) les données DESPS d'un candidat et recalcule/stocke son bilan.
     * Données issues du site : source 'site', identité et cursus conservés ; l'identité
     * du site est comparée à celle déclarée (avertissement si différente).
     *
     * @param array $candidat id, matricule_dsps, ecole_id (+ nom, prenoms, date_naissance pour le contrôle d'identité)
     * @param array $donnees  introuvable, ecole_desps, classe_desps, annee_debut, ecole_conforme,
     *                        source ('site'|'manuel'), identite_desps (array), cursus_brut (array), derniere_dfa
     */
    function enregistrerCursusEtBilan(PDO $pdo, array $candidat, array $donnees, int $anneeExamenDebut, array $indexEcoles): array
    {
        $cursus = [
            'introuvable' => (int) ($donnees['introuvable'] ?? 0),
            'ecole_desps' => $donnees['ecole_desps'] ?? null,
            'classe_desps' => $donnees['classe_desps'] ?? null,
            'annee_debut' => $donnees['annee_debut'] ?? null,
            'ecole_conforme' => (int) ($donnees['ecole_conforme'] ?? 0),
        ];
        $source = ($donnees['source'] ?? 'manuel') === 'site' ? 'site' : 'manuel';
        $identite = is_array($donnees['identite_desps'] ?? null) ? $donnees['identite_desps'] : null;
        $cursusBrut = is_array($donnees['cursus_brut'] ?? null) ? $donnees['cursus_brut'] : null;

        $bilan = calculerBilanEleve((string) $candidat['matricule_dsps'], (int) $candidat['ecole_id'], $cursus, $anneeExamenDebut, $indexEcoles);
        if ($identite) {
            $bilan = appliquerControleIdentite($bilan, identiteDifferente($identite, $candidat));
        }

        $pdo->prepare("
            INSERT INTO desps_cursus (candidat_id, introuvable, source, verifie_le, identite_desps, cursus_brut, derniere_dfa,
                                      ecole_desps, classe_desps, annee_debut, ecole_conforme,
                                      statut, changement_ecole, motif, lignes_dfa, avertissements, annee_examen_debut, calcule_le)
            VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE introuvable = VALUES(introuvable), source = VALUES(source), verifie_le = NOW(),
                identite_desps = VALUES(identite_desps), cursus_brut = VALUES(cursus_brut), derniere_dfa = VALUES(derniere_dfa),
                ecole_desps = VALUES(ecole_desps), classe_desps = VALUES(classe_desps),
                annee_debut = VALUES(annee_debut), ecole_conforme = VALUES(ecole_conforme), statut = VALUES(statut),
                changement_ecole = VALUES(changement_ecole), motif = VALUES(motif), lignes_dfa = VALUES(lignes_dfa),
                avertissements = VALUES(avertissements), annee_examen_debut = VALUES(annee_examen_debut), calcule_le = NOW()
        ")->execute([
            (int) $candidat['id'], $cursus['introuvable'], $source,
            $identite ? json_encode($identite, JSON_UNESCAPED_UNICODE) : null,
            $cursusBrut ? json_encode($cursusBrut, JSON_UNESCAPED_UNICODE) : null,
            $donnees['derniere_dfa'] ?? null,
            $cursus['ecole_desps'], $cursus['classe_desps'], $cursus['annee_debut'],
            $cursus['ecole_conforme'], $bilan['statut'], $bilan['changement_ecole'] ? 1 : 0, $bilan['motif'],
            json_encode($bilan['lignes'], JSON_UNESCAPED_UNICODE), json_encode($bilan['avertissements'], JSON_UNESCAPED_UNICODE), $anneeExamenDebut,
        ]);
        return $bilan;
    }
}

if (!function_exists('recalculerTousLesBilans')) {
    // Recalcule le bilan de tous les élèves de l'année ayant des données DESPS (changement d'année d'examen, d'école...).
    function recalculerTousLesBilans(PDO $pdo, int $anneeId, int $anneeExamenDebut): int
    {
        $index = indexEcolesDfa($pdo);
        $stmt = $pdo->prepare("
            SELECT c.id, c.nom, c.prenoms, c.date_naissance, c.matricule_dsps, c.ecole_id, d.introuvable, d.ecole_desps, d.classe_desps,
                   d.annee_debut, d.ecole_conforme, d.source, d.derniere_dfa, d.identite_desps, d.cursus_brut
            FROM desps_cursus d INNER JOIN candidats c ON c.id = d.candidat_id
            WHERE c.annee_id = ? AND c.est_candidat_libre = 0 AND c.ecole_id IS NOT NULL
        ");
        $stmt->execute([$anneeId]);
        $n = 0;
        foreach ($stmt->fetchAll() as $r) {
            $r['identite_desps'] = $r['identite_desps'] ? json_decode($r['identite_desps'], true) : null;
            $r['cursus_brut'] = $r['cursus_brut'] ? json_decode($r['cursus_brut'], true) : null;
            enregistrerCursusEtBilan($pdo, $r, $r, $anneeExamenDebut, $index);
            $n++;
        }
        return $n;
    }
}

// ------------------------------------------
// Lecture du bilan
// ------------------------------------------

if (!function_exists('lireBilanEcole')) {
    /**
     * Une ligne par candidat de l'école (ordre alphabétique), bilan stocké décodé.
     *
     * @return array<int, array>
     */
    function lireBilanEcole(PDO $pdo, int $anneeId, int $ecoleId): array
    {
        $stmt = $pdo->prepare("
            SELECT c.id, c.nom, c.prenoms, c.matricule_dsps, c.ecole_id,
                   d.id AS cursus_id, d.introuvable, d.ecole_desps, d.classe_desps, d.annee_debut, d.ecole_conforme,
                   d.statut, d.changement_ecole, d.motif, d.lignes_dfa, d.avertissements,
                   d.source, d.verifie_le, d.cursus_brut, d.derniere_dfa
            FROM candidats c
            LEFT JOIN desps_cursus d ON d.candidat_id = c.id
            WHERE c.annee_id = ? AND c.est_candidat_libre = 0 AND c.ecole_id = ?
            ORDER BY c.nom ASC, c.prenoms ASC, c.id ASC
        ");
        $stmt->execute([$anneeId, $ecoleId]);
        $lignes = [];
        foreach ($stmt->fetchAll() as $r) {
            $r['statut'] = $r['cursus_id'] ? $r['statut'] : 'NON_VERIFIE';
            if ($r['cursus_id'] === null && trim((string) $r['matricule_dsps']) === '') {
                $r['motif'] = "Pas de matricule : impossible de vérifier sur DESPS";
            } elseif ($r['cursus_id'] === null) {
                $r['motif'] = "Pas encore vérifié sur DESPS";
            }
            $r['lignes'] = $r['lignes_dfa'] ? json_decode($r['lignes_dfa'], true) : [];
            $r['avert'] = $r['avertissements'] ? json_decode($r['avertissements'], true) : [];
            $r['cursus'] = $r['cursus_brut'] ? json_decode($r['cursus_brut'], true) : [];
            $lignes[] = $r;
        }
        return $lignes;
    }
}

if (!function_exists('recapBilanParEcole')) {
    /**
     * Compte par école et par statut (toutes les écoles qui ont des candidats).
     *
     * @return array<int, array{id:int, nom:string, total:int, OK:int, DFA:int, CHANGEMENT_ECOLE:int, a_traiter:int, NON_VERIFIE:int, changement_avec_dfa:int}>
     */
    function recapBilanParEcole(PDO $pdo, int $anneeId): array
    {
        $stmt = $pdo->prepare("
            SELECT e.id, e.nom, c.id AS cid, c.matricule_dsps, d.statut, d.changement_ecole
            FROM ecoles e
            INNER JOIN candidats c ON c.ecole_id = e.id AND c.annee_id = ? AND c.est_candidat_libre = 0
            LEFT JOIN desps_cursus d ON d.candidat_id = c.id
            ORDER BY e.nom
        ");
        $stmt->execute([$anneeId]);
        $recap = [];
        foreach ($stmt->fetchAll() as $r) {
            $id = (int) $r['id'];
            $recap[$id] ??= ['id' => $id, 'nom' => $r['nom'], 'total' => 0, 'OK' => 0, 'DFA' => 0, 'CHANGEMENT_ECOLE' => 0,
                'a_traiter' => 0, 'NON_VERIFIE' => 0, 'changement_avec_dfa' => 0];
            $recap[$id]['total']++;
            $statut = $r['statut'] ?? 'NON_VERIFIE';
            if (in_array($statut, ['OK', 'DFA', 'CHANGEMENT_ECOLE', 'NON_VERIFIE'], true)) {
                $recap[$id][$statut]++;
            } else {
                $recap[$id]['a_traiter']++;   // SAUT_DE_NIVEAU, A_VERIFIER, MATRICULE_INTROUVABLE
            }
            if ($statut === 'DFA' && (int) $r['changement_ecole'] === 1) {
                $recap[$id]['changement_avec_dfa']++;
            }
        }
        return $recap;
    }
}

if (!function_exists('lignesDfaEcole')) {
    /**
     * Lignes du fichier DFA d'une école : uniquement les élèves au statut DFA
     * (y compris avec changement d'école), élèves par ordre alphabétique puis
     * lignes par année croissante.
     *
     * @return array<int, array{annee:string, matricule:string, dfa:string}>
     */
    function lignesDfaEcole(PDO $pdo, int $anneeId, int $ecoleId): array
    {
        $sortie = [];
        foreach (lireBilanEcole($pdo, $anneeId, $ecoleId) as $r) {
            if ($r['statut'] !== 'DFA') {
                continue;
            }
            $lignes = $r['lignes'];
            usort($lignes, fn ($a, $b) => strcmp($a['annee'], $b['annee']));
            foreach ($lignes as $l) {
                $sortie[] = ['annee' => (string) $l['annee'], 'matricule' => strtoupper(preg_replace('/\s+/', '', (string) $l['matricule'])), 'dfa' => $l['dfa']];
            }
        }
        return $sortie;
    }
}

if (!function_exists('ecrireFichierDfa')) {
    /**
     * Écrit le .xls au format imposé par la plateforme (modèle DFA176.xls) :
     * une feuille "eleves", en-têtes annee|matricule|dfa en minuscules, tout en texte,
     * aucune mise en forme.
     */
    function ecrireFichierDfa(array $lignes, string $cheminSortie): void
    {
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sh = $ss->getActiveSheet();
        $sh->setTitle('eleves');
        $texte = \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING;
        foreach (['annee', 'matricule', 'dfa'] as $i => $h) {
            $sh->setCellValueExplicit([$i + 1, 1], $h, $texte);
        }
        $ligne = 1;
        foreach ($lignes as $l) {
            $ligne++;
            $sh->setCellValueExplicit([1, $ligne], $l['annee'], $texte);
            $sh->setCellValueExplicit([2, $ligne], $l['matricule'], $texte);
            $sh->setCellValueExplicit([3, $ligne], $l['dfa'], $texte);
        }
        (new \PhpOffice\PhpSpreadsheet\Writer\Xls($ss))->save($cheminSortie);
    }
}
