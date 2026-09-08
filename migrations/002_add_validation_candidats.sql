-- Migration: Ajout des champs de validation de candidature (IEPP)
-- Date: 2026-09-04

-- Case "Matricule vérifié sur plateforme DSPS"
ALTER TABLE candidats
ADD COLUMN IF NOT EXISTS matricule_verifie TINYINT(1) DEFAULT 0 AFTER matricule_dsps;

-- Case "Droits d'examen payés"
ALTER TABLE candidats
ADD COLUMN IF NOT EXISTS droits_payes TINYINT(1) DEFAULT 0 AFTER matricule_verifie;

-- Le statut final "Candidat Validé" n'est pas stocké : il est calculé
-- automatiquement (matricule_verifie = 1 ET droits_payes = 1) dans les requêtes.
