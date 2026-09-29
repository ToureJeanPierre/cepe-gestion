<?php
// Configuration de la base de données pour Laragon
$host = '127.0.0.1';
$db   = 'cepe_gestion';
$user = 'root';
$pass = ''; // Par défaut, il n'y a pas de mot de passe sur Laragon
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Active les erreurs
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Retourne des tableaux associatifs
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    // Si vous voulez tester la connexion, décommentez la ligne suivante :
    // echo "Connexion réussie !";
} catch (\PDOException $e) {
    // En cas d'erreur, on arrête tout et on affiche le message
    die("Erreur de connexion : " . $e->getMessage());
}

// ==========================================
// CONTEXTE ANNÉE SCOLAIRE (sélecteur global + archivage)
// ==========================================
// Résolu une fois ici et partagé par toutes les pages qui incluent ce fichier :
//   $anneesDisponibles  -> liste complète (id, annee_scolaire, statut), plus récente d'abord
//   $anneeActive        -> ligne de l'année au statut 'en_cours' (celle où on écrit par défaut)
//   $anneeSelectionnee  -> ligne de l'année actuellement consultée (choisie via le sélecteur)
//   $anneeId            -> id de $anneeSelectionnee (raccourci le plus utilisé)
//   $ANNEE_SCOLAIRE     -> libellé de $anneeSelectionnee (ex: "2026-2027")
//   $anneeLectureSeule  -> true si l'année consultée est archivée (bloque créations/modifs)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ==========================================
// AUTHENTIFICATION
// ==========================================
// config/database.php est inclus en tout premier par les 30+ pages de
// public/ (index, tous les pdf_*.php, tous les api_*.php...) : un seul
// point d'accroche ici couvre toute l'application sans avoir à modifier
// chaque fichier. Exemptions : les scripts CLI (migrations/run_migration_*.php,
// qui incluent ce même fichier) et login.php/logout.php lui-même, pour ne
// pas créer de redirection en boucle.
if (PHP_SAPI !== 'cli') {
    $scriptCourant = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $pagesSansAuth = ['login.php', 'logout.php'];

    if (!in_array($scriptCourant, $pagesSansAuth, true)) {
        $utilisateurConnecte = null;
        if (!empty($_SESSION['utilisateur_id'])) {
            $stmtUtilisateur = $pdo->prepare("SELECT id, nom, actif FROM utilisateurs WHERE id = ?");
            $stmtUtilisateur->execute([$_SESSION['utilisateur_id']]);
            $utilisateurConnecte = $stmtUtilisateur->fetch();
        }

        // Revérifié à chaque requête (pas seulement à la connexion) : un
        // compte désactivé pendant qu'il est déjà connecté doit perdre
        // l'accès immédiatement, pas seulement à la prochaine tentative de
        // connexion.
        if (!$utilisateurConnecte || (int) $utilisateurConnecte['actif'] !== 1) {
            unset($_SESSION['utilisateur_id'], $_SESSION['utilisateur_nom']);
            header('Location: login.php');
            exit;
        }

        $_SESSION['utilisateur_nom'] = $utilisateurConnecte['nom'];
    }
}

$anneesDisponibles = $pdo->query("SELECT id, annee_scolaire, statut FROM annees ORDER BY annee_scolaire DESC")->fetchAll();

$anneeActive = null;
foreach ($anneesDisponibles as $a) {
    if ($a['statut'] === 'en_cours') {
        $anneeActive = $a;
        break;
    }
}

$anneeSelectionnee = null;
if (!empty($_SESSION['annee_id'])) {
    foreach ($anneesDisponibles as $a) {
        if ((int) $a['id'] === (int) $_SESSION['annee_id']) {
            $anneeSelectionnee = $a;
            break;
        }
    }
}
if (!$anneeSelectionnee) {
    $anneeSelectionnee = $anneeActive ?? ($anneesDisponibles[0] ?? null);
}
if ($anneeSelectionnee) {
    $_SESSION['annee_id'] = (int) $anneeSelectionnee['id'];
}

$anneeId = $anneeSelectionnee['id'] ?? null;
$ANNEE_SCOLAIRE = $anneeSelectionnee['annee_scolaire'] ?? null;
$anneeLectureSeule = ($anneeSelectionnee['statut'] ?? null) === 'archive';

/**
 * Vrai si l'année scolaire donnée (par id) est archivée. Sert à verrouiller
 * une écriture ciblant un enregistrement précis (ex: suppression par id),
 * indépendamment de l'année actuellement sélectionnée en session — utile
 * contre un onglet resté ouvert sur une autre année ou un lien obsolète.
 * Ne fait aucune requête : cherche dans $anneesDisponibles déjà chargé.
 */
function estAnneeArchivee(?int $anneeId, array $anneesDisponibles): bool
{
    if ($anneeId === null) {
        return false;
    }
    foreach ($anneesDisponibles as $a) {
        if ((int) $a['id'] === $anneeId) {
            return $a['statut'] === 'archive';
        }
    }
    return false;
}

/**
 * Fragment SQL de la règle d'éligibilité CEPE d'un candidat (officiel,
 * matricule vérifié, droits payés) — dupliquée mot pour mot dans plusieurs
 * fichiers (résultats, PDF, export DSPS, effectifs de centres, plans de
 * salle) avant sa centralisation ici. $alias est l'alias SQL de la table
 * `candidats` dans la requête appelante (ex: 'c', 'ca').
 */
function conditionCandidatEligibleCEPE(string $alias = 'c'): string
{
    return "({$alias}.est_candidat_libre = 0 AND {$alias}.matricule_verifie = 1 AND {$alias}.droits_payes = 1)";
}
?>