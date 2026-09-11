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

        $row = Db::one("SELECT moysklad_id, name, article, code, parent_id, site_url, site_url_synced_at
                        FROM products_cache WHERE moysklad_id=?", [$moyskladId]);
        if (!$row) return null;

        if (self::fresh($row['site_url_synced_at'] ?? null)) {
            return ($row['site_url'] ?? '') !== '' ? (string)$row['site_url'] : null;
        }

        $url = self::resolve($row);

        // A variant has no page of its own — the product's page is the answer
        if ($url === null && !empty($row['parent_id'])) {
            $parent = Db::one("SELECT moysklad_id, name, article, code, parent_id FROM products_cache WHERE moysklad_id=?",
                              [$row['parent_id']]);
            if ($parent) $url = self::resolve($parent);
        }

        // An empty string is a decision too: «checked, the site has no such page»
        Db::q("UPDATE products_cache SET site_url=?, site_url_synced_at=datetime('now') WHERE moysklad_id=?",
              [$url ?? '', $moyskladId]);
        return $url;
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
            'sample'   => null,
        ];
        $row = Db::one("SELECT moysklad_id, name, article, code, parent_id FROM products_cache
                        WHERE is_archived IS NOT 1 AND article IS NOT NULL AND article <> '' LIMIT 1");
        if ($row) {
            $out['sample'] = ['name' => $row['name'], 'article' => $row['article'], 'url' => self::resolve($row)];
        }
        return $out;
    }

    // ---------------------------------------------------------------- resolving

    private static function resolve(array $product): ?string {
        foreach ([self::fromWebhook($product), self::fromTemplate($product)] as $url) {
            if ($url === null) continue;
            if (!self::verify($url)) continue;
            return $url;
        }
        // The search page is never verified: it answers 200 whatever we ask it
        return self::fromSearch($product);
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
        curl_close($ch);

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
        curl_close($ch);

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
