<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?? 'Gestion CEPE' ?> - IEPP Yopougon-Niangon</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { font-family: 'Segoe UI', sans-serif; background-color: #f4f6f9; }
        .sidebar {
            min-height: 100vh;
            background: #2c3e50;
            color: white;
            padding-top: 20px;
        }
        .sidebar .nav-link {
            color: #ecf0f1;
            padding: 12px 20px;
            border-radius: 5px;
            margin: 3px 10px;
        }
        .sidebar .nav-link:hover, .sidebar .nav-link.active {
            background: #3498db;
            color: white;
        }
        .sidebar .nav-link i { margin-right: 10px; }
        .main-content { padding: 30px; }
        .card-header { background: #2c3e50; color: white; }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <!-- SIDEBAR / MENU PRINCIPAL -->
        <nav class="col-md-2 sidebar">
            <h4 class="text-center mb-4">🎓 CEPE Gestion</h4>
            <ul class="nav flex-column">
                <li class="nav-item">
                    <a class="nav-link" href="index.php">
                        <i class="bi bi-house-door"></i> Accueil
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="ecoles.php">
                        <i class="bi bi-building"></i> Écoles
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="enseignants.php">
                        <i class="bi bi-person-badge"></i> Enseignants
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="candidats.php">
                        <i class="bi bi-people"></i> Candidats
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="centres.php">
                        <i class="bi bi-geo-alt"></i> Centres
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="plans.php">
                        <i class="bi bi-map"></i> Plans de salle
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="affectations.php">
                        <i class="bi bi-person-check"></i> Affectations
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="documents.php">
                        <i class="bi bi-file-earmark-pdf"></i> Documents
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="sauvegarde.php">
                        <i class="bi bi-hdd"></i> Sauvegarde
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="parametres.php">
                        <i class="bi bi-gear"></i> Paramètres
                    </a>
                </li>
            </ul>
        </nav>

        <!-- CONTENU PRINCIPAL -->
        <main class="col-md-10 main-content">