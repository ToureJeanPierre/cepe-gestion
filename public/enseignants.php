<?php
session_start();
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/docx_helpers.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

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
 * Reconstitue, à partir du tableau du modèle Word, des lignes compatibles
 * avec l'ordre de colonnes de l'import Excel (Nom, Prénoms, Sexe, Téléphone,
 * Identifiant, NiveauTenu, Emploi, Fonction, Disponibilité) — Disponibilité
 * n'existe pas sur la fiche Word et reste vide (valeur par défaut déjà
 * gérée plus loin dans l'import).
 *
 * @return array<int, array<int, string>>
 */
function mapperLignesDocxPersonnel(array $lignesDocx, string $typeEcole): array
{
    // Les 2 premières lignes du tableau Word sont les en-têtes (dont la
    // sous-ligne 1erEB/2eEB/6e/3eouTle) : les données commencent à la ligne 2.
    $donnees = array_slice($lignesDocx, 2);

    $rows = [];
    foreach ($donnees as $ligne) {
        if ($typeEcole === 'Privé') {
            // N°, NOM, PRENOMS, SEXE, N°AUTORISATION, FONCTION, COURSTENU, CONTACT, ...
            [$nom, $prenoms, $sexeRaw, $identifiant, $fonction, $coursTenu, $contact] = array_pad(array_slice($ligne, 1, 7), 7, '');
            $emploi = '';
        } else {
            // N°, NOM, PRENOMS, SEXE, MATRICULE, CORPS&GRADE, FONCTION, COURSTENU, CONTACT, ...
            [$nom, $prenoms, $sexeRaw, $identifiant, $corpsGrade, $fonction, $coursTenu, $contact] = array_pad(array_slice($ligne, 1, 8), 8, '');
            $emploi = deviverEmploiDepuisCorpsGrade($corpsGrade) ?? '';
        }

        if (trim($nom) === '') {
            continue; // ligne vide du modèle, non remplie par le directeur
        }

        $rows[] = [$nom, $prenoms, normaliserSexeDocx($sexeRaw), $contact, $identifiant, $coursTenu, $emploi, $fonction, ''];
    }

    return $rows;
}

$pageTitle = 'Gestion du Personnel';

// Listes de référence utilisées à plusieurs endroits du fichier
const CATEGORIES_PERSONNEL = ['enseignant' => 'Enseignant', 'conseiller' => 'Conseiller', 'administratif' => 'Personnel Administratif'];
const SOUS_TYPES_CONSEILLER = ['Pédagogique', 'Extrascolaire'];
const FONCTIONS_ENSEIGNANT = ['Directeur (avec classe)', 'Directeur (sans classe)', 'Adjoint', 'Enseignant'];
const NIVEAUX = ['CP1', 'CP2', 'CE1', 'CE2', 'CM1', 'CM2'];
const DISPONIBILITES = ['En activité', 'Congé maternité', 'Congé maladie', 'Absent', 'Autre'];

// Correspondances nom abrégé (tel qu'utilisé dans certains fichiers reçus,
// ex. exports consolidés) -> nom exact déjà enregistré dans l'onglet Écoles.
// Chaque entrée a été vérifiée individuellement (une seule école possible
// pour ce nom abrégé) avant d'être ajoutée ici — ne complète cette liste
// qu'après une vérification aussi précise, pour éviter de rattacher des
// enseignants à la mauvaise école.
const ALIAS_NOMS_ECOLES_IMPORT = [
    'epp antenne 1' => 'EPP NIANGON SUD SOGEFIHA ANTENNE 1',
    'epp antenne 2' => 'EPP NIANGON SUD SOGEFIHA ANTENNE 2',
    'epp antenne 3' => 'EPP NIANGON SUD SOGEFIHA ANTENNE 3',
    'epp centre 1' => 'EPP NIANGON SUD SICOGI CENTRE 1',
    'epp centre 2' => 'EPP NIANGON SUD CENTRE 2',
    'epp centre 3' => 'EPP NIANG.SUD SICOGI CENTRE 3',
    'epp lagune 1' => 'EPP NIANGON SUD SOGEFIHA LAGUNE 1',
    'epp lagune 2' => 'EPP NIANG.SUD SOGEFIHA LAGUNE 2',
    'epp les lauriers 2a' => 'EPP LES LAURIERS 2 A',
    'epp les lauriers 2b' => 'EPP LAURIERS 2 B',
    'epp lokoa 1' => 'EPP NIANGON LOKOA 1',
    'epp lokoa 2' => 'EPP NIANGON LOKOA 2',
    'epp lokoa 3' => 'EPP NIANGON LOKOA 3',
    'epp sipim ivoire' => 'EPP NIANGON SUD SIPIM IVOIRE',
    'epp terminus 1' => 'EPP NIANGON SUD SICOGI TERMINUS 1',
    'epp terminus 2' => 'EPP NIANGON SUD SICOGI TERMINUS 2',
    'epv datro zahui' => 'EPV DATRO ZAHUI DE YOPOUGON AZITO',
    'epv divine fontaine' => 'EPV DIVINE FONTAINE DE YOPOUGON',
    'epv fatoumaba' => 'GROUPE SCOLAIRE FATOUMABA',
    "epv kouame n' dri" => "EPV KOUAME N'DRI",
    'epv la colline' => 'EPV LA COLLINE DE NIANGON',
    'epv la misericorde 1' => 'GROUPE SCOLAIRE LA MISÉRICORDE 1',
    "epv l'effort" => "EPV L' EFFORT",
    'epv les petits savants' => 'GROUPE SCOLAIRE LES PETITS SAVANTS',
    'epv les petit genies' => 'EPV GS LES PETITS GENIES',
    'epv les tisserins' => 'EPV LES TISSSERINS',
    'epv nippon' => 'EPV GS NIPPON',
    'epv saint chalmel' => 'EPV GS SAINT CHALMEL',
    'epv saint exupery' => 'EPV ANTOINE DE SAINT EXUPERY',
    'epv sainte gloire' => 'EPV LA SAINTE GLOIRE',
];

