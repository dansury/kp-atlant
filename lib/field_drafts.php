<?php
/**
 * Unsent text of any field, kept per manager (module 063).
 *
 * The browser keeps its own copy first; this is the copy that survives a
 * cleared browser and follows the manager to another device.
 */
final class FieldDrafts {
    public const MAX_KEY  = 190;
    public const MAX_BODY = 200000;
    public const TTL_DAYS = 30;

    /** @return array<string, array{v:string, base:string, at:int}> */
    public static function all(int $managerId): array {
        $out = [];
        foreach (Db::all("SELECT key, body, base, updated_at FROM field_drafts WHERE manager_id=?", [$managerId]) as $r) {
            $out[$r['key']] = ['v' => (string)$r['body'], 'base' => (string)$r['base'],
                               'at' => (int)(strtotime((string)$r['updated_at'] . ' UTC') * 1000)];
        }
        return $out;
    }

    /** Empty body = the field was cleared, so is the draft. */
    public static function save(int $managerId, string $key, string $body, string $base = ''): bool {
        $key = self::key($key);
        if ($key === '') return false;
        if (trim($body) === '') { self::clear($managerId, $key); return false; }
        if (mb_strlen($body) > self::MAX_BODY) $body = mb_substr($body, 0, self::MAX_BODY);
        Db::q("INSERT INTO field_drafts (manager_id, key, body, base, updated_at) VALUES (?,?,?,?,datetime('now'))
               ON CONFLICT(manager_id, key) DO UPDATE SET body=excluded.body, base=excluded.base, updated_at=excluded.updated_at",
              [$managerId, $key, $body, substr($base, 0, 64)]);
        Db::q("DELETE FROM field_drafts WHERE updated_at < datetime('now', ?)", ['-' . self::TTL_DAYS . ' days']);
        return true;
    }

    public static function clear(int $managerId, string $key): void {
        Db::q("DELETE FROM field_drafts WHERE manager_id=? AND key=?", [$managerId, self::key($key)]);
    }

    private static function key(string $key): string {
        return mb_substr(trim($key), 0, self::MAX_KEY);
    }
}
