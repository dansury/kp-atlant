<?php
/**
 * Link to the product page on atlant-armour.ru (module 013).
 *
 * The shop runs on 1С-Битрикс, the catalog lives in МойСклад, and a КП has to
 * carry a link the client can actually open. Nothing joins the two automatically,
 * so this resolves a link in three ways and stops at the first that answers:
 *
 *   1. **The site itself** — an inbound REST webhook (`BITRIX_WEBHOOK_URL`)
 *      asked by артикул / код / name. This is the only source that knows the
 *      real `DETAIL_PAGE_URL`, so it is tried first when it is configured.
 *   2. **A URL template** (`BITRIX_URL_TEMPLATE`) — `{article}`, `{code}`,
 *      `{slug}`, `{id}`. Works when the shop's URLs are built from the same
 *      артикул as МойСклад, which is the usual case for an imported catalog.
 *   3. **A search URL** (`BITRIX_SEARCH_TEMPLATE`) — the honest fallback: it
 *      always opens something relevant even when neither of the above resolves.
 *
 * A resolved link is verified (`BITRIX_VERIFY_URL`) before it is cached: a 404
 * in a signed commercial document is worse than no link at all. The verdict —
 * a URL, or an empty string meaning «проверено, страницы нет» — is cached on
 * the product row with a timestamp, so a КП never waits on the site and a
 * rebuild never re-asks.
 *
 * With `BITRIX_ENABLED` off, every call here returns null and the КП simply
 * prints no link. Nothing upstream may depend on the site being reachable.
 *
 * When the site runs the `atlant.kpsync` module (module 017, `bitrix-module/`
 * in this repository), the same webhook also answers `action=export` with the
 * whole catalog in pages — `syncFromSite()` walks it and fills every link in
 * one conversation instead of one request per position. That is an
 * optimisation and nothing more: with no module, or a module that is off, the
 * three-step resolution above still answers every product on its own.
 */
final class Bitrix {

    public static function enabled(): bool {
        return (int)Settings::get('BITRIX_ENABLED', 0) === 1
            && (self::base() !== '' || self::webhook() !== '');
    }

