<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once __DIR__ . '/../src/cap_ceap_config.php';

$pageTitle = 'CAP / CEAP';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}
if ($anneeLectureSeule && ($_SERVER['REQUEST_METHOD'] === 'POST' || isset($_GET['supprimer']))) {
    die("Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.");
}

// ==========================================
// TRAITEMENT : AJOUT / MODIFICATION
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['ajouter']) || isset($_POST['modifier']))) {
    $nom = trim($_POST['nom'] ?? '');
    $prenoms = trim($_POST['prenoms'] ?? '');
    $nature = $_POST['nature_examen'] ?? '';
    $observations = trim($_POST['observations'] ?? '') ?: 'POUR ATTRIBUTION';

    if (empty($nom) || empty($prenoms) || !array_key_exists($nature, CAP_CEAP_NATURES)) {
        $error = "Nom, prénoms et nature de l'examen sont obligatoires.";
    } else {
        // Clés indexées (0, 1, 2...) plutôt que le texte de la pièce : plus robuste
        // pour un nom de champ de formulaire (apostrophes, "+", espaces...).
        $pieces = [];
        foreach (CAP_CEAP_PIECES[$nature] as $index => $piece) {
            $pieces[$piece] = isset($_POST['piece'][$index]);
        }
        $piecesJson = json_encode($pieces, JSON_UNESCAPED_UNICODE);

        if (isset($_POST['ajouter'])) {
            $stmt = $pdo->prepare("INSERT INTO cap_ceap_candidats (annee_id, nature_examen, nom, prenoms, pieces_json, observations) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$anneeId, $nature, $nom, $prenoms, $piecesJson, $observations]);
        } else {
            $id = (int) $_POST['id'];
            $stmt = $pdo->prepare("UPDATE cap_ceap_candidats SET nature_examen=?, nom=?, prenoms=?, pieces_json=?, observations=? WHERE id=? AND annee_id=?");
            $stmt->execute([$nature, $nom, $prenoms, $piecesJson, $observations, $id, $anneeId]);
        }
        header("Location: cap_ceap.php");
        exit;
    }
}

// ==========================================
// TRAITEMENT : SUPPRESSION
// ==========================================
if (isset($_GET['supprimer'])) {
    $pdo->prepare("DELETE FROM cap_ceap_candidats WHERE id = ? AND annee_id = ?")->execute([(int) $_GET['supprimer'], $anneeId]);
    header("Location: cap_ceap.php");
    exit;
}

// ==========================================
// DONNÉES & AFFICHAGE
// ==========================================
$filtreNature = $_GET['nature'] ?? '';
$sql = "SELECT * FROM cap_ceap_candidats WHERE annee_id = ?";
$params = [$anneeId];
if ($filtreNature && array_key_exists($filtreNature, CAP_CEAP_NATURES)) {
    $sql .= " AND nature_examen = ?";
    $params[] = $filtreNature;
}
$sql .= " ORDER BY nature_examen ASC, nom ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidats = $stmt->fetchAll();

$candidatAModifier = null;
if (isset($_GET['modifier'])) {
    $stmt = $pdo->prepare("SELECT * FROM cap_ceap_candidats WHERE id = ? AND annee_id = ?");
    $stmt->execute([(int) $_GET['modifier'], $anneeId]);
    $candidatAModifier = $stmt->fetch();
}

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>🎓 CAP / CEAP — Candidats aux concours enseignants</h2>
    <div>
        <a href="pdf_bordereau_recapitulatif_cap_ceap.php" target="_blank" class="btn btn-outline-success me-2"><i class="bi bi-file-earmark-pdf"></i> Bordereau récapitulatif</a>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalAjout"><i class="bi bi-plus-circle"></i> Ajouter</button>
    </div>
</div>

<?php if (isset($error)): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<form method="GET" class="row g-3 mb-3">
    <div class="col-md-4">
        <select name="nature" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les natures d'examen</option>
            <?php foreach (CAP_CEAP_NATURES as $code => $libelle): ?>
                <option value="<?= $code ?>" <?= $filtreNature === $code ? 'selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <?php if ($filtreNature): ?>
            <a href="pdf_bordereau_detail_cap_ceap.php?nature=<?= $filtreNature ?>" target="_blank" class="btn btn-outline-primary w-100"><i class="bi bi-file-earmark-pdf"></i> Bordereau détaillé (pièces)</a>
        <?php endif; ?>
    </div>
</form>

<div class="card shadow-sm">
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr><th>Nom</th><th>Prénoms</th><th>Nature de l'examen</th><th>Pièces fournies</th><th>Observations</th><th>Actions</th></tr>
            </thead>
            <tbody>
                <?php foreach ($candidats as $c): ?>
                    <?php
                    $pieces = json_decode($c['pieces_json'] ?? '{}', true) ?: [];
                    $nbFournies = count(array_filter($pieces));
                    $nbTotal = count(CAP_CEAP_PIECES[$c['nature_examen']] ?? []);
                    ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($c['nom']) ?></strong></td>
                        <td><?= htmlspecialchars($c['prenoms']) ?></td>
                        <td><?= htmlspecialchars(CAP_CEAP_NATURES[$c['nature_examen']] ?? $c['nature_examen']) ?></td>
                        <td>
                            <span class="badge bg-<?= $nbFournies === $nbTotal ? 'success' : 'warning text-dark' ?>"><?= $nbFournies ?> / <?= $nbTotal ?></span>
                        </td>
                        <td><?= htmlspecialchars($c['observations']) ?></td>
                        <td>
                            <a href="?modifier=<?= $c['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                            <a href="?supprimer=<?= $c['id'] ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Supprimer ce candidat ?')"><i class="bi bi-trash"></i></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$candidats): ?>
                    <tr><td colspan="6" class="text-muted text-center py-3">Aucun candidat CAP/CEAP enregistré.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal Ajout / Modification -->
<div class="modal fade <?= $candidatAModifier ? 'show' : '' ?>" id="modalAjout" tabindex="-1" style="<?= $candidatAModifier ? 'display:block;background:rgba(0,0,0,0.5)' : '' ?>">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <?php if ($candidatAModifier): ?><input type="hidden" name="id" value="<?= $candidatAModifier['id'] ?>"><?php endif; ?>
            <div class="modal-header">
                <h5 class="modal-title"><?= $candidatAModifier ? 'Modifier' : 'Ajouter' ?> un candidat CAP/CEAP</h5>
                <a href="cap_ceap.php" class="btn-close"></a>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Nom</label>
                    <input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($candidatAModifier['nom'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Prénoms</label>
                    <input type="text" name="prenoms" class="form-control" value="<?= htmlspecialchars($candidatAModifier['prenoms'] ?? '') ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label">Nature de l'examen</label>
                    <select name="nature_examen" id="selectNature" class="form-select" onchange="afficherPieces()" required>
                        <option value="">-- Choisir --</option>
                        <?php foreach (CAP_CEAP_NATURES as $code => $libelle): ?>
                            <option value="<?= $code ?>" <?= ($candidatAModifier['nature_examen'] ?? '') === $code ? 'selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Pièces fournies</label>
                    <?php $piecesActuelles = $candidatAModifier ? (json_decode($candidatAModifier['pieces_json'] ?? '{}', true) ?: []) : []; ?>
                    <?php foreach (CAP_CEAP_PIECES as $nature => $listePieces): ?>
                        <div class="bloc-pieces" data-nature="<?= $nature ?>" style="display:none;">
                            <?php foreach ($listePieces as $index => $piece): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="piece[<?= $index ?>]" id="p_<?= $nature ?>_<?= $index ?>" disabled <?= !empty($piecesActuelles[$piece]) ? 'checked' : '' ?>>
                                    <label class="form-check-label small" for="p_<?= $nature ?>_<?= $index ?>"><?= htmlspecialchars($piece) ?></label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="mb-3">
                    <label class="form-label">Observations</label>
                    <input type="text" name="observations" class="form-control" value="<?= htmlspecialchars($candidatAModifier['observations'] ?? 'POUR ATTRIBUTION') ?>">
                </div>
            </div>
            <div class="modal-footer">
                <a href="cap_ceap.php" class="btn btn-secondary">Annuler</a>
                <button type="submit" name="<?= $candidatAModifier ? 'modifier' : 'ajouter' ?>" class="btn btn-primary">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<script>
function afficherPieces() {
    var nature = document.getElementById('selectNature').value;
    document.querySelectorAll('.bloc-pieces').forEach(function (bloc) {
        var visible = (bloc.dataset.nature === nature);
        bloc.style.display = visible ? '' : 'none';
        bloc.querySelectorAll('input[type="checkbox"]').forEach(function (c) { c.disabled = !visible; });
    });
}
document.addEventListener('DOMContentLoaded', afficherPieces);
</script>

<?php include '../views/layouts/footer.php'; ?>