/** Normalise un nom d'école pour comparaison : minuscules, espaces multiples réduits, espaces de bord retirés. */
function normaliserNomEcolePourComparaison(string $nom): string
{
    return trim(preg_replace('/\s+/', ' ', mb_strtolower($nom)));
}

// ==========================================
// TRAITEMENT : IMPORTATION EXCEL
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_personnel'])) {
    // La catégorie et l'école ne sont plus des colonnes du fichier : elles sont
    // choisies UNE FOIS pour tout le fichier, car en pratique chaque source de
    // données arrive déjà séparée (liste des enseignants d'UNE école transmise
    // par son directeur, ou liste des conseillers transmise par la RH).
    $typeImport = $_POST['type_import'] ?? 'enseignant_ecole'; // enseignant_ecole | enseignant_multi_ecoles | conseiller | administratif
    $ecoleIdImport = !empty($_POST['ecole_id_import']) ? (int) $_POST['ecole_id_import'] : null;
    $estMultiEcoles = $typeImport === 'enseignant_multi_ecoles';
    $categorieImport = $typeImport === 'conseiller' ? 'conseiller' : ($typeImport === 'administratif' ? 'administratif' : 'enseignant');

    if ($categorieImport === 'enseignant' && !$estMultiEcoles && !$ecoleIdImport) {
        $error = "Veuillez choisir l'école concernée par ce fichier d'enseignants.";
    } elseif (isset($_FILES['fichier_personnel']) && $_FILES['fichier_personnel']['error'] === 0) {
        try {
            $typeEcoleImport = 'Public';
            if ($ecoleIdImport) {
                $stmtTypeEcole = $pdo->prepare("SELECT statut FROM ecoles WHERE id = ?");
                $stmtTypeEcole->execute([$ecoleIdImport]);
                $typeEcoleImport = $stmtTypeEcole->fetchColumn() ?: 'Public';
            }

            $nomFichier = $_FILES['fichier_personnel']['name'];
            $extension = strtolower(pathinfo($nomFichier, PATHINFO_EXTENSION));

            if ($extension === 'docx') {
                // Fichier Word envoyé tel quel par le directeur (modèle
                // officiel de liste des enseignants) : lu directement, sans
                // conversion préalable en Excel.
                $lignesDocx = extraireTableauDocx($_FILES['fichier_personnel']['tmp_name']);
                $rows = mapperLignesDocxPersonnel($lignesDocx, $typeEcoleImport);
            } else {
                $spreadsheet = IOFactory::load($_FILES['fichier_personnel']['tmp_name']);
                $rowsBrut = $spreadsheet->getActiveSheet()->toArray();
                array_shift($rowsBrut); // Saute l'en-tête

                $rows = $estMultiEcoles ? mapperLignesMultiEcoles($rowsBrut) : $rowsBrut;
            }

            // Cache des écoles déjà recherchées (fichier consolidé multi-écoles
            // uniquement) : évite une requête par ligne sur un fichier de
            // plusieurs centaines d'enseignants répartis sur peu d'écoles.
            $ecoleParNomCache = [];

            $nbAjouts = 0;
            $nbMajs = 0;
            $erreurs = [];

            // Colonnes attendues (catégorie et école fixées pour tout le fichier, cf. ci-dessus) :
            // A:Nom B:Prénoms C:Sexe D:Téléphone E:Matricule (Public) / N° Autorisation (Privé)
            // F:NiveauTenu G:Emploi (IO/IA — Public uniquement, ignoré si école Privée)
            // H:Fonction I:Disponibilité
            //
            // Simplifications volontaires par rapport à la fiche complète (édition manuelle) :
            //   - Sous-type, Grade, Diplôme et Niveau d'étude ne sont plus des colonnes de
            //     l'import (peu utiles au suivi CEPE, modifiables ensuite à la main si besoin).
            //   - Un enseignant n'a qu'UN SEUL identifiant selon son école : matricule si
            //     Public, n° d'autorisation (enseigner ou diriger selon la Fonction) si Privé.
            //   - École Privée -> Emploi toujours 'IA' automatiquement.
            //   - École Publique -> Grade déduit automatiquement de l'Emploi (IO -> B3, IA -> C3).
            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    $nom = trim($row[0] ?? '');
                    if (empty($nom)) continue;
                    $prenoms = trim($row[1] ?? '');
                    $sexe = strtoupper(trim($row[2] ?? 'M')) === 'F' ? 'F' : 'M';
                    $telephone = trim($row[3] ?? '');
                    $identifiant = trim($row[4] ?? '') ?: null;

                    $niveauVal = in_array(trim($row[5] ?? ''), NIVEAUX) ? trim($row[5]) : null;
                    $emploiVal = in_array(strtoupper(trim($row[6] ?? '')), ['IO', 'IA']) ? strtoupper(trim($row[6])) : null;

                    $fonctionRaw = trim($row[7] ?? '');

                    $dispRaw = trim($row[8] ?? '');
                    $disponibilite = in_array($dispRaw, DISPONIBILITES) ? $dispRaw : 'En activité';

                    $sousType = null;
                    $diplome = null;
                    $niveauEtude = null;

                    if ($estMultiEcoles) {
                        $nomEcoleLigne = trim($row[9] ?? '');
                        if ($nomEcoleLigne === '') {
                            $erreurs[] = "Ligne $numLigne : nom d'école manquant pour $nom $prenoms — ignorée.";
                            continue;
                        }
                        $cleEcole = normaliserNomEcolePourComparaison($nomEcoleLigne);
                        if (!array_key_exists($cleEcole, $ecoleParNomCache)) {
                            $stmtEcole = $pdo->prepare("SELECT id, statut FROM ecoles WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) LIMIT 1");
                            $stmtEcole->execute([$nomEcoleLigne]);
                            $trouvee = $stmtEcole->fetch();

                            if (!$trouvee && isset(ALIAS_NOMS_ECOLES_IMPORT[$cleEcole])) {
                                $stmtEcole->execute([ALIAS_NOMS_ECOLES_IMPORT[$cleEcole]]);
                                $trouvee = $stmtEcole->fetch();
                            }

                            $ecoleParNomCache[$cleEcole] = $trouvee ?: null;
                        }
                        $ecoleTrouvee = $ecoleParNomCache[$cleEcole];
                        if (!$ecoleTrouvee) {
                            $erreurs[] = "Ligne $numLigne : école '$nomEcoleLigne' introuvable pour $nom $prenoms — ignorée.";
                            continue;
                        }
                        $ecoleIdImport = (int) $ecoleTrouvee['id'];
                        $typeEcoleImport = $ecoleTrouvee['statut'];
                    }

                    $ecoleId = $categorieImport === 'enseignant' ? $ecoleIdImport : null;
                    $typeEcole = $categorieImport === 'enseignant' ? $typeEcoleImport : 'Public';

                    $matricule = null;
                    $numAutoEnseigner = null;
                    $numAutoDiriger = null;
                    $gradeVal = null;

                    if ($categorieImport === 'enseignant') {
                        // La liste d'une école ne distingue que Directeur / Adjoint.
                        $fonction = stripos($fonctionRaw, 'directeur') === 0 ? 'Directeur' : 'Adjoint';

                        if ($typeEcole === 'Privé') {
                            $emploiVal = 'IA'; // automatique en privé
                            if ($fonction === 'Directeur') {
                                $numAutoDiriger = $identifiant;
                            } else {
                                $numAutoEnseigner = $identifiant;
                            }
                        } else {
                            $matricule = $identifiant;
                            if ($emploiVal === 'IO') {
                                $gradeVal = 'B3';
                            } elseif ($emploiVal === 'IA') {
                                $gradeVal = 'C3';
                            }
                        }
                    } else {
                        $matricule = $identifiant;
                        $fonction = !empty($fonctionRaw) ? $fonctionRaw : ($categorieImport === 'conseiller' ? 'Conseiller' : 'Agent Administratif');
                    }

                    // Vérification Doublon : par Matricule (Public) ou N° d'autorisation (Privé) si
                    // disponible — clés les plus fiables — sinon par Nom + Prénoms + École.
                    $existing = null;
                    if (!empty($matricule)) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE matricule = ?");
                        $checkStmt->execute([$matricule]);
                        $existing = $checkStmt->fetch();
                    }
                    if (!$existing && (!empty($numAutoEnseigner) || !empty($numAutoDiriger))) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE (numero_autorisation_enseigner IS NOT NULL AND numero_autorisation_enseigner = ?) OR (numero_autorisation_diriger IS NOT NULL AND numero_autorisation_diriger = ?)");
                        $checkStmt->execute([$numAutoEnseigner ?? '', $numAutoDiriger ?? '']);
                        $existing = $checkStmt->fetch();
                    }
                    if (!$existing) {
                        $checkStmt = $pdo->prepare("SELECT id FROM personnel WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) AND LOWER(TRIM(prenoms)) = LOWER(TRIM(?)) AND (ecole_id <=> ?)");
                        $checkStmt->execute([$nom, $prenoms, $ecoleId]);
                        $existing = $checkStmt->fetch();
                    }

                    if ($existing) {
                        // sous_type / plus_haut_diplome / plus_haut_niveau_etude ne
                        // font pas partie des colonnes du fichier Excel importé : on
                        // ne les touche pas ici pour ne pas écraser une valeur saisie
                        // manuellement dans l'application par une valeur vide.
                        $upd = $pdo->prepare("
                            UPDATE personnel SET ecole_id=?, categorie=?, sexe=?, telephone=?, type_ecole=?, matricule=?,
                                numero_autorisation_enseigner=?, numero_autorisation_diriger=?, niveau_tenu=?, emploi=?, grade=?,
                                fonction=?, disponibilite=?
                            WHERE id=?
                        ");
                        $upd->execute([
                            $ecoleId, $categorieImport, $sexe, $telephone, $typeEcole, $matricule,
                            $numAutoEnseigner, $numAutoDiriger, $niveauVal, $emploiVal, $gradeVal, $fonction, $disponibilite,
                            $existing['id'],
                        ]);
                        $nbMajs++;
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO personnel (annee_id, ecole_id, categorie, sous_type, nom, prenoms, sexe, telephone, type_ecole, matricule, numero_autorisation_enseigner, numero_autorisation_diriger, niveau_tenu, emploi, grade, fonction, disponibilite, plus_haut_diplome, plus_haut_niveau_etude)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        ");
                        $stmt->execute([
                            $anneeActive['id'] ?? $anneeId, $ecoleId, $categorieImport, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule,
                            $numAutoEnseigner, $numAutoDiriger, $niveauVal, $emploiVal, $gradeVal, $fonction, $disponibilite,
                            $diplome, $niveauEtude,
                        ]);
                        $nbAjouts++;
                    }

                    // Renseigne automatiquement le contact du directeur sur la fiche
                    // école : la liste d'enseignants d'une école indique déjà qui en
                    // est le directeur et son téléphone, pas la peine de le ressaisir
                    // à la main dans l'onglet École.
                    if ($categorieImport === 'enseignant' && $fonction === 'Directeur' && $ecoleId && !empty($telephone)) {
                        $pdo->prepare("UPDATE ecoles SET directeur_nom = ?, directeur_telephone = ? WHERE id = ?")
                            ->execute([trim("$nom $prenoms"), $telephone, $ecoleId]);
                    }
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            $msg = "Import terminé (" . CATEGORIES_PERSONNEL[$categorieImport] . ") : $nbAjouts ajouté(s), $nbMajs mis à jour.";
            if (!empty($erreurs)) {
                $msg .= " <br><small>" . count($erreurs) . " remarque(s) (voir détail ci-dessous).</small>";
                $_SESSION['import_erreurs_personnel'] = $erreurs;
            } else {
                unset($_SESSION['import_erreurs_personnel']);
            }
            header("Location: enseignants.php?msg=" . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $error = "Erreur fichier : " . $e->getMessage();
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $pdo->prepare("DELETE FROM personnel WHERE id = ?")->execute([(int) $_GET['supprimer']]);
    header("Location: enseignants.php");
    exit;
}

// ==========================================
// TRAITEMENT : AJOUT / MODIFICATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajouter']) || isset($_POST['modifier']))) {
    $categorie = array_key_exists($_POST['categorie'] ?? '', CATEGORIES_PERSONNEL) ? $_POST['categorie'] : 'enseignant';
    $sousType = trim($_POST['sous_type'] ?? '') ?: null;
    $ecoleId = !empty($_POST['ecole_id']) ? (int) $_POST['ecole_id'] : null;
    $nom = trim($_POST['nom'] ?? '');
    $prenoms = trim($_POST['prenoms'] ?? '');
    $sexe = $_POST['sexe'] ?? 'M';
    $telephone = trim($_POST['telephone'] ?? '');
    $typeEcole = $_POST['type_ecole'] ?? 'Public';
    $matricule = trim($_POST['matricule'] ?? '') ?: null;
    $numAutoEnseigner = trim($_POST['numero_autorisation_enseigner'] ?? '') ?: null;
    $numAutoDiriger = trim($_POST['numero_autorisation_diriger'] ?? '') ?: null;
    $niveau = $_POST['niveau_tenu'] ?: null;
    $emploi = $_POST['emploi'] ?: null;
    $grade = trim($_POST['grade'] ?? '') ?: null;
    $fonction = trim($_POST['fonction'] ?? '') ?: 'Adjoint';
    $disponibilite = $_POST['disponibilite'] ?? 'En activité';
    $diplome = trim($_POST['plus_haut_diplome'] ?? '') ?: null;
    $niveauEtude = trim($_POST['plus_haut_niveau_etude'] ?? '') ?: null;

    if (empty($nom) || empty($prenoms)) {
        $error = "Nom et Prénoms obligatoires.";
    } else {
        if (isset($_POST['ajouter'])) {
            $stmt = $pdo->prepare("
                INSERT INTO personnel (annee_id, ecole_id, categorie, sous_type, nom, prenoms, sexe, telephone, type_ecole, matricule, numero_autorisation_enseigner, numero_autorisation_diriger, niveau_tenu, emploi, grade, fonction, disponibilite, plus_haut_diplome, plus_haut_niveau_etude)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$anneeActive['id'] ?? $anneeId, $ecoleId, $categorie, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $numAutoEnseigner, $numAutoDiriger, $niveau, $emploi, $grade, $fonction, $disponibilite, $diplome, $niveauEtude]);
        } elseif (isset($_POST['modifier'])) {
            $id = (int) $_POST['id'];
            $stmt = $pdo->prepare("
                UPDATE personnel SET ecole_id=?, categorie=?, sous_type=?, nom=?, prenoms=?, sexe=?, telephone=?, type_ecole=?, matricule=?, numero_autorisation_enseigner=?, numero_autorisation_diriger=?, niveau_tenu=?, emploi=?, grade=?, fonction=?, disponibilite=?, plus_haut_diplome=?, plus_haut_niveau_etude=?
                WHERE id=?
            ");
            $stmt->execute([$ecoleId, $categorie, $sousType, $nom, $prenoms, $sexe, $telephone, $typeEcole, $matricule, $numAutoEnseigner, $numAutoDiriger, $niveau, $emploi, $grade, $fonction, $disponibilite, $diplome, $niveauEtude, $id]);
        }
        header("Location: enseignants.php");
        exit;
    }
}