    /**
     * The product's page on the site, or null when there is none to give.
     * Cached on `products_cache` for `BITRIX_CACHE_DAYS`.
     */
    public static function productUrl(string $moyskladId): ?string {
        if ($moyskladId === '' || !self::enabled()) return null;

        $row = Db::one("SELECT moysklad_id, name, article, code, parent_id, site_url, site_url_synced_at, site_url_source
                        FROM products_cache WHERE moysklad_id=?", [$moyskladId]);
        if (!$row) return null;

        if (self::fresh($row['site_url_synced_at'] ?? null) && !self::staleSearchLink($row)) {
            return ($row['site_url'] ?? '') !== '' ? (string)$row['site_url'] : null;
        }

        [$url, $source] = self::resolveWithSource($row);

        // A variant has no page of its own — the product's page is the answer
        if ($url === null && !empty($row['parent_id'])) {
            $parent = Db::one("SELECT moysklad_id, name, article, code, parent_id FROM products_cache WHERE moysklad_id=?",
                              [$row['parent_id']]);
            if ($parent) [$url, $source] = self::resolveWithSource($parent);
        }

        // An empty string is a decision too: «checked, the site has no such page»
        Db::q("UPDATE products_cache SET site_url=?, site_url_source=?, site_url_synced_at=datetime('now') WHERE moysklad_id=?",
              [$url ?? '', $source, $moyskladId]);
        return $url;
    }

    /**
     * Ссылка на ПОИСК, которую пора переспросить (модуль 034).
     *
     * Пока модуля сайта не было, каждая позиция получала последний вариант —
     * «/search/?q=артикул» — и он ложился в кэш на месяц. Модуль подключили, а
     * в КП по-прежнему уходила ссылка на поиск: срок кэша ещё не вышел, и
     * настоящую страницу товара никто не спрашивал. Поисковая ссылка теперь
     * держится ровно до тех пор, пока спросить больше некого.
     */
    private static function staleSearchLink(array $row): bool {
        $source = trim((string)($row['site_url_source'] ?? ''));
        // Строка из прежних версий источника не знает — считаем поиском, если
        // адрес выглядит как поиск
        if ($source === '') {
            $url = (string)($row['site_url'] ?? '');
            $source = ($url !== '' && self::looksLikeSearch($url)) ? 'search' : 'unknown';
        }
        if ($source !== 'search') return false;
        // Спросить есть кого: вебхук сайта или шаблон адреса товара
        return self::webhook() !== '' || trim((string)Settings::get('BITRIX_URL_TEMPLATE', '')) !== '';
    }

    /**
     * Ссылка ведёт на ПОИСК по сайту, а не на страницу товара (модуль 034).
     *
     * Строка КП замораживает ссылку в момент сборки — в том числе поисковую,
     * поставленную до того, как подключили модуль сайта. Спрашивают отсюда:
     * такую ссылку стоит переспросить, обычную — нет.
     */
    public static function isSearchUrl(string $url): bool {
        return trim($url) !== '' && self::looksLikeSearch($url);
    }

    /** Адрес ведёт на поиск по сайту, а не на страницу товара. */
    private static function looksLikeSearch(string $url): bool {
        $template = trim((string)Settings::get('BITRIX_SEARCH_TEMPLATE', ''));
        $path = (string)(parse_url($template ?: '/search/', PHP_URL_PATH) ?: '/search/');
        return $path !== '' && str_contains($url, $path);
    }

    /** Warm the cache outside a КП — for cron, so a generation never waits. */
    public static function refreshUrls(int $limit = 50): int {
        if (!self::enabled()) return 0;
        $days = max(1, (int)Settings::get('BITRIX_CACHE_DAYS', 30));
        $rows = Db::all(
            "SELECT moysklad_id FROM products_cache
             WHERE is_archived IS NOT 1
               AND (site_url_synced_at IS NULL OR site_url_synced_at < datetime('now', ?))
             ORDER BY site_url_synced_at IS NOT NULL, moysklad_id LIMIT ?",
            ["-$days days", $limit]
        );
        $done = 0;
        foreach ($rows as $row) {
            self::productUrl((string)$row['moysklad_id']);
            $done++;
        }
        return $done;
    }

    /** Connection check for «Настройки → Сайт (Битрикс)». */
    public static function diagnose(): array {
        $out = [
            'enabled'  => self::enabled(),
            'base'     => self::base(),
            'webhook'  => self::webhook() !== '' ? 'задан' : 'не задан',
            'template' => (string)Settings::get('BITRIX_URL_TEMPLATE', ''),
            'module'   => self::modulePing(),
            'sample'   => null,
        ];
        $row = Db::one("SELECT moysklad_id, name, article, code, parent_id FROM products_cache
                        WHERE is_archived IS NOT 1 AND article IS NOT NULL AND article <> '' LIMIT 1");
        if ($row) {
            $out['sample'] = ['name' => $row['name'], 'article' => $row['article'], 'url' => self::resolve($row)];
        }
        return $out;
    }

    // ------------------------------------------------------- catalog export

    /**
     * Fill `products_cache.site_url` for the whole catalog from one endpoint.
     *
     * The per-product path costs one HTTP request per position and is paid at
     * the moment a КП is built; this pays for the whole catalog once, from
     * cron. It only ever writes a link the site actually gave us — a product
     * the site did not mention is left exactly as it was, so a half-finished
     * export never erases links that are already known good.
     *
     * The pass is bounded by `BITRIX_EXPORT_STEPS`: on a shared host a walk of
     * 20 000 positions must be able to stop and be continued by the next cron
     * run rather than time out halfway.
     *
     * @return array{checked:int,updated:int,pages:int,done:bool}
     */
    public static function syncFromSite(): array
    {
        $out = ['checked' => 0, 'updated' => 0, 'pages' => 0, 'done' => true];
        if (!self::enabled() || self::webhook() === '') return $out;

        $limit  = max(1, min(1000, (int)Settings::get('BITRIX_EXPORT_PAGE', 500)));
        $steps  = max(1, (int)Settings::get('BITRIX_EXPORT_STEPS', 20));
        $offset = 0;

        for ($step = 0; $step < $steps; $step++) {
            $body = self::http(self::exportUrl($offset, $limit));
            // A site that stopped answering mid-walk is not a finished walk
            if ($body === null) { $out['done'] = false; break; }

            $page = self::parseExport($body);
            if (!$page['items']) break;

            $out['pages']++;
            foreach ($page['items'] as $item) {
                $out['checked']++;
                if (self::applyExported($item)) $out['updated']++;
            }

            if ($page['next'] === null) break;
            $offset = $page['next'];
            if ($step === $steps - 1) $out['done'] = false;
        }

        if ($out['updated'] > 0) {
            Logger::info('bitrix', 'Ссылки на товары обновлены с сайта', $out);
        }
        return $out;
    }

    /**
     * One page of the module's answer, reduced to what the catalog needs.
     *
     * Pure on purpose: a test hands it a recorded answer and no site has to be
     * reachable. Both spellings of every field are accepted, because a Битрикс
     * answer is `NAME`/`DETAIL_PAGE_URL` and a hand-written one is usually
     * `name`/`url`.
     *
     * @return array{items:array<int,array{article:string,code:string,name:string,url:string}>,next:?int}
     */
    public static function parseExport(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) return ['items' => [], 'next' => null];

        $rows = $data['result'] ?? [];
        if (!is_array($rows)) return ['items' => [], 'next' => null];

        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $url = trim((string)($row['url'] ?? $row['URL'] ?? $row['DETAIL_PAGE_URL']
                                 ?? $row['detailPageUrl'] ?? ''));
            if ($url === '') continue;   // a row with no page is not an answer
            $items[] = [
                'article' => trim((string)($row['ARTICLE'] ?? $row['article'] ?? '')),
                'code'    => trim((string)($row['CODE'] ?? $row['code'] ?? '')),
                'name'    => trim((string)($row['NAME'] ?? $row['name'] ?? '')),
                'url'     => self::absolute($url),
            ];
        }

        $next = $data['next'] ?? null;
        return ['items' => $items, 'next' => is_numeric($next) ? (int)$next : null];
    }

