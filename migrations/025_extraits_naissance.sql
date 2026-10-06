-- Extraits de naissance reçus (images / PDF) : lecture, vérification, renommage.
-- Le fichier d'origine n'est jamais modifié : l'application en garde une copie
-- (dossier storage/extraits, hors du site web) et fabrique des copies renommées à l'export.
CREATE TABLE IF NOT EXISTS extraits_naissance (
    id INT AUTO_INCREMENT PRIMARY KEY,
    annee_id INT NOT NULL,
    ecole_id INT NULL,
    candidat_id INT NULL,

    nom_original VARCHAR(255) NOT NULL,
    hash_sha256 CHAR(64) NOT NULL,
    extension VARCHAR(8) NOT NULL,
    taille_octets INT NOT NULL DEFAULT 0,
    chemin VARCHAR(255) NOT NULL,

    statut ENUM('a_lire', 'lu', 'valide', 'erreur') NOT NULL DEFAULT 'a_lire',
    lecture_en_cours DATETIME NULL,

    nom VARCHAR(150) NULL,
    prenoms VARCHAR(200) NULL,
    sexe ENUM('M', 'F') NULL,
    date_naissance DATE NULL,
    lieu_naissance VARCHAR(150) NULL,
    sous_prefecture VARCHAR(150) NULL,
    pere VARCHAR(200) NULL,
    mere VARCHAR(200) NULL,
    contact VARCHAR(100) NULL,
    numero_acte VARCHAR(50) NULL,
    date_acte DATE NULL,
    lieu_acte VARCHAR(150) NULL,
    nationalite VARCHAR(80) NULL,

    type_document VARCHAR(30) NULL,
    confiance ENUM('haute', 'moyenne', 'basse') NULL,
    alertes TEXT NULL,
    reponse_brute MEDIUMTEXT NULL,

    modele VARCHAR(60) NULL,
    tokens_entree INT NOT NULL DEFAULT 0,
    tokens_sortie INT NOT NULL DEFAULT 0,
    cout_usd DECIMAL(10, 5) NOT NULL DEFAULT 0,
    nb_lectures INT NOT NULL DEFAULT 0,
    erreur_message VARCHAR(300) NULL,
    lu_le DATETIME NULL,
    valide_le DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- Un même fichier (même contenu) n'est enregistré, donc payé, qu'une seule fois par année.
    UNIQUE KEY uq_extrait_hash (annee_id, hash_sha256),
    KEY idx_extrait_ecole (annee_id, ecole_id),
    KEY idx_extrait_candidat (candidat_id),
    KEY idx_extrait_statut (annee_id, statut)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
