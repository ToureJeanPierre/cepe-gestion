<?php

// ==========================================
// CONTRÔLE DU DOSSIER DE DÉPÔTS
// ==========================================
// Les directeurs envoient leurs listes (Word) par WhatsApp ; elles sont
// enregistrées dans un dossier du PC. Ce module les analyse SANS rien importer
// pour dire, fichier par fichier : de quel type de liste il s'agit, de quelle
// école, combien de lignes, et ce qui est DÉJÀ dans la base (donc inutile de
// le ré-importer) — et à l'inverse quelles écoles n'ont rien envoyé.

require_once __DIR__ . '/import_helpers.php';

if (!function_exists('reglageLire')) {
    function reglageLire(PDO $pdo, string $cle, ?string $defaut = null): ?string
    {
        $stmt = $pdo->prepare("SELECT valeur FROM reglages WHERE cle = ?");
        $stmt->execute([$cle]);
        $v = $stmt->fetchColumn();
        return $v === false ? $defaut : $v;
    }
}

if (!function_exists('reglageEcrire')) {
    function reglageEcrire(PDO $pdo, string $cle, string $valeur): void
    {
        $pdo->prepare("INSERT INTO reglages (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)")
            ->execute([$cle, $valeur]);
    }
}

if (!function_exists('dossierDepots')) {
    // Dossier configuré (slashs normalisés, sans slash final).
    function dossierDepots(PDO $pdo): string
    {
        $d = reglageLire($pdo, 'dossier_depots', 'C:/Users/hp/Desktop/2026-2027');
        return rtrim(str_replace('\\', '/', (string) $d), '/');
    }
}

if (!function_exists('resoudreFichierDepot')) {
    /**
     * Retourne le chemin réel d'un fichier du dossier de dépôts à partir de son
     * chemin RELATIF, ou null s'il sort du dossier (".." etc.), n'existe pas ou
     * n'est pas un fichier Word/Excel. Empêche d'utiliser l'import pour lire
     * un fichier arbitraire du PC.
     *
     * @return array{chemin: string, nom: string}|null
     */
    function resoudreFichierDepot(PDO $pdo, string $relatif): ?array
    {
        $racine = realpath(dossierDepots($pdo));
        if ($racine === false || $relatif === '' || str_contains($relatif, "\0")) {
            return null;
        }
        $chemin = realpath($racine . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relatif));
        if ($chemin === false || !is_file($chemin) || !str_starts_with($chemin, $racine . DIRECTORY_SEPARATOR)) {
            return null;
        }
        if (!in_array(strtolower(pathinfo($chemin, PATHINFO_EXTENSION)), ['docx', 'xlsx', 'xls'], true)) {
            return null;
        }
        return ['chemin' => $chemin, 'nom' => basename($chemin)];
    }
}

if (!function_exists('fichierImportSource')) {
    /**
     * Fichier à importer : soit téléversé par le formulaire, soit choisi dans le
     * dossier de dépôts (champ POST depot_chemin).
     *
     * @return array{tmp: string, nom: string}|null
     */
    function fichierImportSource(PDO $pdo, string $champFichier): ?array
    {
        if (!empty($_POST['depot_chemin'])) {
            $r = resoudreFichierDepot($pdo, (string) $_POST['depot_chemin']);
            return $r ? ['tmp' => $r['chemin'], 'nom' => $r['nom']] : null;
        }
        if (isset($_FILES[$champFichier]) && $_FILES[$champFichier]['error'] === 0) {
            return ['tmp' => $_FILES[$champFichier]['tmp_name'], 'nom' => $_FILES[$champFichier]['name']];
        }
        return null;
    }
}

if (!function_exists('urlRetourImport')) {
    // Après un import lancé depuis le contrôle du dossier, on y revient.
    function urlRetourImport(string $pageParDefaut, string $msg): string
    {
        if (($_POST['retour'] ?? '') === 'controle_depots') {
            return 'controle_depots.php?analyser=1&msg=' . urlencode($msg);
        }
        return $pageParDefaut . '?msg=' . urlencode($msg);
    }
}

// ------------------------------------------
// Lecture d'une liste Word
// ------------------------------------------

