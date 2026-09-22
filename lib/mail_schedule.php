<?php
/**
 * Отложенная отправка письма (issue #60).
 *
 * «Надо чтобы можно было сделать отложенную отправку в заданное время и день».
 * Ответ, написанный в полночь, приходит клиенту в девять утра; письмо, которое
 * должно лежать сверху в понедельник, пишется в пятницу.
 *
 * Письмо хранится ЦЕЛИКОМ — тем самым телом, которое собрал менеджер, — и
 * уходит тем же кодом, что и кнопка «Отправить» (`MailCompose::send()`).
 * Ничего не пересобирается в момент отправки: подпись, цитата и вложения уже
 * решены, и отложенное письмо не должно однажды уйти другим.
 *
 * Отправляет крон. Не настроен ни один — письма уйдут при следующем заходе за
 * почтой: `cron/check_mail.php` зовёт `run()` сам.
 */
final class MailSchedule {

    /** Больше трёх попыток — это не «сеть моргнула», а сломанное письмо. */
    private const MAX_ATTEMPTS = 3;

    /** Запланировать письмо. $sendAt — 'Y-m-d H:i:s' в часовом поясе сервиса. */
    public static function add(array $input, int $managerId, string $sendAt): array {
        $ts = strtotime($sendAt);
        if ($ts === false) throw new InvalidArgumentException('Не разобрали время отправки');
        // Прошедшее время — почти всегда описка в дате, а не просьба отправить
        // немедленно: у «отправить сейчас» есть своя кнопка
        if ($ts < time() - 60) throw new InvalidArgumentException('Это время уже прошло');
        if (trim((string)($input['to'] ?? '')) === '') throw new InvalidArgumentException('Укажите адрес получателя');
        if (trim((string)($input['text'] ?? '')) === '') throw new InvalidArgumentException('Письмо пустое');

        $id = Db::insert('mail_scheduled', [
            'manager_id'   => $managerId,
            'send_at'      => date('Y-m-d H:i:s', $ts),
            'payload_json' => json_encode($input, JSON_UNESCAPED_UNICODE),
            'subject'      => mb_substr(trim((string)($input['subject'] ?? '')), 0, 300),
            'to_addr'      => mb_substr(trim((string)($input['to'] ?? '')), 0, 300),
            'status'       => 'pending',
        ]);
        Logger::info('mail', 'Письмо отложено до ' . date('Y-m-d H:i', $ts),
                     ['manager_id' => $managerId, 'scheduled_id' => $id]);
        return self::get($id);
    }

    public static function get(int $id): array {
        $row = Db::one("SELECT * FROM mail_scheduled WHERE id=?", [$id]);
        if (!$row) throw new RuntimeException('Отложенное письмо не найдено');
        unset($row['payload_json']);
        return $row;
    }

    /** Что ещё не ушло — у этого менеджера или у всех. */
    public static function pending(?int $managerId = null): array {
        $sql = "SELECT id, manager_id, send_at, subject, to_addr, status, attempts, error
                FROM mail_scheduled WHERE status='pending'";
        $args = [];
        if ($managerId !== null) { $sql .= " AND manager_id=?"; $args[] = $managerId; }
        return Db::all($sql . " ORDER BY send_at", $args);
    }

    /** Отменить — своё письмо отменяет автор, любое — администратор. */
    public static function cancel(int $id, int $managerId, bool $isAdmin = false): void {
        $row = Db::one("SELECT id, manager_id, status FROM mail_scheduled WHERE id=?", [$id]);
        if (!$row) throw new RuntimeException('Отложенное письмо не найдено');
        if (!$isAdmin && (int)$row['manager_id'] !== $managerId) {
            throw new RuntimeException('Это письмо отложил другой менеджер');
        }
        if ($row['status'] !== 'pending') throw new RuntimeException('Это письмо уже не в очереди');
        Db::update('mail_scheduled', ['status' => 'cancelled'], 'id=?', [$id]);
        Logger::info('mail', 'Отложенное письмо отменено', ['scheduled_id' => $id, 'manager_id' => $managerId]);
    }

