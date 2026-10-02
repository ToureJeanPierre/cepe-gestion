<?php

// ==========================================
// DIFFUSION D'UN DOCUMENT : PDF (par défaut) OU EXCEL (?format=xlsx)
// ==========================================
// Tous les documents officiels sont construits en HTML puis rendus en PDF.
// Plutôt que de réécrire chaque document pour Excel (et de risquer qu'un
// chiffre diverge entre les deux versions), la version Excel est extraite du
// MÊME HTML : mêmes données, mêmes totaux, garantis identiques au PDF.
// L'en-tête officielle et le bloc de signature (mise en forme pour
// l'impression) sont omis ; chaque tableau devient un tableau Excel.

if (!function_exists('htmlVersSectionsExcel')) {
    /**
     * @return array<int, array{rows: array<int, array{cells: string[], bold: bool, entete: bool, titre: bool}>}>
     */
    function htmlVersSectionsExcel(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        $sections = [['rows' => []]];
        $blocs = ['table', 'div', 'p', 'h1', 'h2', 'h3', 'h4', 'ul', 'ol', 'li'];

        $nettoyer = function (string $t): string {
            $t = str_replace("\u{00A0}", ' ', $t);
            $lignes = array_map(fn ($l) => trim(preg_replace('/[ \t]+/u', ' ', $l)), explode("\n", $t));
            return implode("\n", array_filter($lignes, fn ($l) => $l !== ''));
        };
        $texteDe = null;
        $texteDe = function (DOMNode $n) use (&$texteDe): string {
            if ($n->nodeType === XML_TEXT_NODE) {
                return $n->nodeValue;
            }
            if ($n->nodeType !== XML_ELEMENT_NODE) {
                return '';
            }
            $tag = strtolower($n->nodeName);
            if ($tag === 'br') {
                return "\n";
            }
            if (in_array($tag, ['style', 'script', 'img'], true)) {
                return '';
            }
            $s = '';
            foreach ($n->childNodes as $c) {
                $s .= $texteDe($c);
            }
            return $s;
        };
        $ajouter = function (array $cells, bool $bold = false, bool $entete = false, bool $titre = false) use (&$sections) {
            $sections[count($sections) - 1]['rows'][] = ['cells' => $cells, 'bold' => $bold, 'entete' => $entete, 'titre' => $titre];
        };
        $aBlocEnfant = function (DOMElement $el) use ($blocs): bool {
            foreach ($el->getElementsByTagName('*') as $d) {
                if (in_array(strtolower($d->nodeName), $blocs, true)) {
                    return true;
                }
            }
            return false;
        };

        $parcourir = null;
        $parcourir = function (DOMNode $parent) use (&$parcourir, $ajouter, $aBlocEnfant, $nettoyer, $texteDe, &$sections) {
            foreach ($parent->childNodes as $n) {
                if ($n->nodeType === XML_TEXT_NODE) {
                    $t = $nettoyer($n->nodeValue);
                    if ($t !== '') {
                        $ajouter([$t]);
                    }
                    continue;
                }
                if ($n->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }
                /** @var DOMElement $n */
                $tag = strtolower($n->nodeName);
                $classe = ' ' . $n->getAttribute('class') . ' ';

                if (in_array($tag, ['style', 'script', 'img', 'hr', 'head'], true)) {
                    continue;
                }
                if (str_contains($classe, ' signature-bloc ') || str_contains($classe, ' entete-table ')) {
                    continue;
                }
                if (str_contains($classe, ' page-break ')) {
                    if ($sections[count($sections) - 1]['rows']) {
                        $sections[] = ['rows' => []];
                    }
                    continue;
                }
                if ($tag === 'table') {
                    $nbLignes = 0;
                    foreach ($n->getElementsByTagName('tr') as $tr) {
                        $cells = [];
                        $toutTh = true;
                        foreach ($tr->childNodes as $td) {
                            if ($td->nodeType !== XML_ELEMENT_NODE || !in_array(strtolower($td->nodeName), ['td', 'th'], true)) {
                                continue;
                            }
                            if (strtolower($td->nodeName) !== 'th') {
                                $toutTh = false;
                            }
                            $cells[] = $nettoyer($texteDe($td));
                            for ($i = 1, $span = (int) $td->getAttribute('colspan'); $i < $span; $i++) {
                                $cells[] = '';
                            }
                        }
                        if ($cells) {
                            $ajouter($cells, $toutTh, $toutTh);
                            $nbLignes++;
                        }
                    }
                    if ($nbLignes) {
                        $ajouter([]);
                    }
                    continue;
                }
                if ($aBlocEnfant($n)) {
                    $parcourir($n);
                    continue;
                }
                $t = $nettoyer($texteDe($n));
                if ($t === '') {
                    continue;
                }
                $estTitre = in_array($tag, ['h1', 'h2', 'h3', 'h4'], true) || str_contains($classe, ' nom-doc ') || str_contains($classe, ' session ');
                // "<strong>LIBELLÉ :</strong> valeur" -> deux colonnes (libellé | valeur).
                $premier = $n->firstChild;
                if ($premier && $premier->nodeType === XML_ELEMENT_NODE && strtolower($premier->nodeName) === 'strong') {
                    $libelle = $nettoyer($texteDe($premier));
                    $reste = $nettoyer(mb_substr($t, mb_strlen($libelle)));
                    $ajouter([$libelle, $reste]);
                    continue;
                }
                $ajouter([$t], $estTitre, false, $estTitre && ($tag === 'h2' || $tag === 'h3'));
            }
        };

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body) {
            $parcourir($body);
        }

        return array_values(array_filter($sections, fn ($s) => $s['rows']));
    }
}

