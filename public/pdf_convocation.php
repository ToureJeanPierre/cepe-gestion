<?php

require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/pdf_letterhead.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use Iepp\CepeGestion\AffectationEngine;

/*
|--------------------------------------------------------------------------
| CONFIGURATION DES 6 TYPES DE CONVOCATION (modèle national DECO)
|--------------------------------------------------------------------------
| "roles" : rôle(s) de la table affectations à utiliser pour pré-remplir
|            automatiquement la liste des personnes (vide = formulaire à
|            remplir à la main, faute de donnée correspondante dans l'appli
|            — cas des corrections/harmonisation, non encore modélisées).
| "colonnes" : en-têtes du "TABLEAU DES SERVICES" sur le document.
| "checkbox" : libellés des deux cases à cocher (Chef / Membre), le cas échéant.
|--------------------------------------------------------------------------
*/
$typesConvocation = [
    'surveillant' => [
        'titre' => 'CONVOCATION DU SURVEILLANT',
        'qualite' => 'surveillant(e)',
        'roles' => ['Surveillant', 'Suppléant'],
        'colonnes' => ['Centre de composition', 'Dates et heures'],
        'checkbox' => null,
        'nb' => "Il est fait obligation à tout personnel du secteur éducation-formation convoqué de participer aux examens et concours relevant du Ministère en charge de l'Éducation Nationale. L'usage de tout support de communication numérique (téléphone portable, tablette, smartphone et tout autre objet connecté) est strictement interdit pendant la composition et constitue une faute professionnelle sanctionnée comme telle. Le surveillant est tenu d'éteindre son support de communication numérique pendant la composition.",
    ],
    'chef_centre' => [
        'titre' => 'CONVOCATION DU CHEF DE CENTRE DE COMPOSITION',
        'qualite' => 'Chef de centre',
        'roles' => ['Président'],
        'colonnes' => ["Centre d'Examen", 'Date et heures'],
        'checkbox' => null,
        'nb' => "Il est fait obligation à tout personnel du secteur éducation-formation convoqué de participer aux examens et concours relevant du Ministère en charge de l'Éducation Nationale. L'usage de tout support de communication numérique pendant la composition est strictement interdit et constitue une faute professionnelle sanctionnée comme telle. Le chef de centre, les forces de l'ordre et les superviseurs sont habilités à utiliser leur support de communication numérique uniquement dans le cadre de l'examen. Par conséquent, le chef de centre doit veiller à ce que les supports de communication numérique des autres acteurs restent éteints pendant la composition.",
    ],
    'secretariat_composition' => [
        'titre' => 'CONVOCATION DU SECRETAIRE DE CENTRE DE COMPOSITION',
        'qualite' => null,
        'roles' => ['Chef Secrétariat', 'Membre Secrétariat'],
        'colonnes' => ['Centre de composition', 'Dates et heures'],
        'checkbox' => ['Chef de Secrétariat de centre de composition', 'Membre de Secrétariat de centre de composition'],
        'nb' => "Il est fait obligation à tout personnel du secteur éducation-formation convoqué de participer aux examens et concours relevant du Ministère en charge de l'Éducation Nationale. L'usage de tout support de communication numérique est strictement interdit et constitue une faute professionnelle sanctionnée comme telle dans le cadre du déroulement des épreuves. Le membre de secrétariat est tenu d'éteindre son support de communication numérique pendant le déroulement des épreuves.",
    ],
    'correcteur' => [
        'titre' => 'CONVOCATION DU CORRECTEUR',
        'qualite' => 'correcteur(trice)',
        'roles' => [],
        'colonnes' => ['Centre de Correction', 'Discipline', 'Date et heures'],
        'checkbox' => null,
        'nb' => "Il est fait obligation à tout personnel du secteur éducation-formation convoqué de participer aux examens et concours relevant du Ministère en charge de l'Éducation Nationale. L'usage de tout support de communication numérique dans une salle de corrections ou de délibérations est strictement interdit et constitue une faute professionnelle sanctionnée comme telle. Le correcteur doit prendre toutes les dispositions utiles pour que les supports de communication numérique en sa possession soient éteints pendant le temps que durent les corrections.",
    ],
    'harmonisateur' => [
        'titre' => "CONVOCATION DE L'HARMONISATEUR",
        'qualite' => 'Harmonisateur(trice)',
        'roles' => [],
        'colonnes' => ['Centre de composition', 'Discipline', 'Dates et heures'],
        'checkbox' => null,
        'nb' => "Votre présence est obligatoire. L'usage de tout support de communication numérique (téléphone portable, tablette, smartphone et tout autre objet connecté) par le correcteur est strictement interdit pendant les corrections et constitue une faute professionnelle sanctionnée comme telle. Par conséquent, l'harmonisateur est tenu d'éteindre son support de communication numérique pendant les corrections.",
    ],
    'secretariat_correction' => [
        'titre' => 'CONVOCATION DU SECRETAIRE DE CENTRE DE CORRECTION ET DE DELIBERATIONS',
        'qualite' => null,
        'roles' => [],
        'colonnes' => ['Centre', 'Dates et heures'],
        'checkbox' => ['Chef de Secrétariat de Correction et de Délibérations', 'Membre de Secrétariat de Correction et de Délibérations'],
        'nb' => "Il est fait obligation à tout personnel du secteur éducation-formation convoqué de participer aux examens et concours relevant du Ministère en charge de l'Éducation Nationale. L'usage de tout support de communication numérique par le correcteur est strictement interdit pendant les corrections et délibérations et constitue une faute professionnelle sanctionnée comme telle. Par conséquent, le membre de secrétariat est tenu d'éteindre son support de communication numérique pendant les délibérations.",
    ],
];

