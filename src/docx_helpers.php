<?php

// ==========================================
// LECTURE D'UN TABLEAU DANS UN DOCUMENT WORD (.docx)
// ==========================================
// Permet d'importer directement les listes .docx envoyées par les
// directeurs d'école (personnel), sans conversion manuelle en Excel —
// un .docx est une archive ZIP contenant du XML, lisible avec les
// extensions PHP natives ZipArchive + DOM (aucune dépendance ajoutée).

if (!function_exists('extraireTableauDocx')) {
    /**
     * Extrait le premier tableau d'un fichier .docx sous forme de lignes de
     * cellules texte (une ligne = un tableau de chaînes, une par cellule).
     *
     * @return array<int, array<int, string>>
     */
    function extraireTableauDocx(string $cheminFichier): array
    {
        $zip = new ZipArchive();
        if ($zip->open($cheminFichier) !== true) {
            throw new RuntimeException("Impossible d'ouvrir le fichier Word (.docx) — fichier corrompu ou invalide.");
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException("Fichier Word invalide : document.xml introuvable dans l'archive.");
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET);

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $tables = $xpath->query('//w:tbl');
        if ($tables->length === 0) {
            throw new RuntimeException("Aucun tableau trouvé dans le document Word.");
        }

        // Certains modèles réels placent un petit tableau d'en-tête (École /
        // Date, cadre de signature...) AVANT le vrai tableau de données —
        // toujours prendre le premier tableau du document produirait alors
        // zéro ligne utile, ou pire, quelques lignes qui passent les
        // contrôles et s'importent comme des données bidon. On prend plutôt
        // le tableau qui a le plus de lignes : la vraie liste (personnel ou
        // candidats) en a toujours nettement plus qu'un tableau de mise en
        // forme.
        $tableau = $tables->item(0);
        $meilleurNbLignes = -1;
        foreach ($tables as $t) {
            $nbLignes = $xpath->query('.//w:tr', $t)->length;
            if ($nbLignes > $meilleurNbLignes) {
                $meilleurNbLignes = $nbLignes;
                $tableau = $t;
            }
        }

        $lignes = [];
        foreach ($xpath->query('.//w:tr', $tableau) as $tr) {
            $cellules = [];
            foreach ($xpath->query('.//w:tc', $tr) as $tc) {
                $morceaux = [];
                foreach ($xpath->query('.//w:t', $tc) as $t) {
                    $morceaux[] = $t->textContent;
                }
                $cellules[] = nettoyerTexteCelluleDocx(implode('', $morceaux));
            }
            $lignes[] = $cellules;
        }

        return $lignes;
    }
}

if (!function_exists('retirerAccents')) {
    /**
     * Retire les accents français courants d'un texte déjà en majuscules
     * (mb_strtoupper seul ne le fait pas : "É" reste "É", différent de "E").
     * Utilisé pour reconnaître un mot-clé d'en-tête ("PRÉNOMS", "ÉCOLE"...)
     * quel que soit l'usage ou non des accents dans le modèle Word réel.
     */
    function retirerAccents(string $texte): string
    {
        return strtr($texte, [
            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'À' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'Î' => 'I', 'Ï' => 'I',
            'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
            'Ç' => 'C',
        ]);
    }
}

if (!function_exists('compterLignesEnteteDocx')) {
    /**
     * Détermine si un tableau importé a 1 ou 2 lignes d'en-tête, en cherchant
     * un mot-clé d'en-tête connu dans la 2ᵉ ligne plutôt qu'en supposant
     * qu'une 1ʳᵉ cellule vide = en-tête : cette dernière heuristique se
     * trompe dès que la colonne N° est une numérotation automatique Word
     * (non lue par extraireTableauDocx, qui ne lit que le texte réel) — dans
     * ce cas la 1ʳᵉ cellule est VIDE aussi pour les vraies lignes de données,
     * pas seulement pour une éventuelle 2ᵉ ligne d'en-tête, et l'ancienne
     * heuristique supprimait alors la toute première personne/candidat du
     * fichier en la prenant pour un en-tête.
     */
    function compterLignesEnteteDocx(array $lignesDocx, array $motsClesEntete): int
    {
        if (count($lignesDocx) < 2) {
            return 1;
        }
        foreach ($lignesDocx[1] as $cellule) {
            $celluleNormalisee = retirerAccents(mb_strtoupper(trim((string) $cellule)));
            foreach ($motsClesEntete as $motCle) {
                if ($celluleNormalisee !== '' && str_contains($celluleNormalisee, $motCle)) {
                    return 2;
                }
            }
        }
        return 1;
    }
}

if (!function_exists('nettoyerTexteCelluleDocx')) {
    /**
     * Nettoie le texte d'une cellule Word : les cellules "vides" d'un modèle
     * contiennent en réalité souvent une espace insécable (U+00A0), invisible
     * à l'écran mais qu'un trim() ordinaire ne reconnaît pas comme vide — une
     * ligne du modèle non remplie par le directeur serait alors traitée comme
     * une vraie ligne de données. Convertie en espace normale avant le trim.
     */
    function nettoyerTexteCelluleDocx(string $texte): string
    {
        return trim(str_replace("\u{00A0}", ' ', $texte));
    }
}
