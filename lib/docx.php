<?php
/**
 * The КП as a Word file (module 016).
 *
 * Of the 37 proposals in the mail archive, 35 left as `.docx` or `.doc` and two
 * as PDF: a закупщик puts our prices into his own НМЦК table and a юрист turns
 * the positions into a спецификация, and neither can do that with a printout.
 * So the manager rebuilt every КП by hand in Word, and the service he was given
 * printed a PDF.
 *
 * The document is NOT written twice. `PdfGenerator::html()` is the КП — the same
 * template, the same frozen requisites, the same match table — and this class
 * converts that one document into WordprocessingML. A change to the template
 * reaches both files, which is the only way the two can stay the same document.
 */
require_once __DIR__ . '/pdf.php';

final class DocxGenerator {

    /**
     * Имя файла .docx — то же, что у PDF, только расширение другое.
     *
     * Метод звали три места (`mail.php`, `proposals.php`, тест модуля 022), а
     * его не было вовсе: приложить Word к письму или скачать его с карточки
     * заканчивалось «Call to undefined method».
     */
    public static function filename(int $proposalId): string {
        return PdfGenerator::fileName($proposalId, 'docx');
    }

    /** Build (or rebuild) the .docx of a proposal. Returns the file path. */
    public static function generate(int $proposalId): string {
        $html = PdfGenerator::html($proposalId);

        $proposal = Db::one("SELECT number FROM proposals WHERE id=?", [$proposalId]);
        $number = (string)($proposal['number'] ?? '') !== '' ? $proposal['number'] : (string)$proposalId;
        $dir = ROOT . '/data/kp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $path = $dir . '/' . PdfGenerator::fileName($proposalId, 'docx');

        (new Html2Docx())->write($html, $path, [
            'title'  => 'Коммерческое предложение ' . $number,
            'author' => (string)(Db::val("SELECT short_name FROM legal_entities WHERE is_active=1 LIMIT 1") ?: 'Atlant Armour'),
        ]);

        Db::update('proposals', ['docx_path' => $path, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$proposalId]);
        return $path;
    }
}

/**
 * HTML → WordprocessingML, for the subset our own template produces.
 *
 * Not a general converter and not trying to be one: it understands the tags the
 * КП uses (headings, paragraphs, tables, lists, images, links, bold) and ignores
 * the rest rather than guessing. Word opens what it writes without a repair
 * prompt, which is the only acceptance test that matters.
 */
final class Html2Docx {

    /** Word measures in EMU; a CSS pixel at 96 dpi is 9525 of them. */
    private const EMU_PER_PX = 9525;
    /** A4 minus 15mm margins, in twips — the widest an image or table may be. */
    private const CONTENT_TWIPS = 9600;

    private array $media = [];      // zip name => binary
    private array $rels = [];       // rId => [type, target]
    private int $relSeq = 0;
    private int $docPrSeq = 0;

