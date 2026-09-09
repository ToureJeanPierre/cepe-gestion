<?php

// ==========================================
// MATIÈRES & BARÈME DU CEPE (partagé entre resultats.php, les imports/exports
// et les générateurs PDF/Excel)
// ==========================================
// Compositions 1 & 2 : 4 matières -> total /170, diviseur 8.5 -> moyenne /20
// Blancs 1 & 2 + Examen Final : + EPS -> total /190, diviseur 9.5 -> moyenne /20
// (170/8.5 = 20 et 190/9.5 = 20 : le diviseur normalise le total sur 20)

if (!defined('MATIERES_COMPOSITION')) {
    define('MATIERES_COMPOSITION', [
        'Exploitation de texte' => 50,
        'Éveil au milieu' => 50,
        'Orthographe' => 20,
        'Mathématiques' => 50,
    ]);
}

if (!defined('MATIERES_EXAMEN')) {
    define('MATIERES_EXAMEN', [
        'Exploitation de texte' => 50,
        'Éveil au milieu' => 50,
        'Orthographe' => 20,
        'Mathématiques' => 50,
        'EPS' => 20,
    ]);
}

const DIVISEUR_COMPOSITION = 8.5;
const DIVISEUR_EXAMEN = 9.5;
const SEUIL_ADMISSION_CEPE = 10.0; // moyenne /20

if (!function_exists('estExamenTypeComposition')) {
    function estExamenTypeComposition(string $codeExamen): bool
    {
        return in_array($codeExamen, ['COMPO_1', 'COMPO_2'], true);
    }
}

if (!function_exists('matieresPourExamen')) {
    /** @return array<string,int> Nom de la matière => note maximale */
    function matieresPourExamen(string $codeExamen): array
    {
        return estExamenTypeComposition($codeExamen) ? MATIERES_COMPOSITION : MATIERES_EXAMEN;
    }
}

if (!function_exists('diviseurPourExamen')) {
    function diviseurPourExamen(string $codeExamen): float
    {
        return estExamenTypeComposition($codeExamen) ? DIVISEUR_COMPOSITION : DIVISEUR_EXAMEN;
    }
}

if (!function_exists('calculerMoyenne20')) {
    /**
     * Calcule la moyenne /20 à partir des notes par matière déjà obtenues.
     * Retourne null si au moins une matière n'a pas encore de note (moyenne
     * non calculable tant que la saisie n'est pas complète).
     *
     * @param array<string,float|null> $notesParMatiere Matière => note obtenue (ou null)
     */
    function calculerMoyenne20(array $notesParMatiere, string $codeExamen): ?float
    {
        $matieres = matieresPourExamen($codeExamen);
        $total = 0.0;
        foreach ($matieres as $matiere => $max) {
            if (!array_key_exists($matiere, $notesParMatiere) || $notesParMatiere[$matiere] === null) {
                return null;
            }
            $total += (float) $notesParMatiere[$matiere];
        }
        return round($total / diviseurPourExamen($codeExamen), 2);
    }
}
