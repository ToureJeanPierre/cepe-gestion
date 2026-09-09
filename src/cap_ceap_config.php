<?php

// Configuration partagée du mini-module CAP/CEAP (candidats.php et les
// générateurs de bordereaux PDF partagent cette même définition).

if (!defined('CAP_CEAP_NATURES')) {
    define('CAP_CEAP_NATURES', [
        'CAP_INTEGRATION_FP' => 'CAP Intégration Fonction Publique',
        'CEAP_TITULARISATION' => 'CEAP Titularisation (titulaire du DIAS)',
        'CEAP_ECRIT' => 'CEAP (épreuve écrite)',
    ]);
}

if (!defined('CAP_CEAP_PIECES')) {
    define('CAP_CEAP_PIECES', [
        'CAP_INTEGRATION_FP' => [
            "Fiche d'inscription + photo",
            "Reçu du droit d'examen",
            "Copie de l'arrêté de nomination",
            "Copie de la décision d'admission au CEAP",
            "Copie du casier judiciaire (moins de 6 mois)",
            "Copie de l'attestation fiche de stage (DPFC)",
            "Reçu original du droit d'examen",
        ],
        'CEAP_TITULARISATION' => [
            "Fiche d'inscription + photo",
            "Reçu du droit d'examen",
            "Copie de la décision de mise en stage dans l'IEPP",
            "Copie du relevé de notes du DIAS",
            "Copie du casier judiciaire (moins de 6 mois)",
            "Copie couleur CNI",
        ],
        'CEAP_ECRIT' => [
            "Fiche d'inscription + photo",
            "Reçu du droit d'examen",
            "Copie originale de l'attestation à usage administratif du BEPC",
            "Copie de la décision portant autorisation d'enseigner",
            "Copie du casier judiciaire (moins de 6 mois)",
        ],
    ]);
}
