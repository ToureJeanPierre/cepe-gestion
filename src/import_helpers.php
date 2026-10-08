<?php

// Lecture et mappage des fichiers Word/Excel envoyés par les directeurs (listes
// nominatives de candidats, listes d'enseignants). Partagé entre les pages
// d'import (candidats.php, enseignants.php) et le contrôle du dossier de dépôts.

require_once __DIR__ . '/docx_helpers.php';
require_once __DIR__ . '/telephone_helpers.php';

/**
 * Interprète une date de naissance venant d'un import (Excel ou Word) :
 * numéro de série Excel, ou texte JJ/MM/AAAA (format le plus courant dans
 * les documents français) — reconnu explicitement plutôt que laissé à
 * strtotime(), qui interprète un texte ambigu à l'américaine (MM/JJ/AAAA)
 * et peut donc inverser jour et mois en silence.
 */
function parserDateNaissanceImport($valeurBrute): ?string
{
    if (empty($valeurBrute)) {
        return null;
    }
    if (is_numeric($valeurBrute)) {
        return \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($valeurBrute)->format('Y-m-d');
    }
    $texte = trim((string) $valeurBrute);
    if (preg_match('#^(\d{1,2})[/\-](\d{1,2})[/\-](\d{4})$#', $texte, $m)) {
        return sprintf('%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1]);
    }
    $timestamp = strtotime($texte);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

/**
 * Reconstitue, à partir du tableau du modèle Word "Liste nominative des
 * candidats", des lignes compatibles avec l'ordre de colonnes de l'import
 * Excel (Nom, Prénoms, Sexe, Nationalité, DateNaiss, LieuNaiss, Matricule,
 * Acte, StatutDemande, NomÉcole, CodeDSPSÉcole) — École et CodeDSPS ne sont
 * pas des colonnes du fichier Word (un fichier = une école) : fournies par
 * l'appelant, déjà choisies dans le formulaire d'import.
 *
 * @return array<int, array<int, string>>
 */
function mapperLignesDocxCandidats(array $lignesDocx, string $nomEcole, ?string $codeDsps): array
{
    // Certains modèles réels ont une 2ᵉ ligne d'en-tête (sous-titres de
    // colonnes) ; on la détecte par mot-clé plutôt que de supposer un nombre
    // fixe de lignes d'en-tête (cf. compterLignesEnteteDocx).
    $motsClesEnteteCandidats = ['NOM', 'PRENOM', 'SEXE', 'NATIONALITE', 'NAISSANCE', 'MATRICULE', 'ACTE'];
    $nbLignesEntete = compterLignesEnteteDocx($lignesDocx, $motsClesEnteteCandidats);
    $donnees = array_slice($lignesDocx, $nbLignesEntete);

    $rows = [];
    foreach ($donnees as $ligne) {
        // N°, NOM, PRENOMS, SEXE, NATIONALITE, DATE DE NAI., LIEU DE NAI., MATRICULE, ACTE DE NAI.
        [$nom, $prenoms, $sexe, $nationalite, $dateNaiss, $lieuNaiss, $matricule, $acte] = array_pad(array_slice($ligne, 1, 8), 8, '');

        if (trim($nom) === '') {
            continue; // ligne vide du modèle, non remplie par le directeur
        }

        $rows[] = [$nom, $prenoms, $sexe, $nationalite, $dateNaiss, $lieuNaiss, $matricule, $acte, '', $nomEcole, $codeDsps ?? ''];
    }

    return $rows;
}


// Le personnel est rattaché à l'année scolaire (personnel.annee_id), au même
// titre que les candidats : ce contrôle manquait ici alors qu'il existe déjà
// partout ailleurs (candidats.php, affectations.php, centres.php...), ce qui
// permettait d'importer/modifier/supprimer du personnel sur une année
// archivée malgré le cadenas affiché dans la barre du haut.
if ($anneeLectureSeule && $_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.");
}

// ==========================================
// IMPORT DIRECT DES FICHIERS WORD (.docx) ENVOYÉS PAR LES DIRECTEURS
// ==========================================
// Les directeurs reçoivent un modèle Word (pas Excel : impressions et
// signature manuscrite plus faciles pour eux) et le renvoient souvent tel
// quel, rempli à l'écran, par clé USB ou WhatsApp. Plutôt que d'exiger une
// conversion manuelle en Excel, on lit directement le tableau Word.
// Le bloc Surveillance/Correction/Secrétariat de ce modèle est un reliquat
// de l'ancien suivi manuel des affectations : il n'est plus utilisé et est
// ignoré ici (voir le module Affectations, qui calcule cela automatiquement).
function deviverEmploiDepuisCorpsGrade(string $texte): ?string
{
    $texte = mb_strtoupper(trim($texte));
    if ($texte === '') {
        return null;
    }
    if (preg_match('/\bIA\b/u', $texte) || str_contains($texte, 'ADJOINT')) {
        return 'IA';
    }
    if (preg_match('/\bIO\b/u', $texte) || str_contains($texte, 'ORDINAIRE')) {
        return 'IO';
    }
    return null;
}

/**
 * Déduit l'emploi (IO/IA) d'une colonne "Corps et grade" au format
 * "OI/B3" ou "IA/C3" (fichiers consolidés multi-écoles) — "OI" est une
 * variante/coquille fréquente de "IO" (Instituteur Ordinaire) dans ces
 * fichiers réels, traitée comme équivalente.
 */
function deviverEmploiDepuisCorpsGradeAvecSlash(string $texte): ?string
{
    $premierMorceau = strtoupper(trim(explode('/', $texte)[0] ?? ''));
    if (in_array($premierMorceau, ['IO', 'OI'], true)) {
        return 'IO';
    }
    if ($premierMorceau === 'IA') {
        return 'IA';
    }
    return null;
}

/**
 * Reconstitue, à partir d'un fichier consolidé couvrant PLUSIEURS écoles à
 * la fois (colonne "Nom Ecole" par ligne, ex: export DSPS/DRENA), des
 * lignes compatibles avec l'import standard — chaque ligne porte en plus
 * (10ᵉ élément) le nom d'école brut, résolu par l'appelant.
 *
 * Colonnes attendues du fichier : Nom, Prénoms, Nom École, Classe (niveau
 * tenu), Matricule/N° autorisation, Fonction, Contact, Corps et grade.
 *
 * @return array<int, array<int, string>>
 */
function mapperLignesMultiEcoles(array $rowsBrut): array
{
    $rows = [];
    foreach ($rowsBrut as $ligne) {
        $nom = trim((string) ($ligne[0] ?? ''));
        if ($nom === '') {
            continue;
        }
        $prenoms = trim((string) ($ligne[1] ?? ''));
        $nomEcole = trim((string) ($ligne[2] ?? ''));
        $classe = trim((string) ($ligne[3] ?? ''));
        $identifiant = trim((string) ($ligne[4] ?? ''));
        $identifiant = ($identifiant === '' || $identifiant === '/') ? '' : $identifiant;
        $fonction = trim((string) ($ligne[5] ?? ''));
        $contact = trim((string) ($ligne[6] ?? ''));
        $corpsGrade = trim((string) ($ligne[7] ?? ''));
        $emploi = deviverEmploiDepuisCorpsGradeAvecSlash($corpsGrade) ?? '';

        $rows[] = [$nom, $prenoms, '', $contact, $identifiant, $classe, $emploi, $fonction, '', $nomEcole];
    }

    return $rows;
}

/**
 * Normalise une valeur "Sexe" telle que tapée librement par un directeur
 * (F, f, Féminin, M, Masculin...) vers M/F — chaîne vide si non reconnu,
 * pour laisser la valeur par défaut (M) déjà gérée par l'import s'appliquer.
 */
function normaliserSexeDocx(string $texte): string
{
    $lettre = strtoupper(mb_substr(trim($texte), 0, 1));
    return $lettre === 'F' ? 'F' : ($lettre === 'M' ? 'M' : '');
}

/**
 * Sépare une cellule "NOM & PRENOMS" combinée (modèle où les deux ne sont
 * pas dans des colonnes séparées) en Nom/Prénoms — meilleur effort, à
 * vérifier après import : le nom est le premier mot (après un éventuel
 * titre Mme/M./Mlle), complété par "NEE ..." quand ce marqueur suit
 * immédiatement (nom d'épouse + nom de jeune fille tous deux dans la
 * cellule Nom, comme le fait déjà l'application pour les fichiers à
 * colonnes séparées où ce genre de mention est tapée directement dans la
 * cellule Nom). Ex. "Mme HUIZAN née GUIE EDITH MARIE HELENE" -> Nom
 * "HUIZAN née GUIE", Prénoms "EDITH MARIE HELENE".
 *
 * Cette colonne combinée ne laisse pas de place à une colonne Sexe séparée
 * (cf. mapperLignesDocxPersonnel ci-dessous) : le titre Mme/M./Mlle, quand
 * il est présent, et la mention "née" (jamais portée que par une femme
 * dans ces fichiers) servent de meilleur indice disponible.
 *
 * @return array{0: string, 1: string, 2: string} [nom, prénoms, sexe ('M'/'F'/'')]
 */
function separerNomPrenoms(string $texte): array
{
    $texte = trim($texte);
    $sexe = '';
    if (preg_match('/^(MME|MLLE)\s+/iu', $texte)) {
        $sexe = 'F';
    } elseif (preg_match('/^M\.?\s+/u', $texte)) {
        $sexe = 'M';
    }
    $texte = trim((string) preg_replace('/^(MME|M\.?|MLLE)\s+/iu', '', $texte));

    $mots = preg_split('/\s+/u', $texte, -1, PREG_SPLIT_NO_EMPTY);

    if (count($mots) <= 1) {
        return [$texte, '', $sexe];
    }

    // count($mots) > 3 (et pas seulement isset($mots[2])) : si la cellule
    // s'arrête exactement à "NOM née NOMJEUNEFILLE" sans rien après, imposer
    // quand même les 3 mots au nom laisserait des prénoms vides — un import
    // silencieux avec prénom manquant est pire qu'un découpage imparfait
    // mais visible (l'admin verra "née ..." dans la colonne prénoms et
    // corrigera à la main).
    $aUneMentionNee = isset($mots[1], $mots[2]) && count($mots) > 3 && in_array(mb_strtoupper($mots[1]), ['NEE', 'NÉE'], true);
    $indexFin = $aUneMentionNee ? 3 : 1;
    if ($aUneMentionNee || (isset($mots[1]) && in_array(mb_strtoupper($mots[1]), ['NEE', 'NÉE'], true))) {
        $sexe = 'F'; // seule une femme porte une mention "née [nom de jeune fille]"
    }

    return [
        implode(' ', array_slice($mots, 0, $indexFin)),
        implode(' ', array_slice($mots, $indexFin)),
        $sexe,
    ];
}

/**
 * Reconstitue, à partir du tableau du modèle Word, des lignes compatibles
 * avec l'ordre de colonnes de l'import Excel (Nom, Prénoms, Sexe, Téléphone,
 * Identifiant, NiveauTenu, Emploi, Fonction, Disponibilité) — Disponibilité
 * n'existe pas sur la fiche Word et reste vide (valeur par défaut déjà
 * gérée plus loin dans l'import).
 *
 * Deux modèles réels rencontrés, détectés à partir de l'en-tête :
 *   - standard : 2 lignes d'en-tête, Nom/Prénoms/Sexe en colonnes séparées.
 *     La colonne Corps et grade existe même pour les écoles privées
 *     (souvent laissée vide par le directeur) : même disposition pour
 *     Public et Privé, plus besoin de distinguer les deux ici.
 *   - à colonnes combinées ("NOM & PRENOMS" ou "NOM ET PRENOMS" en un seul
 *     en-tête) : une seule ligne d'en-tête, pas de colonne Sexe séparée.
 *
 * @return array<int, array<int, string>>
 */
function mapperLignesDocxPersonnel(array $lignesDocx): array
{
    if (empty($lignesDocx)) {
        return [];
    }

    // retirerAccents() est nécessaire ici : mb_strtoupper() seul laisse "É"
    // tel quel ("PRÉNOMS" ne contiendrait alors jamais "PRENOM"), donc un
    // modèle réel écrivant "Prénoms" avec l'accent (orthographe la plus
    // naturelle) manquait la détection et voyait ses colonnes décalées en
    // silence (matricule dans la case sexe, etc.).
    $texteEntete = retirerAccents(mb_strtoupper(implode(' | ', $lignesDocx[0])));
    $nomEtPrenomsCombines = str_contains($texteEntete, 'NOM & PRENOM') || str_contains($texteEntete, 'NOM ET PRENOM');

    // Une vraie sous-ligne d'en-tête (ex. la répartition 1erEB/2eEB/6e du
    // bloc Surveillance) contient un mot-clé d'en-tête ; une ligne de données
    // n'en contient pas, même si sa colonne N° est vide (numérotation Word
    // automatique non lue par extraireTableauDocx) — se fier à la seule
    // cellule N° vide supprimait alors la toute première personne du fichier.
    $motsClesEntetePersonnel = ['NOM', 'PRENOM', 'SEXE', 'MATRICULE', 'CORPS', 'GRADE', 'FONCTION', 'COURS', 'CONTACT', 'AUTORISATION'];
    $donnees = array_slice($lignesDocx, compterLignesEnteteDocx($lignesDocx, $motsClesEntetePersonnel));

    $rows = [];
    foreach ($donnees as $ligne) {
        if ($nomEtPrenomsCombines) {
            // N°, NOM & PRENOMS, MATRICULE, CORPS ET GRADE, FONCTION, COURS TENU, CONTACT, ...
            [$nomPrenoms, $identifiant, $corpsGrade, $fonction, $coursTenu, $contact] = array_pad(array_slice($ligne, 1, 6), 6, '');
            if (trim($nomPrenoms) === '') {
                continue; // ligne vide du modèle, non remplie par le directeur
            }
            [$nom, $prenoms, $sexeRaw] = separerNomPrenoms($nomPrenoms);
        } else {
            // N°, NOM, PRENOMS, SEXE, MATRICULE/N°AUTORISATION, CORPS&GRADE, FONCTION, COURSTENU, CONTACT, ...
            [$nom, $prenoms, $sexeRaw, $identifiant, $corpsGrade, $fonction, $coursTenu, $contact] = array_pad(array_slice($ligne, 1, 8), 8, '');
            if (trim($nom) === '') {
                continue; // ligne vide du modèle, non remplie par le directeur
            }
        }

        $emploi = deviverEmploiDepuisCorpsGrade($corpsGrade) ?? '';
        $rows[] = [$nom, $prenoms, normaliserSexeDocx($sexeRaw), $contact, $identifiant, $coursTenu, $emploi, $fonction, ''];
    }

    return $rows;
}

// ==========================================
// RETROUVER UN MEMBRE DU PERSONNEL DÉJÀ ENREGISTRÉ (import)
// ==========================================

if (!function_exists('identifiantPersonnelExploitable')) {
    /**
     * Un vrai matricule ou n° d'autorisation contient toujours un chiffre. « En cours »,
     * « ENCOURS », « F », « N »... sont des mentions, pas des identifiants : s'en servir pour
     * retrouver quelqu'un fusionnait des personnes différentes (l'une écrasait la fiche de l'autre).
     */
    function identifiantPersonnelExploitable(?string $v): bool
    {
        return $v !== null && preg_match('/\d/', $v) === 1;
    }
}

if (!function_exists('motsNomPersonnel')) {
    function motsNomPersonnel(?string $t): array
    {
        $t = retirerAccents(mb_strtoupper(trim((string) $t)));
        $m = array_values(array_filter(explode(' ', (string) preg_replace('/[^A-Z0-9]+/', ' ', $t)), fn ($x) => $x !== ''));
        sort($m);
        return $m;
    }
}

if (!function_exists('memePersonneParNom')) {
    /** Même nom (mots, ordre et accents indifférents) ou au moins un prénom en commun. */
    function memePersonneParNom(string $nomA, string $prenomsA, string $nomB, string $prenomsB): bool
    {
        if (motsNomPersonnel($nomA) === motsNomPersonnel($nomB)) {
            return true;
        }
        return (bool) array_intersect(motsNomPersonnel($prenomsA), motsNomPersonnel($prenomsB));
    }
}

if (!function_exists('trouverPersonnelExistant')) {
    /**
     * Cherche la fiche existante correspondant à une ligne importée : d'abord par matricule / n° d'autorisation
     * (seulement s'ils sont de vrais identifiants ET que le nom est compatible), sinon par nom + prénoms +
     * catégorie + école. Lecture seule.
     *
     * @return array{id: ?int, avertissement: ?string} avertissement : un identifiant déjà porté par une AUTRE personne
     */
    function trouverPersonnelExistant(PDO $pdo, string $nom, string $prenoms, string $categorie, ?int $ecoleId, ?string $matricule, ?string $numEnseigner, ?string $numDiriger): array
    {
        $avertissement = null;
        $tests = [];
        if (identifiantPersonnelExploitable($matricule)) {
            $tests[] = ['matricule = ?', [$matricule], "matricule « $matricule »"];
        }
        foreach ([$numEnseigner, $numDiriger] as $num) {
            if (identifiantPersonnelExploitable($num)) {
                $tests[] = ['(numero_autorisation_enseigner = ? OR numero_autorisation_diriger = ?)', [$num, $num], "n° d'autorisation « $num »"];
            }
        }
        foreach ($tests as [$condition, $params, $libelle]) {
            $stmt = $pdo->prepare("SELECT id, nom, prenoms FROM personnel WHERE $condition");
            $stmt->execute($params);
            foreach ($stmt->fetchAll() as $r) {
                if (memePersonneParNom($nom, $prenoms, (string) $r['nom'], (string) $r['prenoms'])) {
                    return ['id' => (int) $r['id'], 'avertissement' => null];
                }
                $avertissement = "$libelle déjà porté par {$r['nom']} {$r['prenoms']} (une autre personne)";
            }
        }
        $stmt = $pdo->prepare("SELECT id FROM personnel WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) AND LOWER(TRIM(prenoms)) = LOWER(TRIM(?)) AND categorie = ? AND (ecole_id <=> ?)");
        $stmt->execute([$nom, $prenoms, $categorie, $ecoleId]);
        $id = $stmt->fetchColumn();
        return ['id' => $id !== false ? (int) $id : null, 'avertissement' => $id !== false ? null : $avertissement];
    }
}
