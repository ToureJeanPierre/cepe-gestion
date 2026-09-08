<?php

// ==========================================
// GROUPES SCOLAIRES : logique de regroupement automatique
// ==========================================
// Règle : un Groupe Scolaire n'existe QUE si au moins 2 écoles partagent
// la même "base" de nom (ex: EPP Azito 1 / EPP Azito 2 / EPP Azito 3).
// Une école seule n'appartient à AUCUN groupe scolaire.
// Le nom du groupe retire les préfixes de type d'école (EPP, EPC, EPU...)
// et s'écrit "Groupe Scolaire <Nom du quartier>".
//
// Partagé entre public/ecoles.php et public/api_bulk_ecoles.php (le recalcul doit
// se faire après tout ajout / import / suppression d'école, où qu'elle ait lieu).

if (!function_exists('extraireBaseEcole')) {
    // Extrait la "base" du nom en retirant le suffixe numérique/alphabétique final
    // (ex: "EPP Azito 1" -> "EPP Azito", "EPP Azito A" -> "EPP Azito")
    function extraireBaseEcole($nom) {
        $base = trim($nom);
        $base = preg_replace('/\s+\d+$/', '', $base);       // enlève " 1", " 2"...
        $base = preg_replace('/\s+[A-Za-z]$/', '', $base);  // enlève " A", " B"...
        return trim($base);
    }
}

if (!function_exists('extraireNomLieu')) {
    // Retire le préfixe de type d'établissement pour ne garder que le nom du lieu
    // (ex: "EPP Azito" -> "Azito", "EPC Rahma" -> "Rahma")
    function extraireNomLieu($base) {
        $prefixes = [
            'ECOLE PRIMAIRE PUBLIQUE', 'ECOLE PRIMAIRE PRIVEE', 'ECOLE PRIMAIRE CATHOLIQUE',
            'GROUPE SCOLAIRE', 'EPP', 'EPC', 'EPU', 'EPRIV', 'EP', 'GS', 'CEG', 'COURS',
        ];
        $resteApresPrefixe = $base;
        foreach ($prefixes as $prefixe) {
            if (stripos(trim($base), $prefixe) === 0) {
                $reste = trim(substr(trim($base), strlen($prefixe)));
                if ($reste !== '') {
                    $resteApresPrefixe = $reste;
                }
                break;
            }
        }
        return $resteApresPrefixe;
    }
}

if (!function_exists('recalculerGroupesScolaires')) {
    // Recalcule le Groupe Scolaire de TOUTES les écoles non verrouillées manuellement.
    // À appeler après chaque ajout / import / suppression d'école.
    function recalculerGroupesScolaires($pdo) {
        $ecoles = $pdo->query("SELECT id, nom, groupe_scolaire_manuel FROM ecoles")->fetchAll();

        // Regroupe les écoles NON verrouillées par leur base de nom
        $paquets = [];
        foreach ($ecoles as $e) {
            if (!empty($e['groupe_scolaire_manuel'])) continue; // on ne touche pas aux ajustements manuels
            $base = extraireBaseEcole($e['nom']);
            $paquets[$base][] = $e['id'];
        }

        foreach ($paquets as $base => $ids) {
            if (count($ids) >= 2) {
                $nomGroupe = 'Groupe Scolaire ' . extraireNomLieu($base);
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $pdo->prepare("UPDATE ecoles SET groupe_scolaire = ? WHERE id IN ($placeholders)")
                    ->execute(array_merge([$nomGroupe], $ids));
            } else {
                // École seule : aucun groupe scolaire
                $pdo->prepare("UPDATE ecoles SET groupe_scolaire = NULL WHERE id = ?")->execute([$ids[0]]);
            }
        }
    }
}
