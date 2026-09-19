-- Migration 014 : Dispense d'EPS. Un candidat dispensé de l'épreuve d'EPS
-- (Blancs 1/2, Examen Final) voit sa moyenne calculée sans cette note, avec
-- le même barème que les Compositions (4 matières, diviseur 8.5).
-- Le flag n'a de sens que sur la ligne de note où matiere = 'EPS'.

ALTER TABLE `notes` ADD COLUMN `dispense` TINYINT(1) NOT NULL DEFAULT 0 AFTER `present`;
