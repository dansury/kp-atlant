<?php
namespace Atlant\KpSync;

/**
 * Минимальный .xlsx без библиотек: один лист, строки текстом, первая строка —
 * жирная шапка, в ячейках перенос строк. На сайте нет Composer, а ZipArchive
 * есть в любом PHP с расширением zip.
 *
 * Чистый класс — без Битрикса, чтобы его можно было проверить где угодно.
 */
final class Xlsx
{
    /**
     * @param string[]            $head   названия столбцов
     * @param array<int,string[]> $rows   строки, по ячейке на столбец
     * @param int[]               $widths ширина столбцов в символах
     * @return string путь к временному файлу .xlsx
     */
    public static function write(array $head, array $rows, array $widths = [], string $sheet = 'Товары'): string
    {
        if (!class_exists(\ZipArchive::class)) throw new \RuntimeException('Нет расширения PHP zip');

        $path = tempnam(sys_get_temp_dir(), 'kpx') ?: sys_get_temp_dir() . '/kpx' . uniqid();
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new \RuntimeException('Не удалось создать файл выгрузки');
        }

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>');
        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');
        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::esc(mb_substr($sheet, 0, 31)) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>');
        // Стиль 1 — обычная ячейка с переносом, 2 — жирная шапка
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf>'
            . '</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
             . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
             . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        if ($widths) {
            $xml .= '<cols>';
            foreach (array_values($widths) as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (float)$w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>' . self::row(1, $head, 2);
        $n = 1;
        foreach ($rows as $row) $xml .= self::row(++$n, $row, 1);
        $xml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $xml);
        $zip->close();
        return $path;
    }

    private static function row(int $n, array $cells, int $style): string
    {
        $out = '<row r="' . $n . '">';
        foreach (array_values($cells) as $i => $v) {
            $out .= '<c r="' . self::col($i) . $n . '" t="inlineStr" s="' . $style . '"><is><t xml:space="preserve">'
                  . self::esc((string)$v) . '</t></is></c>';
        }
        return $out . '</row>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function esc(string $v): string
    {
        // Управляющие символы в XML запрещены — Excel иначе не откроет файл
        $v = (string)preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v);
        // Ячейка Excel вмещает 32 767 символов
        if (mb_strlen($v) > 32000) $v = mb_substr($v, 0, 32000) . '…';
        return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
