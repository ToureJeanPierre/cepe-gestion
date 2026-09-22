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

        $tableau = $xpath->query('(//w:tbl)[1]');
        if ($tableau->length === 0) {
            throw new RuntimeException("Aucun tableau trouvé dans le document Word.");
        }

        $lignes = [];
        foreach ($xpath->query('.//w:tr', $tableau->item(0)) as $tr) {
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
