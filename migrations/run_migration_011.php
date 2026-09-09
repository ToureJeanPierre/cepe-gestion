<?php
require_once __DIR__ . '/../config/database.php';

echo "=== Migration 011 : Mini-module CAP/CEAP ===\n\n";

function tableExiste011($pdo, $table) {
    $stmt = $pdo->prepare("SELECT COUNT(*) as cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return $stmt->fetch()['cnt'] > 0;
}

if (tableExiste011($pdo, 'cap_ceap_candidats')) {
    echo "OK - Table 'cap_ceap_candidats' existe déjà\n";
} else {
    $sql = file_get_contents(__DIR__ . '/011_cap_ceap.sql');
    try {
        $pdo->exec($sql);
        echo "OK - Table 'cap_ceap_candidats' créée\n";
    } catch (PDOException $e) {
        echo "ERREUR : " . $e->getMessage() . "\n";
        exit(1);
    }
}

echo "\n=== Migration terminée ===\n";
