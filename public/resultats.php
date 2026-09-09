<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once '../config/database.php';
require_once '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$pageTitle = 'Résultats & Notes';

// Seuil d'admission CEPE (moyenne sur 20). À ajuster si un barème officiel différent
// est communiqué avec la trame DSPS (point laissé "à plus tard" par l'utilisateur).
const SEUIL_ADMISSION = 10.0;

$success = null;
$error = null;

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

if ($anneeLectureSeule && $_SERVER['REQUEST_METHOD'] === 'POST') {
    die("Cette année scolaire est archivée (lecture seule) : aucune modification n'est autorisée.");
}

/*
|--------------------------------------------------------------------------
| EXAMENS DE L'ANNÉE
|--------------------------------------------------------------------------
*/
$stmt = $pdo->prepare("SELECT id, code, libelle, ordre FROM examens WHERE annee_id = ? AND actif = 1 ORDER BY ordre ASC");
$stmt->execute([$anneeId]);
$examens = $stmt->fetchAll();

if (!$examens) {
    die("Aucun examen configuré pour cette année scolaire.");
}

$examenId = isset($_POST['examen_id']) ? (int) $_POST['examen_id'] : (isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : (int) $examens[0]['id']);
$examenActif = null;
foreach ($examens as $e) {
    if ((int) $e['id'] === $examenId) {
        $examenActif = $e;
        break;
    }
}
if (!$examenActif) {
    $examenActif = $examens[0];
    $examenId = (int) $examenActif['id'];
}
$estFinal = $examenActif['code'] === 'CEPE_FINAL';
$estComposition = in_array($examenActif['code'], ['COMPO_1', 'COMPO_2'], true);

