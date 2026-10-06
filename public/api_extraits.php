<?php
// Points d'accès AJAX de l'onglet « Extraits de naissance » (envoi, lecture, enregistrement, fichier).
// Le contrôle de connexion est fait par config/database.php.
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/extraits_helpers.php';

$action = $_REQUEST['action'] ?? '';

// --- Téléchargement / affichage du fichier reçu (GET) : le dossier de stockage n'est pas accessible directement.
if ($action === 'fichier') {
    $stmt = $pdo->prepare("SELECT chemin, extension FROM extraits_naissance WHERE id = ?");
    $stmt->execute([(int) ($_GET['id'] ?? 0)]);
    $f = $stmt->fetch();
    $chemin = $f ? extraitsDossier() . '/' . $f['chemin'] : '';
    if (!$f || !is_file($chemin)) {
        http_response_code(404);
        exit;
    }
    $types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
    header('Content-Type: ' . ($types[$f['extension']] ?? 'application/octet-stream'));
    header('Content-Length: ' . filesize($chemin));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600');
    readfile($chemin);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$repondre = function (array $data, int $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
};

// --- Lecture seule (GET) : candidats proches d'un extrait.
if ($action === 'candidats') {
    $stmt = $pdo->prepare("SELECT * FROM extraits_naissance WHERE id = ? AND annee_id = ?");
    $stmt->execute([(int) ($_GET['id'] ?? 0), $anneeId]);
    $e = $stmt->fetch();
    if (!$e) {
        $repondre(['message' => 'Extrait introuvable'], 404);
    }
    $props = proposerCandidatsExtrait($pdo, (int) $anneeId, $e, $e['ecole_id'] ? (int) $e['ecole_id'] : null);
    $repondre(['propositions' => $props]);
}

// --- Lecture seule (GET) : recherche libre d'un candidat par nom ou prénom (quand la lecture s'est trompée).
if ($action === 'chercher') {
    $q = trim((string) ($_GET['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        $repondre(['candidats' => []]);
    }
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $stmt = $pdo->prepare("
        SELECT c.id, c.nom, c.prenoms, c.date_naissance, e.nom AS ecole
        FROM candidats c LEFT JOIN ecoles e ON e.id = c.ecole_id
        WHERE c.annee_id = ? AND (c.nom LIKE ? OR c.prenoms LIKE ? OR CONCAT(c.nom, ' ', c.prenoms) LIKE ?)
        ORDER BY c.nom, c.prenoms LIMIT 10
    ");
    $stmt->execute([$anneeId, $like, $like, $like]);
    $repondre(['candidats' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $repondre(['message' => 'Méthode non autorisée'], 405);
}
if ($anneeLectureSeule) {
    $repondre(['etat' => 'erreur', 'message' => 'Année archivée : lecture seule'], 403);
}
set_time_limit(180);

try {
    switch ($action) {
        // Un fichier par requête : pas de limite de nombre de fichiers, progression exacte.
        case 'envoyer':
            $fichier = $_FILES['fichier'] ?? null;
            if (!$fichier || $fichier['error'] !== UPLOAD_ERR_OK) {
                $repondre(['etat' => 'refuse', 'message' => 'Envoi interrompu ou fichier trop gros pour le serveur'], 400);
            }
            $ecoleId = (int) ($_POST['ecole_id'] ?? 0);
            if ($ecoleId) {
                $existe = $pdo->prepare("SELECT 1 FROM ecoles WHERE id = ?");
                $existe->execute([$ecoleId]);
                if (!$existe->fetchColumn()) {
                    $ecoleId = 0;
                }
            }
            $r = enregistrerFichierExtrait($pdo, (int) $anneeId, $ecoleId ?: null, basename((string) $fichier['name']), (string) file_get_contents($fichier['tmp_name']));
            $repondre($r);

        case 'lire':
            $res = lireExtraitNaissance($pdo, (int) ($_POST['id'] ?? 0), null, !empty($_POST['fort']));
            $repondre([
                'etat' => 'lu', 'cout' => round($res['cout'], 5), 'modele' => $res['modele'], 'escalade' => $res['escalade'],
                'confiance' => $res['lecture']['confiance'], 'depense' => round(extraitsDepenseUsd($pdo), 4),
            ]);

        case 'enregistrer':
            $stmt = $pdo->prepare("SELECT id, statut FROM extraits_naissance WHERE id = ? AND annee_id = ?");
            $stmt->execute([(int) ($_POST['id'] ?? 0), $anneeId]);
            $e = $stmt->fetch();
            if (!$e) {
                $repondre(['etat' => 'erreur', 'message' => 'Extrait introuvable'], 404);
            }
            $nettoyer = fn (string $k, int $max = 150) => ($v = trim(preg_replace('/\s+/', ' ', (string) ($_POST[$k] ?? '')))) === '' ? null : mb_substr($v, 0, $max);
            $date = function (string $k): ?string {
                $v = trim((string) ($_POST[$k] ?? ''));
                return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $v : null;
            };
            $nom = $nettoyer('nom', 100);
            $prenoms = $nettoyer('prenoms', 200);
            $sexe = in_array($_POST['sexe'] ?? '', ['M', 'F'], true) ? $_POST['sexe'] : null;
            $valider = !empty($_POST['valider']);
            $candidatId = (int) ($_POST['candidat_id'] ?? 0) ?: null;
            $ecoleId = (int) ($_POST['ecole_id'] ?? 0) ?: null;
            if ($ecoleId) {
                $x = $pdo->prepare("SELECT 1 FROM ecoles WHERE id = ?");
                $x->execute([$ecoleId]);
                if (!$x->fetchColumn()) {
                    $ecoleId = null;
                }
            }

            if ($valider && ($nom === null || $prenoms === null)) {
                $repondre(['etat' => 'erreur', 'message' => 'Le nom et les prénoms sont obligatoires pour valider.'], 422);
            }
            if ($candidatId) {
                $c = $pdo->prepare("SELECT id FROM candidats WHERE id = ? AND annee_id = ?");
                $c->execute([$candidatId, $anneeId]);
                if (!$c->fetchColumn()) {
                    $repondre(['etat' => 'erreur', 'message' => 'Candidat introuvable'], 422);
                }
                $autre = $pdo->prepare("SELECT id FROM extraits_naissance WHERE candidat_id = ? AND statut = 'valide' AND id <> ? LIMIT 1");
                $autre->execute([$candidatId, $e['id']]);
                if ($valider && $autre->fetchColumn()) {
                    $repondre(['etat' => 'erreur', 'message' => 'Un autre extrait est déjà validé pour ce candidat (doublon ?).'], 422);
                }
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE extraits_naissance SET nom = ?, prenoms = ?, sexe = ?, date_naissance = ?, lieu_naissance = ?, sous_prefecture = ?,
                    pere = ?, mere = ?, contact = ?, numero_acte = ?, date_acte = ?, lieu_acte = ?, nationalite = ?,
                    ecole_id = ?, candidat_id = ?, statut = ?, valide_le = IF(? = 1, NOW(), valide_le), erreur_message = NULL
                    WHERE id = ?")
                ->execute([
                    $nom !== null ? mb_strtoupper($nom) : null, $prenoms !== null ? mb_strtoupper($prenoms) : null, $sexe, $date('date_naissance'),
                    $nettoyer('lieu_naissance'), $nettoyer('sous_prefecture'), $nettoyer('pere', 200), $nettoyer('mere', 200), $nettoyer('contact', 100),
                    $nettoyer('numero_acte', 50), $date('date_acte'), $nettoyer('lieu_acte'), $nettoyer('nationalite', 80),
                    $ecoleId, $candidatId, $valider ? 'valide' : ($e['statut'] === 'a_lire' || $e['statut'] === 'erreur' ? 'lu' : $e['statut']),
                    $valider ? 1 : 0, $e['id'],
                ]);
            // Seule modification d'une fiche candidat : le témoin « a un acte de naissance », sur validation explicite.
            if ($valider && $candidatId) {
                $pdo->prepare("UPDATE candidats SET a_acte_naissance = 1 WHERE id = ? AND annee_id = ?")->execute([$candidatId, $anneeId]);
            }
            $pdo->commit();
            $repondre(['etat' => 'ok']);

        case 'plafond':
            $v = (float) str_replace(',', '.', (string) ($_POST['plafond'] ?? ''));
            if ($v < 0 || $v > 10000) {
                $repondre(['etat' => 'erreur', 'message' => 'Plafond invalide'], 422);
            }
            reglageEcrire($pdo, 'extraits_plafond_usd', (string) $v);
            $repondre(['etat' => 'ok', 'plafond' => $v]);

        case 'retirer':
            // Retire un extrait NON validé de la liste. Les fichiers d'origine de l'utilisateur ne sont pas touchés :
            // seule la copie de travail de l'application est supprimée.
            $stmt = $pdo->prepare("SELECT id, chemin FROM extraits_naissance WHERE id = ? AND annee_id = ? AND statut <> 'valide'");
            $stmt->execute([(int) ($_POST['id'] ?? 0), $anneeId]);
            $e = $stmt->fetch();
            if (!$e) {
                $repondre(['etat' => 'erreur', 'message' => 'Extrait introuvable ou déjà validé'], 404);
            }
            $pdo->prepare("DELETE FROM extraits_naissance WHERE id = ?")->execute([$e['id']]);
            $copie = extraitsDossier() . '/' . $e['chemin'];
            if (is_file($copie)) {
                @unlink($copie);
            }
            $repondre(['etat' => 'ok']);

        default:
            $repondre(['etat' => 'erreur', 'message' => 'Action inconnue'], 400);
    }
} catch (ExtraitLectureException $e) {
    $repondre(['etat' => 'erreur', 'message' => $e->getMessage(), 'arreter' => $e->arreter, 'depense' => round(extraitsDepenseUsd($pdo), 4)], 200);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('api_extraits: ' . $e->getMessage());
    $repondre(['etat' => 'erreur', 'message' => 'Erreur technique'], 500);
}
