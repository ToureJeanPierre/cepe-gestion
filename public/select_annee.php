<?php
require_once '../config/database.php';

$anneeDemandee = isset($_GET['annee_id']) ? (int) $_GET['annee_id'] : null;

if ($anneeDemandee) {
    $stmt = $pdo->prepare("SELECT id FROM annees WHERE id = ? LIMIT 1");
    $stmt->execute([$anneeDemandee]);
    if ($stmt->fetch()) {
        $_SESSION['annee_id'] = $anneeDemandee;
    }
}

$retour = $_GET['retour'] ?? 'index.php';
// On n'autorise que les redirections internes (pas de redirection ouverte)
if (!preg_match('/^[a-zA-Z0-9_\-]+\.php(\?[^\s]*)?$/', $retour)) {
    $retour = 'index.php';
}

header('Location: ' . $retour);
exit;
