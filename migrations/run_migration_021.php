<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/telephone_helpers.php';

echo "=== Migration 021 : normalisation des numéros de téléphone ===\n\n";

$cibles = [
    ['personnel', 'telephone'],
    ['ecoles', 'directeur_telephone'],
];

foreach ($cibles as [$table, $colonne]) {
    $lignes = $pdo->query("SELECT id, `$colonne` AS tel FROM `$table` WHERE `$colonne` IS NOT NULL AND `$colonne` <> ''")->fetchAll();
    $maj = $pdo->prepare("UPDATE `$table` SET `$colonne` = ? WHERE id = ?");
    $nb = 0;
    foreach ($lignes as $l) {
        $propre = normaliserTelephone($l['tel']);
        if ($propre !== $l['tel']) {
            echo "  $table #{$l['id']} : \"{$l['tel']}\"  ->  \"$propre\"\n";
            $maj->execute([$propre, $l['id']]);
            $nb++;
        }
    }
    echo "$table.$colonne : $nb ligne(s) corrigée(s) sur " . count($lignes) . "\n\n";
}

echo "=== Migration terminée ===\n";
