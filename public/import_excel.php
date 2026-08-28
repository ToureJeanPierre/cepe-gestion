<?php
require_once '../config/database.php';
$pageTitle = 'Importer des écoles depuis CSV/Excel';

$message = '';
$typeMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier_csv'])) {
    $fichier = $_FILES['fichier_csv']['tmp_name'];

    if (!is_uploaded_file($fichier)) {
        $message = "Erreur : Aucun fichier téléchargé.";
        $typeMessage = 'danger';
    } else {
        // Ouverture du fichier CSV
        // On utilise le point-virgule comme séparateur (standard Excel FR)
        if (($handle = fopen($fichier, "r")) !== FALSE) {
            $ligneNum = 0;
            $succes = 0;
            $erreurs = [];

            // Désactiver temporairement les contraintes de clés étrangères pour l'import
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
            
            while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) {
                $ligneNum++;

                // Ignorer la ligne d'en-tête (ligne 1)
                if ($ligneNum === 1) continue;

                // Vérifier si la ligne n'est pas vide
                if (count($data) < 2 || empty(trim($data[0]))) continue;

                try {
                    // Mapping des colonnes Excel vers les variables
                    // Ordre attendu : nom, type_ecole, code_administratif, est_tutrice, nom_officiel, effectif_inscrits, effectif_garcons, effectif_filles, directeur_nom, directeur_telephone
                    $nom = trim($data[0] ?? '');
                    $typeEcole = trim($data[1] ?? 'Laïque');
                    $codeAdmin = trim($data[2] ?? null);
                    $estTutrice = (int)($data[3] ?? 0);
                    $nomOfficiel = trim($data[4] ?? $nom); // Si vide, on prend le nom
                    $effectifInscrits = (int)($data[5] ?? 0);
                    $effectifGarcons = (int)($data[6] ?? 0);
                    $effectifFilles = (int)($data[7] ?? 0);
                    $directeurNom = trim($data[8] ?? '');
                    $directeurTel = trim($data[9] ?? '');

                    // Si nom_officiel est vide dans le CSV, on le génère simplement
                    if (empty($nomOfficiel)) $nomOfficiel = $nom;

                    // Insertion
                    $stmt = $pdo->prepare("
                        INSERT INTO ecoles (
                            nom, type_ecole, code_administratif, est_tutrice, nom_officiel, 
                            effectif_inscrits, effectif_garcons, effectif_filles, 
                            directeur_nom, directeur_telephone
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $nom, $typeEcole, empty($codeAdmin) ? null : $codeAdmin, 
                        $estTutrice, $nomOfficiel, 
                        $effectifInscrits, $effectifGarcons, $effectifFilles, 
                        $directeurNom, $directeurTel
                    ]);

                    $succes++;

                } catch (PDOException $e) {
                    $erreurs[] = "Ligne $ligneNum : " . htmlspecialchars($e->getMessage());
                }
            }

            // Réactiver les contraintes
            $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
            fclose($handle);

            if ($succes > 0) {
                $message = "✅ Import réussi ! <strong>$succes</strong> écoles ajoutées.";
                $typeMessage = 'success';
                if (!empty($erreurs)) {
                    $message .= "<br><small>" . count($erreurs) . " erreurs ignorées.</small>";
                }
            } else {
                $message = "⚠️ Aucune école importée. Vérifiez le format du fichier.";
                $typeMessage = 'warning';
            }
            
            if (!empty($erreurs)) {
                $message .= "<ul>";
                foreach(array_slice($erreurs, 0, 5) as $err) { // Afficher max 5 erreurs
                    $message .= "<li>$err</li>";
                }
                $message .= "</ul>";
            }

        } else {
            $message = "Erreur : Impossible d'ouvrir le fichier.";
            $typeMessage = 'danger';
        }
    }
}

include '../views/layouts/header.php';
?>

<div class="container mt-4">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow">
                <div class="card-header bg-primary text-white">
                    <h4 class="mb-0">📥 Importer des écoles depuis Excel/CSV</h4>
                </div>
                <div class="card-body">
                    
                    <?php if ($message): ?>
                        <div class="alert alert-<?= $typeMessage ?>"><?= $message ?></div>
                    <?php endif; ?>

                    <div class="alert alert-info">
                        <strong>Instructions :</strong>
                        <ol class="mb-0">
                            <li>Préparez votre fichier Excel avec les colonnes exactes suivantes :</li>
                            <code>nom | type_ecole | code_administratif | est_tutrice | nom_officiel | effectif_inscrits | effectif_garcons | effectif_filles | directeur_nom | directeur_telephone</code>
                            <li>La première ligne doit contenir les titres (elle sera ignorée).</li>
                            <li>Enregistrez le fichier au format <strong>CSV (point-virgule)</strong>.</li>
                            <li>Pour <code>est_tutrice</code>, mettez <strong>1</strong> (Vrai) ou <strong>0</strong> (Faux).</li>
                        </ol>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label for="fichier_csv" class="form-label">Sélectionnez votre fichier CSV</label>
                            <input class="form-control" type="file" name="fichier_csv" id="fichier_csv" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-success w-100">
                            <i class="bi bi-upload"></i> Lancer l'importation
                        </button>
                    </form>

                    <hr>
                    <a href="ecoles.php" class="btn btn-secondary">
                        <i class="bi bi-arrow-left"></i> Retour à la gestion des écoles
                    </a>
                </div>
            </div>
            
            <!-- Exemple de tableau à copier dans Excel -->
            <div class="card mt-4 border-secondary">
                <div class="card-header bg-secondary text-white">Exemple de structure à copier dans Excel</div>
                <div class="card-body p-0">
                    <table class="table table-sm table-bordered mb-0 text-small">
                        <thead class="table-light">
                            <tr>
                                <th>nom</th>
                                <th>type_ecole</th>
                                <th>code_administratif</th>
                                <th>est_tutrice</th>
                                <th>nom_officiel</th>
                                <th>effectif_inscrits</th>
                                <th>...</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>EPP KALAMBA</td>
                                <td>Laïque</td>
                                <td>EC001</td>
                                <td>1</td>
                                <td>EPP KALAMBA</td>
                                <td>450</td>
                                <td>...</td>
                            </tr>
                            <tr>
                                <td>EPP KALAMBA 1</td>
                                <td>Laïque</td>
                                <td></td>
                                <td>0</td>
                                <td>EPP KALAMBA</td>
                                <td>200</td>
                                <td>...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>