/*
|--------------------------------------------------------------------------
| TRAITEMENT : SAISIE MANUELLE (par lot)
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enregistrer_notes'])) {
    $notesPost = $_POST['note'] ?? [];
    $absentsPost = $_POST['absent'] ?? [];

    $stmtUpsert = $pdo->prepare("
        INSERT INTO notes (candidat_id, examen_id, note, present, source)
        VALUES (?, ?, ?, ?, 'saisie')
        ON DUPLICATE KEY UPDATE note = VALUES(note), present = VALUES(present), source = 'saisie'
    ");

    $nb = 0;
    foreach ($notesPost as $candidatId => $valeurNote) {
        $candidatId = (int) $candidatId;
        if ($candidatId <= 0) continue;

        $estAbsent = isset($absentsPost[$candidatId]);
        $note = ($estAbsent || $valeurNote === '') ? null : round((float) str_replace(',', '.', $valeurNote), 2);
        if ($note !== null) {
            $note = max(0, min(20, $note));
        }

        $stmtUpsert->execute([$candidatId, $examenId, $note, $estAbsent ? 0 : 1]);
        $nb++;
    }

    header("Location: resultats.php?examen_id=$examenId&msg=" . urlencode("$nb note(s) enregistrée(s)."));
    exit;
}

/*
|--------------------------------------------------------------------------
| TRAITEMENT : IMPORTATION EXCEL DES NOTES
|--------------------------------------------------------------------------
*/
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['importer_notes'])) {
    if (isset($_FILES['fichier_notes']) && $_FILES['fichier_notes']['error'] === 0) {
        try {
            $spreadsheet = IOFactory::load($_FILES['fichier_notes']['tmp_name']);
            $rows = $spreadsheet->getActiveSheet()->toArray();
            array_shift($rows); // Saute l'en-tête

            $nbMajs = 0;
            $erreurs = [];

            $stmtUpsert = $pdo->prepare("
                INSERT INTO notes (candidat_id, examen_id, note, present, source)
                VALUES (?, ?, ?, ?, 'import')
                ON DUPLICATE KEY UPDATE note = VALUES(note), present = VALUES(present), source = 'import'
            ");

            foreach ($rows as $index => $row) {
                $numLigne = $index + 2;
                try {
                    // Colonnes attendues : A:Matricule DSPS (ou vide), B:Nom, C:Prénoms, D:Note (/20), E:Présent(O/N)
                    $matricule = trim($row[0] ?? '');
                    $nom = trim($row[1] ?? '');
                    $prenoms = trim($row[2] ?? '');
                    $noteRaw = trim((string) ($row[3] ?? ''));
                    $presentRaw = strtoupper(trim($row[4] ?? 'O'));
                    $present = !in_array($presentRaw, ['N', 'NON', '0'], true) ? 1 : 0;
                    $note = ($present && $noteRaw !== '') ? max(0, min(20, round((float) str_replace(',', '.', $noteRaw), 2))) : null;

                    $candidat = null;
                    if (!empty($matricule)) {
                        $stmtC = $pdo->prepare("SELECT id FROM candidats WHERE annee_id = ? AND matricule_dsps = ?");
                        $stmtC->execute([$anneeId, $matricule]);
                        $candidat = $stmtC->fetch();
                    }
                    if (!$candidat && !empty($nom)) {
                        $stmtC = $pdo->prepare("SELECT id FROM candidats WHERE annee_id = ? AND LOWER(TRIM(nom)) = LOWER(TRIM(?)) AND LOWER(TRIM(prenoms)) = LOWER(TRIM(?))");
                        $stmtC->execute([$anneeId, $nom, $prenoms]);
                        $candidat = $stmtC->fetch();
                    }

                    if (!$candidat) {
                        $erreurs[] = "Ligne $numLigne : candidat introuvable ($nom $prenoms / matricule $matricule).";
                        continue;
                    }

                    $stmtUpsert->execute([$candidat['id'], $examenId, $note, $present]);
                    $nbMajs++;
                } catch (Exception $e) {
                    $erreurs[] = "Ligne $numLigne : " . $e->getMessage();
                }
            }

            $msg = "Import terminé : $nbMajs note(s) enregistrée(s).";
            if ($erreurs) {
                $msg .= " <br><small>" . count($erreurs) . " erreur(s) (voir détail ci-dessous).</small>";
                $_SESSION['import_erreurs_notes'] = $erreurs;
            } else {
                unset($_SESSION['import_erreurs_notes']);
            }
            header("Location: resultats.php?examen_id=$examenId&msg=" . urlencode($msg));
            exit;
        } catch (Exception $e) {
            $error = "Erreur critique : " . $e->getMessage();
        }
    }
}

/*
|--------------------------------------------------------------------------
| LISTE DES CANDIDATS DE CET EXAMEN (groupés par école, libres en dernier)
|--------------------------------------------------------------------------
| Compositions 1 & 2 et Examens Blancs : uniquement les candidats officiels
| validés. Examen Final : + les candidats libres affectés (règle du cahier
| des charges — les libres ne participent qu'à l'Examen Final).
|--------------------------------------------------------------------------
*/
$filtreEcole = $_GET['ecole_id'] ?? '';
$filtreRecherche = $_GET['q'] ?? '';

$sql = "
    SELECT c.id, c.nom, c.prenoms, c.matricule_dsps, c.est_candidat_libre, c.ecole_id,
           COALESCE(e.nom, 'Candidats Libres') AS nom_ecole,
           n.note, n.present
    FROM candidats c
    LEFT JOIN ecoles e ON e.id = c.ecole_id
    LEFT JOIN notes n ON n.candidat_id = c.id AND n.examen_id = ?
    WHERE c.annee_id = ?
      AND (
            (c.est_candidat_libre = 0 AND c.matricule_verifie = 1 AND c.droits_payes = 1)
         OR (c.est_candidat_libre = 1 AND ? = 1)
      )
";
$params = [$examenId, $anneeId, $estFinal ? 1 : 0];

if ($filtreEcole) {
    $sql .= " AND c.ecole_id = ?";
    $params[] = $filtreEcole;
}
if ($filtreRecherche) {
    $sql .= " AND (c.nom LIKE ? OR c.prenoms LIKE ? OR c.matricule_dsps LIKE ?)";
    $t = "%$filtreRecherche%";
    $params[] = $t; $params[] = $t; $params[] = $t;
}

$sql .= " ORDER BY c.est_candidat_libre ASC, nom_ecole ASC, c.nom ASC, c.prenoms ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidats = $stmt->fetchAll();

$groupes = [];
foreach ($candidats as $c) {
    $groupes[$c['nom_ecole']][] = $c;
}

// Statistiques sur l'ensemble filtré
$total = count($candidats);
$presents = 0;
$admis = 0;
foreach ($candidats as $c) {
    if ($c['note'] !== null) {
        $presents++;
        if ((float) $c['note'] >= SEUIL_ADMISSION) {
            $admis++;
        }
    }
}
$tauxReussite = $presents > 0 ? round(($admis / $presents) * 100, 1) : 0;

// Liste des écoles pour le filtre
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom ASC")->fetchAll();

include '../views/layouts/header.php';
?>

<?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($_GET['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($_SESSION['import_erreurs_notes'])): ?>
    <div class="alert alert-warning">
        <button class="btn btn-sm btn-outline-dark mb-2" type="button" data-bs-toggle="collapse" data-bs-target="#detailErreursNotes">
            <i class="bi bi-list-ul"></i> Voir le détail des <?= count($_SESSION['import_erreurs_notes']) ?> erreurs
        </button>
        <div class="collapse" id="detailErreursNotes">
            <ul class="mb-0 small"><?php foreach ($_SESSION['import_erreurs_notes'] as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?></ul>
        </div>
    </div>
    <?php unset($_SESSION['import_erreurs_notes']); ?>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?= htmlspecialchars($error) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>📊 Résultats & Notes</h2>
    <div>
        <button class="btn btn-success me-2" data-bs-toggle="modal" data-bs-target="#modalImportNotes"><i class="bi bi-file-earmark-excel"></i> Importer Excel</button>
        <a href="liste_saisie_pdf.php?examen_id=<?= $examenId ?><?= $filtreEcole ? '&ecole_id=' . (int) $filtreEcole : '' ?>" class="btn btn-outline-primary me-2" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Listes de saisie (PDF)</a>
        <a href="export_dsps.php?examen_id=<?= $examenId ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Export DSPS</a>
    </div>
</div>

<!-- Sélection de l'examen -->
<form method="GET" class="row g-3 mb-3 align-items-end">
    <div class="col-md-5">
        <label class="form-label">Examen</label>
        <select name="examen_id" class="form-select" onchange="this.form.submit()">
            <?php foreach ($examens as $e): ?>
                <option value="<?= (int) $e['id'] ?>" <?= $examenId === (int) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['libelle']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">École</label>
        <select name="ecole_id" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les écoles</option>
            <?php foreach ($ecoles as $e): ?>
                <option value="<?= (int) $e['id'] ?>" <?= (string) $filtreEcole === (string) $e['id'] ? 'selected' : '' ?>><?= htmlspecialchars($e['nom']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label">Recherche</label>
        <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($filtreRecherche) ?>" placeholder="Nom, matricule...">
    </div>
</form>

<?php if ($estComposition): ?>
    <div class="alert alert-info"><i class="bi bi-info-circle"></i> Composition organisée par les écoles : l'IEPP reçoit et saisit ici les notes transmises par les directeurs.</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-md-3"><?php statCard('bi-people', 'navy', (string) $total, 'Total'); ?></div>
    <div class="col-md-3"><?php statCard('bi-pencil-square', 'blue', (string) $presents, 'Notes saisies'); ?></div>
    <div class="col-md-3"><?php statCard('bi-patch-check', 'green', (string) $admis, 'Admis (≥ ' . SEUIL_ADMISSION . '/20)'); ?></div>
    <div class="col-md-3"><?php statCard('bi-graph-up', 'orange', $tauxReussite . '%', 'Taux de réussite'); ?></div>
</div>

<form method="POST">
<input type="hidden" name="examen_id" value="<?= $examenId ?>">

<?php foreach ($groupes as $nomEcole => $lignes): ?>
    <div class="card shadow-sm mb-3">
        <div class="card-header"><strong><?= htmlspecialchars($nomEcole) ?></strong> <span class="text-muted">(<?= count($lignes) ?>)</span></div>
        <div class="card-body p-0 table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nom</th>
                        <th>Prénoms</th>
                        <th>Matricule</th>
                        <th style="width:120px">Note /20</th>
                        <th class="text-center" style="width:90px">Absent</th>
                        <th>Résultat</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($lignes as $c): ?>
                        <?php
                        $estAbsentActuel = $c['note'] === null && (int) ($c['present'] ?? 1) === 0;
                        $estAdmis = $c['note'] !== null && (float) $c['note'] >= SEUIL_ADMISSION;
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($c['nom']) ?></td>
                            <td><?= htmlspecialchars($c['prenoms']) ?></td>
                            <td><code><?= htmlspecialchars($c['matricule_dsps'] ?? '-') ?></code></td>
                            <td>
                                <input type="number" min="0" max="20" step="0.25" class="form-control form-control-sm champ-note"
                                       name="note[<?= $c['id'] ?>]" value="<?= $c['note'] !== null ? htmlspecialchars($c['note']) : '' ?>"
                                       <?= $estAbsentActuel ? 'disabled' : '' ?>>
                            </td>
                            <td class="text-center">
                                <input type="checkbox" class="case-absent" name="absent[<?= $c['id'] ?>]" value="1" <?= $estAbsentActuel ? 'checked' : '' ?> onchange="basculerAbsent(this)">
                            </td>
                            <td>
                                <?php if ($estAbsentActuel): ?>
                                    <span class="badge bg-secondary">Absent</span>
                                <?php elseif ($c['note'] === null): ?>
                                    <span class="badge bg-light text-dark border">Non saisi</span>
                                <?php elseif ($estAdmis): ?>
                                    <span class="badge bg-success">Admis</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Ajourné</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($total === 0): ?>
    <p class="text-muted">Aucun candidat éligible pour cet examen (candidats officiels validés<?= $estFinal ? ' + candidats libres affectés' : '' ?>).</p>
<?php else: ?>
    <button type="submit" name="enregistrer_notes" class="btn btn-primary"><i class="bi bi-save"></i> Enregistrer les notes</button>
<?php endif; ?>
</form>

<!-- Modal Import -->
<div class="modal fade" id="modalImportNotes" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="examen_id" value="<?= $examenId ?>">
            <div class="modal-header"><h5 class="modal-title">Importer les notes (Excel)</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Colonnes attendues : Matricule DSPS (ou vide), Nom, Prénoms, Note /20, Présent (O/N).</p>
                <input type="file" name="fichier_notes" class="form-control" accept=".xlsx,.xls,.csv" required>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annuler</button>
                <button type="submit" name="importer_notes" class="btn btn-success">Importer</button>
            </div>
        </form>
    </div>
</div>

<script>
function basculerAbsent(caseAbsent) {
    var ligne = caseAbsent.closest('tr');
    var champNote = ligne.querySelector('.champ-note');
    champNote.disabled = caseAbsent.checked;
    if (caseAbsent.checked) champNote.value = '';
}
</script>

<?php include '../views/layouts/footer.php'; ?>
