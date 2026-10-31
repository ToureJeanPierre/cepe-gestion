<?php
// Export de « ce qu'on voit à l'écran » depuis n'importe quel onglet, en Excel ou en PDF.
//
// Chaque page construit sa liste (avec ses filtres) puis la confie à exporterVue() : ce qui est exporté est donc EXACTEMENT
// la liste affichée. Le bouton « Exporter » (boutonExportVue) reprend l'adresse de la page avec ses filtres et ajoute
// export=xlsx|pdf ; des exports supplémentaires propres à l'onglet (effectifs, écoles ayant déposé...) passent par
// export_type=<nom>.
require_once __DIR__ . '/../vendor/autoload.php'; // Excel (PhpSpreadsheet) et PDF (Dompdf), même si la page ne les chargeait pas
require_once __DIR__ . '/pdf_letterhead.php';
require_once __DIR__ . '/telephone_helpers.php';

if (!function_exists('exportDemande')) {
    /** 'xlsx', 'pdf' ou null selon l'adresse. */
    function exportDemande(): ?string
    {
        $e = $_GET['export'] ?? '';
        return in_array($e, ['xlsx', 'pdf'], true) ? $e : null;
    }
}

if (!function_exists('exportType')) {
    /** Nom de l'export supplémentaire demandé (ex. « effectifs »), '' pour la liste affichée. */
    function exportType(): string
    {
        return preg_replace('/[^a-z_]/', '', (string) ($_GET['export_type'] ?? ''));
    }
}

if (!function_exists('resumeFiltres')) {
    /**
     * « Statut : Privé · Recherche : lagune » — seulement les filtres renseignés.
     *
     * @param array<string, ?string> $filtres libellé => valeur
     */
    function resumeFiltres(array $filtres): string
    {
        $parts = [];
        foreach ($filtres as $libelle => $valeur) {
            if ($valeur !== null && trim((string) $valeur) !== '') {
                $parts[] = $libelle . ' : ' . trim((string) $valeur);
            }
        }
        return $parts ? implode(' · ', $parts) : 'Aucun filtre (liste complète)';
    }
}

if (!function_exists('boutonExportVue')) {
    /**
     * Menu « Exporter » : la liste affichée (avec les filtres actuels) en Excel ou PDF, plus des exports propres à l'onglet.
     *
     * @param array<int, array{type: string, titre: string, aide?: string}> $extras exports supplémentaires
     */
    function boutonExportVue(array $extras = [], string $classe = 'btn btn-outline-success'): string
    {
        $base = $_GET;
        unset($base['export'], $base['export_type'], $base['msg'], $base['page']);
        $url = function (string $format, string $type = '') use ($base): string {
            $q = $base + [];
            $q['export'] = $format;
            if ($type !== '') {
                $q['export_type'] = $type;
            }
            return '?' . http_build_query($q);
        };
        $ligne = function (string $titre, string $type = '', string $aide = '') use ($url): string {
            return '<li class="px-3 py-1"><div class="small fw-semibold">' . htmlspecialchars($titre) . '</div>'
                . ($aide !== '' ? '<div class="text-muted" style="font-size:.75em;">' . htmlspecialchars($aide) . '</div>' : '')
                . '<div class="d-flex gap-2 mt-1">'
                . '<a class="btn btn-sm btn-outline-success" href="' . htmlspecialchars($url('xlsx', $type)) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a>'
                . '<a class="btn btn-sm btn-outline-danger" target="_blank" href="' . htmlspecialchars($url('pdf', $type)) . '"><i class="bi bi-file-earmark-pdf"></i> PDF</a>'
                . '</div></li>';
        };
        $h = '<div class="btn-group">'
            . '<button type="button" class="' . htmlspecialchars($classe) . ' dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-download"></i> Exporter</button>'
            . '<ul class="dropdown-menu dropdown-menu-end" style="min-width: 290px;">'
            . $ligne('La liste affichée', '', 'Avec les filtres et la recherche actuels');
        foreach ($extras as $x) {
            $h .= '<li><hr class="dropdown-divider my-1"></li>';
            if (isset($x['url'])) { // export déjà existant, proposé tel quel (un seul fichier)
                $h .= '<li class="px-3 py-1"><div class="small fw-semibold">' . htmlspecialchars($x['titre']) . '</div>'
                    . (!empty($x['aide']) ? '<div class="text-muted" style="font-size:.75em;">' . htmlspecialchars($x['aide']) . '</div>' : '')
                    . '<a class="btn btn-sm btn-outline-success mt-1" href="' . htmlspecialchars($x['url']) . '"><i class="bi bi-file-earmark-excel"></i> Excel</a></li>';
            } else {
                $h .= $ligne($x['titre'], $x['type'], $x['aide'] ?? '');
            }
        }
        return $h . '</ul></div>';
    }
}

