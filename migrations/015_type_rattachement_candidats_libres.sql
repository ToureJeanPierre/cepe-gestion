-- Migration 015 : Ajoute la valeur 'candidats_libres' à ecoles.type_rattachement.
-- Permet de créer une "école" pour une structure non homologuée DSPS (pas de
-- code DSPS, pas d'école tutrice) dont les candidats sont administrativement
-- des candidats libres, tout en la gérant comme une école normale dans
-- l'application (import de sa liste, affectation à un centre d'examen).

ALTER TABLE `ecoles` MODIFY COLUMN `type_rattachement` ENUM('aucun','sans_code_dsps','arrimee','candidats_libres') DEFAULT 'aucun';
