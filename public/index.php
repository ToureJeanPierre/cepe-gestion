<?php
require_once '../config/database.php';
$pageTitle = 'Accueil';
include '../views/layouts/header.php';
?>

<div class="card shadow">
    <div class="card-header">
        <h3> Bienvenue - Application de Gestion du CEPE</h3>
        <p class="mb-0">IEPP Yopougon-Niangon - Service Examens et Concours</p>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-3">
                <div class="card text-center bg-primary text-white">
                    <div class="card-body">
                        <h5>🏫 Écoles</h5>
                        <h2>-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center bg-success text-white">
                    <div class="card-body">
                        <h5>👨‍🏫 Enseignants</h5>
                        <h2>-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center bg-warning text-white">
                    <div class="card-body">
                        <h5>👥 Candidats</h5>
                        <h2>-</h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center bg-info text-white">
                    <div class="card-body">
                        <h5>📍 Centres</h5>
                        <h2>-</h2>
                    </div>
                </div>
            </div>
        </div>
        <div class="alert alert-info mt-4">
            <strong>💡 Prochaine étape :</strong> Commencez par ajouter vos écoles dans le menu "Écoles".
        </div>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>