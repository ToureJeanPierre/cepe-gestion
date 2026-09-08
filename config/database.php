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
?>