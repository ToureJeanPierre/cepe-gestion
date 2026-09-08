<?php
// Ce fichier est inclus depuis enseignants_new.php (modales Ajout / Modification).
// $personnelAModifier contient les valeurs existantes en mode modification, vide en mode ajout.
$v = $personnelAModifier ?? [];
?>
<div class="row">
    <div class="col-md-4 mb-2"><label>Nom *</label><input type="text" name="nom" class="form-control" value="<?= htmlspecialchars($v['nom'] ?? '') ?>" required></div>
    <div class="col-md-4 mb-2"><label>Prénoms *</label><input type="text" name="prenoms" class="form-control" value="<?= htmlspecialchars($v['prenoms'] ?? '') ?>" required></div>
    <div class="col-md-4 mb-2"><label>Sexe *</label>
        <select name="sexe" class="form-select">
            <option value="M" <?= ($v['sexe'] ?? 'M') == 'M' ? 'selected' : '' ?>>Masculin</option>
            <option value="F" <?= ($v['sexe'] ?? '') == 'F' ? 'selected' : '' ?>>Féminin</option>
        </select>
    </div>
</div>

<div class="row">
    <div class="col-md-4 mb-2">
        <label>Catégorie *</label>
        <select name="categorie" class="form-select" onchange="ajusterChampsCategorie(this)">
            <?php foreach (CATEGORIES_PERSONNEL as $val => $label): ?>
                <option value="<?= $val ?>" <?= ($v['categorie'] ?? 'enseignant') == $val ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 mb-2">
        <label>Téléphone</label>
        <input type="text" name="telephone" class="form-control" value="<?= htmlspecialchars($v['telephone'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-2" data-categorie="conseiller,administratif">
        <label>Sous-type / Intitulé de poste</label>
        <input type="text" name="sous_type" class="form-control" list="listeSousTypes" value="<?= htmlspecialchars($v['sous_type'] ?? '') ?>" placeholder="ex: Pédagogique, Chargé de communication...">
        <datalist id="listeSousTypes">
            <option value="Pédagogique"><option value="Extrascolaire">
            <option value="Agent d'État"><option value="Enseignant détaché">
            <option value="Chargé de communication"><option value="Ressources Humaines">
        </datalist>
    </div>
</div>

<hr>
<h6>Rattachement</h6>
<div class="row">
    <div class="col-md-6 mb-2">
        <label>École</label>
        <select name="ecole_id" class="form-select">
            <option value="">-- Aucune (rattaché à l'Inspection) --</option>
            <?php foreach ($ecoles as $ec): ?>
                <option value="<?= $ec['id'] ?>" <?= ($v['ecole_id'] ?? 0) == $ec['id'] ? 'selected' : '' ?>><?= htmlspecialchars($ec['nom']) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="text-muted">Laisser vide pour un Conseiller/Administratif rattaché directement à l'IEPP.</small>
    </div>
    <div class="col-md-6 mb-2" data-categorie="enseignant">
        <label>Type École</label>
        <select name="type_ecole" class="form-select">
            <option value="Public" <?= ($v['type_ecole'] ?? 'Public') == 'Public' ? 'selected' : '' ?>>Public</option>
            <option value="Privé" <?= ($v['type_ecole'] ?? '') == 'Privé' ? 'selected' : '' ?>>Privé</option>
        </select>
    </div>
</div>

<hr>
<h6>Fonction & Corps</h6>
<div class="row">
    <div class="col-md-4 mb-2" data-categorie="enseignant">
        <label>Fonction *</label>
        <select name="fonction" class="form-select">
            <?php foreach (FONCTIONS_ENSEIGNANT as $f): ?>
                <option value="<?= $f ?>" <?= ($v['fonction'] ?? 'Adjoint') == $f ? 'selected' : '' ?>><?= $f ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 mb-2" data-categorie="conseiller,administratif">
        <label>Fonction *</label>
        <input type="text" name="fonction" class="form-control" value="<?= htmlspecialchars(!in_array($v['fonction'] ?? '', FONCTIONS_ENSEIGNANT) ? ($v['fonction'] ?? '') : '') ?>" placeholder="ex: Conseiller Pédagogique">
    </div>
    <div class="col-md-4 mb-2" data-categorie="enseignant">
        <label>Niveau tenu</label>
        <select name="niveau_tenu" class="form-select">
            <option value="">-- Sans classe --</option>
            <?php foreach (NIVEAUX as $n): ?>
                <option value="<?= $n ?>" <?= ($v['niveau_tenu'] ?? '') == $n ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-4 mb-2">
        <label>Disponibilité</label>
        <select name="disponibilite" class="form-select">
            <?php foreach (DISPONIBILITES as $d): ?>
                <option value="<?= $d ?>" <?= ($v['disponibilite'] ?? 'En activité') == $d ? 'selected' : '' ?>><?= $d ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<div class="row" data-categorie="enseignant">
    <div class="col-md-4 mb-2">
        <label>Matricule (Public)</label>
        <input type="text" name="matricule" class="form-control" value="<?= htmlspecialchars($v['matricule'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-2">
        <label>N° Autorisation d'Enseigner (Privé, Adjoint)</label>
        <input type="text" name="numero_autorisation_enseigner" class="form-control" value="<?= htmlspecialchars($v['numero_autorisation_enseigner'] ?? '') ?>">
    </div>
    <div class="col-md-4 mb-2">
        <label>N° Autorisation de Diriger (Privé, Directeur)</label>
        <input type="text" name="numero_autorisation_diriger" class="form-control" value="<?= htmlspecialchars($v['numero_autorisation_diriger'] ?? '') ?>">
    </div>
</div>

<div class="row" data-categorie="administratif">
    <div class="col-md-6 mb-2">
        <label>Matricule (Agent de l'État)</label>
        <input type="text" name="matricule" class="form-control" value="<?= htmlspecialchars($v['matricule'] ?? '') ?>">
    </div>
</div>

<div class="row" data-categorie="enseignant">
    <div class="col-md-6 mb-2">
        <label>Emploi</label>
        <select name="emploi" class="form-select">
            <option value="">-- Aucun --</option>
            <option value="IO" <?= ($v['emploi'] ?? '') == 'IO' ? 'selected' : '' ?>>IO</option>
            <option value="IA" <?= ($v['emploi'] ?? '') == 'IA' ? 'selected' : '' ?>>IA</option>
        </select>
    </div>
    <div class="col-md-6 mb-2">
        <label>Grade</label>
        <input type="text" name="grade" class="form-control" value="<?= htmlspecialchars($v['grade'] ?? '') ?>">
    </div>
</div>

<hr>
<h6>Diplômes</h6>
<div class="row">
    <div class="col-md-6 mb-2">
        <label>Plus haut diplôme obtenu</label>
        <input type="text" name="plus_haut_diplome" class="form-control" value="<?= htmlspecialchars($v['plus_haut_diplome'] ?? '') ?>" placeholder="ex: CAP, BAC, Licence...">
    </div>
    <div class="col-md-6 mb-2">
        <label>Plus haut niveau d'étude atteint</label>
        <input type="text" name="plus_haut_niveau_etude" class="form-control" value="<?= htmlspecialchars($v['plus_haut_niveau_etude'] ?? '') ?>" placeholder="ex: Terminale, Licence 3...">
    </div>
</div>
