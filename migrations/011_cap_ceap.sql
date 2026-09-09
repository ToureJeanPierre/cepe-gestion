-- Migration 011 : Mini-module CAP/CEAP (Certificat d'Aptitude Pédagogique /
-- Certificat Élémentaire d'Aptitude Pédagogique) — concours enseignants,
-- domaine distinct du CEPE (élèves) mais géré par le même IEPP.

CREATE TABLE IF NOT EXISTS `cap_ceap_candidats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `annee_id` int NOT NULL,
  `nature_examen` enum('CAP_INTEGRATION_FP','CEAP_TITULARISATION','CEAP_ECRIT') NOT NULL,
  `nom` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `prenoms` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `pieces_json` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `observations` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT 'POUR ATTRIBUTION',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_cap_ceap_annee` (`annee_id`),
  KEY `idx_cap_ceap_nature` (`nature_examen`),
  CONSTRAINT `cap_ceap_candidats_ibfk_1` FOREIGN KEY (`annee_id`) REFERENCES `annees` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
