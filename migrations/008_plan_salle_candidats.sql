-- Migration 008 : Affectation nominative candidat -> salle (règle 5.3 du cahier des charges)
-- Permet de générer une vraie liste d'émargement alphabétique par salle,
-- au lieu de ne connaître que les effectifs par salle.

CREATE TABLE IF NOT EXISTS `plan_salle_candidats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_salle_id` int NOT NULL,
  `candidat_id` int NOT NULL,
  `examen_id` int NOT NULL,
  `numero_ordre` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_candidat_examen` (`candidat_id`, `examen_id`),
  KEY `idx_plan_salle_candidats_salle` (`plan_salle_id`),
  KEY `idx_plan_salle_candidats_examen` (`examen_id`),
  CONSTRAINT `plan_salle_candidats_ibfk_1` FOREIGN KEY (`plan_salle_id`) REFERENCES `plan_salles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_salle_candidats_ibfk_2` FOREIGN KEY (`candidat_id`) REFERENCES `candidats` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_salle_candidats_ibfk_3` FOREIGN KEY (`examen_id`) REFERENCES `examens` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
