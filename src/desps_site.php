<?php

// ==========================================
// VÉRIFICATION SUR LE SITE AGCP / DSPS-MEN (fiche cursus primaire)
// ==========================================
// https://agcp.sigfne.net/edit/fiches-primaire/ : page publique (sans compte) ;
// on y saisit un matricule et elle renvoie la "Fiche cursus scolaire - primaire"
// de l'élève (identité + une ligne par année : IEP, école, niveau, DFA, CE/SN).
// L'application fait cette demande à la place de l'utilisateur, un élève à la
// fois, et en déduit le bilan (cf. dfa_helpers.php).

require_once __DIR__ . '/dfa_helpers.php';

if (!defined('DESPS_URL_FICHE')) {
    define('DESPS_URL_FICHE', 'https://agcp.sigfne.net/edit/edition/');
}

if (!function_exists('bundleCertificatsDesps')) {
    /**
     * Le serveur du site n'envoie que son propre certificat, sans l'intermédiaire
     * Sectigo : les navigateurs le retrouvent seuls, pas PHP. On complète la
     * chaîne avec l'intermédiaire public (config/ca/sectigo_dv_r36.pem, validé
     * contre les racines de confiance) SANS désactiver la vérification HTTPS.
     */
    function bundleCertificatsDesps(): string
    {
        static $bundle = null;
        if ($bundle === null) {
            $racines = (string) ini_get('curl.cainfo');
            $bundle = ($racines !== '' && is_file($racines) ? file_get_contents($racines) : '')
                . "\n" . file_get_contents(__DIR__ . '/../config/ca/sectigo_dv_r36.pem');
        }
        return $bundle;
    }
}

if (!function_exists('appelerFicheCursus')) {
    /**
     * @return array{ok: bool, http: int, erreur: ?string, html: string}
     */
    function appelerFicheCursus(string $matricule): array
    {
        $ch = curl_init(DESPS_URL_FICHE);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['typedoc' => 'cursus', 'matricule' => $matricule]),
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_CAINFO_BLOB => bundleCertificatsDesps(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Gestion CEPE - IEPP Yopougon-Niangon)',
        ]);
        $html = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($html === false || $err !== '') {
            return ['ok' => false, 'http' => $http, 'erreur' => $err !== '' ? $err : 'Aucune réponse du site', 'html' => ''];
        }
        if ($http !== 200) {
            return ['ok' => false, 'http' => $http, 'erreur' => "Le site a répondu HTTP $http", 'html' => (string) $html];
        }
        return ['ok' => true, 'http' => $http, 'erreur' => null, 'html' => (string) $html];
    }
}

if (!function_exists('parserFicheCursus')) {
    /**
     * Lit la page renvoyée par le site.
     *
     * @return array{trouve: bool, formulaire: bool, identite: array<string,string>, cursus: array<int, array{annee:string, iep:string, ecole:string, niveau:string, dfa:string, ce_sn:string}>}
     */
    function parserFicheCursus(string $html): array
    {
        $res = ['trouve' => false, 'formulaire' => str_contains($html, 'Entrez votre numéro matricule') || str_contains($html, 'Entrez votre num'),
            'identite' => [], 'cursus' => []];
        if (trim($html) === '') {
            return $res;
        }
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        $xp = new DOMXPath($dom);

        $texte = fn (DOMNode $n) => trim(preg_replace('/\s+/u', ' ', str_replace("\u{00A0}", ' ', $n->textContent)));

        foreach ($xp->query('//table[contains(@class,"identite")]//tr') as $tr) {
            $tds = $xp->query('./td', $tr);
            if ($tds->length >= 2) {
                $cle = trim(preg_replace('/[\s\-]+$/u', '', $texte($tds->item(0))));
                $res['identite'][$cle] = $texte($tds->item(1));
            }
        }
        foreach ($xp->query('//table[contains(@class,"cursus")]//tbody/tr') as $tr) {
            $tds = $xp->query('./td', $tr);
            if ($tds->length < 4) {
                continue;
            }
            $res['cursus'][] = [
                'annee' => $texte($tds->item(0)),
                'iep' => $texte($tds->item(1)),
                'ecole' => $texte($tds->item(2)),
                'niveau' => $texte($tds->item(3)),
                'dfa' => $tds->length > 4 ? $texte($tds->item(4)) : '',
                'ce_sn' => $tds->length > 5 ? $texte($tds->item(5)) : '',
            ];
        }
        $res['trouve'] = $res['identite'] !== [] && $res['cursus'] !== [];
        return $res;
    }
}

