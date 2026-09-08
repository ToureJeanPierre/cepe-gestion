-- Migration 006 — Correctifs de schéma pour le module Surveillance & Affectations
--
-- 1. Le rôle "Superviseur" (Module 6.1 du cahier des charges, réservé aux
--    Conseillers Pédagogiques/Extrascolaires) manquait dans l'ENUM `role`.
--
-- 2. La colonne `salle_id` pointait vers la table `salles`, qui n'est en
--    réalité jamais alimentée par l'application (le plan de salle réel vit
--    dans `plan_salles`, lié à `centre_effectifs`). On corrige la référence
--    et on renomme la colonne en `plan_salle_id` pour éviter toute confusion.
--
-- 3. Un enseignant ne doit jamais pouvoir cumuler deux rôles différents pour
--    le même examen (règle de "non-redondance", Module 6.2.A.3). L'ancienne
--    clé unique incluait `role`, ce qui permettait justement ce cumul : on la
--    remplace par une clé unique sans `role`.
--
-- Ce fichier est fourni à titre de documentation lisible.
-- L'exécution réelle se fait via : php migrations/run_migration_006.php

ALTER TABLE affectations
    MODIFY role ENUM('Président','Chef Secrétariat','Membre Secrétariat','Superviseur','Surveillant','Suppléant')
    COLLATE utf8mb4_unicode_ci NOT NULL;

ALTER TABLE affectations DROP FOREIGN KEY affectations_ibfk_4;
ALTER TABLE affectations CHANGE salle_id plan_salle_id INT DEFAULT NULL;
ALTER TABLE affectations
    ADD CONSTRAINT affectations_ibfk_4
    FOREIGN KEY (plan_salle_id) REFERENCES plan_salles (id) ON DELETE SET NULL;

ALTER TABLE affectations DROP INDEX unique_affectation;
ALTER TABLE affectations
    ADD UNIQUE KEY unique_affectation_enseignant (annee_id, type_examen, enseignant_id);