// ==========================================
// FILTRES & DONNÉES
// ==========================================
$filtreCategorie = $_GET['categorie'] ?? '';
$filtreEcole = $_GET['ecole_id'] ?? '';
$filtreDispo = $_GET['disponibilite'] ?? '';
$filtreRecherche = $_GET['q'] ?? '';

$sql = "SELECT p.*, ec.nom as ecole_nom FROM personnel p LEFT JOIN ecoles ec ON p.ecole_id = ec.id WHERE 1=1";
$params = [];

if ($filtreCategorie) {
    $sql .= " AND p.categorie = ?";
    $params[] = $filtreCategorie;
}
if ($filtreEcole) {
    $sql .= " AND p.ecole_id = ?";
    $params[] = $filtreEcole;
}
if ($filtreDispo) {
    $sql .= " AND p.disponibilite = ?";
    $params[] = $filtreDispo;
}
if ($filtreRecherche) {
    $sql .= " AND (p.nom LIKE ? OR p.prenoms LIKE ? OR p.matricule LIKE ?)";
    $t = "%$filtreRecherche%";
    $params[] = $t; $params[] = $t; $params[] = $t;
}

$sql .= " ORDER BY p.nom ASC, p.prenoms ASC";
$stmtListe = $pdo->prepare($sql);
$stmtListe->execute($params);
$personnels = $stmtListe->fetchAll();