    public function write(string $html, string $path, array $meta = []): void {
        $body = $this->parse($html);
        $xml = $this->document($body);

        if (is_file($path)) @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось создать файл .docx: ' . $path);
        }
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->packageRels());
        $zip->addFromString('docProps/core.xml', $this->coreProps($meta));
        $zip->addFromString('word/document.xml', $xml);
        $zip->addFromString('word/styles.xml', $this->styles());
        $zip->addFromString('word/_rels/document.xml.rels', $this->documentRels());
        foreach ($this->media as $name => $bytes) $zip->addFromString('word/' . $name, $bytes);
        $zip->close();
    }

    // ------------------------------------------------------------------ parse

    /** Body of the template as a DOM, with the CSS and the <head> dropped. */
    private function parse(string $html): DOMElement {
        $html = (string)preg_replace('#<(style|script)\b[^>]*>.*?</\1>#isu', '', $html);
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $body = $doc->getElementsByTagName('body')->item(0);
        if (!$body instanceof DOMElement) throw new RuntimeException('КП не удалось разобрать как HTML');
        return $body;
    }

    // -------------------------------------------------------------- rendering

    private function document(DOMElement $body): string {
        $content = $this->blocks($body);
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document ' . $this->namespaces() . '><w:body>'
            . $content
            . '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="567" w:right="850" w:bottom="850" w:left="850" w:header="0" w:footer="0" w:gutter="0"/>'
            . '</w:sectPr></w:body></w:document>';
    }

    /** Every child of a container, in order. Unknown blocks fall back to a paragraph. */
    private function blocks(DOMNode $node, array $inherited = []): string {
        $out = '';
        $runs = '';
        foreach ($node->childNodes as $child) {
            if ($this->isBlock($child)) {
                // Text that stood beside a block is a paragraph of its own;
                // paragraph() itself drops it when it is only whitespace.
                $out .= $this->paragraph($runs, $inherited);
                $runs = '';
                $out .= $this->block($child, $inherited);
            } else {
                $runs .= $this->inline($child, $inherited);
            }
        }
        return $out . $this->paragraph($runs, $inherited);
    }

    private function isBlock(DOMNode $n): bool {
        if (!$n instanceof DOMElement) return false;
        return in_array(strtolower($n->tagName), [
            'div', 'p', 'table', 'ul', 'ol', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'hr', 'blockquote',
        ], true);
    }

    private function block(DOMNode $n, array $inherited): string {
        if (!$n instanceof DOMElement) return '';
        $tag = strtolower($n->tagName);
        $style = $this->styleOf($n, $inherited);
        // «С новой страницы» из шаблона — явным разрывом, а не свойством абзаца:
        // блок бывает контейнером, и его собственный абзац в Word не печатается
        // вовсе (приложение №1 и карточка товара — модуль 034)
        $break = $this->startsPage($n)
            ? '<w:p><w:pPr><w:spacing w:after="0"/></w:pPr><w:r><w:br w:type="page"/></w:r></w:p>' : '';

        return $break . match ($tag) {
            'table' => $this->table($n, $style),
            'ul', 'ol' => $this->list($n, $style, $tag === 'ol'),
            'hr' => '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="CCCCCC"/></w:pBdr></w:pPr></w:p>',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => $this->paragraph(
                $this->inlineChildren($n, ['b' => true] + $style),
                ['b' => true, 'size' => (int)(30 - (int)substr($tag, 1) * 2), 'after' => 120] + $style
            ),
            default => $this->hasBlockChild($n)
                ? $this->blocks($n, $style)
                : $this->paragraph($this->inlineChildren($n, $style), $style),
        };
    }

    /** Блок, который в документе начинает новую страницу. */
    private function startsPage(DOMElement $n): bool {
        return (bool)preg_match('/(^|\s)(appendix|card--break)(\s|$)/',
                                (string)$n->getAttribute('class'));
    }

    private function hasBlockChild(DOMElement $n): bool {
        foreach ($n->childNodes as $c) if ($this->isBlock($c)) return true;
        return false;
    }

    private function inlineChildren(DOMNode $n, array $style): string {
        $out = '';
        foreach ($n->childNodes as $c) $out .= $this->inline($c, $style);
        return $out;
    }

    /** One inline node as a sequence of <w:r> runs. */
    private function inline(DOMNode $n, array $style): string {
        if ($n instanceof DOMText) return $this->run($n->nodeValue ?? '', $style);
        if (!$n instanceof DOMElement) return '';

        $tag = strtolower($n->tagName);
        $style = $this->styleOf($n, $style);

        return match ($tag) {
            'br'  => '<w:r><w:br/></w:r>',
            'img' => $this->image($n),
            'b', 'strong' => $this->inlineChildren($n, $style + ['b' => true]),
            'i', 'em'     => $this->inlineChildren($n, $style + ['i' => true]),
            'u'           => $this->inlineChildren($n, $style + ['u' => true]),
            'a'           => $this->inlineChildren($n, ['color' => 'C00000'] + $style),
            default       => $this->inlineChildren($n, $style),
        };
    }

    private function run(string $text, array $style): string {
        $text = str_replace("\xC2\xA0", ' ', $text);
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        if ($text === '') return '';
        return '<w:r>' . $this->runProps($style) . '<w:t xml:space="preserve">' . $this->esc($text) . '</w:t></w:r>';
    }

    /**
     * Word validates the ORDER of these elements, not just their presence, and
     * a run out of order is what makes it offer to repair the file on open:
     * b, i, color, sz, u — that sequence is the schema's, not a preference.
     */
    private function runProps(array $s): string {
        $p = '';
        if (!empty($s['b'])) $p .= '<w:b/>';
        if (!empty($s['i'])) $p .= '<w:i/>';
        if (!empty($s['color'])) $p .= '<w:color w:val="' . $s['color'] . '"/>';
        if (!empty($s['size'])) $p .= '<w:sz w:val="' . (int)$s['size'] . '"/><w:szCs w:val="' . (int)$s['size'] . '"/>';
        if (!empty($s['u'])) $p .= '<w:u w:val="single"/>';
        return $p === '' ? '' : '<w:rPr>' . $p . '</w:rPr>';
    }

    private function paragraph(string $runs, array $style, string $extraProps = ''): string {
        if (trim(preg_replace('/<[^>]+>/', '', $runs) ?? '') === '' && !str_contains($runs, '<w:drawing>')
            && !str_contains($runs, '<w:br/>')) {
            return '';
        }
        // Same rule as runProps(): spacing, then indent, then alignment
        $p = '<w:spacing w:after="' . (int)($style['after'] ?? 80) . '" w:line="260" w:lineRule="auto"/>';
        if ($extraProps !== '') $p .= $extraProps;
        if (!empty($style['align'])) $p .= '<w:jc w:val="' . $style['align'] . '"/>';
        return '<w:p><w:pPr>' . $p . '</w:pPr>' . $runs . '</w:p>';
    }

    /**
     * Class names of the КП template carry its typography — the title, the red
     * accent, the small print of the requisites block. They are mapped here so
     * the Word file reads like the PDF instead of like a dump of the text.
     */
    private function styleOf(DOMElement $n, array $inherited): array {
        $style = $inherited;
        unset($style['b'], $style['i'], $style['u']);   // emphasis is per-element
        $classes = preg_split('/\s+/', (string)$n->getAttribute('class')) ?: [];
        foreach ($classes as $class) {
            $style = match ($class) {
                'title'        => ['b' => true, 'size' => 32, 'align' => 'center', 'after' => 200] + $style,
                'entity-name'  => ['b' => true, 'size' => 22] + $style,
                'header'       => ['size' => 18, 'after' => 20] + $style,
                'match__title', 'upsell__title', 'req__title', 'card__name', 'card__subtitle'
                               => ['b' => true, 'size' => 22, 'after' => 60] + $style,
                'total-row'    => ['b' => true, 'size' => 24, 'align' => 'right'] + $style,
                'vat-row'      => ['align' => 'right', 'size' => 20] + $style,
                'req', 'req__row', 'disclaimer', 'match__note', 'fits'
                               => ['size' => 18, 'after' => 20] + $style,
                'swap', 'stock-warning', 'accent'
                               => ['color' => 'C00000'] + $style,
                'sign-name'    => ['b' => true] + $style,
                'appendix__title'    => ['b' => true, 'size' => 26, 'align' => 'right'] + $style,
                'appendix__subtitle' => ['b' => true, 'size' => 24, 'align' => 'center'] + $style,
                default        => $style,
            };
        }
        if (str_contains(mb_strtolower((string)$n->getAttribute('style')), 'text-align:center')) {
            $style['align'] = 'center';
        }
        return $style;
    }

    // ----------------------------------------------------------------- tables

    private function table(DOMElement $table, array $style): string {
        $rows = [];
        foreach ($table->getElementsByTagName('tr') as $tr) $rows[] = $tr;
        if (!$rows) return '';

        // Column count comes from the widest row: a template may merge cells in
        // a note row, and Word insists every row declares the same grid.
        $cols = 0;
        foreach ($rows as $tr) $cols = max($cols, count($this->cellsOf($tr)));
        if ($cols === 0) return '';

        // Equal columns turn «Наименование» into a tower of two-letter lines
        // next to a half-empty «Кол-во». The template's own class names say
        // which column is a number and which is prose, so they set the widths.
        $widths = $this->columnWidths($rows, $cols);
        $width = (int)floor(self::CONTENT_TWIPS / $cols);

        $xml = '<w:tbl><w:tblPr><w:tblW w:w="' . self::CONTENT_TWIPS . '" w:type="dxa"/>'
             . '<w:tblBorders>'
             . '<w:top w:val="single" w:sz="4" w:color="BBBBBB"/><w:left w:val="single" w:sz="4" w:color="BBBBBB"/>'
             . '<w:bottom w:val="single" w:sz="4" w:color="BBBBBB"/><w:right w:val="single" w:sz="4" w:color="BBBBBB"/>'
             . '<w:insideH w:val="single" w:sz="4" w:color="BBBBBB"/><w:insideV w:val="single" w:sz="4" w:color="BBBBBB"/>'
             . '</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr><w:tblGrid>';
        foreach ($widths as $w) $xml .= '<w:gridCol w:w="' . $w . '"/>';
        $xml .= '</w:tblGrid>';

        foreach ($rows as $tr) {
            $cells = $this->cellsOf($tr);
            $last = $cells ? $cells[count($cells) - 1] : null;
            $xml .= '<w:tr>';
            $i = 0;
            foreach ($cells as $cell) {
                $isHead = strtolower($cell->tagName) === 'th';
                $span = max(1, (int)($cell->getAttribute('colspan') ?: 1));
                // The last cell of a short row stretches over what is missing
                if ($cell === $last && $i + $span < $cols) $span = $cols - $i;
                $i += $span;

                $cellStyle = $this->styleOf($cell, $style) + ($isHead ? ['b' => true, 'align' => 'center'] : []);
                if ($isHead) $cellStyle['b'] = true;
                $inner = $this->hasBlockChild($cell)
                    ? $this->blocks($cell, $cellStyle)
                    : $this->paragraph($this->inlineChildren($cell, $cellStyle), ['after' => 20] + $cellStyle);
                if (trim($inner) === '') $inner = '<w:p/>';

                $cellWidth = 0;
                for ($c = $i - $span; $c < $i; $c++) $cellWidth += $widths[$c] ?? $width;
                $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $cellWidth . '" w:type="dxa"/>'
                      . ($span > 1 ? '<w:gridSpan w:val="' . $span . '"/>' : '')
                      . ($isHead ? '<w:shd w:val="clear" w:color="auto" w:fill="EFEFEF"/>' : '')
                      . '</w:tcPr>' . $inner . '</w:tc>';
            }
            // A row shorter than the grid still has to close it
            while ($i < $cols) { $xml .= '<w:tc><w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/></w:tcPr><w:p/></w:tc>'; $i++; }
            $xml .= '</w:tr>';
        }
        return $xml . '</w:tbl>' . '<w:p><w:pPr><w:spacing w:after="120"/></w:pPr></w:p>';
    }

    /**
     * Twips per column. A cell that is a number («№», «Кол-во», «Ед.изм.») needs
     * a fraction of what a product name needs, and the template already labels
     * them — `.num`, `.qty`, `.unit`, `.price`, `.sum`, `.state`. Unlabelled
     * columns share what is left, so a table this converter has never seen
     * still comes out readable.
     */
    private function columnWidths(array $rows, int $cols): array {
        $weights = array_fill(0, $cols, 0.0);
        foreach ($rows as $tr) {
            $i = 0;
            foreach ($this->cellsOf($tr) as $cell) {
                $span = max(1, (int)($cell->getAttribute('colspan') ?: 1));
                if ($span === 1 && $i < $cols) {
                    $weights[$i] = max($weights[$i], $this->columnWeight($cell));
                }
                $i += $span;
            }
        }
        $total = array_sum($weights) ?: (float)$cols;
        $out = [];
        $used = 0;
        for ($i = 0; $i < $cols; $i++) {
            $w = $i === $cols - 1
                ? self::CONTENT_TWIPS - $used
                : (int)round(self::CONTENT_TWIPS * (($weights[$i] ?: 1.0) / $total));
            $out[$i] = max(600, $w);
            $used += $out[$i];
        }
        return $out;
    }

    private function columnWeight(DOMElement $cell): float {
        $classes = preg_split('/\s+/', (string)$cell->getAttribute('class')) ?: [];
        foreach ($classes as $class) {
            $w = match ($class) {
                'num', 'unit' => 0.8,
                'qty'         => 1.0,
                'price', 'sum', 'state' => 1.8,
                default       => 0.0,
            };
            if ($w > 0) return $w;
        }
        // No class: judge by what is in it — an article is short, a name is not
        $len = mb_strlen(trim((string)$cell->textContent));
        return $len <= 4 ? 0.9 : ($len <= 14 ? 1.6 : 4.0);
    }

    /** Direct cells of a row — `getElementsByTagName` would also take a nested table's. */
    private function cellsOf(DOMElement $tr): array {
        $cells = [];
        foreach ($tr->childNodes as $c) {
            if ($c instanceof DOMElement && in_array(strtolower($c->tagName), ['td', 'th'], true)) $cells[] = $c;
        }
        return $cells;
    }

    private function list(DOMElement $list, array $style, bool $ordered): string {
        $out = '';
        $n = 1;
        foreach ($list->getElementsByTagName('li') as $li) {
            $marker = $ordered ? ($n++) . '. ' : '— ';
            $runs = $this->run($marker, $style) . $this->inlineChildren($li, $style);
            $out .= $this->paragraph($runs, ['after' => 20] + $style,
                                     '<w:ind w:left="360" w:hanging="180"/>');
        }
        return $out;
    }

    // ----------------------------------------------------------------- images

    /** A data: URI from the template becomes a real part of the package. */
    private function image(DOMElement $img): string {
        $src = (string)$img->getAttribute('src');
        if (!preg_match('#^data:image/([a-z0-9.+-]+);base64,(.+)$#is', $src, $m)) return '';
        $ext = strtolower($m[1]) === 'jpeg' ? 'jpg' : strtolower($m[1]);
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || $bytes === '') return '';

        $size = @getimagesizefromstring($bytes);
        [$w, $h] = $size ? [(int)$size[0], (int)$size[1]] : [480, 320];
        if ($w <= 0 || $h <= 0) return '';
        // A photo pasted at its own pixel size would run off an A4 page; a QR
        // is not a photo and only has to stay big enough for a phone camera,
        // and a signature scan is neither — напечатанная на полстраницы подпись
        // и была тем, на что жаловались (модуль 034).
        $classes = preg_split('/\s+/', strtolower(trim((string)$img->getAttribute('class')))) ?: [];
        $maxPx = 430;
        $maxHeightPx = 0;
        foreach ($classes as $class) {
            if ($class === 'logo')     { $maxPx = 220; }
            if ($class === 'qr')       { $maxPx = 110; }
            if ($class === 'sign-img') { $maxPx = 170; $maxHeightPx = 60; }
        }
        if ($w > $maxPx) { $h = (int)round($h * $maxPx / $w); $w = $maxPx; }
        if ($maxHeightPx > 0 && $h > $maxHeightPx) {
            $w = (int)round($w * $maxHeightPx / $h);
            $h = $maxHeightPx;
        }
        if ($w < 1 || $h < 1) return '';

        $name = 'media/image' . (count($this->media) + 1) . '.' . $ext;
        $this->media[$name] = $bytes;
        $rid = $this->addRel('http://schemas.openxmlformats.org/officeDocument/2006/relationships/image', $name);
        $id = ++$this->docPrSeq;

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . ($w * self::EMU_PER_PX) . '" cy="' . ($h * self::EMU_PER_PX) . '"/>'
            . '<wp:docPr id="' . $id . '" name="Изображение ' . $id . '"/>'
            . '<a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">'
            . '<a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:nvPicPr><pic:cNvPr id="' . $id . '" name="image' . $id . '.' . $ext . '"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'
            . '<pic:spPr><a:xfrm><a:off x="0" y="0"/>'
            . '<a:ext cx="' . ($w * self::EMU_PER_PX) . '" cy="' . ($h * self::EMU_PER_PX) . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    private function addRel(string $type, string $target): string {
        $rid = 'rId' . (++$this->relSeq);
        $this->rels[$rid] = [$type, $target];
        return $rid;
    }

    // ------------------------------------------------------------- boilerplate

    private function namespaces(): string {
        return 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
             . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
             . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"';
    }

    private function contentTypes(): string {
        $ext = ['rels' => 'application/vnd.openxmlformats-package.relationships+xml', 'xml' => 'application/xml'];
        foreach (array_keys($this->media) as $name) {
            $e = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $ext[$e] = match ($e) {
                'png' => 'image/png', 'gif' => 'image/gif', 'bmp' => 'image/bmp',
                'webp' => 'image/webp', default => 'image/jpeg',
            };
        }
        $defaults = '';
        foreach ($ext as $e => $type) $defaults .= '<Default Extension="' . $e . '" ContentType="' . $type . '"/>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . $defaults
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '</Types>';
    }

    private function packageRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdDoc" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rIdCore" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '</Relationships>';
    }

    private function documentRels(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
             . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        foreach ($this->rels as $rid => [$type, $target]) {
            $xml .= '<Relationship Id="' . $rid . '" Type="' . $type . '" Target="' . $target . '"/>';
        }
        return $xml . '</Relationships>';
    }

    private function styles(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles ' . $this->namespaces() . '>'
            . '<w:docDefaults><w:rPrDefault><w:rPr>'
            . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
            . '<w:sz w:val="22"/><w:szCs w:val="22"/><w:lang w:val="ru-RU"/>'
            . '</w:rPr></w:rPrDefault>'
            . '<w:pPrDefault><w:pPr><w:spacing w:after="80" w:line="260" w:lineRule="auto"/></w:pPr></w:pPrDefault>'
            . '</w:docDefaults>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'
            . '</w:styles>';
    }

    private function coreProps(array $meta): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . $this->esc((string)($meta['title'] ?? 'Коммерческое предложение')) . '</dc:title>'
            . '<dc:creator>' . $this->esc((string)($meta['author'] ?? 'Atlant Armour')) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . date('c') . '</dcterms:created>'
            . '</cp:coreProperties>';
    }

    private function esc(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
