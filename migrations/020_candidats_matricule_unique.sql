-- Garde-fou au niveau base de données contre un doublon de matricule DSPS
-- au sein d'une même année scolaire, y compris en cas d'écriture concurrente
-- (deux imports lancés en même temps par deux utilisateurs) : la vérification
-- applicative (SELECT puis INSERT) ne suffit pas seule à empêcher une course,
-- cet index unique la bloque à coup sûr. NULL reste autorisé plusieurs fois
-- (un candidat sans matricule n'est jamais concerné).
ALTER TABLE `candidats`
    ADD UNIQUE KEY `uq_candidats_annee_matricule` (`annee_id`, `matricule_dsps`);
