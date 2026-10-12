<?php
// Lecture des listes envoyées en PDF (candidats, personnel), en plus de Word et Excel.
//
// Principe : un script Python (src/pdf_tables.py, PyMuPDF, 100 % local, rien n'est envoyé sur Internet) extrait les
// tableaux du PDF. Contrairement à un tableau Word, les colonnes d'un PDF ne sont PAS fiables d'une page à l'autre
// (cellules fusionnées, colonnes fantômes : 9, 10, 11, 18 colonnes pour le même modèle). On ne lit donc jamais
// « la 3ᵉ colonne = le sexe » : chaque valeur est reconnue à son CONTENU (un M/F, une date, un matricule, un
// téléphone, une classe...). Une ligne qu'on ne sait pas lire est signalée, jamais devinée.
require_once __DIR__ . '/docx_helpers.php';
require_once __DIR__ . '/import_helpers.php';

if (!function_exists('pdfPythonExe')) {
    /** Cherche python.exe (le service Apache ne voit pas le PATH de l'utilisateur). */
    function pdfPythonExe(): ?string
    {
        $candidats = array_merge(
            glob('C:/Python3*/python.exe') ?: [],
            glob('C:/Program Files/Python3*/python.exe') ?: [],
            glob('C:/Users/*/AppData/Local/Programs/Python/Python3*/python.exe') ?: []
        );
        rsort($candidats); // version la plus récente d'abord
        foreach ($candidats as $c) {
            if (is_file($c)) {
                return $c;
            }
        }
        return null;
    }
}