$type = $_GET['type'] ?? '';
if (!isset($typesConvocation[$type])) {
    die("Type de convocation invalide. Valeurs possibles : " . implode(', ', array_keys($typesConvocation)));
}
$config = $typesConvocation[$type];

$examenId = isset($_GET['examen_id']) ? (int) $_GET['examen_id'] : 0;
$filtrePersonnelId = isset($_GET['personnel_id']) && $_GET['personnel_id'] !== '' ? (int) $_GET['personnel_id'] : null;

if (!$examenId || !$anneeId) {
    die("Examen invalide.");
}

$stmt = $pdo->prepare("SELECT id, code, libelle FROM examens WHERE id = ? AND annee_id = ?");
$stmt->execute([$examenId, $anneeId]);
$examen = $stmt->fetch();
if (!$examen) {
    die("Examen introuvable pour l'année scolaire consultée.");
}
$typeExamen = AffectationEngine::libelleTypeExamen($examen['code']);

/*
|--------------------------------------------------------------------------
| PERSONNES À CONVOQUER
|--------------------------------------------------------------------------
| Rôles connus de l'appli (surveillant/chef de centre/secrétariat) : liste
| réelle tirée des affectations. Rôles non modélisés (correcteur,
| harmonisateur, secrétariat de correction) : un unique formulaire vierge,
| à dupliquer/remplir à la main comme aujourd'hui sur papier.
|--------------------------------------------------------------------------
*/
$personnes = [];
if ($config['roles']) {
    $placeholders = implode(',', array_fill(0, count($config['roles']), '?'));
    $sql = "
        SELECT a.role, p.nom, p.prenoms, p.matricule, p.emploi, p.fonction, ecp.nom AS nom_ecole_personnel,
               e.nom AS nom_centre
        FROM affectations a
        INNER JOIN personnel p ON p.id = a.enseignant_id
        INNER JOIN centres c ON c.id = a.centre_id
        INNER JOIN ecoles e ON e.id = c.ecole_id
        LEFT JOIN ecoles ecp ON ecp.id = p.ecole_id
        WHERE a.annee_id = ? AND a.type_examen = ? AND a.role IN ($placeholders)
    ";
    $params = array_merge([$anneeId, $typeExamen], $config['roles']);
    if ($filtrePersonnelId) {
        $sql .= " AND a.enseignant_id = ?";
        $params[] = $filtrePersonnelId;
    }
    $sql .= " ORDER BY p.nom ASC, p.prenoms ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $personnes = $stmt->fetchAll();
}

$aFormulaireVierge = empty($config['roles']);

