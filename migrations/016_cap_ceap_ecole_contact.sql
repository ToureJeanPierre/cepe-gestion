-- Ajoute les colonnes école et contact aux candidats CAP/CEAP.
-- "ecole" reste un texte libre (pas de clé étrangère vers `ecoles`) : le
-- formulaire propose les écoles existantes via une liste de suggestions,
-- mais un candidat peut venir d'une structure non répertoriée.
ALTER TABLE `cap_ceap_candidats`
    ADD COLUMN `ecole` VARCHAR(255) NULL AFTER `prenoms`,
    ADD COLUMN `contact` VARCHAR(50) NULL AFTER `ecole`;