    /**
     * Store one exported row against the catalog.
     *
     * Matched on артикул, then on код — never on the name. A name that merely
     * looks alike would put the wrong page into a signed document, and the
     * per-product path already makes that guess where it is cheap to undo.
     */
    private static function applyExported(array $item): bool
    {
        foreach ([['article', $item['article']], ['code', $item['code']]] as [$column, $value]) {
            if ($value === '') continue;

            $rows = Db::all(
                "SELECT moysklad_id FROM products_cache
                 WHERE $column IS NOT NULL AND $column <> '' AND $column = ? COLLATE NOCASE
                   AND is_archived IS NOT 1",
                [$value]
            );
            if (!$rows) continue;

            foreach ($rows as $row) {
                // Источник — модуль сайта: это НАСТОЯЩАЯ страница товара, и она
                // перебивает лежавшую в кэше ссылку на поиск (модуль 034)
                Db::q("UPDATE products_cache SET site_url=?, site_url_source='webhook', site_url_synced_at=datetime('now')
                       WHERE moysklad_id=?",
                      [$item['url'], (string)$row['moysklad_id']]);
            }
            return true;
        }
        return false;
    }

    private static function exportUrl(int $offset, int $limit): string
    {
        $webhook = self::webhook();
        $query = http_build_query(['action' => 'export', 'offset' => $offset, 'limit' => $limit]);
        return $webhook . (str_contains($webhook, '?') ? '&' : '?') . $query;
    }

    /** What the site module says about itself; null when there is none. */
    private static function modulePing(): ?array
    {
        $webhook = self::webhook();
        if ($webhook === '') return null;

        $body = self::http($webhook . (str_contains($webhook, '?') ? '&' : '?') . 'action=ping');
        if ($body === null) return null;

        $data = json_decode($body, true);
        return is_array($data) && isset($data['result']) && is_array($data['result'])
            ? $data['result'] : null;
    }

    // ---------------------------------------------------------------- resolving

    private static function resolve(array $product): ?string {
        return self::resolveWithSource($product)[0];
    }

    /**
     * Ссылка и то, ЧЕМ она найдена: вебхук сайта, шаблон адреса или поиск.
     *
     * Источник сохраняется рядом со ссылкой: только по нему видно, что в кэше
     * лежит последний вариант, а не страница товара, — и что его надо
     * переспросить, когда модуль сайта наконец подключили (модуль 034).
     *
     * @return array{0:?string,1:string}
     */
    private static function resolveWithSource(array $product): array {
        foreach (['webhook' => self::fromWebhook($product), 'template' => self::fromTemplate($product)] as $source => $url) {
            if ($url === null) continue;
            if (!self::verify($url)) continue;
            return [$url, $source];
        }
        // The search page is never verified: it answers 200 whatever we ask it
        $search = self::fromSearch($product);
        // Модуль сайта подключён, а страницу товара он не дал — в КП уйдёт
        // ссылка на поиск, и понять это можно только из журнала (модуль 035)
        if ($search !== null && self::webhook() !== '') {
            Logger::warning('bitrix', 'Сайт не дал страницу товара — в КП уйдёт ссылка на поиск: '
                            . (string)($product['name'] ?? ''),
                            ['article' => (string)($product['article'] ?? ''),
                             'code' => (string)($product['code'] ?? ''), 'url' => $search]);
        }
        return [$search, $search === null ? 'none' : 'search'];
    }

