<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 019 : création de la table utilisateurs ===\n\n";

$stmt = $pdo->query("SHOW TABLES LIKE 'utilisateurs'");
if ($stmt->fetch()) {
    echo "OK - La table utilisateurs existe déjà\n";
} else {
    $pdo->exec("
        CREATE TABLE `utilisateurs` (
            `id` INT NOT NULL AUTO_INCREMENT,
            `nom` VARCHAR(100) NOT NULL,
            `identifiant` VARCHAR(50) NOT NULL,
            `mot_de_passe_hash` VARCHAR(255) NOT NULL,
            `actif` TINYINT(1) NOT NULL DEFAULT 1,
            `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
            `derniere_connexion` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uq_utilisateurs_identifiant` (`identifiant`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "OK - Table utilisateurs créée\n";
}

echo "\n=== Migration terminée ===\n";
