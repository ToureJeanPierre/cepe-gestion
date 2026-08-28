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
?>