if (!function_exists('lireDepotWord')) {
    /**
     * @return array{ok: bool, erreur: ?string, type: ?string, ecole_texte: ?string, contact: ?string, lignes: array, rows: array}
     */
    function lireDepotWord(string $chemin): array
    {
        $res = ['ok' => false, 'erreur' => null, 'type' => null, 'ecole_texte' => null, 'contact' => null, 'lignes' => [], 'rows' => []];
        try {
            $zip = new ZipArchive();
            if ($zip->open($chemin) !== true) {
                $res['erreur'] = "Fichier Word illisible (corrompu ou protégé).";
                return $res;
            }
            $xml = $zip->getFromName('word/document.xml');
            $zip->close();
            if ($xml === false) {
                $res['erreur'] = "Fichier Word invalide.";
                return $res;
            }
            $dom = new DOMDocument();
            $dom->loadXML($xml, LIBXML_NONET);
            $xp = new DOMXPath($dom);
            $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

            // "ECOLE : <nom>  CONTACT : <tél>" — ligne du modèle renseignée par le directeur.
            $tout = '';
            foreach ($xp->query('//w:p') as $p) {
                $t = trim(preg_replace('/\s+/u', ' ', $p->textContent));
                $tout .= ' ' . $t;
                if ($res['ecole_texte'] === null && preg_match('/\bECOLE\s*:\s*(.*?)\s*(?:CONTACT\s*:\s*(.*))?$/iu', $t, $m) && stripos($t, 'ECOLE') !== false) {
                    $nom = trim($m[1]);
                    // Un paragraphe "ECOLE : CONTACT :" vide donne un faux "CONTACT" comme nom.
                    if ($nom !== '' && stripos($nom, 'CONTACT') !== 0) {
                        $res['ecole_texte'] = $nom;
                        $res['contact'] = isset($m[2]) ? trim($m[2]) : null;
                    }
                }
            }

            $lignes = extraireTableauDocx($chemin);
            $res['lignes'] = $lignes;

            $entete = retirerAccents(mb_strtoupper(implode(' | ', $lignes[0] ?? [])));
            $titre = retirerAccents(mb_strtoupper($tout));
            if (str_contains($entete, 'FONCTION') || str_contains($entete, 'CORPS')) {
                $res['type'] = 'personnel';
            } elseif (str_contains($entete, 'NAI') || str_contains($entete, 'MATRICULE')) {
                $res['type'] = 'candidats';
            } elseif (str_contains($titre, 'LISTE NOMINATIVE DES CANDIDATS')) {
                $res['type'] = 'candidats';
            } elseif (str_contains($titre, 'ENSEIGNANT')) {
                $res['type'] = 'personnel';
            }

            if ($res['type'] === 'candidats') {
                $res['rows'] = mapperLignesDocxCandidats($lignes, '', null);
            } elseif ($res['type'] === 'personnel') {
                $res['rows'] = mapperLignesDocxPersonnel($lignes);
            }
            $res['ok'] = true;
        } catch (Throwable $e) {
            $res['erreur'] = "Lecture impossible : " . $e->getMessage();
        }
        return $res;
    }
}

// ------------------------------------------
// Reconnaissance de l'école (noms qui varient d'un document à l'autre)
// ------------------------------------------

if (!function_exists('jetonsNomEcole')) {
    /**
     * Mots distinctifs d'un nom d'école ou de fichier : majuscules, sans accents,
     * sans ponctuation, lettres et chiffres séparés ("TERMINUS1" -> TERMINUS 1),
     * sans mots génériques (EPP, ECOLE, LISTE, 2026...).
     *
     * @return string[]
     */
    function jetonsNomEcole(string $texte, bool $estNomFichier = false): array
    {
        if ($estNomFichier) {
            $texte = preg_replace('/\.(docx|xlsx|xls)$/i', '', $texte);
        }
        $t = retirerAccents(mb_strtoupper($texte));
        $t = preg_replace('/[^A-Z0-9]+/', ' ', $t);
        $t = preg_replace('/([A-Z])(\d)/', '$1 $2', $t);
        $t = preg_replace('/(\d)([A-Z])/', '$1 $2', $t);

        $generiques = ['EPP', 'EPV', 'EPC', 'EPU', 'ECOLE', 'PRIMAIRE', 'PUBLIQUE', 'PUBLIC', 'PRIVEE', 'PRIVE', 'CATHOLIQUE',
            'GROUPE', 'SCOLAIRE', 'LE', 'LA', 'LES', 'DE', 'DES', 'DU', 'D', 'L', 'ET', 'ST', 'SAINT'];
        if ($estNomFichier) {
            $generiques = array_merge($generiques, ['LISTE', 'NOMINATIVE', 'CANDIDATS', 'CANDIDAT', 'ENSEIGNANTS', 'ENSEIGNANT',
                'CEPE', 'CM2', 'CM', 'SESSION', 'ANNEE', 'DOCX', 'XLSX', 'COPIE', 'FINAL', 'NOUVEAU', '2025', '2026', '2027', '2028']);
        }
        $jetons = [];
        foreach (preg_split('/\s+/', trim($t), -1, PREG_SPLIT_NO_EMPTY) as $j) {
            if (!in_array($j, $generiques, true)) {
                $jetons[] = $j;
            }
        }
        return array_values(array_unique($jetons));
    }
}

