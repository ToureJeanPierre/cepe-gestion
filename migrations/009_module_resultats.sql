-- Migration 009 : Module Résultats (Module 7 du cahier des charges)
-- Ajoute les Compositions 1 & 2 comme types d'examen (organisées par les directeurs,
-- l'IEPP se contente de recevoir/saisir les notes) et une table de notes.

-- Renumérote l'ordre existant pour laisser la place aux 2 compositions avant les blancs
UPDATE `examens` SET `ordre` = `ordre` + 2 WHERE `code` IN ('BLANC_1', 'BLANC_2', 'CEPE_FINAL');

CREATE TABLE IF NOT EXISTS `notes` (
  `id` int NOT NULL AUTO_INCREMENT,
  `candidat_id` int NOT NULL,
  `examen_id` int NOT NULL,
  `note` decimal(5,2) DEFAULT NULL,
  `present` tinyint(1) NOT NULL DEFAULT '1',
  `source` enum('saisie','import') NOT NULL DEFAULT 'saisie',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_note_candidat_examen` (`candidat_id`, `examen_id`),
  KEY `idx_notes_examen` (`examen_id`),
  CONSTRAINT `notes_ibfk_1` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `notes_ibfk_2` FOREIGN KEY (`examen_id`) REFERENCES `examens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
