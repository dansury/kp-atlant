<?php
namespace Atlant\KpSync;

use Bitrix\Main\Loader;
use CCatalogSKU;
use CIBlockElement;

/**
 * Выгрузка каталога в Excel (issue #67): внешний код, название, модификации с
 * их характеристиками, описание и ссылка на сайте — по строке на товар.
 *
 * Модификации (торговые предложения) идут в ячейку своего товара, по одной на
 * строку: «Название — Размер: L; Цвет: олива». Описание — текстом, без HTML.
 */
final class Export
{
    public const HEAD = ['Внешний код', 'Название товара', 'Модификации и характеристики', 'Описание', 'Ссылка на сайте'];
    private const PAGE = 200;

    /** Файл .xlsx во временной папке; удаляет его вызывающий. */
    public static function xlsx(): string
    {
        return Xlsx::write(self::HEAD, self::rows(), [18, 40, 50, 70, 45], 'Товары');
    }

    /** Отдать файл браузеру и закончить запрос. */
    public static function download(): void
    {
        @set_time_limit(300);
        $path = self::xlsx();
        $name = 'catalog-' . date('Y-m-d') . '.xlsx';
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: no-store');
        readfile($path);
        @unlink($path);
    }

    /** @return array<int,string[]> */
    public static function rows(): array
    {
        if (!Loader::includeModule('iblock')) return [];
        $catalog = Loader::includeModule('catalog') && class_exists(CCatalogSKU::class);

        $out = [];
        foreach (Catalog::catalogIblocks() as $iblockId) {
            // Инфоблок предложений выгружается внутри своих товаров
            if ($catalog && CCatalogSKU::GetInfoByOfferIBlock($iblockId)) continue;
            $sku = $catalog ? CCatalogSKU::GetInfoByProductIBlock($iblockId) : false;

            $filter = ['IBLOCK_ID' => $iblockId];
            if (Config::get('ACTIVE_ONLY') === 'Y') $filter['ACTIVE'] = 'Y';

            for ($offset = 0; ; $offset += self::PAGE) {
                $res = CIBlockElement::GetList(['ID' => 'ASC'], $filter, false,
                    ['nOffset' => $offset, 'nTopCount' => self::PAGE],
                    ['ID', 'IBLOCK_ID', 'NAME', 'XML_ID', 'DETAIL_PAGE_URL',
                     'DETAIL_TEXT', 'DETAIL_TEXT_TYPE', 'PREVIEW_TEXT', 'PREVIEW_TEXT_TYPE']);
                $page = [];
                while ($row = $res->GetNext(true, true)) $page[(int)$row['ID']] = $row;
                if (!$page) break;

                $offers = $sku ? self::offers($sku, array_keys($page)) : [];
                foreach ($page as $id => $row) {
                    $text = Catalog::text($row, 'DETAIL_TEXT') !== ''
                        ? Catalog::text($row, 'DETAIL_TEXT') : Catalog::text($row, 'PREVIEW_TEXT');
                    $out[] = [
                        (string)($row['~XML_ID'] ?? $row['XML_ID'] ?? ''),
                        (string)($row['~NAME'] ?? $row['NAME'] ?? ''),
                        implode("\n", $offers[$id] ?? []),
                        self::plain($text),
                        Catalog::absoluteUrl((string)($row['~DETAIL_PAGE_URL'] ?? $row['DETAIL_PAGE_URL'] ?? '')),
                    ];
                }
                if (count($page) < self::PAGE) break;
            }
        }
        return $out;
    }

    /**
     * Модификации товаров страницы: id товара → строки «Название — Свойство: значение; …».
     * @return array<int,string[]>
     */
    private static function offers(array $sku, array $productIds): array
    {
        $linkId = (int)($sku['SKU_PROPERTY_ID'] ?? 0);
        $offerIblock = (int)($sku['IBLOCK_ID'] ?? 0);
        if (!$linkId || !$offerIblock || !$productIds) return [];

        $filter = ['IBLOCK_ID' => $offerIblock, 'PROPERTY_' . $linkId => $productIds];
        if (Config::get('ACTIVE_ONLY') === 'Y') $filter['ACTIVE'] = 'Y';
        $res = CIBlockElement::GetList(['SORT' => 'ASC', 'ID' => 'ASC'], $filter, false, false,
                                       ['ID', 'IBLOCK_ID', 'NAME']);
        $out = [];
        while ($el = $res->GetNextElement(true, true)) {
            $fields = $el->GetFields();
            $props = $el->GetProperties();
            $productId = 0;
            $chars = [];
            foreach ($props as $p) {
                if ((int)$p['ID'] === $linkId) { $productId = (int)$p['VALUE']; continue; }
                // Файлы и привязки — не характеристики
                if (in_array($p['PROPERTY_TYPE'] ?? '', ['F', 'E', 'G'], true)) continue;
                if (in_array($p['CODE'] ?? '', ['CML2_LINK', 'MORE_PHOTO'], true)) continue;
                $v = $p['VALUE'] ?? '';
                if (is_array($v)) $v = implode(', ', array_filter(array_map('strval', $v), 'strlen'));
                $v = trim(strip_tags((string)$v));
                if ($v === '') continue;
                $chars[] = trim((string)$p['NAME']) . ': ' . $v;
            }
            if (!$productId) continue;
            $name = (string)($fields['~NAME'] ?? $fields['NAME'] ?? '');
            $out[$productId][] = $name . ($chars ? ' — ' . implode('; ', $chars) : '');
        }
        return $out;
    }

    /** HTML описания → текст: абзацы и пункты списков остаются строками. */
    public static function plain(string $html): string
    {
        $t = preg_replace('#<\s*(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $html) ?? $html;
        $t = preg_replace('#<\s*li\b[^>]*>#i', '— ', $t) ?? $t;
        $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace("/[ \t\x{00A0}]+/u", ' ', $t) ?? $t;
        $t = preg_replace("/ *\n */", "\n", $t) ?? $t;
        return trim((string)preg_replace("/\n{3,}/", "\n\n", $t));
    }
}
