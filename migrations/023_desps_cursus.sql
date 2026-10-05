-- Bilan DESPS / DFA : résultat de la vérification d'un élève sur la plateforme
-- DESPS (école, classe et année de la dernière ligne du cursus primaire) et
-- bilan calculé à partir de ces données (statut, lignes DFA, avertissements).
-- Le bilan est stocké pour que l'écran et le fichier téléchargé soient
-- strictement identiques (recalculé uniquement à l'import, à la saisie ou sur
-- demande).
CREATE TABLE IF NOT EXISTS `desps_cursus` (
    `id` INT NOT NULL AUTO_INCREMENT,
    `candidat_id` INT NOT NULL,
    `introuvable` TINYINT(1) NOT NULL DEFAULT 0,
    `ecole_desps` VARCHAR(255) NULL,
    `classe_desps` VARCHAR(5) NULL,
    `annee_debut` SMALLINT NULL,
    `ecole_conforme` TINYINT(1) NOT NULL DEFAULT 0,
    `statut` VARCHAR(30) NOT NULL DEFAULT 'NON_VERIFIE',
    `changement_ecole` TINYINT(1) NOT NULL DEFAULT 0,
    `motif` VARCHAR(255) NULL,
    `lignes_dfa` TEXT NULL,
    `avertissements` TEXT NULL,
    `annee_examen_debut` SMALLINT NULL,
    `calcule_le` TIMESTAMP NULL,
    `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_desps_candidat` (`candidat_id`),
    CONSTRAINT `fk_desps_candidat` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
