<?php

// ==========================================
// EN-TÊTES OFFICIELLES POUR LES DOCUMENTS IMPRIMÉS (dompdf)
// ==========================================
// Reproduit fidèlement les deux en-têtes utilisées par l'IEPP Yopougon-Niangon :
//   - enteteIepp()  : documents émis localement par l'IEPP (listes, annexes,
//                     statistiques, mission de supervision, bordereaux...)
//   - enteteDeco()  : convocations émises sur le modèle national de la
//                     Direction des Examens et Concours (DECO)
// Coordonnées et signataire mis à jour depuis l'en-tête officielle 2026-2027
// (SYLLA ISSIAKA, nouvel Inspecteur — remplace SORO TIONRO).
//
// Le blason de la République (public/img/armoiries_cote_ivoire.png) a été
// extrait du modèle PDF fourni le 22/09/2026 ("entête actualisé 2026-1.pdf")
// et fond détouré (fond noir d'origine retiré via son masque de transparence).

if (!function_exists('creerDompdfIepp')) {
    // dompdf restreint par défaut l'accès aux fichiers locaux (ex : le blason)
    // à son propre dossier vendor/dompdf/dompdf — sans chroot élargi à la
    // racine du projet, une <img> vers public/img/... échoue silencieusement.
    function creerDompdfIepp(): \Dompdf\Dompdf
    {
        $options = new \Dompdf\Options();
        $options->set('isRemoteEnabled', false);
        $options->set('chroot', realpath(__DIR__ . '/..'));

        return new \Dompdf\Dompdf($options);
    }
}

