-- Vérification directe sur le site AGCP / DSPS-MEN (fiche cursus primaire) :
-- on garde la provenance de la donnée (site ou saisie manuelle), la date de
-- vérification, l'identité renvoyée par le site (pour détecter un matricule qui
-- appartient à un autre élève) et le cursus complet (pour contrôle à l'écran).
ALTER TABLE `desps_cursus`
    ADD COLUMN `source` VARCHAR(10) NOT NULL DEFAULT 'manuel' AFTER `introuvable`,
    ADD COLUMN `verifie_le` DATETIME NULL AFTER `source`,
    ADD COLUMN `identite_desps` TEXT NULL AFTER `verifie_le`,
    ADD COLUMN `cursus_brut` MEDIUMTEXT NULL AFTER `identite_desps`,
    ADD COLUMN `derniere_dfa` VARCHAR(5) NULL AFTER `cursus_brut`;