if (!function_exists('exporterHtmlVersExcel')) {
    function exporterHtmlVersExcel(string $html, string $nomFichierBase): void
    {
        $sections = htmlVersSectionsExcel($html);
        if (!$sections) {
            $sections = [['rows' => [['cells' => ['Aucune donnée dans ce document.'], 'bold' => false, 'entete' => false, 'titre' => false]]]];
        }

        $classeur = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $classeur->removeSheetByIndex(0);

        // Une feuille par section (centre, salle, école...) quand il y en a un
        // nombre raisonnable ; au-delà, une seule feuille filtrable vaut mieux
        // que des centaines d'onglets.
        $parFeuille = count($sections) > 1 && count($sections) <= 40;
        if (!$parFeuille) {
            $fusion = ['rows' => []];
            foreach ($sections as $i => $s) {
                if ($i > 0) {
                    $fusion['rows'][] = ['cells' => [], 'bold' => false, 'entete' => false, 'titre' => false];
                }
                $fusion['rows'] = array_merge($fusion['rows'], $s['rows']);
            }
            $sections = [$fusion];
        }

        $nomsPris = [];
        foreach ($sections as $index => $section) {
            $nom = 'Document';
            if ($parFeuille) {
                foreach ($section['rows'] as $r) {
                    if ($r['titre']) {
                        $nom = $r['cells'][0];
                        break;
                    }
                }
                if ($nom === 'Document') {
                    foreach ($section['rows'] as $r) {
                        if (count($r['cells']) === 2 && preg_match('/^(CENTRE|SALLE|ECOLE|ÉCOLE)\b/iu', $r['cells'][0])) {
                            $nom = $r['cells'][1] !== '' ? $r['cells'][1] : $r['cells'][0];
                            break;
                        }
                    }
                }
                if ($nom === 'Document') {
                    $nom = $section['rows'][0]['cells'][0] ?? ('Feuille ' . ($index + 1));
                }
            }
            // Retire une parenthèse finale ("(30 candidats)") avant de tronquer à
            // 28 caractères, sinon elle coupe le nom de l'onglet en plein milieu.
            $sansParenthese = trim(preg_replace('/\s*\([^)]*\)?\s*$/u', '', $nom));
            if ($sansParenthese !== '') {
                $nom = $sansParenthese;
            }
            $nom = trim(mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $nom), 0, 28)) ?: 'Feuille';
            $base = $nom;
            for ($n = 2; isset($nomsPris[mb_strtolower($nom)]); $n++) {
                $nom = mb_substr($base, 0, 25) . ' (' . $n . ')';
            }
            $nomsPris[mb_strtolower($nom)] = true;

            $feuille = $classeur->createSheet();
            $feuille->setTitle($nom);

            $ligne = 1;
            $largeurMax = 1;
            foreach ($section['rows'] as $r) {
                foreach ($r['cells'] as $i => $valeur) {
                    $coord = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i + 1) . $ligne;
                    // Nombres réels (notes, effectifs) en numérique pour pouvoir
                    // calculer/trier ; matricules, téléphones, codes (zéro initial
                    // ou plus de 6 chiffres) restent du texte pour ne rien altérer.
                    if (preg_match('/^(0|[1-9]\d{0,5})$/', $valeur)) {
                        $feuille->setCellValueExplicit($coord, (int) $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                    } elseif (preg_match('/^(0|[1-9]\d{0,5})[.,]\d{1,2}$/', $valeur)) {
                        $feuille->setCellValueExplicit($coord, (float) str_replace(',', '.', $valeur), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
                    } else {
                        $feuille->setCellValueExplicit($coord, $valeur, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    }
                    if (str_contains($valeur, "\n")) {
                        $feuille->getStyle($coord)->getAlignment()->setWrapText(true);
                    }
                }
                $largeurMax = max($largeurMax, count($r['cells']));
                if ($r['bold'] && $r['cells']) {
                    $plage = 'A' . $ligne . ':' . \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(count($r['cells'])) . $ligne;
                    $feuille->getStyle($plage)->getFont()->setBold(true);
                    if ($r['entete']) {
                        $feuille->getStyle($plage)->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setRGB('D9E7F5');
                    }
                }
                $ligne++;
            }
            for ($c = 1; $c <= $largeurMax; $c++) {
                $feuille->getColumnDimensionByColumn($c)->setAutoSize(true);
            }
        }
        $classeur->setActiveSheetIndex(0);

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $nomFichierBase . '.xlsx"');
        header('Cache-Control: max-age=0');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($classeur))->save('php://output');
    }
}

if (!function_exists('diffuserDocumentIepp')) {
    // $orientation : 'portrait' ou 'landscape' (PDF uniquement).
    function diffuserDocumentIepp(string $html, string $nomFichierBase, string $orientation = 'portrait'): void
    {
        if (($_GET['format'] ?? '') === 'xlsx') {
            exporterHtmlVersExcel($html, $nomFichierBase);
            exit;
        }

        $dompdf = creerDompdfIepp();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', $orientation);
        $dompdf->render();
        $dompdf->stream($nomFichierBase . '.pdf', ['Attachment' => false]);
    }
}
