<?php
require_once '../config/database.php';

// Si déjà connecté, inutile de repasser par ici.
if (!empty($_SESSION['utilisateur_id'])) {
    header('Location: index.php');
    exit;
}

$erreur = null;

// Premier démarrage de l'application (table utilisateurs vide) : on propose
// de créer le tout premier compte au lieu d'exiger un identifiant/mot de
// passe qui n'existerait nulle part — personne d'autre que la personne
// présente devant l'écran ne peut créer ce compte, puisqu'il faut déjà être
// sur ce poste pour voir ce formulaire.
$aucunUtilisateur = (int) $pdo->query("SELECT COUNT(*) FROM utilisateurs")->fetchColumn() === 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $aucunUtilisateur && isset($_POST['creer_premier_compte'])) {
    $nom = trim($_POST['nom'] ?? '');
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');
    $confirmation = (string) ($_POST['confirmation'] ?? '');

    if ($nom === '' || $identifiant === '' || $motDePasse === '') {
        $erreur = "Tous les champs sont obligatoires.";
    } elseif (strlen($motDePasse) < 8) {
        $erreur = "Le mot de passe doit contenir au moins 8 caractères.";
    } elseif ($motDePasse !== $confirmation) {
        $erreur = "Les deux mots de passe ne correspondent pas.";
    } else {
        $stmt = $pdo->prepare("INSERT INTO utilisateurs (nom, identifiant, mot_de_passe_hash) VALUES (?, ?, ?)");
        $stmt->execute([$nom, $identifiant, password_hash($motDePasse, PASSWORD_DEFAULT)]);

        session_regenerate_id(true);
        $_SESSION['utilisateur_id'] = (int) $pdo->lastInsertId();
        $_SESSION['utilisateur_nom'] = $nom;
        header('Location: index.php');
        exit;
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$aucunUtilisateur && isset($_POST['connexion'])) {
    $identifiant = trim($_POST['identifiant'] ?? '');
    $motDePasse = (string) ($_POST['mot_de_passe'] ?? '');

    $stmt = $pdo->prepare("SELECT id, nom, mot_de_passe_hash, actif FROM utilisateurs WHERE identifiant = ?");
    $stmt->execute([$identifiant]);
    $utilisateur = $stmt->fetch();

    // Message volontairement générique dans les deux cas (identifiant
    // inconnu ou mot de passe faux) : ne pas révéler quels identifiants
    // existent dans l'application.
    if (!$utilisateur || (int) $utilisateur['actif'] !== 1 || !password_verify($motDePasse, $utilisateur['mot_de_passe_hash'])) {
        $erreur = "Identifiant ou mot de passe incorrect.";
    } else {
        session_regenerate_id(true);
        $_SESSION['utilisateur_id'] = (int) $utilisateur['id'];
        $_SESSION['utilisateur_nom'] = $utilisateur['nom'];
        $pdo->prepare("UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?")->execute([$utilisateur['id']]);
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Connexion - Gestion CEPE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --ci-orange: #f58220; --ci-green: #009e49; --primary: #17365d; }
        body {
            margin: 0;
            font-family: 'Inter', 'Segoe UI', Arial, sans-serif;
            background: #f5f7fa;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
        }
        .top-government-bar {
            position: fixed; top: 0; left: 0; right: 0; height: 6px;
            background: linear-gradient(to right, var(--ci-orange) 0%, var(--ci-orange) 33.33%, #fff 33.33%, #fff 66.66%, var(--ci-green) 66.66%, var(--ci-green) 100%);
        }
        .login-card { width: 100%; max-width: 380px; }
        .login-title { color: var(--primary); font-weight: 700; }
        .login-subtitle { color: #6c757d; font-size: 13px; }
        .btn-primary { background: var(--primary); border-color: var(--primary); }
        .btn-primary:hover { background: #102944; border-color: #102944; }
    </style>
</head>
<body>
<div class="top-government-bar"></div>

<div class="card shadow-sm login-card">
    <div class="card-body p-4">
        <div class="text-center mb-4">
            <div class="login-title fs-4">Gestion CEPE</div>
            <div class="login-subtitle">IEPP Yopougon-Niangon — Service Examens et Concours</div>
        </div>

        <?php if ($erreur): ?>
            <div class="alert alert-danger py-2"><?= htmlspecialchars($erreur) ?></div>
        <?php endif; ?>

        <?php if ($aucunUtilisateur): ?>
            <p class="small text-muted">Première utilisation : créez le compte administrateur de l'application.</p>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Nom complet</label>
                    <input type="text" name="nom" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Identifiant</label>
                    <input type="text" name="identifiant" class="form-control" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mot de passe (8 caractères minimum)</label>
                    <input type="password" name="mot_de_passe" class="form-control" required minlength="8">
                </div>
                <div class="mb-3">
                    <label class="form-label">Confirmer le mot de passe</label>
                    <input type="password" name="confirmation" class="form-control" required minlength="8">
                </div>
                <button type="submit" name="creer_premier_compte" class="btn btn-primary w-100">Créer le compte et se connecter</button>
            </form>
        <?php else: ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Identifiant</label>
                    <input type="text" name="identifiant" class="form-control" required autofocus>
                </div>
                <div class="mb-3">
                    <label class="form-label">Mot de passe</label>
                    <input type="password" name="mot_de_passe" class="form-control" required>
                </div>
                <button type="submit" name="connexion" class="btn btn-primary w-100">Se connecter</button>
            </form>
        <?php endif; ?>
    </div>
</div>

</body>
</html>
