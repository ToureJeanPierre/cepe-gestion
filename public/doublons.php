<?php
require_once '../config/database.php';

$pageTitle = 'Doublons possibles';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

$success = null;
$error = null;

// ==========================================
// SUPPRESSION D'UNE FICHE EN DOUBLE (une à la fois, après confirmation)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['supprimer_candidat']) || isset($_POST['supprimer_personnel']))) {
    if ($anneeLectureSeule) {
        $error = "Cette année scolaire est archivée (lecture seule) : suppression impossible.";
    } elseif (isset($_POST['supprimer_candidat'])) {
        $id = (int) $_POST['supprimer_candidat'];
        $stmt = $pdo->prepare("DELETE FROM candidats WHERE id = ? AND annee_id = ?");
        $stmt->execute([$id, $anneeId]);
        $success = $stmt->rowCount() ? "Fiche candidat supprimée." : "Fiche introuvable (déjà supprimée ?).";
    } else {
        $id = (int) $_POST['supprimer_personnel'];
        $stmt = $pdo->prepare("DELETE FROM personnel WHERE id = ? AND annee_id = ?");
        $stmt->execute([$id, $anneeId]);
        $success = $stmt->rowCount() ? "Fiche personnel supprimée." : "Fiche introuvable (déjà supprimée ?).";
    }
}

