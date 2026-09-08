-- Migration : Ajout d'un verrou pour les ajustements manuels du Groupe Scolaire
-- Date: 2026-09-05
-- Sans ce verrou, un nom de Groupe Scolaire saisi à la main serait écrasé
-- à chaque recalcul automatique (import, ajout, modification d'une autre école).

ALTER TABLE ecoles
ADD COLUMN IF NOT EXISTS groupe_scolaire_manuel TINYINT(1) DEFAULT 0 AFTER groupe_scolaire;
