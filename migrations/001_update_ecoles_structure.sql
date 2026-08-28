-- Migration: Ajout des champs pour la gestion des écoles arrimées et subdivisions
-- Date: 2026-08-11

-- Ajouter le champ code_ecole (peut être NULL pour les écoles arrimées)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS code_ecole VARCHAR(50) NULL AFTER nom;

-- Ajouter le champ est_arrimee (1 = école arrimée sans code, 0 = école normale)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS est_arrimee TINYINT(1) DEFAULT 0 AFTER code_ecole;

-- Ajouter le champ ecole_tutrice_id (référence l'école tutrice pour les subdivisions)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS ecole_tutrice_id INT NULL AFTER est_arrimee,
ADD CONSTRAINT fk_ecole_tutrice FOREIGN KEY (ecole_tutrice_id) REFERENCES ecoles(id) ON DELETE SET NULL;

-- Ajouter le champ est_subdivision (1 = subdivision d'une école tutrice, 0 = normale)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS est_subdivision TINYINT(1) DEFAULT 0 AFTER ecole_tutrice_id;

-- Ajouter le champ effectif (pour la compilation des données)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS effectif INT NULL DEFAULT 0 AFTER est_subdivision;

-- Ajouter le champ effectif_total pour les écoles tutrices (compilé)
ALTER TABLE ecoles 
ADD COLUMN IF NOT EXISTS effectif_total INT NULL DEFAULT 0 AFTER effectif;
