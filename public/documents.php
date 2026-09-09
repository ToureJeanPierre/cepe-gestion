<?php

require_once '../config/database.php';

$pageTitle = 'Documents & Statistiques';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$stmt = $pdo->prepare("SELECT id, code, libelle, ordre FROM examens WHERE annee_id = ? AND actif = 1 ORDER BY ordre ASC");
$stmt->execute([$anneeId]);
$examens = $stmt->fetchAll();

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : (int) ($examens[0]['id'] ?? 0);

/*
|--------------------------------------------------------------------------
| STATISTIQUES (règle Module 8 du cahier des charges)
|--------------------------------------------------------------------------
*/

// Répartition candidats H/F, immatriculés/non-immatriculés/sans extrait, sur l'année consultée
$stmtRepart = $pdo->prepare("
    SELECT
        SUM(CASE WHEN sexe = 'F' THEN 1 ELSE 0 END) AS filles,
        SUM(CASE WHEN sexe = 'M' THEN 1 ELSE 0 END) AS garcons,
        SUM(CASE WHEN matricule_dsps IS NOT NULL AND matricule_dsps != '' THEN 1 ELSE 0 END) AS immatricules,
        SUM(CASE WHEN (matricule_dsps IS NULL OR matricule_dsps = '') AND a_acte_naissance = 1 THEN 1 ELSE 0 END) AS non_immatricules,
        SUM(CASE WHEN (matricule_dsps IS NULL OR matricule_dsps = '') AND a_acte_naissance = 0 THEN 1 ELSE 0 END) AS sans_extrait,
        COUNT(*) AS total
    FROM candidats WHERE annee_id = ?
");
$stmtRepart->execute([$anneeId]);
$repartCandidats = $stmtRepart->fetch();

// Taux de réussite par secteur (Public/Privé), basé sur l'examen sélectionné
const SEUIL_ADMISSION_DOC = 10.0;
$reussiteParSecteur = [];
if ($examenId) {
    $stmt = $pdo->prepare("
        SELECT e.statut,
            COUNT(*) AS total_notes,
            SUM(CASE WHEN n.note >= ? THEN 1 ELSE 0 END) AS admis
        FROM candidats c
        INNER JOIN ecoles e ON e.id = c.ecole_id
        INNER JOIN notes n ON n.candidat_id = c.id AND n.examen_id = ?
        WHERE c.annee_id = ? AND n.note IS NOT NULL
        GROUP BY e.statut
    ");
    $stmt->execute([SEUIL_ADMISSION_DOC, $examenId, $anneeId]);
    $reussiteParSecteur = $stmt->fetchAll();
}

// Bilan de couverture du personnel encadrant
$stmtPersonnel = $pdo->query("
    SELECT categorie, COUNT(*) AS nb
    FROM personnel
    GROUP BY categorie
");
$personnelParCategorie = ['enseignant' => 0, 'conseiller' => 0, 'administratif' => 0];
foreach ($stmtPersonnel->fetchAll() as $row) {
    $personnelParCategorie[$row['categorie']] = (int) $row['nb'];
}

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>📄 Documents & Statistiques</h2>
</div>

<form method="GET" class="row g-3 mb-4 align-items-end">
    <div class="col-md-5">
        <label class="form-label">Examen (pour les documents liés à un examen)</label>
        <select name="examen_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($examens as $e): ?>
                <option value="<?= (int) $e['id'] ?>" <?= $examenId === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['libelle']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header">📚 Référentiels</div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="pdf_ecoles.php" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Répertoire des écoles (PDF)</a>
                <a href="pdf_annuaire_personnel.php" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Annuaire du personnel (PDF)</a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header">🏛️ Examens & Logistique — <?= htmlspecialchars($examens[array_search($examenId, array_column($examens, 'id'))]['libelle'] ?? '') ?></div>
            <div class="card-body d-flex flex-column gap-2">
                <a href="pdf_annexe_repartition.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Annexe — Répartition des candidats par centre (PDF)</a>
                <a href="pdf_plans_salle.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Plans de salle par centre (PDF)</a>
                <a href="pdf_emargement.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Listes d'émargement par salle (PDF)</a>
                <a href="pdf_liste_acteurs.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Liste des acteurs du centre (PDF)</a>
                <a href="pdf_mission_supervision.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Missions de supervision (PDF)</a>
                <a href="liste_saisie_pdf.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Listes de saisie des notes (PDF)</a>
                <a href="pdf_statistiques_examen.php?examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary text-start"><i class="bi bi-file-earmark-pdf"></i> Statistiques de l'examen (PDF)</a>
                <a href="export_dsps.php?examen_id=<?= $examenId ?>" class="btn btn-outline-success text-start"><i class="bi bi-file-earmark-excel"></i> Export résultats DSPS (Excel)</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header">✉️ Convocations individuelles (modèle national DECO) — <?= htmlspecialchars($examens[array_search($examenId, array_column($examens, 'id'))]['libelle'] ?? '') ?></div>
            <div class="card-body">
                <p class="small text-muted">Les convocations Surveillant / Chef de centre / Secrétariat de composition sont pré-remplies à partir des affectations enregistrées. Correcteur / Harmonisateur / Secrétariat de correction ne sont pas encore rattachés à une donnée dans l'appli (pas de module Correction) : le formulaire est généré vierge, à dupliquer et compléter à la main comme aujourd'hui.</p>
                <div class="d-flex flex-wrap gap-2">
                    <a href="pdf_convocation.php?type=surveillant&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Surveillant</a>
                    <a href="pdf_convocation.php?type=chef_centre&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Chef de centre</a>
                    <a href="pdf_convocation.php?type=secretariat_composition&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-primary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Secrétariat de composition</a>
                    <a href="pdf_convocation.php?type=correcteur&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Correcteur (vierge)</a>
                    <a href="pdf_convocation.php?type=harmonisateur&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Harmonisateur (vierge)</a>
                    <a href="pdf_convocation.php?type=secretariat_correction&examen_id=<?= $examenId ?>" target="_blank" class="btn btn-outline-secondary btn-sm"><i class="bi bi-file-earmark-pdf"></i> Secrétariat de correction (vierge)</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-header">📊 Statistiques — Candidats (année scolaire consultée)</div>
    <div class="card-body">
        <div class="row text-center g-3">
            <div class="col-md-2"><h6>Total</h6><h4><?= (int) ($repartCandidats['total'] ?? 0) ?></h4></div>
            <div class="col-md-2"><h6>Filles</h6><h4><?= (int) ($repartCandidats['filles'] ?? 0) ?></h4></div>
            <div class="col-md-2"><h6>Garçons</h6><h4><?= (int) ($repartCandidats['garcons'] ?? 0) ?></h4></div>
            <div class="col-md-2"><h6>Immatriculés</h6><h4 class="text-success"><?= (int) ($repartCandidats['immatricules'] ?? 0) ?></h4></div>
            <div class="col-md-2"><h6>Non-immatriculés</h6><h4 class="text-warning"><?= (int) ($repartCandidats['non_immatricules'] ?? 0) ?></h4></div>
            <div class="col-md-2"><h6>Sans extrait</h6><h4 class="text-danger"><?= (int) ($repartCandidats['sans_extrait'] ?? 0) ?></h4></div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header">🎯 Taux de réussite par secteur</div>
            <div class="card-body">
                <?php if (!$reussiteParSecteur): ?>
                    <p class="text-muted mb-0">Aucune note saisie pour cet examen.</p>
                <?php else: ?>
                    <table class="table table-sm">
                        <thead><tr><th>Secteur</th><th>Notes saisies</th><th>Admis</th><th>Taux</th></tr></thead>
                        <tbody>
                            <?php foreach ($reussiteParSecteur as $r): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['statut']) ?></td>
                                    <td><?= (int) $r['total_notes'] ?></td>
                                    <td><?= (int) $r['admis'] ?></td>
                                    <td><?= $r['total_notes'] > 0 ? round($r['admis'] / $r['total_notes'] * 100, 1) : 0 ?>%</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header">👥 Bilan de couverture du personnel encadrant</div>
            <div class="card-body">
                <ul class="list-unstyled mb-0">
                    <li>👨‍🏫 Enseignants : <strong><?= $personnelParCategorie['enseignant'] ?></strong></li>
                    <li>🧑‍💼 Conseillers : <strong><?= $personnelParCategorie['conseiller'] ?></strong></li>
                    <li>🗂️ Personnel administratif : <strong><?= $personnelParCategorie['administratif'] ?></strong></li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
