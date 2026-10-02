<?php
require_once '../config/database.php';
require_once '../vendor/autoload.php';

$pageTitle = 'Suivi des dépôts';

if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}

// ==========================================
// DONNÉES : une ligne par école, avec ce qui a été reçu pour l'année consultée
// ==========================================
// "Déposé" = au moins une fiche enregistrée pour cette école sur l'année
// consultée (par import du fichier du directeur ou saisie manuelle : on ne
// distingue pas, l'important est que les données soient bien dans l'appli).
// Les candidats libres n'ont pas d'école : ils ne sont pas concernés ici.
$stmt = $pdo->prepare("
    SELECT
        e.id, e.nom, e.statut, e.code_dsps,
        t.nom AS tutrice_nom,
        e.directeur_nom, e.directeur_telephone,
        COALESCE(c.nb, 0) AS nb_candidats,
        COALESCE(c.nb_valides, 0) AS nb_candidats_valides,
        c.dernier AS dernier_candidat,
        COALESCE(p.nb, 0) AS nb_personnel,
        p.dernier AS dernier_personnel
    FROM ecoles e
    LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
    LEFT JOIN (
        SELECT ecole_id, COUNT(*) AS nb,
               SUM(matricule_verifie = 1 AND droits_payes = 1) AS nb_valides,
               MAX(created_at) AS dernier
        FROM candidats
        WHERE annee_id = ? AND est_candidat_libre = 0 AND ecole_id IS NOT NULL
        GROUP BY ecole_id
    ) c ON c.ecole_id = e.id
    LEFT JOIN (
        SELECT ecole_id, COUNT(*) AS nb, MAX(created_at) AS dernier
        FROM personnel
        WHERE annee_id = ? AND categorie = 'enseignant' AND ecole_id IS NOT NULL
        GROUP BY ecole_id
    ) p ON p.ecole_id = e.id
    ORDER BY e.nom ASC
");
$stmt->execute([$anneeId, $anneeId]);
$toutes = $stmt->fetchAll();

foreach ($toutes as &$e) {
    $aCand = (int) $e['nb_candidats'] > 0;
    $aPers = (int) $e['nb_personnel'] > 0;
    $e['etat'] = $aCand && $aPers ? 'complet' : (!$aCand && !$aPers ? 'rien' : ($aCand ? 'personnel_manquant' : 'candidats_manquants'));
}
unset($e);

$total = count($toutes);
$nbAvecCandidats = count(array_filter($toutes, fn ($e) => (int) $e['nb_candidats'] > 0));
$nbAvecPersonnel = count(array_filter($toutes, fn ($e) => (int) $e['nb_personnel'] > 0));
$nbComplets = count(array_filter($toutes, fn ($e) => $e['etat'] === 'complet'));
$nbRien = count(array_filter($toutes, fn ($e) => $e['etat'] === 'rien'));
$totalCandidats = array_sum(array_column($toutes, 'nb_candidats'));
$totalPersonnel = array_sum(array_column($toutes, 'nb_personnel'));

// ==========================================
// FILTRES
// ==========================================
$etatsLibelles = [
    'rien' => 'Rien reçu',
    'candidats_manquants' => 'Personnel reçu, candidats manquants',
    'personnel_manquant' => 'Candidats reçus, personnel manquant',
    'complet' => 'Complet (candidats + personnel)',
];
$filtreEtat = $_GET['etat'] ?? '';
$filtreType = $_GET['type'] ?? '';
$recherche = trim($_GET['q'] ?? '');

$ecoles = array_values(array_filter($toutes, function ($e) use ($filtreEtat, $filtreType, $recherche, $etatsLibelles) {
    if ($filtreEtat === 'candidats_a_relancer' && (int) $e['nb_candidats'] > 0) return false;
    if ($filtreEtat === 'personnel_a_relancer' && (int) $e['nb_personnel'] > 0) return false;
    if (isset($etatsLibelles[$filtreEtat]) && $e['etat'] !== $filtreEtat) return false;
    if ($filtreType !== '' && $e['statut'] !== $filtreType) return false;
    if ($recherche !== '' && mb_stripos($e['nom'], $recherche) === false) return false;
    return true;
}));

// ==========================================
// EXPORT EXCEL (respecte les filtres en cours)
// ==========================================
if (($_GET['format'] ?? '') === 'xlsx') {
    $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $feuille = $classeur->getActiveSheet();
    $feuille->setTitle('Suivi des dépôts');
    $feuille->fromArray([
        'École', 'Statut', 'Code DSPS', 'École tutrice', 'Directeur', 'Téléphone directeur',
        'Candidats reçus', 'dont validés', 'Dernier dépôt candidats',
        'Personnel reçu', 'Dernier dépôt personnel', 'Situation',
    ], null, 'A1');
    $l = 2;
    foreach ($ecoles as $e) {
        $feuille->fromArray([
            $e['nom'], $e['statut'], '', $e['tutrice_nom'], $e['directeur_nom'], '',
            (int) $e['nb_candidats'], (int) $e['nb_candidats_valides'],
            $e['dernier_candidat'] ? date('d/m/Y', strtotime($e['dernier_candidat'])) : '',
            (int) $e['nb_personnel'],
            $e['dernier_personnel'] ? date('d/m/Y', strtotime($e['dernier_personnel'])) : '',
            $etatsLibelles[$e['etat']],
        ], null, 'A' . $l);
        $feuille->setCellValueExplicit('C' . $l, (string) $e['code_dsps'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $feuille->setCellValueExplicit('F' . $l, (string) $e['directeur_telephone'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $l++;
    }
    $feuille->getStyle('A1:L1')->getFont()->setBold(true);
    $feuille->getStyle('A1:L1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
    $feuille->freezePane('A2');
    $feuille->setAutoFilter('A1:L' . max(1, $l - 1));
    foreach (range('A', 'L') as $col) {
        $feuille->getColumnDimension($col)->setAutoSize(true);
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="suivi_depots_' . date('Y-m-d') . '.xlsx"');
    header('Cache-Control: max-age=0');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save('php://output');
    exit;
}

$qsExport = http_build_query(array_filter(['etat' => $filtreEtat, 'type' => $filtreType, 'q' => $recherche]) + ['format' => 'xlsx']);

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><i class="bi bi-clipboard-check"></i> Suivi des dépôts — <?= htmlspecialchars($ANNEE_SCOLAIRE) ?></h2>
    <a href="suivi_depots.php?<?= htmlspecialchars($qsExport) ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Exporter en Excel</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3"><?php statCard('bi-building', 'navy', $total, 'Écoles', 'référentiel complet'); ?></div>
    <div class="col-md-3"><?php statCard('bi-people', 'orange', $nbAvecCandidats . ' / ' . $total, 'Ont déposé leurs candidats', $totalCandidats . ' candidats reçus'); ?></div>
    <div class="col-md-3"><?php statCard('bi-person-badge', 'green', $nbAvecPersonnel . ' / ' . $total, 'Ont déposé leur personnel', $totalPersonnel . ' enseignants reçus'); ?></div>
    <div class="col-md-3"><?php statCard('bi-exclamation-circle', 'red', (string) $nbRien, 'Rien reçu du tout', $nbComplets . ' école(s) complète(s)'); ?></div>
</div>

<form method="GET" class="row g-2 mb-3 align-items-end">
    <div class="col-md-4">
        <label class="form-label small mb-1">Situation</label>
        <select name="etat" class="form-select" onchange="this.form.submit()">
            <option value="">Toutes les écoles (<?= $total ?>)</option>
            <option value="candidats_a_relancer" <?= $filtreEtat === 'candidats_a_relancer' ? 'selected' : '' ?>>Candidats non déposés (à relancer)</option>
            <option value="personnel_a_relancer" <?= $filtreEtat === 'personnel_a_relancer' ? 'selected' : '' ?>>Personnel non déposé (à relancer)</option>
            <?php foreach ($etatsLibelles as $code => $libelle): ?>
                <option value="<?= $code ?>" <?= $filtreEtat === $code ? 'selected' : '' ?>><?= htmlspecialchars($libelle) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small mb-1">Public / Privé</label>
        <select name="type" class="form-select" onchange="this.form.submit()">
            <option value="">Tous</option>
            <option value="Public" <?= $filtreType === 'Public' ? 'selected' : '' ?>>Public</option>
            <option value="Privé" <?= $filtreType === 'Privé' ? 'selected' : '' ?>>Privé</option>
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label small mb-1">Nom de l'école</label>
        <input type="text" name="q" value="<?= htmlspecialchars($recherche) ?>" class="form-control" placeholder="Rechercher...">
    </div>
    <div class="col-md-2 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        <a href="suivi_depots.php" class="btn btn-outline-secondary">Réinitialiser</a>
    </div>
</form>

<div class="alert alert-light border small">
    <strong>Comment lire ce tableau :</strong> une école est "déposée" dès qu'au moins une fiche la concerne pour l'année consultée
    (import du fichier du directeur ou saisie manuelle). Les écoles rattachées à une tutrice apparaissent chacune sur leur propre ligne.
    Un fichier partiellement importé (ex. quelques lignes ignorées en erreur) compte comme déposé : vérifie le nombre de candidats
    reçus par rapport à ce que le directeur t'a annoncé.
</div>

<div class="card shadow-sm">
    <div class="card-header"><strong><?= count($ecoles) ?></strong> école(s) affichée(s)</div>
    <div class="card-body p-0 table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>École</th>
                    <th>Statut</th>
                    <th class="text-end">Candidats</th>
                    <th>Dernier dépôt</th>
                    <th class="text-end">Personnel</th>
                    <th>Dernier dépôt</th>
                    <th>Directeur</th>
                    <th>Situation</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$ecoles): ?>
                    <tr><td colspan="8" class="text-center text-muted py-4">Aucune école ne correspond à ce filtre.</td></tr>
                <?php endif; ?>
                <?php foreach ($ecoles as $e): ?>
                    <?php
                    $badge = match ($e['etat']) {
                        'complet' => 'bg-success',
                        'rien' => 'bg-danger',
                        default => 'bg-warning text-dark',
                    };
                    $court = match ($e['etat']) {
                        'complet' => 'Complet',
                        'rien' => 'Rien reçu',
                        'candidats_manquants' => 'Candidats manquants',
                        'personnel_manquant' => 'Personnel manquant',
                    };
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($e['nom']) ?></strong>
                            <?php if ($e['tutrice_nom']): ?><div class="small text-muted">rattachée à <?= htmlspecialchars($e['tutrice_nom']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($e['statut']) ?></span></td>
                        <td class="text-end">
                            <?php if ((int) $e['nb_candidats'] > 0): ?>
                                <a href="candidats.php?ecole_id=<?= (int) $e['id'] ?>"><strong><?= (int) $e['nb_candidats'] ?></strong></a>
                                <div class="small text-muted"><?= (int) $e['nb_candidats_valides'] ?> validé(s)</div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $e['dernier_candidat'] ? date('d/m/Y', strtotime($e['dernier_candidat'])) : '' ?></td>
                        <td class="text-end">
                            <?php if ((int) $e['nb_personnel'] > 0): ?>
                                <a href="enseignants.php?ecole_id=<?= (int) $e['id'] ?>"><strong><?= (int) $e['nb_personnel'] ?></strong></a>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $e['dernier_personnel'] ? date('d/m/Y', strtotime($e['dernier_personnel'])) : '' ?></td>
                        <td class="small">
                            <?= htmlspecialchars((string) $e['directeur_nom']) ?>
                            <?php if ($e['directeur_telephone']): ?><div class="text-muted"><?= htmlspecialchars($e['directeur_telephone']) ?></div><?php endif; ?>
                        </td>
                        <td><span class="badge <?= $badge ?>"><?= $court ?></span></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../views/layouts/footer.php'; ?>
