<?php
require_once '../config/database.php';

$pageTitle = 'Sauvegarde';

$success = null;
$error = null;

// Dossier de sauvegardes, en dehors du dossier public (jamais accessible
// directement par une URL) — les fichiers ne sont servis qu'à travers ce
// script, après vérification.
$dossierSauvegardes = __DIR__ . '/../backups';
if (!is_dir($dossierSauvegardes)) {
    mkdir($dossierSauvegardes, 0777, true);
}

const MOTIF_NOM_SAUVEGARDE = '/^cepe_gestion_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}\.sql$/';

/**
 * Chemin du binaire mysqldump fourni par Laragon (pas sur le PATH système,
 * donc appelé par son chemin complet). Retourne null si introuvable, pour
 * que la page affiche une erreur claire plutôt qu'un échec silencieux.
 */
function trouverMysqldump(): ?string
{
    $candidats = glob('C:/laragon/bin/mysql/*/bin/mysqldump.exe');
    return $candidats ? $candidats[0] : null;
}

// ==========================================
// TRAITEMENT : CRÉER UNE SAUVEGARDE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['creer_sauvegarde'])) {
    $mysqldump = trouverMysqldump();

    if (!$mysqldump) {
        $error = "mysqldump introuvable sur ce poste : la sauvegarde ne peut pas être générée.";
    } else {
        global $host, $db, $user, $pass;

        $nomFichier = 'cepe_gestion_' . date('Y-m-d_H-i-s') . '.sql';
        $cheminFichier = $dossierSauvegardes . '/' . $nomFichier;

        $commande = escapeshellarg($mysqldump)
            . ' -h ' . escapeshellarg($host)
            . ' -u ' . escapeshellarg($user)
            . (!empty($pass) ? ' -p' . escapeshellarg($pass) : '')
            . ' ' . escapeshellarg($db)
            . ' > ' . escapeshellarg($cheminFichier)
            . ' 2>&1';

        exec($commande, $sortie, $codeRetour);

        if ($codeRetour !== 0 || !file_exists($cheminFichier) || filesize($cheminFichier) === 0) {
            if (file_exists($cheminFichier)) {
                unlink($cheminFichier);
            }
            $error = "Échec de la sauvegarde : " . implode(' ', $sortie);
        } else {
            $success = "Sauvegarde créée : $nomFichier (" . round(filesize($cheminFichier) / 1024, 1) . " Ko).";
        }
    }
}

// ==========================================
// TRAITEMENT : SUPPRIMER UNE SAUVEGARDE
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['supprimer_sauvegarde'])) {
    $nom = basename($_POST['fichier'] ?? '');
    if (preg_match(MOTIF_NOM_SAUVEGARDE, $nom)) {
        $chemin = $dossierSauvegardes . '/' . $nom;
        if (file_exists($chemin)) {
            unlink($chemin);
            $success = "Sauvegarde supprimée : $nom.";
        }
    } else {
        $error = "Nom de fichier invalide.";
    }
}

// ==========================================
// TÉLÉCHARGEMENT (sert le fichier lui-même, hors dossier public)
// ==========================================
if (isset($_GET['telecharger'])) {
    $nom = basename($_GET['telecharger']);
    if (!preg_match(MOTIF_NOM_SAUVEGARDE, $nom)) {
        http_response_code(400);
        die("Nom de fichier invalide.");
    }
    $chemin = $dossierSauvegardes . '/' . $nom;
    if (!file_exists($chemin)) {
        http_response_code(404);
        die("Sauvegarde introuvable.");
    }
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $nom . '"');
    header('Content-Length: ' . filesize($chemin));
    readfile($chemin);
    exit;
}

// ==========================================
// LISTE DES SAUVEGARDES EXISTANTES
// ==========================================
$sauvegardes = [];
foreach (glob($dossierSauvegardes . '/cepe_gestion_*.sql') as $chemin) {
    $nom = basename($chemin);
    if (preg_match(MOTIF_NOM_SAUVEGARDE, $nom)) {
        $sauvegardes[] = [
            'nom' => $nom,
            'taille' => filesize($chemin),
            'date' => filemtime($chemin),
        ];
    }
}
usort($sauvegardes, fn ($a, $b) => $b['date'] <=> $a['date']);

$mysqldumpDisponible = trouverMysqldump() !== null;

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-archive"></i> Sauvegarde</h2>
    <form method="POST">
        <button type="submit" name="creer_sauvegarde" class="btn btn-primary" <?= $mysqldumpDisponible ? '' : 'disabled' ?>>
            <i class="bi bi-download"></i> Créer une sauvegarde maintenant
        </button>
    </form>
</div>

<?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!$mysqldumpDisponible): ?>
    <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> L'outil de sauvegarde (mysqldump) n'a pas été trouvé sur ce poste.</div>
<?php endif; ?>

<div class="alert alert-light border small">
    <strong>À quoi sert cette page :</strong> génère une copie complète de la base de données (toutes les écoles, tous les candidats, toutes les notes, etc.) dans un fichier unique, que tu peux télécharger et conserver ailleurs (clé USB, autre ordinateur). En cas de problème sur ce poste, ce fichier permet de tout restaurer.
    Les sauvegardes sont stockées sur ce PC, en dehors du dossier accessible par le navigateur — seul ce bouton "Télécharger" y donne accès.
</div>

<div class="card shadow-sm">
    <div class="card-header"><strong>Sauvegardes disponibles</strong> <span class="text-muted">(<?= count($sauvegardes) ?>)</span></div>
    <div class="card-body p-0 table-responsive tableau-scrollable">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Fichier</th>
                    <th>Date</th>
                    <th>Taille</th>
                    <th class="text-center" style="width:220px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($sauvegardes)): ?>
                    <tr><td colspan="4" class="text-center text-muted py-4">Aucune sauvegarde pour l'instant.</td></tr>
                <?php endif; ?>
                <?php foreach ($sauvegardes as $s): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($s['nom']) ?></code></td>
                        <td><?= date('d/m/Y à H:i', $s['date']) ?></td>
                        <td><?= round($s['taille'] / 1024, 1) ?> Ko</td>
                        <td class="text-center">
                            <a href="sauvegarde.php?telecharger=<?= urlencode($s['nom']) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-download"></i> Télécharger</a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette sauvegarde ?');">
                                <input type="hidden" name="fichier" value="<?= htmlspecialchars($s['nom']) ?>">
                                <button type="submit" name="supprimer_sauvegarde" class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