$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();

$stats = $pdo->query("
    SELECT
        COUNT(*) as total,
        SUM(CASE WHEN categorie='enseignant' THEN 1 ELSE 0 END) as nb_enseignants,
        SUM(CASE WHEN categorie='conseiller' THEN 1 ELSE 0 END) as nb_conseillers,
        SUM(CASE WHEN categorie='administratif' THEN 1 ELSE 0 END) as nb_administratifs,
        SUM(CASE WHEN fonction LIKE 'Directeur%' THEN 1 ELSE 0 END) as nb_directeurs,
        SUM(CASE WHEN disponibilite='En activité' THEN 1 ELSE 0 END) as nb_actifs
    FROM personnel
")->fetch();

$personnelAModifier = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM personnel WHERE id = ?");
    $stmt->execute([(int) $_GET['modifier']]);
    $personnelAModifier = $stmt->fetch();
}

include '../views/layouts/header.php';
?>

<!-- Alertes -->
<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= $_GET['msg'] ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($_SESSION['import_erreurs_personnel'])): ?>
    <div class="alert alert-warning">
        <button class="btn btn-sm btn-outline-dark mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#detailErreursPersonnel">
            <i class="bi bi-list-ul"></i> Voir le détail (<?= count($_SESSION['import_erreurs_personnel']) ?>)
        </button>
        <div class="collapse" id="detailErreursPersonnel">
            <div style="max-height: 300px; overflow-y: auto;">
                <ul class="mb-0 small">
                    <?php foreach ($_SESSION['import_erreurs_personnel'] as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
    <?php unset($_SESSION['import_erreurs_personnel']); ?>
<?php endif; ?>
<?php if (isset($error)): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>👥 Gestion du Personnel</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImport"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter</button>
    </div>
</div>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-2"><?php statCard('bi-person-badge', 'navy', (string) $stats['total'], 'Total'); ?></div>
    <div class="col-md-2"><?php statCard('bi-easel', 'blue', (string) $stats['nb_enseignants'], 'Enseignants'); ?></div>
    <div class="col-md-2"><?php statCard('bi-person-lines-fill', 'purple', (string) $stats['nb_conseillers'], 'Conseillers'); ?></div>
    <div class="col-md-3"><?php statCard('bi-briefcase', 'orange', (string) $stats['nb_administratifs'], 'Personnel Admin.'); ?></div>
    <div class="col-md-3"><?php statCard('bi-check-circle', 'green', (string) $stats['nb_actifs'], 'En activité'); ?></div>
</div>

<!-- Filtres -->
<form method="GET" id="formFiltresPersonnel" class="row g-3 mb-4 p-3 bg-light rounded">
    <div class="col-md-3">
        <label class="form-label">Rechercher (Nom/Matricule)</label>
        <input type="text" name="q" id="inputReecherchePersonnel" class="form-control" value="<?= htmlspecialchars($filtreRecherche) ?>" autocomplete="off">
    </div>
    <div class="col-md-3">
        <label class="form-label">Catégorie</label>
        <select name="categorie" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php foreach (CATEGORIES_PERSONNEL as $val => $label): ?>
                <option value="<?= $val ?>" <?= $filtreCategorie === $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">École</label>
        <select name="ecole_id" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les écoles</option>
            <?php foreach ($ecoles as $e): ?>
                <option value="<?= $e['id'] ?>" <?= $filtreEcole == $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label">Disponibilité</label>
        <select name="disponibilite" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes</option>
            <?php foreach (DISPONIBILITES as $d): ?>
                <option value="<?= $d ?>" <?= $filtreDispo === $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <a href="enseignants.php" class="btn btn-outline-secondary w-100" title="Réinitialiser"><i class="bi bi-x-lg"></i></a>
    </div>
</form>
<script>
(function () {
    var champ = document.getElementById('inputReecherchePersonnel');
    var minuteur;
    champ.addEventListener('input', function () {
        clearTimeout(minuteur);
        minuteur = setTimeout(function () {
            document.getElementById('formFiltresPersonnel').submit();
        }, 600);
    });
})();
</script>

<!-- Barre d'actions en masse (apparaît dès qu'au moins 1 personnel est sélectionné) -->
<div class="alert alert-primary d-none align-items-center justify-content-between py-2 mb-3" id="barreActionsMassePersonnel">
    <span><strong id="nbSelectionnesPersonnel">0</strong> personnel(s) sélectionné(s)</span>
    <button type="button" class="btn btn-sm btn-danger" onclick="actionMassePersonnel('supprimer')"><i class="bi bi-trash"></i> Supprimer</button>