// ==========================================
// DÉTECTION : même école + même nom + mêmes prénoms (casse et accents ignorés
// par la collation de la base). Une même personne saisie deux fois, par exemple
// avec une date de naissance ou un matricule différent d'un envoi à l'autre.
// ==========================================
$stmt = $pdo->prepare("
    SELECT c.id, c.nom, c.prenoms, c.sexe, c.date_naissance, c.lieu_naissance, c.matricule_dsps,
           c.matricule_verifie, c.droits_payes, c.created_at, c.ecole_id, e.nom AS ecole_nom
    FROM candidats c
    LEFT JOIN ecoles e ON e.id = c.ecole_id
    WHERE c.annee_id = ?
      AND EXISTS (
          SELECT 1 FROM candidats d
          WHERE d.annee_id = c.annee_id AND d.id <> c.id AND d.ecole_id <=> c.ecole_id
            AND TRIM(d.nom) = TRIM(c.nom) AND TRIM(d.prenoms) = TRIM(c.prenoms)
      )
    ORDER BY e.nom, c.nom, c.prenoms, c.id
");
$stmt->execute([$anneeId]);
$candidatsDoublons = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT p.id, p.nom, p.prenoms, p.sexe, p.matricule, p.numero_autorisation_enseigner, p.numero_autorisation_diriger,
           p.fonction, p.telephone, p.created_at, p.ecole_id, e.nom AS ecole_nom
    FROM personnel p
    LEFT JOIN ecoles e ON e.id = p.ecole_id
    WHERE p.annee_id = ?
      AND EXISTS (
          SELECT 1 FROM personnel q
          WHERE q.annee_id = p.annee_id AND q.id <> p.id AND q.ecole_id <=> p.ecole_id AND q.categorie = p.categorie
            AND TRIM(q.nom) = TRIM(p.nom) AND TRIM(q.prenoms) = TRIM(p.prenoms)
      )
    ORDER BY e.nom, p.nom, p.prenoms, p.id
");
$stmt->execute([$anneeId]);
$personnelDoublons = $stmt->fetchAll();

// Regroupe par personne (clé école + nom + prénoms normalisés).
$grouper = function (array $lignes): array {
    $groupes = [];
    foreach ($lignes as $l) {
        $cle = ($l['ecole_id'] ?? 0) . '|' . mb_strtolower(trim($l['nom'])) . '|' . mb_strtolower(trim($l['prenoms']));
        $groupes[$cle][] = $l;
    }
    return array_values($groupes);
};
$groupesCandidats = $grouper($candidatsDoublons);
$groupesPersonnel = $grouper($personnelDoublons);

include '../views/layouts/header.php';
?>

<h2 class="mb-3"><i class="bi bi-files"></i> Doublons possibles — <?= htmlspecialchars($ANNEE_SCOLAIRE) ?></h2>

<?php if ($success): ?><div class="alert alert-success alert-dismissible fade show"><?= htmlspecialchars($success) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="alert alert-light border small">
    Ici apparaissent les personnes enregistrées <strong>plusieurs fois dans la même école</strong> (même nom et mêmes prénoms) — par exemple une liste
    importée deux fois avec une date ou un matricule légèrement différent. Compare les fiches et supprime celle en trop (garde de préférence
    celle qui a le matricule et qui est validée). Rien n'est supprimé sans ton clic et ta confirmation.
</div>

<?php
$tables = [
    ['Candidats', $groupesCandidats, 'supprimer_candidat',
        ['Date de naissance', 'Matricule', 'Validé', 'Enregistré le']],
    ['Personnel', $groupesPersonnel, 'supprimer_personnel',
        ['Fonction', 'Matricule / N° autorisation', 'Téléphone', 'Enregistré le']],
];
foreach ($tables as [$titre, $groupes, $champSuppr, $colonnes]): ?>
    <h5 class="mt-4"><?= $titre ?> : <?= count($groupes) ?> personne(s) en double</h5>
    <?php if (!$groupes): ?>
        <div class="alert alert-success py-2"><i class="bi bi-check-circle"></i> Aucun doublon détecté.</div>
    <?php else: ?>
        <div class="card shadow-sm mb-3"><div class="card-body p-0 table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light"><tr><th>École</th><th>Nom et prénoms</th><?php foreach ($colonnes as $c): ?><th><?= $c ?></th><?php endforeach; ?><th></th></tr></thead>
                <tbody>
                    <?php foreach ($groupes as $g): ?>
                        <?php foreach ($g as $i => $l): ?>
                            <tr <?= $i === 0 ? 'style="border-top:2px solid #adb5bd;"' : '' ?>>
                                <td class="small"><?= $i === 0 ? htmlspecialchars((string) ($l['ecole_nom'] ?? 'Candidat libre')) : '' ?></td>
                                <td><strong><?= htmlspecialchars($l['nom']) ?></strong> <?= htmlspecialchars($l['prenoms']) ?></td>
                                <?php if ($titre === 'Candidats'): ?>
                                    <td><?= $l['date_naissance'] ? date('d/m/Y', strtotime($l['date_naissance'])) : '<span class="text-muted">—</span>' ?></td>
                                    <td><code><?= htmlspecialchars((string) $l['matricule_dsps']) ?: '—' ?></code></td>
                                    <td><?= ((int) $l['matricule_verifie'] === 1 && (int) $l['droits_payes'] === 1) ? '<span class="badge bg-success">validé</span>' : '<span class="badge bg-secondary">non</span>' ?></td>
                                <?php else: ?>
                                    <td><?= htmlspecialchars((string) $l['fonction']) ?></td>
                                    <td><code><?= htmlspecialchars((string) ($l['matricule'] ?: ($l['numero_autorisation_enseigner'] ?: $l['numero_autorisation_diriger']))) ?: '—' ?></code></td>
                                    <td class="small"><?= htmlspecialchars((string) $l['telephone']) ?></td>
                                <?php endif; ?>
                                <td class="small text-muted"><?= date('d/m/Y H:i', strtotime($l['created_at'])) ?></td>
                                <td class="text-end">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Supprimer cette fiche en double : <?= htmlspecialchars(addslashes($l['nom'] . ' ' . $l['prenoms'])) ?> ?');">
                                        <input type="hidden" name="<?= $champSuppr ?>" value="<?= (int) $l['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" <?= $anneeLectureSeule ? 'disabled' : '' ?>><i class="bi bi-trash"></i> Supprimer</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
<?php endforeach; ?>

<?php include '../views/layouts/footer.php'; ?>