if (!function_exists('extrairePdf')) {
    /**
     * Lecture d'un PDF avec cache (la lecture prend environ une seconde : un dossier de 40 PDF se relit à chaque analyse).
     * La clé du cache change dès que le fichier change (chemin + taille + date), jamais de résultat périmé.
     *
     * @return array{ok: bool, erreur: ?string, texte: string, tableaux: array, caracteres: int}
     */
    function extrairePdf(string $chemin): array
    {
        $cle = is_file($chemin) ? sha1(realpath($chemin) . '|' . filesize($chemin) . '|' . filemtime($chemin)) : null;
        $dossierCache = dirname(__DIR__) . '/storage/cache_pdf';
        if ($cle !== null && is_file("$dossierCache/$cle.json")) {
            $cache = json_decode((string) file_get_contents("$dossierCache/$cle.json"), true);
            if (is_array($cache) && !empty($cache['ok'])) {
                return $cache;
            }
        }
        $res = extrairePdfSansCache($chemin);
        if ($cle !== null && $res['ok'] && (is_dir($dossierCache) || @mkdir($dossierCache, 0775, true))) {
            @file_put_contents("$dossierCache/$cle.json", json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return $res;
    }
}

if (!function_exists('extrairePdfSansCache')) {
    /** @return array{ok: bool, erreur: ?string, texte: string, tableaux: array, caracteres: int} */
    function extrairePdfSansCache(string $chemin): array
    {
        $vide = ['ok' => false, 'erreur' => null, 'texte' => '', 'tableaux' => [], 'caracteres' => 0];
        $python = pdfPythonExe();
        if ($python === null) {
            return ['erreur' => "Lecture des PDF impossible : Python n'est pas installé sur ce PC."] + $vide;
        }
        if (!is_file($chemin) || filesize($chemin) === 0) {
            return ['erreur' => "Fichier PDF vide ou introuvable."] + $vide;
        }
        $script = __DIR__ . '/pdf_tables.py';
        $proc = @proc_open(
            [$python, '-X', 'utf8', '-s', $script, $chemin], // -s : n'utilise que la copie de PyMuPDF du projet (python_libs), pas le profil d'un utilisateur
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['PYTHONIOENCODING' => 'utf-8', 'PYTHONUTF8' => '1', 'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows']
        );
        if (!is_resource($proc)) {
            return ['erreur' => "Impossible de lancer la lecture du PDF."] + $vide;
        }
        stream_set_blocking($pipes[1], false);
        $sortie = '';
        $debut = time();
        while (true) {
            $sortie .= (string) stream_get_contents($pipes[1]);
            $etat = proc_get_status($proc);
            if (!$etat['running']) {
                $sortie .= (string) stream_get_contents($pipes[1]);
                break;
            }
            if (time() - $debut > 90) {
                proc_terminate($proc);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($proc);
                return ['erreur' => "Lecture du PDF trop longue (plus de 90 secondes)."] + $vide;
            }
            usleep(50000);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        // Tolérant : on ne garde que la ligne JSON (un message parasite avant ne doit pas tout casser).
        $debutJson = strpos($sortie, '{"ok"');
        $data = $debutJson === false ? null : json_decode(substr($sortie, $debutJson), true);
        if (!is_array($data)) {
            return ['erreur' => "Réponse illisible du lecteur de PDF."] + $vide;
        }
        if (empty($data['ok'])) {
            return ['erreur' => (string) ($data['erreur'] ?? 'PDF illisible.')] + $vide;
        }
        return ['ok' => true, 'erreur' => null, 'texte' => (string) ($data['texte'] ?? ''), 'tableaux' => $data['tableaux'] ?? [], 'caracteres' => (int) ($data['caracteres'] ?? 0)];
    }
}

if (!function_exists('lignesDeTableauxPdf')) {
    /**
     * Toutes les lignes des vrais tableaux de données d'un PDF déjà lu par extrairePdf() (plusieurs pages mises bout à
     * bout), comme extraireTableauDocx() le fait pour Word. Les petits tableaux de mise en page sont écartés.
     *
     * @return array<int, array<int, string>>
     */
    function lignesDeTableauxPdf(array $pdf): array
    {
        if ($pdf['caracteres'] < 50) {
            throw new RuntimeException("Ce PDF ne contient pas de texte (scan ou photo) : il ne peut pas être lu automatiquement. Envoyer le fichier Word ou Excel d'origine.");
        }
        $lignes = [];
        foreach ($pdf['tableaux'] as $t) {
            if (count($t['lignes']) >= 3) {
                foreach ($t['lignes'] as $l) {
                    $lignes[] = $l;
                }
            }
        }
        if (!$lignes) {
            throw new RuntimeException("Aucun tableau trouvé dans le PDF.");
        }
        return $lignes;
    }
}

if (!function_exists('extraireTableauPdf')) {
    /** @return array<int, array<int, string>> */
    function extraireTableauPdf(string $chemin): array
    {
        $pdf = extrairePdf($chemin);
        if (!$pdf['ok']) {
            throw new RuntimeException($pdf['erreur']);
        }
        return lignesDeTableauxPdf($pdf);
    }
}

if (!function_exists('pdfTexteEcole')) {
    /** « ETABLISSEMENT : <nom> » ou « ECOLE : <nom> » du modèle rempli, sinon null. */
    function pdfTexteEcole(string $texte): ?string
    {
        foreach (preg_split('/\R/u', $texte) as $ligne) {
            if (preg_match('/\b(?:ETABLISSEMENT|ÉTABLISSEMENT|ECOLE|ÉCOLE)\s*:\s*(.+)$/iu', trim($ligne), $m)) {
                $nom = trim($m[1]);
                if ($nom !== '' && stripos($nom, 'CONTACT') !== 0) {
                    return $nom;
                }
            }
        }
        return null;
    }
}

// ==========================================
// RECONNAISSANCE DES VALEURS PAR LEUR CONTENU
// ==========================================

if (!function_exists('pdfEstDate')) {
    function pdfEstDate(string $v): bool
    {
        return preg_match('#^\d{1,2}\s*[/\-.]\s*\d{1,2}\s*[/\-.]\s*\d{2,4}$#', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstMatricule')) {
    /** Matricule DSPS : 6 à 9 chiffres suivis d'une lettre (ex. 27721501Y). */
    function pdfEstMatricule(string $v): bool
    {
        return preg_match('/^\d{6,9}\s?[A-Za-z]$/', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstTelephone')) {
    function pdfEstTelephone(string $v): bool
    {
        return preg_match('#^[\d\s/.\-+]{8,}$#', trim($v)) === 1 && strlen(preg_replace('/\D/', '', $v)) >= 8;
    }
}
if (!function_exists('pdfEstSexe')) {
    function pdfEstSexe(string $v): bool
    {
        return preg_match('/^(M|F|MASCULIN|FEMININ|FÉMININ|GARCON|GARÇON|FILLE)\.?$/iu', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstActe')) {
    function pdfEstActe(string $v): bool
    {
        return preg_match('/^(OUI|NON|SANS\s+EXTRAIT.*|O|N)$/iu', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstFonction')) {
    function pdfEstFonction(string $v): bool
    {
        return preg_match('/^(DIRECT\w*|ADJOINT\w*|ENSEIGNANT\w*|STAGIAIRE\w*|VACATAIRE\w*|MAITRE\w*|MAÎTRE\w*|INSTITUT\w*|DIR\.?|ADJ\.?)\b/iu', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstClasse')) {
    function pdfEstClasse(string $v): bool
    {
        return preg_match('/^(CP1|CP2|CE1|CE2|CM1|CM2|CP|CE|CM)\b/iu', trim($v)) === 1;
    }
}
if (!function_exists('pdfEstCorps')) {
    function pdfEstCorps(string $v): bool
    {
        return preg_match('#^(I[\s.]*[AO]\b|IEPP|IP\b)|^[A-Z]{1,3}\s*/\s*[A-C]\d#iu', trim($v)) === 1;
    }
}

if (!function_exists('pdfCompacter')) {
    /**
     * Une ligne de PDF sans ses cellules vides, dans l'ordre de lecture. Les colonnes d'un PDF sont instables (une
     * même valeur peut se trouver en colonne 3, 6 ou 9 selon la page) : leur POSITION n'a aucune valeur, seul l'ordre
     * et le contenu comptent.
     *
     * @return string[]
     */
    function pdfCompacter(array $ligne): array
    {
        $c = [];
        foreach ($ligne as $cellule) {
            $v = trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', (string) $cellule)));
            if ($v !== '') {
                $c[] = $v;
            }
        }
        return $c;
    }
}

if (!function_exists('pdfLigneDonnees')) {
    /** Une ligne de données commence par un numéro d'ordre suivi d'un nom ; ni les en-têtes ni les lignes vides. */
    function pdfLigneDonnees(array $compacte): bool
    {
        return count($compacte) >= 2 && preg_match('/^\d{1,4}$/', $compacte[0]) === 1;
    }
}

if (!function_exists('pdfEstNationalite')) {
    function pdfEstNationalite(string $v): bool
    {
        $t = retirerAccents(mb_strtoupper(trim($v)));
        return preg_match('/^(IV|CI|IVOIRIEN(NE)?|BURKINAB\w*|MALIEN(NE)?|GUINEEN(NE)?|NIGERIEN(NE)?|NIGERIAN(E)?|TOGOLAIS(E)?|BENINOIS(E)?|GHANEEN(NE)?|LIBERIEN(NE)?|SENEGALAIS(E)?|CAMEROUNAIS(E)?|FRANCAIS(E)?|LIBANAIS(E)?|MAURITANIEN(NE)?|ETRANGER(E)?|AUTRE)$/', $t) === 1;
    }
}
if (!function_exists('pdfLignesDonnees')) {
    /**
     * Les lignes de données d'une liste, chacune sous la forme « cellules non vides, N° en tête ».
     *  - Liste numérotée (la plupart des lignes commencent par un numéro d'ordre) : on garde les lignes « numéro + nom ».
     *  - Liste SANS numéro dans le PDF (numérotation automatique de Word, qui ne s'imprime pas) : on garde les lignes dont
     *    au moins une valeur est reconnaissable (fonction, classe, téléphone ; ou sexe, date, matricule pour les
     *    candidats), en écartant en-têtes, sous-titres (1er EB, 2e EB...) et pieds de page (signature, cachet).
     *
     * @return array<int, string[]> index d'origine de la ligne => cellules
     */
    function pdfLignesDonnees(array $lignes, string $type): array
    {
        $compactes = [];
        foreach ($lignes as $i => $l) {
            $compactes[$i] = pdfCompacter($l);
        }
        $total = 0;
        $numerotees = 0;
        foreach ($compactes as $c) {
            if (count($c) >= 3) {
                $total++;
                if (preg_match('/^\d{1,4}$/', $c[0])) {
                    $numerotees++;
                }
            }
        }
        $numerote = $total > 0 && $numerotees >= 0.5 * $total;

        $sortie = [];
        foreach ($compactes as $i => $c) {
            if ($numerote) {
                if (pdfLigneDonnees($c)) {
                    $sortie[$i] = $c;
                }
                continue;
            }
            if (count($c) < 3 || preg_match('/^(1er|1ère|2e|3e|6e)\b/iu', $c[0])) {
                continue;
            }
            $texte = retirerAccents(mb_strtoupper(implode(' ', $c)));
            if (str_contains($texte, 'NOM') && (str_contains($texte, 'PRENOM') || str_contains($texte, 'MATRICULE') || str_contains($texte, 'FONCTION'))) {
                continue; // ligne d'en-tête
            }
            $reconnue = false;
            foreach ($c as $v) {
                $ok = $type === 'personnel'
                    ? (pdfEstFonction($v) || pdfEstClasse($v) || pdfEstTelephone($v))
                    : (pdfEstSexe($v) || pdfEstDate($v) || pdfEstMatricule($v));
                if ($ok) {
                    $reconnue = true;
                    break;
                }
            }
            if ($reconnue) {
                $sortie[$i] = array_merge(['0'], $c);
            }
        }
        return $sortie;
    }
}

if (!function_exists('typeListePdf')) {
    /** 'personnel', 'candidats' ou null, d'après les en-têtes et le titre. */
    function typeListePdf(array $lignes, string $texte): ?string
    {
        $entete = retirerAccents(mb_strtoupper(implode(' | ', array_merge([], ...array_slice($lignes, 0, 12)))));
        $titre = retirerAccents(mb_strtoupper($texte));
        if (str_contains($entete, 'FONCTION') || str_contains($entete, 'CORPS')) {
            return 'personnel';
        }
        if (str_contains($entete, 'NAI') || str_contains($entete, 'MATRICULE')) {
            return 'candidats';
        }
        if (str_contains($titre, 'LISTE NOMINATIVE DES CANDIDATS')) {
            return 'candidats';
        }
        if (str_contains($titre, 'ENSEIGNANT')) {
            return 'personnel';
        }
        return null;
    }
}

if (!function_exists('mapperLignesPdfCandidats')) {
    /**
     * Même sortie que mapperLignesDocxCandidats : Nom, Prénoms, Sexe, Nationalité, Date naissance, Lieu, Matricule,
     * Acte (O/N), Statut (vide), École, Code DSPS. Aucune position de colonne : chaque valeur est reconnue à son contenu.
     *
     * @param string[] $avertissements rempli avec les lignes douteuses
     */
    function mapperLignesPdfCandidats(array $lignes, string $nomEcole, ?string $codeDsps, array &$avertissements = []): array
    {
        $rows = [];
        foreach (pdfLignesDonnees($lignes, 'candidats') as $i => $c) {
            $nom = $c[1];
            $reste = array_slice($c, 2);
            // Prénoms : la cellule suivante, sauf si elle est déjà un sexe / une date / un matricule (prénoms absents).
            $prenoms = '';
            if ($reste && !pdfEstSexe($reste[0]) && !pdfEstDate($reste[0]) && !pdfEstMatricule($reste[0])) {
                $prenoms = array_shift($reste);
            }
            $sexe = '';
            $nationalite = '';
            $date = '';
            $matricule = '';
            $acte = '';
            $lieu = '';
            foreach ($reste as $v) {
                if ($matricule === '' && pdfEstMatricule($v)) {
                    $matricule = preg_replace('/\s+/', '', $v);
                } elseif ($date === '' && pdfEstDate($v)) {
                    $date = $v;
                } elseif ($sexe === '' && pdfEstSexe($v) && $date === '' && $lieu === '') {
                    $sexe = $v;
                } elseif ($sexe !== '' && $nationalite === '' && $date === '' && $lieu === '' && $matricule === '' && pdfEstSexe($v)) {
                    $nationalite = $v; // certains fichiers recopient « M » dans la colonne nationalité : le Word aussi le lit ainsi
                } elseif ($acte === '' && pdfEstActe($v) && ($sexe !== '' || preg_match('/^(OUI|NON|SANS)/iu', $v))) {
                    $acte = $v;
                } elseif ($nationalite === '' && $date === '' && $lieu === '' && $matricule === '' && pdfEstNationalite($v)) {
                    $nationalite = $v;
                } else {
                    $lieu .= ($lieu === '' ? '' : ' ') . $v;
                }
            }
            // « Sans Extrait » = pas d'acte de naissance.
            $acteCode = preg_match('/^(OUI|O)$/iu', $acte) ? 'OUI' : '';
            if ($sexe === '' && $date === '' && $matricule === '') {
                $avertissements[] = "Ligne " . ($i + 1) . " du PDF : $nom $prenoms — sexe, date et matricule non reconnus, ligne lue avec prudence (à vérifier).";
            }
            $rows[] = [$nom, $prenoms, $sexe, $nationalite, $date, $lieu, $matricule, $acteCode, '', $nomEcole, $codeDsps ?? ''];
        }
        return $rows;
    }
}

if (!function_exists('mapperLignesPdfPersonnel')) {
    /**
     * Même sortie que mapperLignesDocxPersonnel : Nom, Prénoms, Sexe, Contact, Identifiant, Cours tenu, Emploi, Fonction,
     * Disponibilité (vide). Aucune position de colonne.
     *
     * @param string[] $avertissements rempli avec les lignes douteuses
     */
    function mapperLignesPdfPersonnel(array $lignes, array &$avertissements = []): array
    {
        $texteEntete = retirerAccents(mb_strtoupper(implode(' | ', array_merge([], ...array_slice($lignes, 0, 12)))));
        $combine = str_contains($texteEntete, 'NOM & PRENOM') || str_contains($texteEntete, 'NOM ET PRENOM');

        $rows = [];
        foreach (pdfLignesDonnees($lignes, 'personnel') as $i => $c) {
            $sexeBrut = '';
            if ($combine) {
                [$nom, $prenoms, $sexeBrut] = separerNomPrenoms($c[1]);
                $reste = array_slice($c, 2);
            } else {
                $nom = $c[1];
                $reste = array_slice($c, 2);
                $prenoms = '';
                if ($reste && !pdfEstSexe($reste[0]) && !pdfEstFonction($reste[0]) && !pdfEstClasse($reste[0]) && !pdfEstTelephone($reste[0])) {
                    $prenoms = array_shift($reste);
                }
            }
            $n = count($reste);
            // Repère principal : la fonction (Directeur / Adjoint). Avant : sexe, identifiant, corps ; après : classe, contact.
            $idxFonction = null;
            foreach ($reste as $k => $v) {
                if (pdfEstFonction($v)) {
                    $idxFonction = $k;
                    break;
                }
            }
            $fonction = $idxFonction !== null ? $reste[$idxFonction] : '';
            $fin = $idxFonction ?? $n;
            $avant = array_slice($reste, 0, $fin);
            if ($avant && pdfEstSexe($avant[0])) {
                $sexeBrut = array_shift($avant);
            }
            $corps = '';
            if ($avant && pdfEstCorps(end($avant))) {
                $corps = preg_replace('/^I[\s.]*([AO])\b/i', 'I$1', array_pop($avant)); // « I A », « I.O » -> « IA », « IO »
            }
            $identifiant = implode(' ', $avant);

            $classe = '';
            $contact = '';
            foreach (array_slice($reste, $idxFonction !== null ? $idxFonction + 1 : 0) as $v) {
                if ($contact === '' && pdfEstTelephone($v)) {
                    $contact = $v;
                } elseif ($classe === '' && $contact === '' && !pdfEstTelephone($v)) {
                    $classe = $v; // CP1, CM2, mais aussi PS / MS / GS (maternelle), « CP1 A »...
                }
            }
            if ($idxFonction === null) {
                $avertissements[] = "Ligne " . ($i + 1) . " du PDF : $nom $prenoms — fonction (Directeur / Adjoint) non reconnue, lue comme Adjoint (à vérifier).";
            }
            $emploi = deviverEmploiDepuisCorpsGrade($corps) ?? '';
            $rows[] = [$nom, $prenoms, normaliserSexeDocx($sexeBrut), $contact, $identifiant, $classe, $emploi, $fonction, ''];
        }
        return $rows;
    }
}

if (!function_exists('lignesPdfModeleStrict')) {
    /**
     * Mode STRICT, pour les fichiers à colonnes FIXES dont beaucoup de cases sont vides (modèles Écoles, Centres, Notes) :
     * une case vide ne se reconnaît pas par son contenu, donc une colonne décalée rangerait une valeur au mauvais endroit
     * (une note dans la mauvaise matière...). Le PDF n'est accepté que s'il reproduit EXACTEMENT le modèle Excel :
     * en-tête reconnu colonne par colonne et bon nombre de colonnes dans chaque tableau. Sinon : refus avec explication.
     *
     * @param string[] $motsClesEntete mot-clé attendu dans l'en-tête, colonne par colonne (les premières colonnes suffisent)
     * @return array<int, array<int, string>> l'en-tête puis les lignes de données, toutes à $nbColonnes colonnes
     */
    function lignesPdfModeleStrict(string $chemin, int $nbColonnes, array $motsClesEntete): array
    {
        $refus = "Ce PDF ne reproduit pas exactement le modèle Excel attendu (colonnes ou en-tête différents) : il n'est pas lu, pour ne rien ranger au mauvais endroit. Importer le fichier Excel d'origine.";
        $pdf = extrairePdf($chemin);
        if (!$pdf['ok']) {
            throw new RuntimeException($pdf['erreur']);
        }
        if ($pdf['caracteres'] < 50) {
            throw new RuntimeException("Ce PDF ne contient pas de texte (scan ou photo) : il ne peut pas être lu automatiquement. Importer le fichier Excel d'origine.");
        }
        $norm = fn ($t) => retirerAccents(mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', (string) $t))));
        $tableaux = array_values(array_filter($pdf['tableaux'], fn ($t) => count($t['lignes']) >= 2));
        if (!$tableaux) {
            throw new RuntimeException("Aucun tableau trouvé dans le PDF.");
        }

        $sortie = [];
        $entete = null;
        foreach ($tableaux as $idx => $t) {
            $nbCol = count($t['lignes'][0]);
            // Largeur réelle des données : dernière colonne non vide de l'ensemble du tableau.
            $larg = 0;
            foreach ($t['lignes'] as $l) {
                for ($k = count($l) - 1; $k >= 0; $k--) {
                    if (trim((string) $l[$k]) !== '') {
                        $larg = max($larg, $k + 1);
                        break;
                    }
                }
            }
            if ($larg > $nbColonnes || $nbCol < $nbColonnes) {
                throw new RuntimeException($refus);
            }
            if ($idx === 0) {
                // Premier tableau : son en-tête doit correspondre au modèle, colonne par colonne.
                $l0 = array_map($norm, array_slice($t['lignes'][0], 0, $nbColonnes));
                foreach ($motsClesEntete as $i => $mot) {
                    if (!isset($l0[$i]) || !str_contains($l0[$i], $mot)) {
                        throw new RuntimeException($refus);
                    }
                }
                $entete = $l0;
            }
            foreach ($t['lignes'] as $l) {
                $ligne = array_map(fn ($c) => trim((string) $c), array_slice($l, 0, $nbColonnes));
                if (!array_filter($ligne, fn ($c) => $c !== '')) {
                    continue; // ligne vide
                }
                if ($entete !== null && array_map($norm, $ligne) === $entete && $sortie !== []) {
                    continue; // en-tête répété sur une page suivante
                }
                $sortie[] = $ligne;
            }
        }
        return $sortie; // 1ʳᵉ ligne = en-tête
    }
}
