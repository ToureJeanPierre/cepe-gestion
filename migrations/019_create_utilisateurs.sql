-- Authentification : jusqu'ici l'application n'avait aucun système de
-- connexion, ce qui n'était pas tenable dès lors que plusieurs collègues
-- réels commencent à s'en servir sur de vraies données d'élèves/personnel.
CREATE TABLE IF NOT EXISTS `utilisateurs` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `nom` VARCHAR(100) NOT NULL,
    `identifiant` VARCHAR(50) NOT NULL,
    `mot_de_passe_hash` VARCHAR(255) NOT NULL,
    `actif` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `derniere_connexion` TIMESTAMP NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_utilisateurs_identifiant` (`identifiant`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
