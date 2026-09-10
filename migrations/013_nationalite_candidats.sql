-- Migration 013 : Ajoute la nationalité des candidats (champ prévu par le cahier
-- des charges, absent du schéma initial).

ALTER TABLE `candidats` ADD COLUMN `nationalite` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL AFTER `sexe`;