if (!function_exists('jetonsCorrespondent')) {
    // Égalité, ou l'un préfixe de l'autre (NIANG / NIANGON, LAURIER / LAURIERS) — pas pour les chiffres.
    function jetonsCorrespondent(string $a, string $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if (ctype_digit($a) || ctype_digit($b)) {
            return false;
        }
        return strlen($a) >= 4 && strlen($b) >= 4 && (str_starts_with($a, $b) || str_starts_with($b, $a));
    }
}

if (!function_exists('proposerEcole')) {
    /**
     * @param array<int, array{id: int, nom: string, jetons: string[]}> $ecoles
     * @param string[] $jetonsSource
     * @return array{sure: bool, ids: int[], score: float}
     */
    function proposerEcole(array $ecoles, array $jetonsSource): array
    {
        if (!$jetonsSource) {
            return ['sure' => false, 'ids' => [], 'score' => 0.0];
        }
        $pleins = [];   // toutes les infos de la source retrouvées dans l'école
        $meilleurPartiel = ['ids' => [], 'ratio' => 0.0];
        foreach ($ecoles as $e) {
            $trouves = 0;
            foreach ($jetonsSource as $j) {
                foreach ($e['jetons'] as $k) {
                    if (jetonsCorrespondent($j, $k)) {
                        $trouves++;
                        break;
                    }
                }
            }
            $ratio = (float) ($trouves / count($jetonsSource));
            $couverture = $e['jetons'] ? (float) ($trouves / count($e['jetons'])) : 0.0;
            if ($ratio === 1.0) {
                $pleins[] = ['id' => $e['id'], 'couverture' => $couverture];
            } elseif ($ratio >= 0.6 && count($jetonsSource) >= 2) {
                if ($ratio > $meilleurPartiel['ratio']) {
                    $meilleurPartiel = ['ids' => [$e['id']], 'ratio' => $ratio];
                } elseif ($ratio === $meilleurPartiel['ratio']) {
                    $meilleurPartiel['ids'][] = $e['id'];
                }
            }
        }
        if ($pleins) {
            usort($pleins, fn ($a, $b) => $b['couverture'] <=> $a['couverture']);
            $top = array_filter($pleins, fn ($p) => abs($p['couverture'] - $pleins[0]['couverture']) < 1e-9);
            return ['sure' => count($top) === 1, 'ids' => array_column($pleins, 'id'), 'score' => (float) $pleins[0]['couverture']];
        }
        return ['sure' => false, 'ids' => $meilleurPartiel['ids'], 'score' => $meilleurPartiel['ratio']];
    }
}

// ------------------------------------------
// Comparaison avec ce qui est déjà en base
// ------------------------------------------

