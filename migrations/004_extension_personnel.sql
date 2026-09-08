-- Migration 004 : Refonte du Module Personnel (Enseignants / Conseillers / Administratifs)
-- Date : 2026-09-05
-- Cette migration est appliquée via migrations/run_migration_004.php (gère les cas
-- où elle a déjà été exécutée en partie, et normalise les anciennes données).

-- 1. Renommage de la table (elle couvre maintenant tout le personnel, pas seulement les enseignants)
RENAME TABLE enseignants TO personnel;

-- 2. L'école devient optionnelle (un Conseiller ou un Agent Administratif peut être
--    rattaché directement à l'inspection, sans école précise)
ALTER TABLE personnel MODIFY ecole_id INT NULL;

-- 3. Catégorie de personnel (pilote l'affichage des champs spécifiques)
ALTER TABLE personnel ADD COLUMN categorie ENUM('enseignant','conseiller','administratif') NOT NULL DEFAULT 'enseignant' AFTER ecole_id;

-- 4. Sous-type (ex : Pédagogique / Extrascolaire pour un Conseiller ; intitulé de poste pour un Administratif)
ALTER TABLE personnel ADD COLUMN sous_type VARCHAR(100) DEFAULT NULL AFTER categorie;

-- 5. Fonction élargie en texte libre (Directeur, Adjoint, Enseignant, Conseiller Pédagogique, etc.)
ALTER TABLE personnel MODIFY fonction VARCHAR(100) DEFAULT 'Adjoint';

-- 6. Position/disponibilité alignée sur le cahier des charges
UPDATE personnel SET disponibilite = 'En activité' WHERE disponibilite = 'Disponible';
UPDATE personnel SET disponibilite = 'Congé maladie' WHERE disponibilite = 'Malade';
UPDATE personnel SET disponibilite = 'Autre' WHERE disponibilite = 'Congé';
ALTER TABLE personnel MODIFY disponibilite ENUM('En activité','Congé maternité','Congé maladie','Absent','Autre') DEFAULT 'En activité';

-- 7. Autorisations spécifiques au Privé
ALTER TABLE personnel ADD COLUMN numero_autorisation_enseigner VARCHAR(50) DEFAULT NULL AFTER matricule;
ALTER TABLE personnel ADD COLUMN numero_autorisation_diriger VARCHAR(50) DEFAULT NULL AFTER numero_autorisation_enseigner;

-- 8. Diplômes / niveau d'étude
ALTER TABLE personnel ADD COLUMN plus_haut_diplome VARCHAR(100) DEFAULT NULL;
ALTER TABLE personnel ADD COLUMN plus_haut_niveau_etude VARCHAR(100) DEFAULT NULL;
