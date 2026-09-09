<?php

/*
|--------------------------------------------------------------------------
| HEADER PRINCIPAL — APPLICATION CEPE
|--------------------------------------------------------------------------
*/

$currentPage = basename($_SERVER['PHP_SELF']);

$pageTitle = $pageTitle ?? 'Gestion CEPE';

// Variables de contexte année (résolues par config/database.php, avec repli défensif
// si une page inclut ce header sans avoir chargé la config au préalable).
$anneesDisponibles = $anneesDisponibles ?? [];
$anneeSelectionnee = $anneeSelectionnee ?? null;
$anneeLectureSeule = $anneeLectureSeule ?? false;
$ANNEE_SCOLAIRE = $ANNEE_SCOLAIRE ?? ($anneeSelectionnee['annee_scolaire'] ?? 'N/A');

$retourSelecteur = $currentPage . (($qs = $_SERVER['QUERY_STRING'] ?? '') !== '' ? ('?' . $qs) : '');

if (!function_exists('statCard')) {
    /**
     * Rend une tuile de statistique cohérente avec l'identité de l'appli
     * (remplace les blocs Bootstrap bg-primary/bg-info/... pleins).
     *
     * @param string      $icone   Classe d'icône Bootstrap Icons (ex: "bi-people")
     * @param string      $couleur Variante stat-icon-* (navy, orange, green, blue, teal, red, gray, purple)
     * @param string      $valeur  Valeur mise en avant (déjà formatée)
     * @param string      $label   Libellé court (affiché en majuscules)
     * @param string|null $sousTexte Texte secondaire optionnel sous la valeur
     * @param string|null $lien    Si fourni, toute la tuile devient un lien cliquable
     */
    function statCard(string $icone, string $couleur, string $valeur, string $label, ?string $sousTexte = null, ?string $lien = null): void
    {
        $balise = $lien ? 'a' : 'div';
        $attrs = $lien ? ' href="' . htmlspecialchars($lien) . '"' : '';
        $classeLien = $lien ? ' is-link' : '';
        ?>
        <<?= $balise ?> class="stat-card<?= $classeLien ?>"<?= $attrs ?>>
            <div class="stat-card-icon stat-icon-<?= htmlspecialchars($couleur) ?>"><i class="bi <?= htmlspecialchars($icone) ?>"></i></div>
            <div class="stat-card-body">
                <div class="stat-card-value"><?= $valeur ?></div>
                <div class="stat-card-label"><?= htmlspecialchars($label) ?></div>
                <?php if ($sousTexte !== null): ?><div class="stat-card-sub"><?= $sousTexte ?></div><?php endif; ?>
            </div>
        </<?= $balise ?>>
        <?php
    }
}

?>