if (!function_exists('derniereLigneCursus')) {
    // Dernière ligne RENSEIGNÉE (les années récentes sans données sont des lignes vides).
    function derniereLigneCursus(array $cursus): ?array
    {
        for ($i = count($cursus) - 1; $i >= 0; $i--) {
            if ($cursus[$i]['ecole'] !== '' || $cursus[$i]['niveau'] !== '') {
                return $cursus[$i];
            }
        }
        return null;
    }
}

if (!function_exists('verifierCandidatSurSite')) {
    /**
     * Interroge le site pour UN candidat, enregistre le résultat et recalcule son bilan.
     *
     * @param array $candidat id, nom, prenoms, date_naissance, matricule_dsps, ecole_id
     * @return array{etat: string, message: string, statut: ?string}  etat : ok | introuvable | erreur | sans_matricule
     */
    function verifierCandidatSurSite(PDO $pdo, array $candidat, int $anneeExamenDebut, array $indexEcoles): array
    {
        $matricule = strtoupper(preg_replace('/\s+/', '', trim((string) ($candidat['matricule_dsps'] ?? ''))));
        if ($matricule === '') {
            return ['etat' => 'sans_matricule', 'message' => 'Pas de matricule : impossible de vérifier', 'statut' => null];
        }

        $rep = appelerFicheCursus($matricule);
        if (!$rep['ok']) {
            // Panne ou blocage du site : surtout PAS "introuvable", on ne touche à rien.
            return ['etat' => 'erreur', 'message' => $rep['erreur'] ?? 'Erreur', 'statut' => null];
        }
        $fiche = parserFicheCursus($rep['html']);

        if (!$fiche['trouve']) {
            if (!$fiche['formulaire']) {
                // Réponse que l'on ne sait pas lire (page modifiée, erreur serveur) : ne rien conclure.
                return ['etat' => 'erreur', 'message' => 'Réponse du site non reconnue (format modifié ?)', 'statut' => null];
            }
            $bilan = enregistrerCursusEtBilan($pdo, $candidat, ['introuvable' => 1, 'source' => 'site'], $anneeExamenDebut, $indexEcoles);
            return ['etat' => 'introuvable', 'message' => 'Matricule introuvable sur le site', 'statut' => $bilan['statut']];
        }

        $derniere = derniereLigneCursus($fiche['cursus']);
        $diff = identiteDifferente($fiche['identite'], $candidat);

        $donnees = [
            'introuvable' => 0, 'source' => 'site',
            'ecole_desps' => $derniere['ecole'] ?? null,
            'classe_desps' => $derniere ? normaliserClasseDesps($derniere['niveau']) : null,
            'annee_debut' => $derniere ? normaliserAnneeDesps($derniere['annee']) : null,
            'identite_desps' => $fiche['identite'], 'cursus_brut' => $fiche['cursus'], 'derniere_dfa' => $derniere['dfa'] ?? null,
        ];
        // Une confirmation "même école" faite à la main reste valable si l'école du site n'a pas changé.
        $ancien = $pdo->prepare("SELECT ecole_desps, ecole_conforme FROM desps_cursus WHERE candidat_id = ?");
        $ancien->execute([$candidat['id']]);
        $a = $ancien->fetch();
        $donnees['ecole_conforme'] = ($a && strcasecmp(trim((string) $a['ecole_desps']), trim((string) $donnees['ecole_desps'])) === 0) ? (int) $a['ecole_conforme'] : 0;

        $bilan = enregistrerCursusEtBilan($pdo, $candidat, $donnees, $anneeExamenDebut, $indexEcoles);
        return ['etat' => 'ok', 'message' => $diff ? 'Vérifié — identité à contrôler' : 'Vérifié', 'statut' => $bilan['statut']];
    }
}
