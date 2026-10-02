-- Petits réglages de l'application (clé / valeur), ex. le dossier où sont
-- enregistrés les fichiers reçus des directeurs (contrôle du dossier de dépôts).
CREATE TABLE IF NOT EXISTS `reglages` (
    `cle` VARCHAR(100) NOT NULL,
    `valeur` TEXT NULL,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`cle`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