if (!function_exists('comparerDepotAvecBase')) {
    /**
     * @return array{total: int, deja: int, nouveaux: int, doublons_fichier: int}
     */
    function comparerDepotAvecBase(PDO $pdo, int $anneeId, string $type, array $rows, ?int $ecoleId): array
    {
        $res = ['total' => 0, 'deja' => 0, 'nouveaux' => 0, 'doublons_fichier' => 0];
        $vus = [];

        if ($type === 'candidats') {
            $parMatricule = $pdo->prepare("SELECT id FROM candidats WHERE annee_id = ? AND matricule_dsps = ? LIMIT 1");
            $parNom = $pdo->prepare("SELECT id FROM candidats WHERE annee_id = ? AND nom = ? AND prenoms = ? AND date_naissance <=> ? LIMIT 1");
            foreach ($rows as $r) {
                $nom = trim((string) ($r[0] ?? ''));
                if ($nom === '') {
                    continue;
                }
                $prenoms = trim((string) ($r[1] ?? ''));
                $matricule = trim((string) ($r[6] ?? ''));
                $date = parserDateNaissanceImport($r[4] ?? null);
                $cle = $matricule !== '' ? 'M:' . mb_strtoupper($matricule) : 'N:' . mb_strtoupper("$nom|$prenoms|$date");
                $res['total']++;
                if (isset($vus[$cle])) {
                    $res['doublons_fichier']++;
                    continue;
                }
                $vus[$cle] = true;
                if ($matricule !== '') {
                    $parMatricule->execute([$anneeId, $matricule]);
                } else {
                    $parNom->execute([$anneeId, $nom, $prenoms, $date]);
                }
                ($matricule !== '' ? $parMatricule : $parNom)->fetch() ? $res['deja']++ : $res['nouveaux']++;
            }
            return $res;
        }

        // personnel : mêmes critères que l'import (matricule / n° d'autorisation, sinon nom + prénoms)
        $parId = $pdo->prepare("SELECT id FROM personnel WHERE matricule = ? OR numero_autorisation_enseigner = ? OR numero_autorisation_diriger = ? LIMIT 1");
        $parNom = $pdo->prepare("SELECT id FROM personnel WHERE LOWER(TRIM(nom)) = LOWER(TRIM(?)) AND LOWER(TRIM(prenoms)) = LOWER(TRIM(?)) AND categorie = 'enseignant' AND (? IS NULL OR ecole_id = ?) LIMIT 1");
        foreach ($rows as $r) {
            $nom = trim((string) ($r[0] ?? ''));
            if ($nom === '') {
                continue;
            }
            $prenoms = trim((string) ($r[1] ?? ''));
            $ident = trim((string) ($r[4] ?? ''));
            $cle = $ident !== '' ? 'M:' . mb_strtoupper($ident) : 'N:' . mb_strtoupper("$nom|$prenoms");
            $res['total']++;
            if (isset($vus[$cle])) {
                $res['doublons_fichier']++;
                continue;
            }
            $vus[$cle] = true;
            $trouve = false;
            if ($ident !== '') {
                $parId->execute([$ident, $ident, $ident]);
                $trouve = (bool) $parId->fetch();
            }
            if (!$trouve) {
                $parNom->execute([$nom, $prenoms, $ecoleId, $ecoleId]);
                $trouve = (bool) $parNom->fetch();
            }
            $trouve ? $res['deja']++ : $res['nouveaux']++;
        }
        return $res;
    }
}

// ------------------------------------------
// Parcours du dossier
// ------------------------------------------

if (!function_exists('listerFichiersDepot')) {
    /**
     * @return array<int, array{relatif: string, nom: string, taille: int, mtime: int, ext: string}>
     */
    function listerFichiersDepot(string $dossier, int $max = 500): array
    {
        $racine = realpath($dossier);
        if ($racine === false || !is_dir($racine)) {
            return [];
        }
        $fichiers = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        $it->setMaxDepth(3);
        foreach ($it as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $nom = $f->getFilename();
            $ext = strtolower($f->getExtension());
            if (str_starts_with($nom, '~$') || !in_array($ext, ['docx', 'xlsx', 'xls'], true)) {
                continue;
            }
            $relatif = str_replace('\\', '/', substr($f->getPathname(), strlen($racine) + 1));
            $fichiers[] = ['relatif' => $relatif, 'nom' => $nom, 'taille' => $f->getSize(), 'mtime' => $f->getMTime(), 'ext' => $ext];
            if (count($fichiers) >= $max) {
                break;
            }
        }
        usort($fichiers, fn ($a, $b) => $b['mtime'] <=> $a['mtime']);
        return $fichiers;
    }
}