</div>

<!-- Tableau -->
<div class="card shadow">
    <div class="card-body p-0 table-responsive tableau-scrollable">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th><input type="checkbox" id="checkToutPersonnel" onclick="toggleTousPersonnel(this)"></th>
                    <th>Nom</th>
                    <th>Prénoms</th>
                    <th>Sexe</th>
                    <th>Catégorie</th>
                    <th>Fonction</th>
                    <th>École / Rattachement</th>
                    <th>Niveau tenu</th>
                    <th>Disponibilité</th>
                    <th>Téléphone</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($personnels as $p): ?>
                <tr>
                    <td><input type="checkbox" class="check-personnel" value="<?= $p['id'] ?>" onchange="majBarreActionsPersonnel()"></td>
                    <td><strong><?= htmlspecialchars($p['nom']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($p['matricule'] ?? '') ?></small></td>
                    <td><?= htmlspecialchars($p['prenoms']) ?></td>
                    <td><span class="badge bg-<?= $p['sexe'] == 'M' ? 'primary' : 'danger' ?>"><?= $p['sexe'] ?></span></td>
                    <td>
                        <?php
                        $badgeCat = ['enseignant' => 'info', 'conseiller' => 'secondary', 'administratif' => 'warning'];
                        ?>
                        <span class="badge bg-<?= $badgeCat[$p['categorie']] ?? 'secondary' ?> <?= $p['categorie'] === 'administratif' ? 'text-dark' : '' ?>">
                            <?= htmlspecialchars(CATEGORIES_PERSONNEL[$p['categorie']] ?? $p['categorie']) ?>
                        </span>
                        <?php if ($p['sous_type']): ?><br><small class="text-muted"><?= htmlspecialchars($p['sous_type']) ?></small><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['fonction']) ?></td>
                    <td>
                        <?php if ($p['ecole_nom']): ?>
                            <?= htmlspecialchars($p['ecole_nom']) ?>
                            <br><small class="text-muted"><?= htmlspecialchars($p['type_ecole']) ?></small>
                        <?php else: ?>
                            <span class="text-muted">Inspection (IEPP)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $p['niveau_tenu'] ? htmlspecialchars($p['niveau_tenu']) : '<span class="text-muted">Sans classe</span>' ?></td>
                    <td>
                        <?php
                        $badgeDispo = $p['disponibilite'] === 'En activité' ? 'success' : (in_array($p['disponibilite'], ['Absent']) ? 'danger' : 'secondary');
                        ?>
                        <span class="badge bg-<?= $badgeDispo ?>"><?= htmlspecialchars($p['disponibilite']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($p['telephone']) ?></td>
                    <td>
                        <a href="?modifier=<?= $p['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <a href="?supprimer=<?= $p['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer cette personne ?')"><i class="bi bi-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($personnels)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">Aucune personne ne correspond à ces filtres.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal IMPORT -->
<div class="modal fade" id="modalImport" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" enctype="multipart/form-data">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title">Importer le Personnel (Excel)</h5>
                    <a href="enseignants.php" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Chaque fichier reste séparé : une école = un fichier d'enseignants (celui transmis par son
                        directeur), et un fichier à part pour les conseillers ou le personnel administratif.
                        La catégorie et l'école ne sont donc plus des colonnes à remplir — vous les choisissez
                        ici une seule fois pour tout le fichier.
                    </p>

                    <div class="mb-3">
                        <label class="form-label">Ce fichier contient…</label>
                        <select name="type_import" id="selectTypeImportPersonnel" class="form-select" onchange="ajusterImportPersonnel()" required>
                            <option value="enseignant_ecole">Les enseignants d'UNE école (liste d'un directeur)</option>
                            <option value="enseignant_multi_ecoles">Les enseignants de PLUSIEURS écoles (fichier consolidé, ex. DSPS)</option>
                            <option value="conseiller">Les conseillers (liste de la RH)</option>
                            <option value="administratif">Le personnel administratif</option>
                        </select>
                    </div>

                    <div class="mb-3" id="blocEcoleImportPersonnel">
                        <label class="form-label">École concernée par ce fichier</label>
                        <select name="ecole_id_import" class="form-select">
                            <option value="">-- Choisir l'école --</option>
                            <?php foreach ($ecoles as $e): ?>
                                <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['nom']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div id="blocColonnesStandard">
                        <p class="small mb-1">Colonnes attendues dans le fichier, dans cet ordre :</p>
                        <ol class="small">
                            <li>Nom</li><li>Prénoms</li><li>Sexe (M/F)</li><li>Téléphone</li>
                            <li>Matricule (école Publique) ou N° d'autorisation (école Privée) — une seule colonne, orientée automatiquement vers "enseigner" ou "diriger" selon la Fonction</li>
                            <li>Niveau tenu (CP1 à CM2 — vide = Sans classe)</li>
                            <li>Emploi : IO ou IA (Public uniquement — en école Privée, IA est appliqué automatiquement, colonne ignorée)</li>
                            <li>Fonction (Directeur ou Adjoint pour un fichier d'enseignants ; texte libre pour Conseillers/Administratifs)</li>
                            <li>Disponibilité (En activité / Congé maternité / Congé maladie / Absent / Autre — vide = En activité)</li>
                        </ol>
                    </div>
                    <div id="blocColonnesMultiEcoles" style="display:none;">
                        <p class="small mb-1">Colonnes attendues dans le fichier, dans cet ordre :</p>
                        <ol class="small">
                            <li>Nom</li><li>Prénoms</li>
                            <li><strong>Nom de l'école</strong> — doit correspondre exactement au nom déjà enregistré dans l'onglet Écoles ; une ligne dont l'école n'est pas retrouvée est ignorée (signalée après l'import)</li>
                            <li>Niveau tenu / classe</li>
                            <li>Matricule ou N° d'autorisation</li>
                            <li>Fonction (Directeur ou Adjoint)</li>
                            <li>Contact (téléphone)</li>
                            <li>Corps et grade (ex: "IO/B3", "IA/C3") — l'emploi (IO/IA) en est déduit automatiquement</li>
                        </ol>
                        <p class="small text-muted">Public/Privé, et donc Matricule vs N° d'autorisation, sont déterminés automatiquement selon l'école retrouvée pour chaque ligne.</p>
                    </div>
                    <p class="small text-muted">
                        Le grade des enseignants du Public est déduit automatiquement de l'emploi (IO → B3, IA → C3).
                        Sous-type, grade (Privé), diplôme et niveau d'étude ne sont plus demandés à l'import — modifiables ensuite au cas par cas via "Modifier".
                    </p>
                    <p class="small text-muted">
                        <i class="bi bi-file-earmark-word"></i>
                        Le fichier Word (.docx) rempli par le directeur — modèle officiel "Liste des enseignants" — est accepté tel quel, sans conversion en Excel (uniquement pour "UNE école").
                        La colonne Sexe (ajoutée après Prénoms) y est lue directement ; Disponibilité, absente du modèle Word, prend sa valeur par défaut (En activité) et reste modifiable ensuite au cas par cas.
                    </p>
                    <input type="file" name="fichier_personnel" class="form-control" accept=".xlsx,.xls,.docx" required>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="importer_personnel" class="btn btn-success">Importer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal AJOUT -->
<div class="modal fade" id="modalAjout" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="formAjoutPersonnel">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title">Ajouter une personne</h5>
                    <a href="enseignants.php" class="btn-close btn-close-white"></a>
                </div>
                <div class="modal-body">
                    <?php include 'partials/personnel_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="ajouter" class="btn btn-primary">Enregistrer</button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Modal MODIFICATION -->
<?php if ($personnelAModifier): $p = $personnelAModifier; ?>
<div class="modal fade show" id="modalEdit" tabindex="-1" style="display:block; background:rgba(0,0,0,0.5)">
    <div class="modal-dialog modal-lg">
        <form method="POST" id="formEditPersonnel">
            <div class="modal-content">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">Modifier</h5>
                    <a href="enseignants.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <?php include 'partials/personnel_form_fields.php'; ?>
                </div>
                <div class="modal-footer">
                    <a href="enseignants.php" class="btn btn-secondary">Annuler</a>
                    <button type="submit" name="modifier" class="btn btn-warning">Mettre à jour</button>
                </div>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
// Affiche/masque ET active/désactive les champs selon la catégorie choisie
// (Enseignant / Conseiller / Administratif). Désactiver les champs masqués
// évite les conflits quand deux champs partagent le même name="" (ex: Fonction).
function ajusterChampsCategorie(select) {
    var form = select.closest('form');
    var categorie = select.value;
    form.querySelectorAll('[data-categorie]').forEach(function (bloc) {
        var categoriesAutorisees = bloc.getAttribute('data-categorie').split(',');
        var visible = categoriesAutorisees.includes(categorie);
        bloc.style.display = visible ? '' : 'none';
        bloc.querySelectorAll('input, select, textarea').forEach(function (champ) {
            champ.disabled = !visible;
        });
    });
}
// Applique l'état initial de chaque formulaire déjà présent dans la page (édition)
document.querySelectorAll('select[name="categorie"]').forEach(ajusterChampsCategorie);

// Modal Import : le sélecteur d'école n'a de sens que pour un fichier d'enseignants
// (les conseillers/administratifs sont rattachés à l'Inspection, pas à une école).
function ajusterImportPersonnel() {
    var type = document.getElementById('selectTypeImportPersonnel').value;
    var bloc = document.getElementById('blocEcoleImportPersonnel');
    var visible = type === 'enseignant_ecole';
    bloc.style.display = visible ? '' : 'none';
    bloc.querySelectorAll('select').forEach(function (champ) {
        champ.required = visible;
        champ.disabled = !visible;
    });

    var multiEcoles = type === 'enseignant_multi_ecoles';
    document.getElementById('blocColonnesStandard').style.display = multiEcoles ? 'none' : '';
    document.getElementById('blocColonnesMultiEcoles').style.display = multiEcoles ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', ajusterImportPersonnel);

function toggleTousPersonnel(caseTete) {
    document.querySelectorAll('.check-personnel').forEach(function (c) { c.checked = caseTete.checked; });
    majBarreActionsPersonnel();
}

function majBarreActionsPersonnel() {
    var coches = document.querySelectorAll('.check-personnel:checked');
    var barre = document.getElementById('barreActionsMassePersonnel');
    document.getElementById('nbSelectionnesPersonnel').textContent = coches.length;
    barre.classList.toggle('d-none', coches.length === 0);
    barre.classList.toggle('d-flex', coches.length > 0);
}

async function actionMassePersonnel(action) {
    var ids = Array.from(document.querySelectorAll('.check-personnel:checked')).map(function (c) { return c.value; });
    if (ids.length === 0) return;

    if (action === 'supprimer' && !confirm('Supprimer ' + ids.length + ' membre(s) du personnel ? Cette action est irréversible.')) {
        return;
    }

    try {
        var reponse = await fetch('api_bulk_personnel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=' + action + '&ids=' + ids.join(',')
        });
        var data = await reponse.json();
        if (data.success) {
            location.reload();
        } else {
            alert('Erreur : ' + (data.error || 'inconnue'));
        }
    } catch (e) {
        alert('Erreur réseau : impossible d\'effectuer cette action.');
    }
}
</script>

<?php include '../views/layouts/footer.php'; ?>
