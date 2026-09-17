<?php
/**
 * Перенаправление письма и адреса, на которые его перенаправляют (модуль 037).
 *
 * Письмо приходит не всегда тому, кто им занимается: запрос на бронеплиты
 * читает снабжение, счёт — бухгалтерия, рекламацию — производство. До сих пор
 * такое письмо пересылалось из обычного почтового клиента: наружу оно уходило
 * мимо архива, и в переписке компании от него не оставалось следа.
 *
 * Адрес, на который переслали, ЗАПОМИНАЕТСЯ: во второй раз его не набирают, а
 * выбирают. Список свой — он растёт из того, чем пользуются, и чистится
 * крестиком, потому что человек увольняется, а его адрес остаётся.
 */
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/mail_text.php';
require_once __DIR__ . '/mail_threads.php';
require_once __DIR__ . '/outbox.php';

final class Forwards {

    /** Адреса, на которые уже пересылали: сначала те, которыми пользуются чаще. */
    public static function all(): array {
        return Db::all("SELECT id, email, name, uses, last_used_at FROM forward_addresses
                        ORDER BY uses DESC, last_used_at DESC, email");
    }

    /**
     * Запомнить адрес. Тот же адрес второй раз не заводится — у него растёт
     * счётчик, и он поднимается в списке.
     */
    public static function remember(string $email, string $name = ''): void {
        $email = self::normalize($email);
        if ($email === '') return;
        $now = date('Y-m-d H:i:s');
        $row = Db::one("SELECT id, name, uses FROM forward_addresses WHERE email=?", [$email]);
        if ($row) {
            Db::update('forward_addresses', [
                'uses'         => (int)$row['uses'] + 1,
                'last_used_at' => $now,
                // Имя дописывается, если раньше его не знали, но не затирается
                'name'         => trim((string)$row['name']) !== '' ? $row['name'] : (trim($name) ?: null),
            ], 'id=?', [(int)$row['id']]);
            return;
        }
        Db::insert('forward_addresses', [
            'email'        => $email,
            'name'         => trim($name) ?: null,
            'uses'         => 1,
            'last_used_at' => $now,
            'created_at'   => $now,
        ]);
    }

    /** Убрать адрес из базы: человек уволился, а адрес остался в списке. */
    public static function forget(int $id): void {
        Db::q("DELETE FROM forward_addresses WHERE id=?", [$id]);
    }

    /**
     * Переслать письмо целиком: с шапкой «кто и когда написал» и с вложениями.
     *
     * Копия уходит в архив тем же путём, что и обычный ответ, и остаётся в той
     * же переписке — иначе «переслал коллеге» видно только в чужом ящике.
     *
     * @param string $note что менеджер дописал от себя; может быть пустым
     * @return array{archive_id:int,sent_state:string,sent_error:?string}
     */
    public static function send(int $mailId, string $to, string $note, int $managerId,
                                $mailboxId = null): array {
        $to = self::normalize($to);
        if ($to === '') throw new RuntimeException('Укажите адрес, на который переслать');

        $src = MailArchive::get($mailId);
        if (!$src) throw new RuntimeException('Письмо не найдено');

        $note = trim($note);
        $head = self::header($src);
        $body = MailText::quoteBody($src);

        $text = ($note !== '' ? $note . "\n\n" : '') . $head['text'] . "\n\n" . $body;
        $html = ($note !== ''
                    ? '<p>' . nl2br(htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</p>'
                    : '')
              . $head['html']
              . '<div style="padding-left:12px;border-left:2px solid #ccc;color:#444">'
              . nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
              . '</div>';

        $res = Mailer::send([
            'to'              => $to,
            'subject'         => self::subject((string)($src['subject'] ?? '')),
            'text'            => $text,
            'html'            => $html,
            // Пересылаем из того же ящика, в который письмо пришло: адресат
            // отвечает туда, где переписка и лежит
            'mailbox_id'      => $mailboxId ?: ($src['mailbox_id'] ?? null),
            'manager_id'      => $managerId,
            'counterparty_id' => $src['counterparty_id'] ?? null,
            'request_id'      => $src['request_id'] ?? null,
            'thread_key'      => $src['thread_key'] ?? null,
            'attachments'     => self::attachments($mailId, $managerId),
        ]);

        self::remember($to);
        Logger::info('mail', "Письмо #$mailId переслано на $to",
                     ['mail_message_id' => $mailId, 'manager_id' => $managerId,
                      'archive_id' => $res['archive_id'] ?? null]);
        return $res;
    }

    // ------------------------------------------------------------- частности

    /** «Fwd: …» — и ровно один раз, сколько бы кругов письмо ни прошло. */
    private static function subject(string $subject): string {
        $subject = trim($subject);
        if ($subject === '') return 'Fwd: письмо без темы';
        return preg_match('/^(fwd|fw|пересл)\b/iu', $subject) ? $subject : 'Fwd: ' . $subject;
    }

    /**
     * Шапка пересылки — та же, что ставят почтовые клиенты: от кого, кому,
     * когда и с какой темой. Без неё пересланное письмо читается как наше.
     *
     * @return array{text:string,html:string}
     */
    private static function header(array $src): array {
        // Настоящий автор, а не наш ящик: письмо могло прийти пересланным
        $real  = MailThreads::realSender($src);
        $name  = trim((string)$real['name']);
        $email = trim((string)$real['email']);
        $from  = $name !== '' && $email !== '' ? "$name <$email>" : ($name !== '' ? $name : $email);

        $lines = [
            '---------- Пересланное письмо ----------',
            'От: ' . ($from !== '' ? $from : 'неизвестно'),
            'Кому: ' . trim((string)($src['to_emails'] ?? '')),
            'Отправлено: ' . date('d.m.Y H:i', strtotime((string)($src['date_at'] ?? '')) ?: time()),
            'Тема: ' . trim((string)($src['subject'] ?? '')),
        ];
        $html = '<p style="color:#666;margin:16px 0 8px">'
              . implode('<br>', array_map(
                    fn($l) => htmlspecialchars($l, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), $lines))
              . '</p>';
        return ['text' => implode("\n", $lines), 'html' => $html];
    }

    /**
     * Вложения исходного письма — копиями в папке менеджера: оригинал остаётся
     * в архиве, а копия уезжает вместе с письмом и убирается сама.
     *
     * @return array<int,string> пути на диске
     */
    private static function attachments(int $mailId, int $managerId): array {
        $names = [];
        foreach (Db::all("SELECT filename, path FROM attachments WHERE mail_message_id=?", [$mailId]) as $a) {
            $path = ROOT . '/' . ltrim((string)$a['path'], '/');
            if (!is_file($path)) continue;
            try {
                $names[] = Outbox::adopt($path, (string)$a['filename'], $managerId);
            } catch (Throwable $e) {
                // Слишком большой или пропавший файл не отменяет пересылку:
                // письмо важнее вложения, а про вложение видно в архиве
                Logger::warning('mail', 'Вложение не поехало с пересланным письмом: ' . $e->getMessage(),
                                ['mail_message_id' => $mailId]);
            }
        }
        return Outbox::resolve($names, $managerId);
    }

    /** Адрес из того, что набрал человек: «Иван <i@z.ru>» — это i@z.ru. */
    private static function normalize(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/<([^>]+)>/', $raw, $m)) $raw = $m[1];
        $raw = trim($raw, " \t<>");
        return filter_var($raw, FILTER_VALIDATE_EMAIL) ? mb_strtolower($raw) : '';
    }
}