    /** Письма, чьё время пришло. */
    public static function due(?string $now = null): array {
        $now = $now ?: date('Y-m-d H:i:s');
        return Db::all("SELECT * FROM mail_scheduled WHERE status='pending' AND send_at <= ?
                        ORDER BY send_at LIMIT 20", [$now]);
    }

    /**
     * Отправить всё, чему пришло время.
     *
     * Упавшее письмо не теряется и не крутится вечно: три попытки, потом
     * менеджеру приходит уведомление — иначе он узнаёт о неотправленном
     * письме от клиента.
     *
     * @return array{sent:int,failed:int}
     */
    public static function run(): array {
        if (!Db::hasTable('mail_scheduled')) return ['sent' => 0, 'failed' => 0];
        require_once ROOT . '/lib/mail_compose.php';
        $sent = 0; $failed = 0;
        foreach (self::due() as $row) {
            $id = (int)$row['id'];
            $payload = json_decode((string)$row['payload_json'], true);
            if (!is_array($payload)) {
                Db::update('mail_scheduled', ['status' => 'failed', 'error' => 'Письмо не разобралось'], 'id=?', [$id]);
                $failed++;
                continue;
            }
            Db::update('mail_scheduled', ['attempts' => (int)$row['attempts'] + 1], 'id=?', [$id]);
            try {
                MailCompose::send($payload, (int)$row['manager_id']);
                Db::update('mail_scheduled', ['status' => 'sent', 'sent_at' => date('Y-m-d H:i:s'),
                                              'error' => null], 'id=?', [$id]);
                $sent++;
            } catch (Throwable $e) {
                $attempts = (int)$row['attempts'] + 1;
                $done = $attempts >= self::MAX_ATTEMPTS;
                Db::update('mail_scheduled', [
                    'status' => $done ? 'failed' : 'pending',
                    'error'  => mb_substr($e->getMessage(), 0, 500),
                ], 'id=?', [$id]);
                Logger::warning('mail', 'Отложенное письмо не ушло: ' . $e->getMessage(),
                                ['scheduled_id' => $id, 'attempts' => $attempts]);
                if ($done) {
                    require_once ROOT . '/lib/notifier.php';
                    Notifier::notify('mail_bounced', 'Отложенное письмо не отправилось',
                        (string)$row['to_addr'] . ' — ' . $e->getMessage(), 'mail', null,
                        (int)$row['manager_id'], '/#mail');
                }
                $failed++;
            }
        }
        return ['sent' => $sent, 'failed' => $failed];
    }

    /**
     * Имена файлов, приложенных к ещё не отправленным письмам.
     *
     * `Outbox::sweep()` убирает вложения через 48 часов — письмо, отложенное
     * до следующего понедельника, ушло бы без них.
     *
     * @return array<int,string>
     */
    public static function pendingFiles(int $managerId): array {
        if (!Db::hasTable('mail_scheduled')) return [];
        $out = [];
        foreach (Db::all("SELECT payload_json FROM mail_scheduled
                          WHERE status='pending' AND manager_id=?", [$managerId]) as $row) {
            $payload = json_decode((string)$row['payload_json'], true);
            foreach ((array)($payload['files'] ?? []) as $f) {
                $name = is_array($f) ? (string)($f['name'] ?? '') : (string)$f;
                if ($name !== '') $out[] = basename($name);
            }
        }
        return array_values(array_unique($out));
    }

    /**
     * Подсказки «когда»: завтра утром, в понедельник утром, через час.
     *
     * Считаются на сервере, в часовом поясе сервиса: браузер менеджера может
     * стоять в другом, и «завтра в 09:00» тогда означало бы не то.
     *
     * @return array<int,array{key:string,label:string,at:string}>
     */
    public static function presets(?int $now = null): array {
        $now = $now ?: time();
        $hour = (int)Settings::get('MAIL_SCHEDULE_HOUR', 9);
        $morning = static fn(int $ts): string => date('Y-m-d', $ts) . ' ' . sprintf('%02d:00:00', $hour);

        $tomorrow = strtotime('+1 day', $now);
        $monday = strtotime('next monday', $now);
        return [
            ['key' => 'hour',     'label' => 'Через час',
             'at' => date('Y-m-d H:i:00', $now + 3600)],
            ['key' => 'tomorrow', 'label' => sprintf('Завтра в %02d:00', $hour),
             'at' => $morning($tomorrow)],
            ['key' => 'monday',   'label' => sprintf('В понедельник в %02d:00', $hour),
             'at' => $morning($monday)],
        ];
    }
}
