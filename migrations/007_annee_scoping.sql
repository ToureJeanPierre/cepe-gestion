-- Migration 007 : Rattachement des candidats, écoles et personnel à une année scolaire
-- Permet l'archivage réel par campagne (règle 2.1 du cahier des charges).

ALTER TABLE `candidats` ADD COLUMN `annee_id` INT NULL AFTER `id`;
ALTER TABLE `ecoles` ADD COLUMN `annee_id` INT NULL AFTER `id`;
ALTER TABLE `personnel` ADD COLUMN `annee_id` INT NULL AFTER `id`;

-- Rattache toutes les données existantes à l'année en cours
UPDATE `candidats` SET `annee_id` = (SELECT id FROM `annees` WHERE `statut` = 'en_cours' LIMIT 1) WHERE `annee_id` IS NULL;
UPDATE `ecoles` SET `annee_id` = (SELECT id FROM `annees` WHERE `statut` = 'en_cours' LIMIT 1) WHERE `annee_id` IS NULL;
UPDATE `personnel` SET `annee_id` = (SELECT id FROM `annees` WHERE `statut` = 'en_cours' LIMIT 1) WHERE `annee_id` IS NULL;

-- Les candidats sont strictement rattachés à une campagne (nouvelle cohorte chaque année)
ALTER TABLE `candidats` MODIFY `annee_id` INT NOT NULL;
ALTER TABLE `candidats` ADD KEY `idx_candidats_annee` (`annee_id`);
ALTER TABLE `candidats` ADD CONSTRAINT `candidats_ibfk_annee` FOREIGN KEY (`annee_id`) REFERENCES `annees` (`id`) ON DELETE CASCADE;

-- Écoles et Personnel restent un référentiel permanent (annee_id = année d'enregistrement,
-- informatif, non bloquant) : ils ne sont pas masqués/verrouillés selon l'année sélectionnée.
ALTER TABLE `ecoles` ADD KEY `idx_ecoles_annee` (`annee_id`);
ALTER TABLE `ecoles` ADD CONSTRAINT `ecoles_ibfk_annee` FOREIGN KEY (`annee_id`) REFERENCES `annees` (`id`) ON DELETE SET NULL;

ALTER TABLE `personnel` ADD KEY `idx_personnel_annee` (`annee_id`);
ALTER TABLE `personnel` ADD CONSTRAINT `personnel_ibfk_annee` FOREIGN KEY (`annee_id`) REFERENCES `annees` (`id`) ON DELETE SET NULL;