$html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes() . '
    .champ-ligne { margin: 4px 0; }
    .champ-ligne strong { display: inline-block; width: 130px; }
    .corps { margin: 12px 0; text-align: justify; }
    .nb { font-size: 8px; color: #333; margin-top: 15px; text-align: justify; }
    .autorite { margin-top: 15px; text-align: right; font-size: 10px; line-height: 1.6; }
    .page-break { page-break-before: always; }
    .checkbox-role { margin: 6px 0; }
    .case { display: inline-block; width: 12px; height: 12px; border: 1px solid #333; margin-right: 5px; text-align: center; font-size: 9px; }
</style></head><body>';

function blocConvocation(array $config, ?array $personne, string $sessionLibelle): string
{
    $html = enteteDeco();
    $html .= titreDocumentIepp('EXAMEN DU CEPE - ' . $sessionLibelle, $config['titre']);

    $html .= '<div class="champ-ligne"><strong>NOM ET PRENOMS :</strong> ' . ($personne ? htmlspecialchars($personne['nom'] . ' ' . $personne['prenoms']) : '……………………………………') . '</div>';
    $html .= '<div class="champ-ligne"><strong>MATRICULE :</strong> ' . ($personne ? htmlspecialchars($personne['matricule'] ?? '-') : '……………………………………') . '</div>';
    $html .= '<div class="champ-ligne"><strong>EMPLOI :</strong> ' . ($personne ? htmlspecialchars($personne['emploi'] ?? $personne['fonction'] ?? '-') : '……………………………………') . '</div>';
    $html .= '<div class="champ-ligne"><strong>ECOLE D\'ORIGINE :</strong> ' . ($personne ? htmlspecialchars($personne['nom_ecole_personnel'] ?? '-') : '……………………………………') . '</div>';
    $html .= '<div class="champ-ligne"><strong>IEPP :</strong> YOPOUGON NIANGON</div>';
    $html .= '<div class="champ-ligne"><strong>DRENA :</strong> ABIDJAN III</div>';

    $html .= '<div class="corps">';
    if ($config['checkbox']) {
        $roleActuel = $personne['role'] ?? null;
        $html .= "J'ai l'honneur de vous informer que vous êtes retenu(e) à l'examen du CEPE en qualité de :</div>";
        foreach ($config['checkbox'] as $i => $libelleCase) {
            $coche = $roleActuel && (($i === 0 && $roleActuel === 'Chef Secrétariat') || ($i === 1 && $roleActuel === 'Membre Secrétariat'));
            $html .= '<div class="checkbox-role"><span class="case">' . ($coche ? 'X' : '') . '</span> ' . htmlspecialchars($libelleCase) . '</div>';
        }
    } else {
        $html .= "J'ai l'honneur de vous informer que vous êtes retenu(e) à l'examen du CEPE en qualité de " . htmlspecialchars($config['qualite']) . '.';
        $html .= '</div>';
    }

    $html .= '<table class="doc-table" style="margin-top:10px;"><thead><tr>';
    foreach ($config['colonnes'] as $col) {
        $html .= '<th>' . htmlspecialchars($col) . '</th>';
    }
    $html .= '</tr></thead><tbody><tr>';
    foreach ($config['colonnes'] as $col) {
        if ($personne && stripos($col, 'centre') !== false) {
            $html .= '<td>' . htmlspecialchars($personne['nom_centre']) . '</td>';
        } else {
            $html .= '<td>……………………………</td>';
        }
    }
    $html .= '</tr></tbody></table>';

    $html .= '<div class="nb"><strong>NB :</strong> ' . $config['nb'] . '</div>';

    $html .= '<div class="autorite">
        Pour le Ministre et par délégation<br>
        Le Directeur des Examens et Concours et par délégation<br>
        Le Directeur Régional de l\'Education Nationale et par délégation<br>
        L\'Inspecteur de l\'Enseignement Préscolaire et Primaire
    </div>';

    $html .= piedDeco();

    return $html;
}

$sessionLibelle = 'SESSION ' . date('Y');

if ($aFormulaireVierge) {
    $html .= blocConvocation($config, null, $sessionLibelle);
} elseif (!$personnes) {
    $html .= enteteDeco();
    $html .= titreDocumentIepp('EXAMEN DU CEPE - ' . $sessionLibelle, $config['titre']);
    $html .= '<p>Aucune personne affectée à ce rôle pour cet examen.</p>';
} else {
    $premiere = true;
    foreach ($personnes as $personne) {
        $html .= $premiere ? '' : '<div class="page-break"></div>';
        $premiere = false;
        $html .= blocConvocation($config, $personne, $sessionLibelle);
    }
}

$html .= '</body></html>';

$options = new Options();
$options->set('isRemoteEnabled', false);
$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();
$dompdf->stream('convocation_' . $type . '_' . $examen['code'] . '.pdf', ['Attachment' => false]);
