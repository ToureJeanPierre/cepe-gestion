-- Trace les affectations créées en forçant volontairement un conflit
-- anti-collusion détecté (case "Forcer malgré le conflit"), pour qu'un
-- contrôle a posteriori distingue une dérogation assumée d'une anomalie.
ALTER TABLE `affectations`
    ADD COLUMN `conflit_force` TINYINT(1) NOT NULL DEFAULT 0 AFTER `est_manuel`;
