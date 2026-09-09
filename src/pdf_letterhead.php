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
// Note : le blason de la République n'a pas pu être récupéré en image depuis
// le PDF fourni (aucun outil d'extraction disponible sur ce poste) ; il est
// remplacé par le bandeau tricolore déjà utilisé dans l'application. Si vous
// fournissez le fichier image du blason, il peut être intégré facilement.

if (!function_exists('pdfStylesCommunes')) {
    function pdfStylesCommunes(): string
    {
        return '
            body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1a1a1a; }
            .entete-table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
            .entete-table td { border: none; vertical-align: top; padding: 0; }
            .entete-gauche { font-size: 9px; line-height: 1.5; text-align: center; width: 62%; }
            .entete-gauche .ministere { font-weight: bold; }
            .entete-droite { font-size: 9px; line-height: 1.5; text-align: center; width: 38%; }
            .entete-droite .republique { font-weight: bold; }
            .drapeau-barre { height: 4px; margin: 4px auto; width: 80px; background: linear-gradient(to right, #f58220 0%, #f58220 33%, #ffffff 33%, #ffffff 66%, #009e49 66%, #009e49 100%); border: 1px solid #ddd; }
            .entete-trait { border: none; border-top: 1px dashed #666; margin: 2px 0; }
            .titre-doc { text-align: center; margin: 14px 0 4px; }
            .titre-doc .session { font-size: 15px; font-weight: bold; }
            .titre-doc .nom-doc { font-size: 13px; font-weight: bold; text-decoration: underline; margin-top: 2px; }
            .numero-reference { font-size: 10px; margin: 6px 0 12px; }
            .signature-bloc { margin-top: 30px; text-align: right; font-size: 11px; }
            .signature-bloc .fonction { margin-top: 40px; }
            .signature-bloc .nom { font-weight: bold; text-decoration: underline; margin-top: 2px; }
            table.doc-table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            table.doc-table th, table.doc-table td { border: 1px solid #999; padding: 4px 6px; text-align: left; font-size: 10px; }
            table.doc-table th { background: #f0f2f5; }
        ';
    }
}

if (!function_exists('enteteIepp')) {
    function enteteIepp(string $anneeScolaire): string
    {
        return '
            <table class="entete-table">
                <tr>
                    <td class="entete-gauche">
                        <div class="ministere">MINISTERE DE L\'EDUCATION NATIONALE DE<br>L\'ALPHABETISATION ET DE L\'ENSEIGNEMENT TECHNIQUE</div>
                        <hr class="entete-trait">
                        <div>DIRECTION REGIONALE ABIDJAN 3</div>
                        <hr class="entete-trait">
                        <div>INSPECTION DE L\'ENSEIGNEMENT DE PRESCOLAIRE ET<br>PRIMAIRE DE YOPOUGON NIANGON</div>
                        <hr class="entete-trait">
                        <div>TEL : 01 51 76 98 08</div>
                        <div>EMAIL : yopniangon2008@gmail.com</div>
                        <div>Service : Examens et Concours</div>
                    </td>
                    <td class="entete-droite">
                        <div class="republique">REPUBLIQUE DE C&Ocirc;TE D\'IVOIRE</div>
                        <div>Union &ndash; Discipline - Travail</div>
                        <div class="drapeau-barre"></div>
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
                <div>Fait &agrave; Abidjan, le ……… / ……… / …………</div>
                <div class="fonction">Le Chef de Circonscription</div>
                <div class="nom">SYLLA ISSIAKA</div>
                <div>Inspecteur de l\'Enseignement Pr&eacute;scolaire et Primaire</div>
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
