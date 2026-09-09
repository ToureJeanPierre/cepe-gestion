-- Migration 012 : Notes par matière (au lieu d'une note globale unique)
-- Compositions 1 & 2 : 4 matières (Exploitation de texte /50, Éveil au milieu /50,
--   Orthographe /20, Mathématiques /50) -> total /170, diviseur 8.5 -> moyenne /20
-- Blancs 1 & 2 + Examen Final : + EPS /20 -> total /190, diviseur 9.5 -> moyenne /20

ALTER TABLE `notes` ADD COLUMN `matiere` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' AFTER `examen_id`;
-- Ajouté AVANT le DROP : uq_note_candidat_examen supporte la FK sur candidat_id.
ALTER TABLE `notes` ADD UNIQUE KEY `uq_note_candidat_examen_matiere` (`candidat_id`, `examen_id`, `matiere`);
ALTER TABLE `notes` DROP INDEX `uq_note_candidat_examen`;
