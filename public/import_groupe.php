<?php
// Import groupé : plusieurs listes (Word, PDF, Excel) d'un coup, avec un tableau de contrôle avant de lancer.
// Les imports eux-mêmes sont faits par les pages Candidats / Personnel (même code qu'un import à la main), fichier après fichier.
require_once '../config/database.php';
require_once '../vendor/autoload.php';
require_once __DIR__ . '/../src/depots_helpers.php';

$type = ($_GET['type'] ?? '') === 'personnel' ? 'personnel' : 'candidats';

// Réponse légère aux pages d'import : après chaque import, elles redirigent ici avec le message du résultat.
if (isset($_GET['msg'])) {
    header('Content-Type: application/json; charset=utf-8');
    $erreurs = array_merge($_SESSION['import_erreurs'] ?? [], $_SESSION['import_erreurs_personnel'] ?? []);
    unset($_SESSION['import_erreurs'], $_SESSION['import_erreurs_personnel']);
    echo json_encode(['msg' => (string) $_GET['msg'], 'erreurs' => array_values($erreurs)], JSON_UNESCAPED_UNICODE);
    exit;
}

$pageTitle = 'Import groupé';
if (!$anneeId) {
    die("Aucune année scolaire n'existe dans la base de données.");
}
$libelle = $type === 'candidats' ? 'des candidats' : 'du personnel (enseignants)';
$cible = $type === 'candidats' ? 'candidats.php' : 'enseignants.php';
$ecoles = $pdo->query("SELECT id, nom FROM ecoles ORDER BY nom")->fetchAll();

include '../views/layouts/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h2 class="mb-0"><i class="bi bi-files"></i> Import groupé — listes <?= $libelle ?> <small class="text-muted">· <?= htmlspecialchars($ANNEE_SCOLAIRE) ?></small></h2>
    <a href="<?= $cible ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left"></i> Retour</a>
</div>

<?php if ($anneeLectureSeule): ?>
    <div class="alert alert-warning">Cette année scolaire est archivée (lecture seule) : aucun import n'est possible.</div>
<?php else: ?>

<div class="alert alert-light border small">
    <strong>1.</strong> Choisis plusieurs fichiers d'un coup (Word, PDF ou Excel). &nbsp;
    <strong>2.</strong> L'application les lit et propose l'école de chacun : vérifie le tableau, corrige une école si besoin. &nbsp;
    <strong>3.</strong> Clique sur « Importer » : les fichiers cochés sont importés <strong>un par un</strong>, comme à la main.
    Rien n'est enregistré avant ce clic, et une liste déjà enregistrée ne crée pas de doublons.
</div>

<div id="zoneDepot" class="border border-2 rounded p-4 text-center text-muted mb-3" style="border-style: dashed !important;">
    <i class="bi bi-cloud-arrow-up fs-2"></i><br>
    Glisser ici les listes <?= $libelle ?>, ou
    <button type="button" class="btn btn-sm btn-primary" id="btnChoisir">choisir des fichiers</button>
    <input type="file" id="inputFichiers" multiple hidden accept=".docx,.pdf,.xlsx,.xls">
    <div class="small mt-1">Word (.docx), PDF ou Excel. Plusieurs écoles à la fois, c'est possible.</div>
</div>

<div id="blocTableau" class="d-none">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-2">
        <button type="button" class="btn btn-success" id="btnImporter"><i class="bi bi-box-arrow-in-down"></i> Importer les fichiers cochés</button>
        <button type="button" class="btn btn-outline-danger d-none" id="btnArreter">Arrêter</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnToutCocher">Tout cocher</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnToutDecocher">Tout décocher</button>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="btnVider">Vider la liste</button>
        <span class="text-muted small ms-auto" id="compteur"></span>
    </div>
    <div class="progress mb-2 d-none" style="height: 18px;" id="progressBloc"><div class="progress-bar progress-bar-striped progress-bar-animated" id="progressBarre" style="width:0%">0%</div></div>
    <div id="bilan" class="alert d-none small"></div>
    <div class="card shadow-sm"><div class="card-body p-0">
        <table class="table table-sm align-middle mb-0" style="table-layout: fixed; width: 100%;">
            <colgroup><col style="width: 38px;"><col><col style="width: 34%;"><col style="width: 17%;"><col style="width: 22%;"></colgroup>
            <thead class="table-light"><tr><th></th><th>Fichier</th><th>École</th><th>Contenu</th><th>État</th></tr></thead>
            <tbody id="lignes"></tbody>
        </table>
    </div></div>
</div>

<script>
var TYPE = <?= json_encode($type) ?>;
var CIBLE = <?= json_encode($cible) ?>;
var ECOLES = <?= json_encode($ecoles, JSON_UNESCAPED_UNICODE) ?>;
var items = [], enCours = false, arret = false, fileAnalyses = Promise.resolve(), compteurId = 0;

