<?php
/**
 * Managers: several people work in the service, and the admin adds them from the
 * panel. config.php still seeds the first admin, but a row edited here is marked
 * ui_managed and config.php stops overwriting it.
 */
final class Managers {
    public static function all(): array {
        return Db::all(
            "SELECT m.id, m.login, m.name, m.email, m.phone, m.is_admin, m.moysklad_uid,
                    COALESCE(m.is_active, 1) AS is_active, COALESCE(m.ui_managed, 0) AS ui_managed,
                    m.created_at, m.updated_at,
                    (SELECT COUNT(*) FROM mailboxes b WHERE b.manager_id = m.id) AS mailboxes,
                    (SELECT COUNT(*) FROM requests r WHERE r.manager_id = m.id) AS requests
             FROM managers m ORDER BY m.is_admin DESC, m.name"
        );
    }

    public static function save(array $in, ?int $id, int $actorId): int {
        $login = trim((string)($in['login'] ?? ''));
        $name  = trim((string)($in['name'] ?? ''));
        if ($name === '') throw new InvalidArgumentException('Укажите имя менеджера');

        $data = [
            'name'         => $name,
            'email'        => trim((string)($in['email'] ?? '')) ?: null,
            'phone'        => trim((string)($in['phone'] ?? '')) ?: null,
            'moysklad_uid' => trim((string)($in['moysklad_uid'] ?? '')) ?: null,
            'is_admin'     => !empty($in['is_admin']) ? 1 : 0,
            'is_active'    => array_key_exists('is_active', $in) ? (int)!empty($in['is_active']) : 1,
            'ui_managed'   => 1,   // from now on config.php no longer overwrites this row
            'updated_at'   => date('Y-m-d H:i:s'),
        ];
        $password = (string)($in['password'] ?? '');

        if ($id) {
            $current = Db::one("SELECT * FROM managers WHERE id=?", [$id]);
            if (!$current) throw new InvalidArgumentException('Менеджер не найден');
            if ($login !== '' && $login !== $current['login']) {
                self::assertLoginFree($login, $id);
                $data['login'] = $login;
            }
            if ($password !== '') $data['password_hash'] = Auth::hashPassword($password);
            // The last admin must stay an admin, otherwise nobody can open the panel
            if (!$data['is_admin'] && $current['is_admin'] && self::adminCount() <= 1) {
                throw new InvalidArgumentException('Нельзя снять права у последнего администратора');
            }
            if (!$data['is_active'] && $current['is_admin'] && self::adminCount() <= 1) {
                throw new InvalidArgumentException('Нельзя отключить последнего администратора');
            }
            Db::update('managers', $data, 'id=?', [$id]);
        } else {
            if ($login === '') throw new InvalidArgumentException('Укажите логин');
            if ($password === '') throw new InvalidArgumentException('Укажите пароль');
            self::assertLoginFree($login, null);
            $data['login'] = $login;
            $data['password_hash'] = Auth::hashPassword($password);
            $id = Db::insert('managers', $data);
        }
        Logger::info('managers', "Менеджер сохранён: $name", ['manager_id' => $id, 'actor_id' => $actorId]);
        return (int)$id;
    }

    /**
     * Deleting a manager who owns data would orphan it, so an account that has
     * worked in the service is switched off instead of erased.
     */
    public static function delete(int $id, int $actorId): string {
        $row = Db::one("SELECT * FROM managers WHERE id=?", [$id]);
        if (!$row) throw new InvalidArgumentException('Менеджер не найден');
        if ($id === $actorId) throw new InvalidArgumentException('Нельзя удалить самого себя');
        if ($row['is_admin'] && self::adminCount() <= 1) throw new InvalidArgumentException('Нельзя удалить последнего администратора');

        $used = (int)Db::val("SELECT COUNT(*) FROM requests WHERE manager_id=?", [$id])
              + (int)Db::val("SELECT COUNT(*) FROM proposals WHERE manager_id=?", [$id])
              + (int)Db::val("SELECT COUNT(*) FROM mailboxes WHERE manager_id=?", [$id]);

        if ($used > 0) {
            Db::update('managers', ['is_active' => 0, 'ui_managed' => 1, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
            Logger::info('managers', "Менеджер отключён: {$row['name']}", ['manager_id' => $id, 'actor_id' => $actorId]);
            return 'disabled';
        }
        Db::q("DELETE FROM managers WHERE id=?", [$id]);
        Db::q("DELETE FROM settings WHERE key=?", ['manager_fp_' . $row['login']]);
        Logger::info('managers', "Менеджер удалён: {$row['name']}", ['actor_id' => $actorId]);
        return 'deleted';
    }

    private static function assertLoginFree(string $login, ?int $exceptId): void {
        $row = Db::one("SELECT id FROM managers WHERE login=?", [$login]);
        if ($row && (int)$row['id'] !== (int)$exceptId) throw new InvalidArgumentException("Логин «{$login}» уже занят");
    }

    private static function adminCount(): int {
        return (int)Db::val("SELECT COUNT(*) FROM managers WHERE is_admin=1 AND COALESCE(is_active,1)=1");
    }
}