if (!function_exists('pdfStylesCommunes')) {
    function pdfStylesCommunes(): string
    {
        return '
            @page { margin: 14mm 14mm 14mm 14mm; }
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; margin: 0; }
            .entete-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .entete-table td { border: none; vertical-align: top; padding: 0; }
            .entete-gauche { font-size: 9px; line-height: 1.5; width: 66%; padding-left: 0; }
            .entete-gauche-inner { display: inline-block; text-align: center; }
            .entete-gauche .ministere { font-weight: bold; }
            .entete-droite { font-size: 9px; line-height: 1.5; text-align: center; width: 34%; }
            .entete-droite .republique { font-weight: bold; }
            .armoiries { width: 42px; height: auto; margin: 3px auto; display: block; }
            .drapeau-barres { margin: 4px auto; text-align: center; }
            .drapeau-barres span { display: inline-block; height: 6px; width: 32px; }
            .drapeau-barres .barre-orange { background: #EC7C30; margin-right: 32px; }
            .drapeau-barres .barre-verte { background: #00AF50; }
            .entete-trait { border: none; border-top: 1px dashed #666; width: 130px; margin: 2px auto; }
            .titre-doc { text-align: center; margin: 14px 0 4px; }
            .titre-doc .session { font-size: 15px; font-weight: bold; }
            .titre-doc .nom-doc { font-size: 13px; font-weight: bold; text-decoration: underline; margin-top: 2px; }
            .numero-reference { font-size: 10px; margin: 6px 0 12px; }
            .signature-bloc { margin-top: 30px; text-align: right; font-size: 11px; page-break-inside: avoid; }
            .signature-bloc-titre { display: inline-block; text-align: center; }
            .signature-bloc .fonction { margin-top: 40px; }
            .signature-bloc .nom { font-weight: bold; text-decoration: underline; margin-top: 45px; }
            table.doc-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            table.doc-table th, table.doc-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; font-size: 10px; }
            table.doc-table th { background: #f0f2f5; }
        ';
    }
}

if (!function_exists('enteteIepp')) {
    function enteteIepp(string $anneeScolaire): string
    {
        $blason = str_replace('\\', '/', realpath(__DIR__ . '/../public/img/armoiries_cote_ivoire.png'));

        return '
            <table class="entete-table">
                <tr>
                    <td class="entete-gauche">
                        <div class="entete-gauche-inner">
                            <div class="ministere">MINISTERE DE L\'EDUCATION NATIONALE DE<br>L\'ALPHABETISATION ET DE L\'ENSEIGNEMENT TECHNIQUE</div>
                            <hr class="entete-trait">
                            <div>DIRECTION REGIONALE ABIDJAN 3</div>
                            <hr class="entete-trait">
                            <div>INSPECTION DE L\'ENSEIGNEMENT DE PRESCOLAIRE ET<br>PRIMAIRE DE YOPOUGON NIANGON</div>
                            <hr class="entete-trait">
                            <div>TEL : 01 51 76 98 08</div>
                            <div>EMAIL : yopniangon2008@gmail.com</div>
                            <div>Service : Examens et Concours</div>
                        </div>
                    </td>
                    <td class="entete-droite">
                        <div class="republique">REPUBLIQUE DE C&Ocirc;TE D\'IVOIRE</div>
                        <img src="' . $blason . '" class="armoiries" alt="">
                        <div>Union &ndash; Discipline - Travail</div>
                        <div class="drapeau-barres"><span class="barre-orange"></span><span class="barre-verte"></span></div>
                        <div>ANNEE SCOLAIRE : ' . htmlspecialchars($anneeScolaire) . '</div>
                    </td>
                </tr>
            </table>
        ';
    }
}

if (!function_exists('titreDocumentIepp')) {
    function titreDocumentIepp(string $sessionLibelle, string $nomDocument): string
    {
        return '
            <div class="titre-doc">
                <div class="session">' . htmlspecialchars($sessionLibelle) . '</div>
                <div class="nom-doc">' . htmlspecialchars($nomDocument) . '</div>
            </div>
        ';
    }
}

if (!function_exists('numeroReferencePointille')) {
    // Ligne de référence laissée en pointillés — à compléter à la main après
    // impression (choix explicite : pas de compteur automatique en base).
    function numeroReferencePointille(): string
    {
        return '<div class="numero-reference">N&deg; ……………………………/2026/IEP/YOP-NIANGON/EXAM</div>';
    }
}

if (!function_exists('signatureIepp')) {
    function signatureIepp(): string
    {
        return '
            <div class="signature-bloc">
                <div>Fait &agrave; Abidjan, le ' . date('d/m/Y') . '</div>
                <div class="signature-bloc-titre">
                    <div class="fonction">Le Chef de Circonscription</div>
                    <div class="nom">SYLLA ISSIAKA</div>
                    <div>Inspecteur de l\'Enseignement Pr&eacute;scolaire et Primaire</div>
                </div>
            </div>
        ';
    }
}

if (!function_exists('enteteDeco')) {
    // En-tête nationale utilisée sur les convocations (modèle DECO), en vert,
    // distincte de l'en-tête IEPP locale.
    function enteteDeco(): string
    {
        return '
            <table class="entete-table">
                <tr>
                    <td class="entete-gauche" style="color:#1a6b2f;">
                        <div class="ministere" style="color:#1a6b2f;">MINISTERE DE L\'EDUCATION NATIONALE,<br>DE L\'ALPHABETISATION ET DE L\'ENSEIGNEMENT TECHNIQUE</div>
                        <hr class="entete-trait">
                        <div>DIRECTION DES EXAMENS ET CONCOURS</div>
                    </td>
                    <td class="entete-droite">
                        <div class="republique">REPUBLIQUE DE C&Ocirc;TE D\'IVOIRE</div>
                        <div>Union &ndash; Discipline - Travail</div>
                        <div class="drapeau-barre"></div>
                    </td>
                </tr>
            </table>
        ';
    }
}

if (!function_exists('piedDeco')) {
    function piedDeco(): string
    {
        return '<div style="margin-top:20px; font-size:8px; color:#555; border-top:1px solid #ccc; padding-top:4px;">BP V 276 Abidjan / Fax 27 20 32 66 49 &nbsp; e-mail: courriel@men-deco.org &nbsp; Site: www.men-deco.org</div>';
    }
}