<!DOCTYPE html>
<html lang="fr">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        <?= htmlspecialchars($pageTitle) ?>
        - Gestion CEPE
    </title>


    <!-- Bootstrap -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css"
        rel="stylesheet"
    >


    <!-- Bootstrap Icons -->
    <link
        href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css"
        rel="stylesheet"
    >


    <!-- Police -->
    <link
        rel="preconnect"
        href="https://fonts.googleapis.com"
    >

    <link
        rel="preconnect"
        href="https://fonts.gstatic.com"
        crossorigin
    >

    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap"
        rel="stylesheet"
    >


    <style>

        /* ======================================================
           VARIABLES
        ====================================================== */

        :root {

            --ci-orange: #f58220;
            --ci-green: #009e49;

            --primary: #17365d;
            --primary-dark: #102944;

            --text-dark: #263238;
            --text-muted: #6c757d;

            --background: #f5f7fa;
            --white: #ffffff;

            --border: #e5e9ef;

            --sidebar-width: 260px;

            --shadow:
                0 2px 10px rgba(0, 0, 0, 0.06);

            --transition:
                all 0.2s ease;
        }


        /* ======================================================
           BASE
        ====================================================== */

        * {
            box-sizing: border-box;
        }


        body {

            margin: 0;

            font-family:
                'Inter',
                'Segoe UI',
                Arial,
                sans-serif;

            background-color: var(--background);

            color: var(--text-dark);

            font-size: 14px;
        }


        a {
            text-decoration: none;
        }


        /* ======================================================
           BARRE INSTITUTIONNELLE SUPÉRIEURE
        ====================================================== */

        .top-government-bar {

            height: 6px;

            background:
                linear-gradient(
                    to right,
                    var(--ci-orange) 0%,
                    var(--ci-orange) 33.33%,
                    #ffffff 33.33%,
                    #ffffff 66.66%,
                    var(--ci-green) 66.66%,
                    var(--ci-green) 100%
                );
        }


        /* ======================================================
           STRUCTURE
        ====================================================== */

        .app-wrapper {

            display: flex;

            min-height: calc(100vh - 6px);
        }


        /* ======================================================
           SIDEBAR
        ====================================================== */

        .sidebar {

            position: fixed;

            top: 6px;
            left: 0;
            bottom: 0;

            width: var(--sidebar-width);

            background:
                linear-gradient(
                    180deg,
                    var(--primary-dark) 0%,
                    var(--primary) 100%
                );

            color: white;

            overflow-y: auto;

            z-index: 1000;

            box-shadow:
                2px 0 10px rgba(0, 0, 0, 0.08);
        }


        /* Bande orange/verte discrète */

        .sidebar-brand-line {

            height: 4px;

            background:
                linear-gradient(
                    to right,
                    var(--ci-orange) 0%,
                    var(--ci-orange) 50%,
                    var(--ci-green) 50%,
                    var(--ci-green) 100%
                );
        }


        /* ======================================================
           IDENTITÉ
        ====================================================== */

        .sidebar-brand {

            padding: 22px 20px 18px;

            border-bottom:
                1px solid
                rgba(255, 255, 255, 0.10);
        }


        .sidebar-brand-title {

            display: flex;

            align-items: center;

            gap: 12px;

            color: white;

            font-size: 18px;

            font-weight: 700;

            margin-bottom: 5px;
        }


        .sidebar-brand-icon {

            width: 40px;
            height: 40px;

            display: flex;

            align-items: center;
            justify-content: center;

            border-radius: 10px;

            background:
                rgba(255, 255, 255, 0.10);

            font-size: 21px;
        }


        .sidebar-brand-subtitle {

            margin-left: 52px;

            color:
                rgba(255, 255, 255, 0.65);

            font-size: 11px;

            line-height: 1.4;
        }


        /* ======================================================
           ANNÉE ACTIVE
        ====================================================== */

        .sidebar-year {

            margin: 18px 16px;

            padding: 12px 14px;

            border-radius: 8px;

            background:
                rgba(255, 255, 255, 0.07);

            border:
                1px solid
                rgba(255, 255, 255, 0.10);
        }


        .sidebar-year-label {

            display: block;

            color:
                rgba(255, 255, 255, 0.55);

            font-size: 10px;

            text-transform: uppercase;

            letter-spacing: 0.7px;

            margin-bottom: 4px;
        }


        .sidebar-year-value {

            color: white;

            font-size: 14px;

            font-weight: 600;
        }


        .sidebar-year i {

            color: var(--ci-orange);

            margin-right: 6px;
        }


        .sidebar-year select {

            width: 100%;

            margin-top: 6px;

            padding: 6px 8px;

            border-radius: 6px;

            border: 1px solid rgba(255, 255, 255, 0.18);

            background: rgba(255, 255, 255, 0.08);

            color: white;

            font-size: 12px;

            font-weight: 600;
        }


        .sidebar-year select option {
            color: var(--text-dark);
        }


        .badge-readonly {

            display: inline-flex;

            align-items: center;

            gap: 5px;

            margin-top: 8px;

            padding: 4px 8px;

            border-radius: 5px;

            background: rgba(220, 53, 69, 0.18);

            color: #ffb3ba;

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 0.4px;
        }


        .topbar-year.is-readonly {

            border-color: #f1aeb5;

            background: #fdf2f3;

            color: #a71d2a;
        }


        .topbar-year.is-readonly i {
            color: #a71d2a;
        }


        /* ======================================================
           MENU
        ====================================================== */

        .sidebar-section {

            padding:
                8px 20px 6px;

            color:
                rgba(255, 255, 255, 0.40);

            font-size: 10px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 1px;
        }


        .sidebar .nav {

            padding:
                0 12px 15px;
        }


        .sidebar .nav-link {

            display: flex;

            align-items: center;

            gap: 12px;

            min-height: 43px;

            margin: 3px 0;

            padding:
                10px 13px;

            border-radius: 7px;

            color:
                rgba(255, 255, 255, 0.78);

            font-size: 13px;

            font-weight: 500;

            transition: var(--transition);
        }


        .sidebar .nav-link i {

            width: 20px;

            text-align: center;

            font-size: 16px;

            color:
                rgba(255, 255, 255, 0.60);
        }


        .sidebar .nav-link:hover {

            background:
                rgba(255, 255, 255, 0.08);

            color: white;
        }


        .sidebar .nav-link:hover i {

            color: white;
        }


        .sidebar .nav-link.active {

            background:
                rgba(245, 130, 32, 0.18);

            color: white;

            font-weight: 600;

            border-left:
                3px solid
                var(--ci-orange);

            padding-left: 10px;
        }


        .sidebar .nav-link.active i {

            color: var(--ci-orange);
        }


        /* ======================================================
           CONTENU PRINCIPAL
        ====================================================== */

        .main-area {

            width: calc(100% - var(--sidebar-width));

            margin-left: var(--sidebar-width);

            min-height: calc(100vh - 6px);

            display: flex;

            flex-direction: column;
        }


        /* ======================================================
           TOPBAR
        ====================================================== */

        .topbar {

            min-height: 68px;

            background: white;

            border-bottom:
                1px solid var(--border);

            display: flex;

            align-items: center;

            justify-content: space-between;

            padding:
                0 28px;

            position: sticky;

            top: 0;

            z-index: 900;
        }


        .topbar-title {

            font-size: 14px;

            font-weight: 600;

            color: var(--text-dark);
        }


        .topbar-subtitle {

            color: var(--text-muted);

            font-size: 12px;
        }


        .topbar-year {

            display: inline-flex;

            align-items: center;

            gap: 8px;

            padding:
                8px 13px;

            border:
                1px solid var(--border);

            border-radius: 7px;

            background: #fafbfc;

            color: var(--primary);

            font-size: 12px;

            font-weight: 600;
        }


        .topbar-year i {

            color: var(--ci-orange);
        }


        /* ======================================================
           ZONE DE CONTENU
        ====================================================== */

        .main-content {

            padding: 28px;

            flex: 1;
        }


        /* ======================================================
           TITRES DE PAGE
        ====================================================== */

        .page-heading {

            margin-bottom: 25px;
        }


        .page-heading h1,
        .page-heading h2 {

            color: var(--primary);

            font-size: 23px;

            font-weight: 700;

            margin-bottom: 5px;
        }


        .page-heading p {

            margin: 0;

            color: var(--text-muted);

            font-size: 13px;
        }


        /* ======================================================
           CARTES
        ====================================================== */

        .card {

            border:
                1px solid var(--border);

            border-radius: 9px;

            box-shadow: var(--shadow);

            background: white;
        }


        .card-header {

            background: white;

            color: var(--primary);

            border-bottom:
                1px solid var(--border);

            font-weight: 600;
        }


        /* ======================================================
           CARTES STATISTIQUES (KPI)
        ====================================================== */
        /* Remplace les blocs Bootstrap bg-primary/bg-info/... pleins par des
           tuiles cohérentes avec l'identité (navy/orange/vert), un peu
           partout dans l'application (tableau de bord, listes...). */

        .stat-card {

            display: flex;

            align-items: center;

            gap: 14px;

            height: 100%;

            padding: 16px 18px;

            background: white;

            border: 1px solid var(--border);

            border-radius: 10px;

            box-shadow: var(--shadow);

            transition: var(--transition);
        }


        .stat-card:hover {

            transform: translateY(-1px);

            box-shadow: 0 4px 14px rgba(0, 0, 0, 0.08);
        }


        .stat-card-icon {

            flex-shrink: 0;

            width: 44px;
            height: 44px;

            border-radius: 10px;

            display: flex;

            align-items: center;
            justify-content: center;

            font-size: 19px;

            color: white;
        }


        .stat-card-body {
            min-width: 0;
        }


        .stat-card-value {

            font-size: 22px;

            font-weight: 700;

            color: var(--primary);

            line-height: 1.15;
        }


        .stat-card-label {

            font-size: 11px;

            font-weight: 700;

            color: var(--text-muted);

            text-transform: uppercase;

            letter-spacing: 0.4px;

            margin-top: 2px;
        }


        .stat-card-sub {

            font-size: 11px;

            color: var(--text-muted);

            margin-top: 3px;

            line-height: 1.4;
        }


        .stat-card.is-link {
            text-decoration: none;
            display: flex;
        }


        /* ======================================================
           LIENS DOCUMENT (ex: pages Documents/Résultats)
        ====================================================== */

        .doc-link {

            display: flex;

            align-items: center;

            gap: 10px;

            padding: 10px 12px;

            border-radius: 8px;

            border: 1px solid var(--border);

            background: #fafbfc;

            color: var(--text-dark);

            font-size: 13px;

            font-weight: 500;

            transition: var(--transition);
        }


        .doc-link:hover {

            background: rgba(23, 54, 93, 0.06);

            border-color: var(--primary);

            color: var(--primary);
        }


        .doc-link i.doc-link-icon {

            font-size: 16px;

            color: var(--ci-orange);

            flex-shrink: 0;
        }


        .doc-link.doc-link-excel i.doc-link-icon {
            color: var(--ci-green);
        }


        .doc-link-chevron {

            margin-left: auto;

            color: var(--text-muted);

            font-size: 12px;
        }


        .stat-icon-navy    { background: var(--primary); }
        .stat-icon-orange  { background: var(--ci-orange); }
        .stat-icon-green   { background: var(--ci-green); }
        .stat-icon-blue    { background: #2f7dd1; }
        .stat-icon-teal    { background: #0f9b8e; }
        .stat-icon-red     { background: #d9534f; }
        .stat-icon-gray    { background: #6c7686; }
        .stat-icon-purple  { background: #7c5cbf; }


        /* ======================================================
           BOUTONS
        ====================================================== */

        .btn {

            border-radius: 6px;

            font-size: 13px;

            font-weight: 500;

            padding:
                8px 14px;
        }


        .btn-primary {

            background-color: var(--primary);

            border-color: var(--primary);
        }


        .btn-primary:hover {

            background-color: var(--primary-dark);

            border-color: var(--primary-dark);
        }


        /* ======================================================
           TABLEAUX
        ====================================================== */

        .table {

            font-size: 13px;
        }


        .table thead th {

            color: var(--primary);

            font-size: 11px;

            font-weight: 700;

            text-transform: uppercase;

            letter-spacing: 0.3px;

            background: #f8f9fb;

            border-bottom:
                1px solid var(--border);

            padding:
                12px 14px;
        }


        .table tbody td {

            padding:
                11px 14px;

            vertical-align: middle;

            border-color:
                #edf0f3;
        }


        /* ======================================================
           BADGES
        ====================================================== */

        .badge {

            font-weight: 500;

            border-radius: 5px;

            padding:
                5px 8px;
        }


        /* ======================================================
           ALERTES
        ====================================================== */

        .alert {

            border-radius: 7px;

            border-width: 1px;

            font-size: 13px;
        }


        /* ======================================================
           FORMULAIRES
        ====================================================== */

        .form-label {

            font-size: 13px;

            font-weight: 600;

            color: var(--text-dark);
        }


        .form-control,
        .form-select {

            border-radius: 6px;

            border-color: #dfe4ea;

            font-size: 13px;

            min-height: 40px;
        }


        .form-control:focus,
        .form-select:focus {

            border-color:
                var(--primary);

            box-shadow:
                0 0 0 0.15rem
                rgba(23, 54, 93, 0.12);
        }


        /* ======================================================
           MODALES
        ====================================================== */

        .modal-content {

            border: none;

            border-radius: 10px;

            box-shadow:
                0 15px 45px
                rgba(0, 0, 0, 0.15);
        }


        .modal-header {

            border-bottom:
                1px solid var(--border);
        }


        /* ======================================================
           SCROLLBAR
        ====================================================== */

        .sidebar::-webkit-scrollbar {

            width: 5px;
        }


        .sidebar::-webkit-scrollbar-thumb {

            background:
                rgba(255, 255, 255, 0.18);

            border-radius: 10px;
        }


        /* ======================================================
           RESPONSIVE
        ====================================================== */

        @media (max-width: 991px) {

            :root {
                --sidebar-width: 220px;
            }

            .main-content {
                padding: 20px;
            }

            .topbar {
                padding: 0 20px;
            }
        }


        /* ======================================================
           MENU MOBILE (bouton + rideau)
        ====================================================== */

        .mobile-menu-btn {

            display: none;

            align-items: center;
            justify-content: center;

            width: 38px;
            height: 38px;

            border-radius: 8px;

            border: 1px solid var(--border);

            background: white;

            color: var(--primary);

            font-size: 18px;

            cursor: pointer;

            flex-shrink: 0;
        }


        .sidebar-backdrop {

            display: none;

            position: fixed;

            inset: 0;

            background: rgba(16, 41, 68, 0.45);

            z-index: 1050;
        }


        .sidebar-backdrop.is-open {
            display: block;
        }


        @media (max-width: 767px) {

            .sidebar {

                position: fixed;

                top: 6px;
                left: 0;
                bottom: 0;

                width: 280px;
                max-width: 85%;

                min-height: auto;

                max-height: none;

                transform: translateX(-100%);

                transition: transform 0.25s ease;

                z-index: 1100;
            }


            .sidebar.is-open {

                transform: translateX(0);

                box-shadow: 4px 0 24px rgba(0, 0, 0, 0.3);
            }


            .main-area {

                width: 100%;

                margin-left: 0;
            }


            .app-wrapper {

                display: block;
            }


            .main-content {

                padding: 15px;
            }


            .topbar {

                position: relative;

                padding:
                    12px 15px;

                gap: 10px;

                flex-wrap: wrap;
            }


            .mobile-menu-btn {
                display: inline-flex;
            }
        }

    </style>

</head>


<body>


<!-- Bande institutionnelle -->

<div class="top-government-bar"></div>


<div class="app-wrapper">


    <!-- Rideau sombre affiché derrière le menu mobile ouvert -->
    <div class="sidebar-backdrop" id="sidebarBackdrop" onclick="fermerMenuMobile()"></div>


    <!-- ======================================================
         SIDEBAR
    ======================================================= -->

    <aside class="sidebar" id="sidebarPrincipal">


        <div class="sidebar-brand-line"></div>


        <div class="sidebar-brand">


            <div class="sidebar-brand-title">

                <div class="sidebar-brand-icon">

                    <i class="bi bi-mortarboard-fill"></i>

                </div>


                <span>
                    CEPE Gestion
                </span>

            </div>


            <div class="sidebar-brand-subtitle">

                IEPP Yopougon-Niangon

            </div>


        </div>


        <!-- ANNÉE SCOLAIRE (sélecteur + archivage) -->

        <div class="sidebar-year">

            <span class="sidebar-year-label">

                Année scolaire consultée

            </span>


            <div class="sidebar-year-value">

                <i class="bi bi-calendar3"></i>

                <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>

            </div>


            <?php if (count($anneesDisponibles) > 1): ?>
                <select onchange="window.location.href = 'select_annee.php?annee_id=' + this.value + '&retour=<?= urlencode($retourSelecteur) ?>';">
                    <?php foreach ($anneesDisponibles as $a): ?>
                        <option value="<?= (int) $a['id'] ?>" <?= ((int) $a['id'] === (int) ($anneeSelectionnee['id'] ?? 0)) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($a['annee_scolaire']) ?><?= $a['statut'] === 'archive' ? ' (archivée)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>


            <?php if ($anneeLectureSeule): ?>
                <div class="badge-readonly">
                    <i class="bi bi-lock-fill"></i> Lecture seule
                </div>
            <?php endif; ?>

        </div>


        <!-- MENU -->

        <div class="sidebar-section">

            Principal

        </div>


        <ul class="nav flex-column">


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>"
                    href="index.php"
                >

                    <i class="bi bi-grid-1x2-fill"></i>

                    <span>
                        Tableau de bord
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'ecoles.php' ? 'active' : '' ?>"
                    href="ecoles.php"
                >

                    <i class="bi bi-building"></i>

                    <span>
                        Écoles
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'enseignants.php' ? 'active' : '' ?>"
                    href="enseignants.php"
                >

                    <i class="bi bi-person-badge"></i>

                    <span>
                        Personnel
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'candidats.php' ? 'active' : '' ?>"
                    href="candidats.php"
                >

                    <i class="bi bi-people"></i>

                    <span>
                        Candidats
                    </span>

                </a>

            </li>

        </ul>


        <div class="sidebar-section">

            Organisation des examens

        </div>


        <ul class="nav flex-column">


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'centres.php' ? 'active' : '' ?>"
                    href="centres.php"
                >

                    <i class="bi bi-geo-alt"></i>

                    <span>
                        Centres d'examen
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'plans.php' ? 'active' : '' ?>"
                    href="plans.php"
                >

                    <i class="bi bi-grid-3x3"></i>

                    <span>
                        Plans de salle
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'affectations.php' ? 'active' : '' ?>"
                    href="affectations.php"
                >

                    <i class="bi bi-person-check"></i>

                    <span>
                        Affectations
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'resultats.php' ? 'active' : '' ?>"
                    href="resultats.php"
                >

                    <i class="bi bi-clipboard-data"></i>

                    <span>
                        Résultats
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'cap_ceap.php' ? 'active' : '' ?>"
                    href="cap_ceap.php"
                >

                    <i class="bi bi-award"></i>

                    <span>
                        CAP / CEAP
                    </span>

                </a>

            </li>

        </ul>


        <div class="sidebar-section">

            Documents & rapports

        </div>


        <ul class="nav flex-column">


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'documents.php' ? 'active' : '' ?>"
                    href="documents.php"
                >

                    <i class="bi bi-file-earmark-text"></i>

                    <span>
                        Documents
                    </span>

                </a>

            </li>


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'sauvegarde.php' ? 'active' : '' ?>"
                    href="sauvegarde.php"
                >

                    <i class="bi bi-archive"></i>

                    <span>
                        Sauvegarde
                    </span>

                </a>

            </li>

        </ul>


        <div class="sidebar-section">

            Administration

        </div>


        <ul class="nav flex-column">


            <li class="nav-item">

                <a
                    class="nav-link <?= $currentPage === 'parametres.php' ? 'active' : '' ?>"
                    href="parametres.php"
                >

                    <i class="bi bi-gear"></i>

                    <span>
                        Paramètres
                    </span>

                </a>

            </li>

        </ul>


    </aside>


    <!-- ======================================================
         ZONE PRINCIPALE
    ======================================================= -->

    <div class="main-area">


        <!-- TOPBAR -->

        <header class="topbar">


            <div class="d-flex align-items-center gap-2">

                <button
                    type="button"
                    class="mobile-menu-btn"
                    id="boutonMenuMobile"
                    onclick="ouvrirMenuMobile()"
                    aria-label="Ouvrir le menu"
                >
                    <i class="bi bi-list"></i>
                </button>

                <div>

                    <div class="topbar-title">

                        Application de gestion du CEPE

                    </div>


                    <div class="topbar-subtitle">

                        IEPP Yopougon-Niangon

                    </div>

                </div>

            </div>


            <div class="topbar-year <?= $anneeLectureSeule ? 'is-readonly' : '' ?>">

                <i class="bi bi-<?= $anneeLectureSeule ? 'lock-fill' : 'calendar-check' ?>"></i>

                Année scolaire :

                <strong>
                    <?= htmlspecialchars($ANNEE_SCOLAIRE) ?>
                </strong>
                <?php if ($anneeLectureSeule): ?>
                    — archivée (lecture seule)
                <?php endif; ?>

            </div>


        </header>


        <!-- CONTENU -->

        <main class="main-content">