if (!function_exists('exporterVue')) {
    /**
     * Envoie le fichier Excel ou PDF et termine la requête.
     *
     * @param string[]               $colonnes
     * @param array<int, array>      $lignes   chaque ligne = valeurs dans l'ordre des colonnes (nombres gardés numériques)
     * @param array{sous_titre?: string, nom_fichier?: string, orientation?: string, totaux?: int[], libelle_total?: string, annee?: string} $opts
     *        totaux : positions (0, 1, 2...) des colonnes numériques à additionner dans une ligne « TOTAL »
     */
    function exporterVue(string $titre, array $colonnes, array $lignes, string $format, array $opts = []): void
    {
        // Rien ne doit précéder le fichier : toute sortie parasite (avis PHP, espace) le rendrait illisible.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $sousTitre = $opts['sous_titre'] ?? '';
        $base = ($opts['nom_fichier'] ?? preg_replace('/[^A-Za-z0-9]+/', '_', retirerAccentsExport($titre))) . '_' . date('Ymd_His');
        $totaux = $opts['totaux'] ?? [];
        $ligneTotal = null;
        if ($totaux && $lignes) {
            $ligneTotal = array_fill(0, count($colonnes), '');
            $ligneTotal[0] = $opts['libelle_total'] ?? 'TOTAL';
            foreach ($totaux as $i) {
                $ligneTotal[$i] = array_sum(array_map(fn ($l) => is_numeric($l[$i] ?? null) ? (float) $l[$i] : 0, $lignes));
                if ($ligneTotal[$i] == (int) $ligneTotal[$i]) {
                    $ligneTotal[$i] = (int) $ligneTotal[$i];
                }
            }
        }
        $annee = $opts['annee'] ?? ($GLOBALS['ANNEE_SCOLAIRE'] ?? '');
        $infos = trim(($sousTitre !== '' ? $sousTitre . ' — ' : '') . count($lignes) . ' ligne(s) — édité le ' . date('d/m/Y à H:i'));

        if ($format === 'pdf') {
            exporterVuePdf($titre, $colonnes, $lignes, $ligneTotal, $infos, (string) $annee, $opts['orientation'] ?? (count($colonnes) > 6 ? 'landscape' : 'portrait'), $base);
            exit;
        }
        exporterVueExcel($titre, $colonnes, $lignes, $ligneTotal, $infos, (string) $annee, $base);
        exit;
    }
}

if (!function_exists('retirerAccentsExport')) {
    function retirerAccentsExport(string $t): string
    {
        return strtr($t, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'à' => 'a', 'â' => 'a', 'î' => 'i', 'ï' => 'i', 'ô' => 'o', 'ù' => 'u', 'û' => 'u', 'ç' => 'c',
            'É' => 'E', 'È' => 'E', 'À' => 'A', 'Ç' => 'C']);
    }
}