if (!function_exists('analyserDossierDepots')) {
    /**
     * @return array{fichiers: array, ecoles: array, resume: array, dossier_ok: bool}
     */
    function analyserDossierDepots(PDO $pdo, int $anneeId, string $dossier): array
    {
        $dossierOk = is_dir($dossier);
        $ecolesBase = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll();
        $ecoles = [];
        foreach ($ecolesBase as $e) {
            $ecoles[(int) $e['id']] = ['id' => (int) $e['id'], 'nom' => $e['nom'], 'jetons' => jetonsNomEcole($e['nom'])];
        }

        $fichiers = [];
        foreach (listerFichiersDepot($dossier) as $f) {
            $chemin = $dossier . '/' . $f['relatif'];
            $f['hash'] = @hash_file('sha256', $chemin) ?: null;
            $f += ['statut' => 'excel', 'type' => null, 'ecole_texte' => null, 'ecole_id' => null, 'ecole_sure' => false,
                'ecoles_possibles' => [], 'total' => 0, 'deja' => 0, 'nouveaux' => 0, 'doublons_fichier' => 0,
                'erreur' => null, 'meme_fichier_que' => null];

            if ($f['ext'] === 'docx') {
                $lu = lireDepotWord($chemin);
                $f['type'] = $lu['type'];
                $f['ecole_texte'] = $lu['ecole_texte'];
                if (!$lu['ok']) {
                    $f['statut'] = 'illisible';
                    $f['erreur'] = $lu['erreur'];
                } elseif ($lu['type'] === null) {
                    $f['statut'] = 'non_reconnu';
                } else {
                    // École : le texte "ECOLE :" du document d'abord, le nom du fichier en secours.
                    $sources = [];
                    if ($lu['ecole_texte'] !== null) {
                        $sources[] = proposerEcole($ecoles, jetonsNomEcole($lu['ecole_texte']));
                    }
                    $sources[] = proposerEcole($ecoles, jetonsNomEcole($f['nom'], true));
                    $choix = null;
                    foreach ($sources as $s) {
                        if ($s['sure']) {
                            $choix = $s;
                            break;
                        }
                    }
                    if ($choix === null) {
                        foreach ($sources as $s) {
                            if ($s['ids']) {
                                $choix = $s;
                                break;
                            }
                        }
                    }
                    if ($choix && $choix['ids']) {
                        $f['ecoles_possibles'] = array_slice($choix['ids'], 0, 6);
                        if ($choix['sure']) {
                            $f['ecole_id'] = (int) $choix['ids'][0];
                            $f['ecole_sure'] = true;
                        }
                    }
                    $cmp = comparerDepotAvecBase($pdo, $anneeId, $lu['type'], $lu['rows'], $f['ecole_id']);
                    $f = array_merge($f, $cmp);
                    if ($cmp['total'] === 0) {
                        $f['statut'] = 'vide';
                    } elseif ($cmp['nouveaux'] === 0) {
                        $f['statut'] = 'deja';
                    } elseif ($cmp['deja'] > 0) {
                        $f['statut'] = 'partiel';
                    } else {
                        $f['statut'] = 'nouveau';
                    }
                }
            }
            $fichiers[] = $f;
        }

        // Même contenu enregistré deux fois dans le dossier (copies WhatsApp, "(1)", etc.)
        $premierParHash = [];
        foreach ($fichiers as $i => $f) {
            if (!$f['hash']) {
                continue;
            }
            if (isset($premierParHash[$f['hash']])) {
                $fichiers[$i]['meme_fichier_que'] = $fichiers[$premierParHash[$f['hash']]]['relatif'];
            } else {
                $premierParHash[$f['hash']] = $i;
            }
        }

        // Situation par école : base de données vs dossier.
        $stmtC = $pdo->prepare("SELECT ecole_id, COUNT(*) n FROM candidats WHERE annee_id = ? AND est_candidat_libre = 0 AND ecole_id IS NOT NULL GROUP BY ecole_id");
        $stmtC->execute([$anneeId]);
        $nbCand = $stmtC->fetchAll(PDO::FETCH_KEY_PAIR);
        $stmtP = $pdo->prepare("SELECT ecole_id, COUNT(*) n FROM personnel WHERE annee_id = ? AND categorie = 'enseignant' AND ecole_id IS NOT NULL GROUP BY ecole_id");
        $stmtP->execute([$anneeId]);
        $nbPers = $stmtP->fetchAll(PDO::FETCH_KEY_PAIR);

        $parEcole = [];
        foreach ($ecoles as $id => $e) {
            $parEcole[$id] = ['id' => $id, 'nom' => $e['nom'], 'nb_candidats' => (int) ($nbCand[$id] ?? 0), 'nb_personnel' => (int) ($nbPers[$id] ?? 0),
                'fichiers_candidats' => [], 'fichiers_personnel' => []];
        }
        foreach ($fichiers as $f) {
            if ($f['ecole_id'] && in_array($f['type'], ['candidats', 'personnel'], true) && !$f['meme_fichier_que']) {
                $parEcole[$f['ecole_id']]['fichiers_' . $f['type']][] = $f['nom'];
            }
        }

        $resume = ['word' => 0, 'deja' => 0, 'partiel' => 0, 'nouveau' => 0, 'vide' => 0, 'non_reconnu' => 0, 'illisible' => 0,
            'excel' => 0, 'ecole_a_confirmer' => 0, 'copies' => 0];
        foreach ($fichiers as $f) {
            if ($f['ext'] === 'docx') {
                $resume['word']++;
            }
            $resume[$f['statut']] = ($resume[$f['statut']] ?? 0) + 1;
            if (in_array($f['statut'], ['nouveau', 'partiel', 'deja'], true) && !$f['ecole_sure']) {
                $resume['ecole_a_confirmer']++;
            }
            if ($f['meme_fichier_que']) {
                $resume['copies']++;
            }
        }

        return ['fichiers' => $fichiers, 'ecoles' => array_values($parEcole), 'resume' => $resume, 'dossier_ok' => $dossierOk];
    }
}
