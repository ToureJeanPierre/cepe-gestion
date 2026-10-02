<?php

// ==========================================
// NUMÉROS DE TÉLÉPHONE : un numéro par ligne, jamais collés bout à bout
// ==========================================
// Les directeurs saisissent parfois deux numéros dans la même case Contact
// (un par paragraphe, ou séparés par "/", "et", etc.). Lus tels quels, ils se
// retrouvaient collés en une seule chaîne de 20 chiffres, affichée sur deux
// lignes coupées n'importe où. Les numéros ivoiriens ont 10 chiffres : on les
// sépare proprement, on les met en forme (07 08 64 86 95), et on les range
// séparés par " / " (stockage) ; l'affichage met un numéro par ligne.

if (!function_exists('extraireNumerosTelephone')) {
    /**
     * @return string[] numéros formatés, sans doublon
     */
    function extraireNumerosTelephone(?string $brut): array
    {
        $brut = trim((string) $brut);
        if ($brut === '') {
            return [];
        }

        // Séparateurs explicites entre deux numéros.
        $morceaux = preg_split('/[\r\n\/;,|]+|\s+(?:ou|et|OU|ET)\s+/u', $brut, -1, PREG_SPLIT_NO_EMPTY);

        $numeros = [];
        foreach ($morceaux as $morceau) {
            $chiffres = preg_replace('/\D/', '', $morceau);
            if ($chiffres === '') {
                continue;
            }
            // Indicatif pays (+225 / 00225) devant un numéro de 10 chiffres.
            if (strlen($chiffres) >= 13 && str_starts_with($chiffres, '00225')) {
                $chiffres = substr($chiffres, 5);
            } elseif (strlen($chiffres) === 13 && str_starts_with($chiffres, '225')) {
                $chiffres = substr($chiffres, 3);
            }

            // Case Excel numérique : le zéro initial est perdu (0708648695 -> 708648695).
            if (strlen($chiffres) === 9 && in_array($chiffres[0], ['1', '5', '7'], true)) {
                $chiffres = '0' . $chiffres;
            }

            // Plusieurs numéros collés sans séparateur : 20 chiffres = 2 x 10,
            // 30 = 3 x 10 ; anciens numéros à 8 chiffres : 16 = 2 x 8.
            if (strlen($chiffres) > 10 && strlen($chiffres) % 10 === 0) {
                $parts = str_split($chiffres, 10);
            } elseif (strlen($chiffres) > 8 && strlen($chiffres) % 8 === 0) {
                $parts = str_split($chiffres, 8);
            } else {
                $parts = [$chiffres];
            }

            foreach ($parts as $p) {
                // Mise en forme par paires de chiffres (10 ou 8 chiffres) ; toute
                // autre longueur est laissée telle quelle plutôt que devinée.
                $numeros[] = (strlen($p) === 10 || strlen($p) === 8)
                    ? trim(chunk_split($p, 2, ' '))
                    : $p;
            }
        }

        return array_values(array_unique($numeros));
    }
}

if (!function_exists('normaliserTelephone')) {
    // Valeur à enregistrer en base : "07 08 64 86 95 / 01 43 35 62 13".
    function normaliserTelephone(?string $brut): string
    {
        return implode(' / ', extraireNumerosTelephone($brut));
    }
}

if (!function_exists('htmlTelephones')) {
    // Pour l'affichage HTML/PDF : un numéro par ligne. Fonctionne aussi sur
    // d'anciennes valeurs non normalisées, donc l'affichage est correct même
    // avant que les données existantes aient été nettoyées.
    function htmlTelephones(?string $brut, string $vide = '-'): string
    {
        $numeros = extraireNumerosTelephone($brut);
        if (!$numeros) {
            return htmlspecialchars($vide);
        }
        return implode('<br>', array_map(fn ($n) => '<span style="white-space:nowrap;">' . htmlspecialchars($n) . '</span>', $numeros));
    }
}
