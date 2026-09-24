<?php
namespace Atlant\KpSync;

use Bitrix\Main\Loader;
use CIBlockElement;
use CCatalog;
use CCatalogSKU;

/**
 * Finding a product on the shop, and handing the whole catalog over at once.
 *
 * The КП service knows a product by its артикул from МойСклад and nothing
 * else, so that is what this answers on — with the код and the name as the
 * next two guesses, in that order, because an артикул that matches is a fact
 * and a name that matches is an opinion.
 *
 * The answer always carries `DETAIL_PAGE_URL` made absolute: the service puts
 * it straight into a signed document, and a relative link there is useless.
 */
final class Catalog
{
    /** Fields every answer carries, whichever way the row was found. */
    private const SELECT = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'XML_ID',
                            'DETAIL_PAGE_URL', 'DETAIL_PICTURE', 'ACTIVE'];

    /**
     * One product, looked up the way the service asked for it.
     *
     * @return array<int,array<string,mixed>> best match first, possibly empty
     */
    public static function find(string $article, string $code, string $name): array
    {
        if (!Loader::includeModule('iblock')) return [];

        $iblocks = self::iblocks();
        if (!$iblocks) return [];

        $prop = trim(Config::get('ARTICLE_PROP')) ?: 'CML2_ARTICLE';
        $byName = Config::get('SEARCH_BY_NAME') === 'Y';

        // In order of how much each guess is worth. The first one that answers
        // wins; nothing below it is even asked.
        $attempts = [];
        if ($article !== '') {
            $attempts[] = ['PROPERTY_' . $prop => $article];
            $attempts[] = ['=XML_ID' => $article];
            $attempts[] = ['=CODE'   => $article];
        }
        if ($code !== '') {
            $attempts[] = ['=CODE'   => $code];
            $attempts[] = ['=XML_ID' => $code];
            $attempts[] = ['PROPERTY_' . $prop => $code];
        }
        if ($byName && $name !== '') {
            $attempts[] = ['=NAME' => $name];
            $attempts[] = ['NAME'  => '%' . $name . '%'];
        }

        foreach ($attempts as $filter) {
            $rows = self::query($filter + ['IBLOCK_ID' => $iblocks], 5);
            if ($rows) return $rows;
        }
        return [];
    }

    /**
     * A page of the catalog: артикул, код, name and the link, for everything
     * that has an артикул to be matched on. The service walks it with
     * `offset` and stores the links against its own products in one pass,
     * instead of asking the site once per position.
     *
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public static function export(int $offset, int $limit): array
    {
        if (!Loader::includeModule('iblock')) return ['items' => [], 'total' => 0];

        $iblocks = self::iblocks();
        if (!$iblocks) return ['items' => [], 'total' => 0];

        $filter = ['IBLOCK_ID' => $iblocks];
        if (Config::get('ACTIVE_ONLY') === 'Y') $filter['ACTIVE'] = 'Y';

        // An empty $arGroupBy is how CIBlockElement returns a count, not rows
        $total = (int)CIBlockElement::GetList([], $filter, []);

        $res = CIBlockElement::GetList(
            ['ID' => 'ASC'],
            $filter,
            false,
            ['nOffset' => max(0, $offset), 'nTopCount' => $limit],
            array_merge(self::SELECT, ['DETAIL_TEXT', 'PREVIEW_TEXT'],
                        ['PROPERTY_' . (trim(Config::get('ARTICLE_PROP')) ?: 'CML2_ARTICLE')])
        );

        $items = [];
        while ($row = $res->GetNext(true, false)) {
            $items[] = self::shape($row);
        }
        return ['items' => $items, 'total' => $total];
    }

    /** What the module says about itself, for the «проверить связь» button. */
    public static function diagnose(): array
    {
        $ok = Loader::includeModule('iblock');
        $iblocks = $ok ? self::iblocks() : [];
        $count = 0;
        if ($iblocks) {
            $filter = ['IBLOCK_ID' => $iblocks];
            if (Config::get('ACTIVE_ONLY') === 'Y') $filter['ACTIVE'] = 'Y';
            $count = (int)CIBlockElement::GetList([], $filter, []);
        }
        return [
            'module'        => Config::MODULE_ID,
            'version'       => Config::version(),
            'iblock_module' => $ok,
            'iblocks'       => $iblocks,
            'elements'      => $count,
            'article_prop'  => trim(Config::get('ARTICLE_PROP')) ?: 'CML2_ARTICLE',
            'base'          => Config::base(),
        ];
    }

    // ---------------------------------------------------------------- inside

    private static function query(array $filter, int $limit): array
    {
        if (Config::get('ACTIVE_ONLY') === 'Y') $filter['ACTIVE'] = 'Y';

        $prop = trim(Config::get('ARTICLE_PROP')) ?: 'CML2_ARTICLE';
        $res = CIBlockElement::GetList(
            ['SORT' => 'ASC', 'ID' => 'ASC'],
            $filter,
            false,
            ['nTopCount' => $limit],
            array_merge(self::SELECT, ['PROPERTY_' . $prop])
        );

        $out = [];
        while ($row = $res->GetNext(true, false)) {
            $out[] = self::shape($row);
        }
        return $out;
    }

    /**
     * A row as the КП service reads it. `url` is the key that matters — the
     * service takes `url` from the top of the answer and `DETAIL_PAGE_URL`
     * from a row, so both spellings are present on purpose.
     */
    private static function shape(array $row): array
    {
        $iblockId = (int)($row['IBLOCK_ID'] ?? 0);
        $url = (string)($row['DETAIL_PAGE_URL'] ?? '');

        // An offer has no page of its own: the product it belongs to is the
        // page a client should open
        $parent = self::parentOf((int)($row['ID'] ?? 0), $iblockId);
        if ($parent !== null) $url = $parent;

        $prop = trim(Config::get('ARTICLE_PROP')) ?: 'CML2_ARTICLE';
        $article = $row['PROPERTY_' . $prop . '_VALUE'] ?? '';
        if (is_array($article)) $article = reset($article);

        // Описание товара на сайте (issue #67): сервис берёт его, когда в
        // МойСклад описания нет. У предложения своего обычно нет — берём товара
        $description = self::text($row, 'DETAIL_TEXT') !== ''
            ? self::text($row, 'DETAIL_TEXT') : self::text($row, 'PREVIEW_TEXT');
        if ($description === '' && $parent !== null) $description = self::parentText((int)($row['ID'] ?? 0), $iblockId);

        return [
            'ID'              => (int)($row['ID'] ?? 0),
            'IBLOCK_ID'       => $iblockId,
            'NAME'            => (string)($row['NAME'] ?? ''),
            'CODE'            => (string)($row['CODE'] ?? ''),
            'XML_ID'          => (string)($row['XML_ID'] ?? ''),
            'ARTICLE'         => trim((string)$article),
            'DETAIL_PAGE_URL' => self::absolute($url),
            'url'             => self::absolute($url),
            'DESCRIPTION'     => $description,
        ];
    }

    /** Описание товара, которому принадлежит предложение. */
    private static function parentText(int $id, int $iblockId): string
    {
        if (!class_exists(CCatalogSKU::class)) return '';
        $info = CCatalogSKU::GetProductInfo($id, $iblockId);
        if (!is_array($info) || empty($info['ID'])) return '';
        $res = CIBlockElement::GetList([], ['ID' => (int)$info['ID']], false,
                                       ['nTopCount' => 1], ['ID', 'DETAIL_TEXT', 'PREVIEW_TEXT']);
        $row = $res->GetNext(true, false);
        if (!$row) return '';
        return self::text($row, 'DETAIL_TEXT') !== '' ? self::text($row, 'DETAIL_TEXT') : self::text($row, 'PREVIEW_TEXT');
    }

    /**
     * Текст элемента без экранирования: `~ПОЛЕ`, когда GetNext отдал «тильду»,
     * иначе само поле (HTML-описание приходит как есть, текстовое — экранированным).
     */
    public static function text(array $row, string $field): string
    {
        if (isset($row['~' . $field])) return (string)$row['~' . $field];
        $v = (string)($row[$field] ?? '');
        return ($row[$field . '_TYPE'] ?? 'html') === 'text' ? htmlspecialchars_decode($v, ENT_QUOTES) : $v;
    }

    /** Инфоблоки каталога — для выгрузки в Excel (`Export`). */
    public static function catalogIblocks(): array
    {
        return self::iblocks();
    }

    public static function absoluteUrl(string $url): string
    {
        return self::absolute($url);
    }

    /** The product page behind an offer, or null when this is a product. */
    private static function parentOf(int $id, int $iblockId): ?string
    {
        if ($id <= 0 || !Loader::includeModule('catalog')) return null;
        if (!class_exists(CCatalogSKU::class)) return null;

        $info = CCatalogSKU::GetProductInfo($id, $iblockId);
        if (!is_array($info) || empty($info['ID'])) return null;

        $res = CIBlockElement::GetList([], ['ID' => (int)$info['ID']], false,
                                       ['nTopCount' => 1], ['ID', 'DETAIL_PAGE_URL']);
        $row = $res->GetNext(true, false);
        return $row ? (string)$row['DETAIL_PAGE_URL'] : null;
    }

    private static function absolute(string $url): string
    {
        $url = trim($url);
        if ($url === '') return '';
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
        $base = Config::base();
        return $base === '' ? $url : $base . '/' . ltrim($url, '/');
    }

    /** Configured iblocks, or every catalog on the site when none were named. */
    private static function iblocks(): array
    {
        $ids = Config::iblockIds();
        if ($ids) return $ids;

        if (Loader::includeModule('catalog') && class_exists(CCatalog::class)) {
            $res = CCatalog::GetList([], [], false, false, ['IBLOCK_ID']);
            $out = [];
            while ($row = $res->Fetch()) {
                $id = (int)$row['IBLOCK_ID'];
                if ($id > 0) $out[] = $id;
            }
            if ($out) return array_values(array_unique($out));
        }
        return [];
    }
}