function el(id) { return document.getElementById(id); }
function esc(t) { return String(t == null ? '' : t).replace(/[&<>"']/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]; }); }
function nomEcole(id) { var e = ECOLES.find(function (x) { return String(x.id) === String(id); }); return e ? e.nom : ''; }
function taille(o) { return o > 1048576 ? (o / 1048576).toFixed(1) + ' Mo' : Math.max(1, Math.round(o / 1024)) + ' Ko'; }

// ---------- règles
function peutImporter(it) {
    var a = it.a;
    return !!(a && a.ok && a.importable && !it.importe && (!a.ecole_requise || it.ecoleId));
}
function cocheParDefaut(it) {
    return peutImporter(it) && it.a.statut !== 'deja' && !it.doublonDe;
}

// ---------- affichage
function rendre(it) {
    var tr = it.tr;
    if (!tr) { tr = it.tr = document.createElement('tr'); el('lignes').appendChild(tr); }
    var a = it.a, ok = peutImporter(it), enAttente = !a;
    var etat;
    if (it.resultat) {
        etat = it.resultat.ok ? '<span class="badge bg-success">Importé</span><div class="small text-success mt-1">' + esc(it.resultat.msg) + '</div>'
            : '<span class="badge bg-danger">Échec</span><div class="small text-danger mt-1">' + esc(it.resultat.msg) + '</div>';
        if (it.resultat.erreurs && it.resultat.erreurs.length) {
            etat += '<details class="small mt-1"><summary style="cursor:pointer;">' + it.resultat.erreurs.length + ' remarque(s)</summary><ul class="mb-0 ps-3">'
                + it.resultat.erreurs.slice(0, 40).map(function (x) { return '<li>' + esc(x) + '</li>'; }).join('') + '</ul></details>';
        }
    } else if (it.etat === 'import') {
        etat = '<span class="badge bg-primary">Import en cours…</span>';
    } else if (enAttente) {
        etat = '<span class="badge bg-secondary">Lecture…</span>';
    } else if (!a.ok) {
        etat = '<span class="badge bg-danger">Refusé</span><div class="small text-danger mt-1">' + esc(a.erreur || '') + '</div>';
    } else if (!a.importable) {
        etat = '<span class="badge bg-secondary">Non importable</span><div class="small text-muted mt-1">' + esc(a.motif || '') + '</div>';
    } else {
        var b = {nouveau: ['À importer', 'bg-primary'], partiel: ['Partiellement enregistré', 'bg-warning text-dark'], deja: ['Déjà enregistré', 'bg-success']}[a.statut] || [a.statut, 'bg-secondary'];
        etat = '<span class="badge ' + b[1] + '">' + b[0] + '</span>';
        if (a.ecole_requise && !it.ecoleId) etat += '<div class="small text-warning mt-1"><i class="bi bi-question-circle"></i> choisis l\'école</div>';
        if (it.doublonDe) etat += '<div class="small text-warning mt-1"><i class="bi bi-files"></i> identique à « ' + esc(it.doublonDe) + ' »</div>';
    }

    var choixEcole = '';
    if (a && a.ok && a.importable && !it.importe) {
        var propose = (a.ecoles_possibles || []).map(String);
        var opts = '<option value="">' + (a.ecole_requise ? '— choisir l\'école —' : '— école lue dans le fichier —') + '</option>';
        propose.forEach(function (id) { opts += '<option value="' + id + '"' + (String(it.ecoleId) === id ? ' selected' : '') + '>★ ' + esc(nomEcole(id)) + '</option>'; });
        ECOLES.forEach(function (e) { if (propose.indexOf(String(e.id)) === -1) opts += '<option value="' + e.id + '"' + (String(it.ecoleId) === String(e.id) ? ' selected' : '') + '>' + esc(e.nom) + '</option>'; });
        choixEcole = '<select class="form-select form-select-sm" style="min-width:0;width:100%;" title="' + esc(nomEcole(it.ecoleId)) + '" data-id="' + it.id + '"' + (enCours ? ' disabled' : '') + '>' + opts + '</select>';
        if (a.ecole_texte) choixEcole += '<div class="small text-muted mt-1">écrit dans le fichier : « ' + esc(a.ecole_texte) + ' »</div>';
    } else if (it.importe) {
        choixEcole = '<span class="small">' + esc(nomEcole(it.ecoleId)) + '</span>';
    }

    var contenu = (a && a.ok && a.total) ? '<div><strong>' + a.total + '</strong> ligne(s)</div><div class="small text-muted">' + a.deja + ' déjà en base · <strong class="text-body">' + a.nouveaux + ' nouveau(x)</strong></div>'
        + (a.doublons_fichier ? '<div class="small text-warning">' + a.doublons_fichier + ' doublon(s) dans le fichier</div>' : '') : '';

    tr.innerHTML = '<td><input type="checkbox" class="form-check-input" data-coche="' + it.id + '"' + (it.coche && ok ? ' checked' : '') + (ok && !enCours ? '' : ' disabled') + '></td>'
        + '<td style="word-break:break-word;"><strong>' + esc(it.file.name) + '</strong><div class="small text-muted">' + taille(it.file.size) + '</div></td>'
        + '<td>' + choixEcole + '</td><td>' + contenu + '</td><td>' + etat + '</td>';
    majCompteur();
}
function majCompteur() {
    var n = items.filter(function (i) { return i.coche && peutImporter(i); }).length;
    el('compteur').textContent = items.length + ' fichier(s) · ' + n + ' coché(s)';
    el('btnImporter').disabled = enCours || n === 0;
    el('btnImporter').innerHTML = '<i class="bi bi-box-arrow-in-down"></i> Importer ' + (n > 1 ? 'les ' + n + ' fichiers cochés' : (n === 1 ? 'le fichier coché' : 'les fichiers cochés'));
}

// ---------- analyse (un fichier à la fois, sans rien écrire)
function marquerDoublons() {
    var vus = {};
    items.forEach(function (it) {
        it.doublonDe = null;
        if (!it.a || !it.a.hash) return;
        if (vus[it.a.hash]) { it.doublonDe = vus[it.a.hash]; } else { vus[it.a.hash] = it.file.name; }
    });
}
async function analyser(it, ecoleId, premiere) {
    var fd = new FormData();
    fd.append('fichier', it.file); fd.append('type', TYPE);
    if (ecoleId) fd.append('ecole_id', ecoleId);
    try {
        var r = await fetch('api_import_groupe.php', {method: 'POST', body: fd, credentials: 'same-origin'});
        it.a = await r.json();
    } catch (e) { it.a = {ok: false, erreur: 'Lecture impossible (connexion interrompue ?)'}; }
    if (premiere && it.a && it.a.ok && !it.ecoleId && it.a.ecole_id) it.ecoleId = String(it.a.ecole_id);
    var avant = items.map(function (i) { return i.doublonDe; });
    marquerDoublons();
    if (premiere) it.coche = cocheParDefaut(it);
    rendre(it);
    // on ne reconstruit que les lignes qui changent (sinon une liste déroulante ouverte se refermerait)
    items.forEach(function (i, k) { if (i !== it && i.doublonDe !== avant[k]) rendre(i); });
}
function ajouter(fichiers) {
    var ok = /\.(docx|pdf|xlsx|xls)$/i;
    var retenus = Array.prototype.filter.call(fichiers, function (f) { return ok.test(f.name); });
    if (!retenus.length) { alert('Aucun fichier Word, PDF ou Excel dans la sélection.'); return; }
    el('blocTableau').classList.remove('d-none');
    retenus.forEach(function (f) {
        var it = {id: ++compteurId, file: f, a: null, ecoleId: '', coche: false, importe: false, resultat: null, etat: '', doublonDe: null};
        items.push(it); rendre(it);
        fileAnalyses = fileAnalyses.then(function () { return analyser(it, null, true); });
    });
}

// ---------- import (un fichier à la fois, par les pages existantes)
async function importerUn(it) {
    var fd = new FormData();
    fd.append('retour', 'import_groupe'); fd.append('type_groupe', TYPE);
    if (TYPE === 'candidats') {
        fd.append('importer_candidats', '1'); fd.append('fichier_candidats', it.file);
        if (it.ecoleId) fd.append('ecole_id_import_docx', it.ecoleId);
    } else {
        fd.append('importer_personnel', '1'); fd.append('type_import', 'enseignant_ecole');
        fd.append('ecole_id_import', it.ecoleId); fd.append('fichier_personnel', it.file);
    }
    var texte = '';
    try {
        var r = await fetch(CIBLE, {method: 'POST', body: fd, credentials: 'same-origin'});
        texte = await r.text();
    } catch (e) { return {ok: false, msg: 'Connexion interrompue : vérifier dans la liste avant de relancer.'}; }
    try {
        var j = JSON.parse(texte);
        if (j && j.msg) return {ok: true, msg: j.msg, erreurs: j.erreurs || []};
    } catch (e) { /* pas du JSON : la page a renvoyé une erreur */ }
    var doc = new DOMParser().parseFromString(texte, 'text/html');
    var err = doc.querySelector('.alert-danger');
    var brut = (doc.body ? doc.body.textContent : texte).replace(/\s+/g, ' ').trim();
    return {ok: false, msg: err ? err.textContent.replace(/\s+/g, ' ').trim() : (brut.slice(0, 220) || 'Réponse inattendue du serveur')};
}
async function lancerImport() {
    var aFaire = items.filter(function (i) { return i.coche && peutImporter(i); });
    if (!aFaire.length || enCours) return;
    var libelleEcoles = aFaire.map(function (i) { return '• ' + i.file.name + '  →  ' + (nomEcole(i.ecoleId) || '(école lue dans le fichier)'); }).join('\n');
    if (!confirm('Importer ' + aFaire.length + ' fichier(s) ?\n\n' + libelleEcoles.slice(0, 1500))) return;
    enCours = true; arret = false;
    el('btnArreter').classList.remove('d-none'); el('progressBloc').classList.remove('d-none'); el('bilan').classList.add('d-none');
    window.onbeforeunload = function () { return 'Un import est en cours.'; };
    var faits = 0, reussis = 0, echecs = 0;
    items.forEach(rendre);
    for (var i = 0; i < aFaire.length && !arret; i++) {
        var it = aFaire[i];
        it.etat = 'import'; rendre(it);
        it.resultat = await importerUn(it);
        it.etat = '';
        if (it.resultat.ok) { it.importe = true; it.coche = false; reussis++; } else { echecs++; }
        faits++;
        el('progressBarre').style.width = Math.round(faits * 100 / aFaire.length) + '%'; el('progressBarre').textContent = faits + ' / ' + aFaire.length;
        rendre(it);
    }
    enCours = false; window.onbeforeunload = null;
    el('btnArreter').classList.add('d-none'); el('progressBarre').classList.remove('progress-bar-animated');
    var b = el('bilan');
    b.className = 'alert small ' + (echecs ? 'alert-warning' : 'alert-success');
    b.innerHTML = '<strong>' + (arret ? 'Import arrêté. ' : 'Terminé. ') + '</strong>' + reussis + ' fichier(s) importé(s)' + (echecs ? ', <strong>' + echecs + ' en échec</strong> (voir la colonne État)' : '')
        + (arret && faits < aFaire.length ? ', ' + (aFaire.length - faits) + ' non traité(s)' : '')
        + '. <a href="' + CIBLE + '" class="alert-link">Voir ' + (TYPE === 'candidats' ? 'les candidats' : 'le personnel') + '</a>';
    items.forEach(rendre);
    // Les fichiers non importés (ex. une copie en double) sont relus : leur état reflète ce qui vient d'être enregistré.
    for (var k = 0; k < items.length; k++) {
        var r = items[k];
        if (!r.importe && r.a && r.a.ok && r.a.importable) {
            await analyser(r, r.ecoleId || null, false);
            if (r.a && r.a.statut === 'deja') r.coche = false;
            rendre(r);
        }
    }
}

// ---------- événements
el('btnChoisir').addEventListener('click', function () { el('inputFichiers').click(); });
el('inputFichiers').addEventListener('change', function () { ajouter(this.files); this.value = ''; });
var zone = el('zoneDepot');
['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('bg-light'); }); });
['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('bg-light'); }); });
zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) ajouter(e.dataTransfer.files); });
el('btnImporter').addEventListener('click', lancerImport);
el('btnArreter').addEventListener('click', function () { arret = true; });
el('btnToutCocher').addEventListener('click', function () { items.forEach(function (i) { i.coche = peutImporter(i); rendre(i); }); });
el('btnToutDecocher').addEventListener('click', function () { items.forEach(function (i) { i.coche = false; rendre(i); }); });
el('btnVider').addEventListener('click', function () {
    if (enCours) return;
    items = []; el('lignes').innerHTML = ''; el('blocTableau').classList.add('d-none'); el('bilan').classList.add('d-none'); el('progressBloc').classList.add('d-none');
});
el('lignes').addEventListener('change', function (e) {
    var t = e.target;
    if (t.dataset.coche) {
        var it = items.find(function (i) { return String(i.id) === t.dataset.coche; });
        if (it) { it.coche = t.checked; majCompteur(); }
    } else if (t.tagName === 'SELECT') {
        var it2 = items.find(function (i) { return String(i.id) === t.dataset.id; });
        if (!it2) return;
        it2.ecoleId = t.value;
        // le nombre de « déjà en base » dépend de l'école (personnel sans numéro) : on relit avec l'école choisie
        it2.a = null; rendre(it2);
        fileAnalyses = fileAnalyses.then(async function () { await analyser(it2, it2.ecoleId, false); it2.coche = cocheParDefaut(it2) || (peutImporter(it2) && !!it2.ecoleId); items.forEach(rendre); });
    }
});
</script>
<?php endif; ?>

<?php include '../views/layouts/footer.php'; ?>
