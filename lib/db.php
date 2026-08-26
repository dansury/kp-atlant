<?php
/**
 * SQLite wrapper (adapted from NeuroPro lib/db.php).
 * WAL mode, foreign keys ON, auto-migration.
 */
class Db {
    private static ?PDO $pdo = null;

    // Init DB connection
    public static function init(string $path): void {
        $dir = dirname($path);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        self::$pdo = new PDO("sqlite:$path", null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        self::$pdo->exec('PRAGMA journal_mode=WAL');
        self::$pdo->exec('PRAGMA foreign_keys=ON');
        self::$pdo->exec('PRAGMA busy_timeout=5000');
    }

    public static function pdo(): PDO {
        if (!self::$pdo) throw new RuntimeException('DB not initialized');
        return self::$pdo;
    }

    // Execute query, return PDOStatement
    public static function q(string $sql, array $params = []): PDOStatement {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    // Fetch one row
    public static function one(string $sql, array $params = []): ?array {
        $row = self::q($sql, $params)->fetch();
        return $row ?: null;
    }

    // Fetch all rows
    public static function all(string $sql, array $params = []): array {
        return self::q($sql, $params)->fetchAll();
    }

    // Fetch single value
    public static function val(string $sql, array $params = []) {
        return self::q($sql, $params)->fetchColumn();
    }

    // Insert row, return last insert ID
    public static function insert(string $table, array $data): int {
        $cols = implode(',', array_keys($data));
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        self::q("INSERT INTO $table ($cols) VALUES ($placeholders)", array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    // Update rows
    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $sets = implode(',', array_map(fn($k) => "$k=?", array_keys($data)));
        $stmt = self::q("UPDATE $table SET $sets WHERE $where", [...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    // Check if column exists in table
    public static function hasColumn(string $table, string $column): bool {
        $cols = self::all("PRAGMA table_info($table)");
        foreach ($cols as $c) {
            if ($c['name'] === $column) return true;
        }
        return false;
    }

    // Check if table exists
    public static function hasTable(string $table): bool {
        $r = self::val("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=?", [$table]);
        return $r > 0;
    }

    // Add column if missing
    public static function ensureColumn(string $table, string $column, string $type, string $default = ''): void {
        if (self::hasColumn($table, $column)) return;
        $sql = "ALTER TABLE $table ADD COLUMN $column $type";
        if ($default !== '') $sql .= " DEFAULT $default";
        self::pdo()->exec($sql);
    }

    // Transaction helpers
    public static function begin(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollback(): void { self::pdo()->rollBack(); }
}
