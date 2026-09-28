-- Élargit personnel.telephone (varchar 20 -> 50) : certains directeurs
-- saisissent deux numéros dans la même cellule Contact (fixe + mobile),
-- ce qui dépasse 20 caractères une fois les espaces inclus. Aligné sur
-- ecoles.directeur_telephone, déjà en varchar(50) pour la même raison.
ALTER TABLE `personnel`
    MODIFY COLUMN `telephone` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL;
