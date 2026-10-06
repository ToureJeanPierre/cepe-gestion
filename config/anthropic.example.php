<?php
// Lecture des extraits de naissance par Claude (onglet « Extraits de naissance »).
//
// 1. Copier ce fichier en config/anthropic.php (ce fichier-là n'est jamais versionné).
// 2. Y coller la clé créée sur https://console.anthropic.com (Settings > API keys).
//    Ne JAMAIS envoyer cette clé dans un message ou un e-mail.
// 3. Sur la console, fixer aussi une limite de dépense mensuelle (Settings > Limits) :
//    c'est le garde-fou ultime, en plus du plafond réglable dans l'application.
return [
    'api_key' => 'sk-ant-...COLLER-ICI...',

    // Modèle économique : lit tous les extraits.
    'modele' => 'claude-haiku-4-5',
    // Modèle plus fort : ne relit que les extraits que le premier juge douteux. '' pour le désactiver.
    'modele_secours' => 'claude-sonnet-5-5',

    // Tarifs en dollars par million de jetons (entrée, sortie) : sert au compteur de dépense affiché.
    // À mettre à jour si Anthropic change ses prix.
    'tarifs' => [
        'claude-haiku-4-5' => ['entree' => 1.00, 'sortie' => 5.00],
        'claude-sonnet-5-5' => ['entree' => 2.00, 'sortie' => 10.00],
    ],

    // Taux indicatif pour l'affichage en FCFA.
    'fcfa_par_dollar' => 600,
];