if (!function_exists('exporterVueExcel')) {
    function exporterVueExcel(string $titre, array $colonnes, array $lignes, ?array $ligneTotal, string $infos, string $annee, string $base): void
    {
        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $feuille = $classeur->getActiveSheet();
        $feuille->setTitle(mb_substr(preg_replace('/[\[\]\*\/\\\\\?:]/', ' ', $titre), 0, 31) ?: 'Export');
        $nbCol = max(1, count($colonnes));
        $derniere = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nbCol);

        $feuille->setCellValue('A1', mb_strtoupper($titre) . ($annee !== '' ? ' — ' . $annee : ''));
        $feuille->mergeCells("A1:{$derniere}1");
        $feuille->getStyle('A1')->getFont()->setBold(true)->setSize(13);
        $feuille->setCellValue('A2', $infos);
        $feuille->mergeCells("A2:{$derniere}2");
        $feuille->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setRGB('555555');

        $ligneEntete = 4;
        foreach ($colonnes as $i => $c) {
            $feuille->setCellValueExplicit([$i + 1, $ligneEntete], (string) $c, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        $stEntete = $feuille->getStyle("A{$ligneEntete}:{$derniere}{$ligneEntete}");
        $stEntete->getFont()->setBold(true);
        $stEntete->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('DDEBF7');
        $stEntete->getAlignment()->setWrapText(true)->setVertical(\PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER);

        $r = $ligneEntete + 1;
        $ecrire = function (array $ligne, int $r) use ($feuille) {
            foreach ($ligne as $i => $v) {
                if ($v === null || $v === '') {
                    continue;
                }
                // Nombres (effectifs...) numériques ; tout le reste en texte (matricules, téléphones, dates, n° commençant par 0).
                if (is_int($v) || is_float($v)) {
                    $feuille->setCellValueExplicit([$i + 1, $r], $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                } else {
                    $feuille->setCellValueExplicit([$i + 1, $r], (string) $v, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
        };
        foreach ($lignes as $l) {
            $ecrire(array_values($l), $r++);
        }
        if ($ligneTotal) {
            $ecrire($ligneTotal, $r);
            $feuille->getStyle("A{$r}:{$derniere}{$r}")->getFont()->setBold(true);
            $feuille->getStyle("A{$r}:{$derniere}{$r}")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F2');
        }
        for ($i = 1; $i <= $nbCol; $i++) {
            $feuille->getColumnDimensionByColumn($i)->setAutoSize(true);
        }
        $feuille->freezePane('A' . ($ligneEntete + 1));
        if ($lignes) {
            $feuille->setAutoFilter("A{$ligneEntete}:{$derniere}" . ($ligneEntete + count($lignes)));
        }
        $feuille->getPageSetup()->setOrientation($nbCol > 6 ? \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_LANDSCAPE : \PhpOffice\PhpSpreadsheet\Worksheet\PageSetup::ORIENTATION_PORTRAIT);
        $feuille->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $feuille->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($ligneEntete, $ligneEntete);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $base . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save('php://output');
    }
}

if (!function_exists('exporterVuePdf')) {
    function exporterVuePdf(string $titre, array $colonnes, array $lignes, ?array $ligneTotal, string $infos, string $annee, string $orientation, string $base): void
    {
        $html = '<html><head><meta charset="UTF-8"><style>' . pdfStylesCommunes()
            . ' thead { display: table-header-group; } tr { page-break-inside: avoid; }'
            . ' table.doc-table td, table.doc-table th { font-size: ' . (count($colonnes) > 9 ? '8' : '9') . 'px; }'
            . ' .infos-export { font-size: 9px; color: #555; margin: 2px 0 6px; text-align: center; }'
            . ' td.num, th.num { text-align: right; }'
            . ' tr.total td { font-weight: bold; background: #f2f2f2; }'
            . '</style></head><body>';
        $html .= enteteIepp($annee);
        $html .= '<div class="titre-doc"><div class="nom-doc" style="font-size:16px;">' . htmlspecialchars(mb_strtoupper($titre)) . '</div></div>';
        $html .= '<div class="infos-export">' . htmlspecialchars($infos) . '</div>';

        // Colonnes numériques alignées à droite.
        $numeriques = [];
        foreach ($colonnes as $i => $_) {
            $numeriques[$i] = $lignes && count(array_filter($lignes, fn ($l) => is_int(array_values($l)[$i] ?? null) || is_float(array_values($l)[$i] ?? null))) === count($lignes);
        }
        $html .= '<table class="doc-table"><thead><tr>';
        foreach ($colonnes as $i => $c) {
            $html .= '<th' . ($numeriques[$i] ? ' class="num"' : '') . '>' . htmlspecialchars((string) $c) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        if (!$lignes) {
            $html .= '<tr><td colspan="' . count($colonnes) . '" style="text-align:center;padding:14px;">Aucune donnée pour ces filtres.</td></tr>';
        }
        foreach ($lignes as $l) {
            $html .= '<tr>';
            foreach (array_values($l) as $i => $v) {
                $html .= '<td' . ($numeriques[$i] ?? false ? ' class="num"' : '') . '>' . nl2br(htmlspecialchars((string) ($v ?? ''))) . '</td>';
            }
            $html .= '</tr>';
        }
        if ($ligneTotal) {
            $html .= '<tr class="total">';
            foreach ($ligneTotal as $i => $v) {
                $html .= '<td' . ($numeriques[$i] ?? false ? ' class="num"' : '') . '>' . htmlspecialchars((string) $v) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></body></html>';

        $dompdf = creerDompdfIepp();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $police = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 90, $canvas->get_height() - 24, 'Page {PAGE_NUM} / {PAGE_COUNT}', $police, 8, [0.3, 0.3, 0.3]);
        $dompdf->stream($base . '.pdf', ['Attachment' => false]);
    }
}

if (!function_exists('exporterDepotEcoles')) {
    /**
     * Deux listes SÉPARÉES : les écoles qui ont déposé leurs candidats (ou leur personnel), et celles qui n'ont rien déposé.
     *
     * @param string     $quoi       'candidats' ou 'personnel'
     * @param bool       $deposees   true = écoles ayant déposé, false = écoles n'ayant PAS déposé
     * @param int[]|null $idsEcoles  restreindre à ces écoles (ex. le filtre Public / Privé de la page), null = toutes
     */
    function exporterDepotEcoles(PDO $pdo, int $anneeId, string $quoi, bool $deposees, string $format, ?array $idsEcoles, string $anneeLibelle, string $filtresTexte = ''): void
    {
        $jointure = $quoi === 'candidats'
            ? "LEFT JOIN candidats x ON x.ecole_id = e.id AND x.annee_id = ? AND x.est_candidat_libre = 0"
            : "LEFT JOIN personnel x ON x.ecole_id = e.id AND x.annee_id = ? AND x.categorie = 'enseignant'";
        $st = $pdo->prepare("
            SELECT e.id, e.nom, e.statut, e.code_dsps, e.directeur_nom, e.directeur_telephone, t.nom AS tutrice, COUNT(x.id) AS nb, MAX(x.created_at) AS dernier
            FROM ecoles e LEFT JOIN ecoles t ON t.id = e.ecole_tutrice_id
            $jointure
            GROUP BY e.id ORDER BY e.nom
        ");
        $st->execute([$anneeId]);
        $toutes = array_filter($st->fetchAll(), fn ($e) => $idsEcoles === null || in_array((int) $e['id'], array_map('intval', $idsEcoles), true));
        $retenues = array_values(array_filter($toutes, fn ($e) => ((int) $e['nb'] > 0) === $deposees));
        $telephones = fn ($t) => implode(' / ', extraireNumerosTelephone((string) $t));
        $objet = $quoi === 'candidats' ? 'leurs candidats' : 'leur liste d\'enseignants';
        $sousTitre = trim(($filtresTexte !== '' ? $filtresTexte . ' — ' : '') . 'année ' . $anneeLibelle . ' — ' . count($retenues) . ' école(s) sur ' . count($toutes));
        $lignes = [];
        if ($deposees) {
            foreach ($retenues as $e) {
                $lignes[] = [$e['nom'], $e['statut'], (int) $e['nb'], $e['dernier'] ? date('d/m/Y', strtotime($e['dernier'])) : '', $e['directeur_nom'] ?? '', $telephones($e['directeur_telephone'])];
            }
            exporterVue('Écoles ayant déposé ' . $objet, ['École', 'Secteur', $quoi === 'candidats' ? 'Candidats reçus' : 'Enseignants reçus', 'Dernier dépôt', 'Directeur', 'Téléphone'],
                $lignes, $format, ['sous_titre' => $sousTitre, 'totaux' => [2], 'libelle_total' => 'TOTAL REÇU', 'nom_fichier' => 'ont_depose_' . $quoi]);
        }
        foreach ($retenues as $e) {
            $lignes[] = [$e['nom'], $e['statut'], $e['code_dsps'] ?? '', $e['tutrice'] ?? '', $e['directeur_nom'] ?? '', $telephones($e['directeur_telephone'])];
        }
        exporterVue('Écoles n\'ayant PAS déposé ' . $objet, ['École', 'Secteur', 'Code DSPS', 'École tutrice', 'Directeur', 'Téléphone'],
            $lignes, $format, ['sous_titre' => $sousTitre, 'nom_fichier' => 'pas_depose_' . $quoi]);
    }
}