    /**
     * Ask the site. Accepted answers, because Битрикс is configured differently
     * on every shop and the КП must not care which one this is:
     *   {"url": "..."} | {"result": {"url": "..."}} | {"result": [{"DETAIL_PAGE_URL": "/..."}]}
     */
    private static function fromWebhook(array $product): ?string {
        $webhook = self::webhook();
        if ($webhook === '') return null;

        $query = http_build_query([
            'article' => (string)($product['article'] ?? ''),
            'code'    => (string)($product['code'] ?? ''),
            'name'    => (string)($product['name'] ?? ''),
        ]);
        $body = self::http($webhook . (str_contains($webhook, '?') ? '&' : '?') . $query);
        if ($body === null) return null;

        $data = json_decode($body, true);
        if (!is_array($data)) return null;

        $candidate = $data['url'] ?? null;
        if ($candidate === null && isset($data['result'])) {
            $result = $data['result'];
            if (is_array($result)) {
                $first = isset($result[0]) && is_array($result[0]) ? $result[0] : $result;
                $candidate = $first['url'] ?? $first['URL'] ?? $first['DETAIL_PAGE_URL'] ?? $first['detailPageUrl'] ?? null;
            }
        }
        return is_string($candidate) && trim($candidate) !== '' ? self::absolute($candidate) : null;
    }

    private static function fromTemplate(array $product): ?string {
        $template = trim((string)Settings::get('BITRIX_URL_TEMPLATE', ''));
        if ($template === '') return null;

        $article = trim((string)($product['article'] ?? ''));
        $code    = trim((string)($product['code'] ?? ''));
        // A template keyed on артикул cannot answer for a row that has none
        if (str_contains($template, '{article}') && $article === '') return null;
        if (str_contains($template, '{code}') && $code === '') return null;

        return self::absolute(strtr($template, [
            '{article}' => rawurlencode($article),
            '{code}'    => rawurlencode($code),
            '{id}'      => rawurlencode((string)($product['moysklad_id'] ?? '')),
            '{slug}'    => self::slug((string)($product['name'] ?? '')),
        ]));
    }

    private static function fromSearch(array $product): ?string {
        $template = trim((string)Settings::get('BITRIX_SEARCH_TEMPLATE', ''));
        if ($template === '' || self::base() === '') return null;

        $query = trim((string)($product['article'] ?? '')) ?: trim((string)($product['name'] ?? ''));
        if ($query === '') return null;
        return self::absolute(str_replace('{query}', rawurlencode($query), $template));
    }

    /** A link that 404s must never reach a client. */
    private static function verify(string $url): bool {
        if ((int)Settings::get('BITRIX_VERIFY_URL', 1) !== 1) return true;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => max(3, (int)Settings::get('BITRIX_TIMEOUT_SEC', 10)),
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Битрикс commonly answers a missing element with 200 + a «404» page;
        // that is the site's business. Everything outside 2xx is a definite no.
        return $code >= 200 && $code < 300;
    }

    private static function http(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => max(3, (int)Settings::get('BITRIX_TIMEOUT_SEC', 10)),
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
            Logger::warning('bitrix', 'Сайт не ответил по товару (HTTP ' . $code . ')',
                            ['error' => $error]);
            return null;
        }
        return $body;
    }

    // ---------------------------------------------------------------- helpers

    private static function base(): string {
        return rtrim(trim((string)Settings::get('BITRIX_SITE_URL', '')), '/');
    }

    private static function webhook(): string {
        return rtrim(trim((string)Settings::get('BITRIX_WEBHOOK_URL', '')), '/');
    }

    private static function absolute(string $url): string {
        $url = trim($url);
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
        return self::base() . '/' . ltrim($url, '/');
    }

    private static function fresh(?string $syncedAt): bool {
        if (!$syncedAt) return false;
        $days = max(1, (int)Settings::get('BITRIX_CACHE_DAYS', 30));
        return (time() - strtotime($syncedAt)) < $days * 86400;
    }

    /** «Бронежилет Страж, размер L» → «bronezhilet-strazh-razmer-l» */
    private static function slug(string $name): string {
        $map = ['а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
                'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
                'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
                'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya'];
        $s = strtr(mb_strtolower($name), $map);
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s);
        return trim((string)$s, '-');
    }
}
