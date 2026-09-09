-- Migration 010 : Autorise un Superviseur à couvrir plusieurs centres sur un même examen
-- (confirmé par le document réel "Mission de Supervision" : un superviseur supervise
-- plusieurs centres dans sa zone). L'ancienne contrainte unique_affectation_enseignant
-- limitait chaque personne à UNE SEULE ligne par examen, tous rôles confondus, ce qui
-- empêchait ce cas d'usage réel. Le reste des rôles (présence physique unique : Président,
-- Chef Secrétariat, Membre Secrétariat, Surveillant, Suppléant) continue d'être limité à
-- un seul centre par examen, mais désormais au niveau applicatif (AffectationEngine),
-- pas au niveau de la contrainte SQL.

ALTER TABLE `affectations` DROP INDEX `unique_affectation_enseignant`;
ALTER TABLE `affectations` ADD UNIQUE KEY `unique_affectation_enseignant_centre` (`annee_id`, `type_examen`, `enseignant_id`, `centre_id`);
