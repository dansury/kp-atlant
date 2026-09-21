<?php
/**
 * Обратная связь из панели: жалоба менеджера → ревью администратора → issue
 * репозитория (модуль 038).
 *
 * До сих пор менеджер, наткнувшийся на сломанный экран, мог только рассказать
 * об этом администратору на словах — со скриншотом в мессенджере, который никуда
 * не попадал. Здесь у жалобы есть форма: текст, экран, с которого её отправили,
 * и файлы — картинки, документы, видео.
 *
 * Прямо в GitHub жалоба НЕ уходит: между менеджером и публичным трекером стоит
 * администратор. Он правит заголовок, дописывает то, что менеджер не знает
 * (версию, ветку), и только тогда нажимает «Отправить». Отклонённая жалоба
 * остаётся в панели с причиной — это ответ менеджеру, а не молчание.
 *
 * Файлы живут в `storage/support/` — вне репозитория, как всё, что пережило
 * деплой. В issue они уходят копией: GitHub принимает вложения только из своего
 * веб-интерфейса, поэтому файл кладётся в репозиторий через Contents API и
 * ссылка на него печатается в теле issue.
 */
final class Support {

    /** Про что жалоба. `quality` заводит проверка КП и письма (модуль 038). */
    public const KINDS = [
        'bug'      => 'Не работает',
        'idea'     => 'Предложение',
        'question' => 'Вопрос',
        'quality'  => 'Качество КП и письма',
    ];

    public const STATUSES = ['new' => 'На ревью', 'approved' => 'В GitHub', 'declined' => 'Отклонена'];

    /** Картинка, документ, видео — всё, что кладут в issue. */
    private const ALLOWED_EXT = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp', 'heic',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'log', 'json', 'zip',
        'mp4', 'mov', 'webm', 'm4v', 'avi',
    ];

    public static function enabled(): bool {
        return (int)Settings::get('SUPPORT_ENABLED', 1) === 1;
    }

    /** Репозиторий, куда уходят issue. Пусто — жалоба живёт только в панели. */
    public static function repo(): string {
        return trim((string)Settings::get('SUPPORT_REPO', ''));
    }

    /**
     * Токен поддержки, а если его нет — общий токен GitHub.
     *
     * Два ключа здесь не прихоть: вики читается токеном с `Contents: Read`, а
     * issue требует `Issues: Write`. Один токен на оба дела — это право писать
     * в репозиторий у ключа, которому хватило бы чтения.
     */
    public static function token(): string {
        $own = trim((string)Settings::get('SUPPORT_TOKEN', ''));
        return $own !== '' ? $own : trim((string)Settings::get('GITHUB_TOKEN', ''));
    }

    public static function maxBytes(): int {
        return max(1, (int)Settings::get('SUPPORT_MAX_MB', 25)) * 1024 * 1024;
    }

    // ---- Жалоба ----

    /**
     * Принять обращение. `$files` — имена из `Outbox` (файлы уже загружены
     * формой), они переезжают в `storage/support/<id>/`.
     *
     * @return array{id:int,files:int}
     */
    public static function submit(int $managerId, array $in, array $files = []): array {
        $kind  = isset(self::KINDS[(string)($in['kind'] ?? '')]) ? (string)$in['kind'] : 'bug';
        $title = trim((string)($in['title'] ?? ''));
        $body  = trim((string)($in['body'] ?? ''));
        if ($title === '' && $body === '') throw new RuntimeException('Опишите, что случилось');
        if ($title === '') $title = mb_substr(preg_replace('/\s+/u', ' ', $body), 0, 80);

        $rating = in_array((string)($in['rating'] ?? ''), ['up', 'down'], true) ? (string)$in['rating'] : null;
        $ticketId = Db::insert('support_tickets', [
            'manager_id' => $managerId ?: null,
            'kind'       => $kind,
            'title'      => mb_substr($title, 0, 200),
            'body'       => $body !== '' ? $body : null,
            'page'       => mb_substr(trim((string)($in['page'] ?? '')), 0, 200) ?: null,
            'rating'     => $rating,
            'model'      => mb_substr(trim((string)($in['model'] ?? '')), 0, 120) ?: null,
            'status'     => 'new',
        ]);

        $kept = 0;
        // `resolve()` отдаёт пару «путь на диске + имя для человека» (модуль 040)
        foreach (Outbox::resolve($files, $managerId) as $file) {
            try { self::keepFile($ticketId, (string)$file['path'], (string)$file['name']); $kept++; }
            catch (Throwable $e) { Logger::exception('support', $e, ['ticket' => $ticketId]); }
        }

        // Администратор узнаёт о жалобе так же, как об ошибке сервиса.
        // Кроме «палец вверх» проверки: хвалебная оценка — не повод для звонка.
        require_once ROOT . '/lib/notifier.php';
        if (empty($in['quiet'])) {
            foreach (Db::all("SELECT id FROM managers WHERE is_admin=1 AND COALESCE(is_active,1)=1") as $a) {
                Notifier::notify('support', 'Обращение в поддержку: ' . $title,
                    mb_substr($body, 0, 200) ?: null, null, $ticketId, (int)$a['id'], '/#settings/support');
            }
        }
        Logger::info('support', 'Новое обращение: ' . $title,
                     ['ticket' => $ticketId, 'manager_id' => $managerId, 'kind' => $kind]);

        return ['id' => $ticketId, 'files' => $kept];
    }

    /** Копия файла рядом с обращением — в `storage/`, чтобы пережить деплой. */
    private static function keepFile(int $ticketId, string $path, string $filename = ''): void {
        $name = Outbox::safeName($filename !== '' ? $filename : Outbox::displayName($path));
        $ext  = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext !== '' && !in_array($ext, self::ALLOWED_EXT, true)) {
            throw new RuntimeException('Такой файл поддержка не принимает: ' . $name);
        }
        $size = (int)@filesize($path);
        if ($size > self::maxBytes()) {
            throw new RuntimeException('Файл больше ' . (int)Settings::get('SUPPORT_MAX_MB', 25) . ' МБ: ' . $name);
        }

        $dir = ROOT . '/storage/support/' . $ticketId;
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Не создаётся папка storage/support');
        }
        $dest = $dir . '/' . bin2hex(random_bytes(4)) . '__' . $name;
        if (!@copy($path, $dest)) throw new RuntimeException('Файл не сохранился: ' . $name);

        Db::insert('support_files', [
            'ticket_id' => $ticketId,
            'filename'  => $name,
            'path'      => str_replace(ROOT . '/', '', $dest),
            'mime'      => self::mimeOf($dest, $name),
            'size'      => $size,
        ]);
    }

    /**
     * Обращения для экрана. Менеджер видит свои, администратор — все.
     *
     * @return array<int,array>
     */
    public static function listFor(array $manager, string $status = ''): array {
        $where  = '1=1';
        $params = [];
        if (empty($manager['is_admin'])) {
            $where .= ' AND t.manager_id = ?';
            $params[] = (int)$manager['id'];
        }
        if (isset(self::STATUSES[$status])) {
            $where .= ' AND t.status = ?';
            $params[] = $status;
        }
        $rows = Db::all(
            "SELECT t.*, m.name AS manager_name, r.name AS reviewer_name
             FROM support_tickets t
             LEFT JOIN managers m ON m.id = t.manager_id
             LEFT JOIN managers r ON r.id = t.reviewed_by
             WHERE $where ORDER BY t.id DESC LIMIT 200", $params);
        foreach ($rows as &$row) $row['files'] = self::files((int)$row['id']);
        unset($row);
        return $rows;
    }

    public static function get(int $id): ?array {
        $row = Db::one("SELECT t.*, m.name AS manager_name FROM support_tickets t
                        LEFT JOIN managers m ON m.id = t.manager_id WHERE t.id=?", [$id]);
        if (!$row) return null;
        $row['files'] = self::files($id);
        return $row;
    }

    public static function files(int $ticketId): array {
        return Db::all("SELECT id, filename, mime, size, remote_url FROM support_files
                        WHERE ticket_id=? ORDER BY id", [$ticketId]);
    }

    /** Сколько обращений ждут администратора — цифра на «Обзоре». */
    public static function pending(): int {
        return (int)Db::val("SELECT COUNT(*) FROM support_tickets WHERE status='new'");
    }

    public static function decline(int $id, int $adminId, string $note): void {
        $row = self::get($id);
        if (!$row) throw new RuntimeException('Обращение не найдено');
        Db::update('support_tickets', [
            'status'      => 'declined',
            'reviewed_by' => $adminId,
            'reviewed_at' => date('Y-m-d H:i:s'),
            'review_note' => trim($note) ?: null,
        ], 'id=?', [$id]);

        // Автор узнаёт решение: отклонение молча — это та же тишина, из-за
        // которой жалобы перестают писать
        if (!empty($row['manager_id'])) {
            require_once ROOT . '/lib/notifier.php';
            Notifier::notify('support', 'Обращение отклонено: ' . (string)$row['title'],
                trim($note) ?: null, null, $id, (int)$row['manager_id'], '/#settings/support');
        }
    }

    /**
     * Одобрить и завести issue.
     *
     * Порядок как у выгрузки правок (модуль 022): сначала GitHub, и только
     * подтверждённый issue помечает обращение одобренным. Пометить раньше —
     * значит потерять жалобу, если GitHub ответил ошибкой.
     *
     * @return array{number:int,url:string,uploaded:int,failed:array<int,string>}
     */
    public static function approve(int $id, int $adminId, array $edits = []): array {
        $row = self::get($id);
        if (!$row) throw new RuntimeException('Обращение не найдено');
        if ((string)$row['status'] === 'approved' && !empty($row['issue_url'])) {
            throw new RuntimeException('Issue уже заведён: ' . (string)$row['issue_url']);
        }
        $repo = self::repo();
        if ($repo === '') throw new RuntimeException('Не указан репозиторий — «Настройки → Обратная связь»');
        if (self::token() === '') {
            throw new RuntimeException('Нужен токен GitHub с правом Issues: Write — «Настройки → Обратная связь»');
        }

        $title = trim((string)($edits['title'] ?? $row['title'])) ?: (string)$row['title'];
        $body  = trim((string)($edits['body']  ?? $row['body'] ?? ''));

        // Файлы уезжают ПЕРВЫМИ: ссылка на файл, которого в репозитории нет, —
        // это issue со сломанной картинкой
        $uploaded = 0;
        $failed   = [];
        foreach ($row['files'] as $file) {
            if (!empty($file['remote_url'])) { $uploaded++; continue; }
            try {
                $url = self::pushFile($repo, (int)$file['id']);
                if ($url !== '') $uploaded++;
            } catch (Throwable $e) {
                $failed[] = (string)$file['filename'] . ' — ' . $e->getMessage();
                Logger::exception('support', $e, ['ticket' => $id, 'file' => $file['filename']]);
            }
        }

        $issue = self::api($repo, 'issues', 'POST', [
            'title'  => mb_substr($title, 0, 250),
            'body'   => self::issueBody($row + ['body' => $body]),
            'labels' => self::labels((string)$row['kind']),
        ]);
        $number = (int)($issue['number'] ?? 0);
        $url    = (string)($issue['html_url'] ?? '');

        Db::update('support_tickets', [
            'status'       => 'approved',
            'title'        => mb_substr($title, 0, 200),
            'body'         => $body !== '' ? $body : null,
            'reviewed_by'  => $adminId,
            'reviewed_at'  => date('Y-m-d H:i:s'),
            'issue_number' => $number ?: null,
            'issue_url'    => $url ?: null,
        ], 'id=?', [$id]);

        Logger::info('support', 'Обращение ушло в GitHub: #' . $number,
                     ['ticket' => $id, 'repo' => $repo, 'manager_id' => $adminId]);
        if (!empty($row['manager_id'])) {
            require_once ROOT . '/lib/notifier.php';
            Notifier::notify('support', 'Обращение принято: ' . $title,
                'Issue #' . $number, null, $id, (int)$row['manager_id'], $url ?: '/#settings/support');
        }

        return ['number' => $number, 'url' => $url, 'uploaded' => $uploaded, 'failed' => $failed];
    }

    private static function labels(string $kind): array {
        return match ($kind) {
            'idea'     => ['enhancement', 'из панели'],
            'question' => ['question', 'из панели'],
            'quality'  => ['качество', 'из панели'],
            default    => ['bug', 'из панели'],
        };
    }

    /**
     * Тело issue: жалоба словами менеджера, а под ней — то, чего он не знает.
     * Кто, когда, с какого экрана и какой моделью сделано то, что оценивают.
     */
    public static function issueBody(array $row): string {
        $out = [];
        $body = trim((string)($row['body'] ?? ''));
        if ($body !== '') $out[] = $body;

        if ((string)($row['kind'] ?? '') === 'quality') {
            $mark = (string)($row['rating'] ?? '') === 'down' ? '👎 плохо' : '👍 хорошо';
            $out[] = '**Оценка качества:** ' . $mark
                   . ((string)($row['model'] ?? '') !== '' ? ' · модель `' . (string)$row['model'] . '`' : '');
        }

        $files = $row['files'] ?? [];
        $shown = [];
        foreach ($files as $file) {
            $url = (string)($file['remote_url'] ?? '');
            if ($url === '') continue;
            $name = (string)$file['filename'];
            $shown[] = self::isImage((string)($file['mime'] ?? ''), $name)
                ? '![' . $name . '](' . $url . ')'
                : '[' . $name . '](' . $url . ')';
        }
        if ($shown) $out[] = "### Файлы\n\n" . implode("\n\n", $shown);

        // Кто, когда и с какого экрана прислал обращение — это метаданные
        // самого тикета (видны в панели Атлант), в GitHub issue не печатаются
        // отдельным блоком (issue #60).

        return implode("\n\n", $out);
    }

    /**
     * Файл — в репозиторий, а ссылку на него — в issue.
     *
     * Вложения issue загружаются только веб-интерфейсом GitHub: у API такой
     * ручки нет вовсе. Поэтому файл коммитится в `SUPPORT_ASSETS_PATH` и
     * печатается ссылкой. В приватном репозитории картинка откроется тому, у
     * кого есть доступ, — то есть ровно тому, кто читает issue.
     */
    private static function pushFile(string $repo, int $fileId): string {
        $file = Db::one("SELECT * FROM support_files WHERE id=?", [$fileId]);
        if (!$file) return '';
        $path = ROOT . '/' . ltrim((string)$file['path'], '/');
        if (!is_file($path)) throw new RuntimeException('Файла нет на диске');

        $bytes = (string)file_get_contents($path);
        if (strlen($bytes) > self::maxBytes()) throw new RuntimeException('Файл слишком велик для issue');

        $dir    = trim((string)Settings::get('SUPPORT_ASSETS_PATH', 'support/uploads'), '/');
        $remote = ($dir !== '' ? $dir . '/' : '')
                . date('Y/m') . '/' . $fileId . '-' . self::asciiName((string)$file['filename']);
        $endpoint = 'contents/' . implode('/', array_map('rawurlencode', explode('/', $remote)));

        $branch = trim((string)Settings::get('SUPPORT_ASSETS_BRANCH', '')) ?: self::defaultBranch($repo);
        $put = [
            'message' => 'Файл к обращению поддержки #' . (int)$file['ticket_id'],
            'content' => base64_encode($bytes),
        ];
        if ($branch !== '') $put['branch'] = $branch;

        $resp = self::api($repo, $endpoint, 'PUT', $put);
        $url = (string)($resp['content']['download_url'] ?? $resp['content']['html_url'] ?? '');
        if ($url !== '') Db::update('support_files', ['remote_url' => $url], 'id=?', [$fileId]);
        return $url;
    }

    /** Ветка по умолчанию — спрашиваем репозиторий, а не угадываем «main». */
    private static function defaultBranch(string $repo): string {
        try {
            $info = self::api($repo, '', 'GET', null, true);
            return (string)($info['default_branch'] ?? '');
        } catch (Throwable) {
            return '';
        }
    }

    /** Имя файла для пути в репозитории: кириллица в URL картинки не нужна. */
    private static function asciiName(string $name): string {
        $ext  = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $base = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', pathinfo($name, PATHINFO_FILENAME));
        $base = trim($base, '-');
        if ($base === '') $base = 'file';
        return mb_substr($base, 0, 60) . ($ext !== '' ? '.' . $ext : '');
    }

    public static function isImage(string $mime, string $name = ''): bool {
        if (str_starts_with($mime, 'image/')) return true;
        return in_array(mb_strtolower(pathinfo($name, PATHINFO_EXTENSION)),
                        ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp'], true);
    }

    private static function mimeOf(string $path, string $name): string {
        if (function_exists('finfo_open')) {
            $fi = @finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) {
                $mime = (string)@finfo_file($fi, $path);
                @finfo_close($fi);
                if ($mime !== '') return $mime;
            }
        }
        return match (mb_strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'png' => 'image/png', 'jpg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
            'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
            default => 'application/octet-stream',
        };
    }

    /** GitHub REST. `$soft` — 404 это «нет такого», а не ошибка. */
    private static function api(string $repo, string $endpoint, string $method = 'GET',
                               ?array $body = null, bool $soft = false): ?array {
        $url = 'https://api.github.com/repos/' . $repo . ($endpoint !== '' ? '/' . $endpoint : '');
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: atlant-kp-support',
        ];
        $token = self::token();
        if ($token !== '') $headers[] = 'Authorization: Bearer ' . $token;
        if ($body !== null) $headers[] = 'Content-Type: application/json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => max(30, (int)Settings::get('KNOWLEDGE_TIMEOUT_SEC', 20)),
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));

        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("GitHub недоступен: $err");
        if ($code === 404 && $soft) return null;
        if ($code === 401 || $code === 403) {
            throw new RuntimeException("GitHub отклонил запрос (HTTP $code). Токену нужны права "
                . 'Issues: Write и Contents: Write на репозиторий ' . $repo);
        }
        if ($code >= 400) {
            $data = json_decode((string)$resp, true);
            throw new RuntimeException('GitHub вернул HTTP ' . $code . ': '
                . (string)($data['message'] ?? mb_substr((string)$resp, 0, 200)));
        }
        $data = json_decode((string)$resp, true);
        return is_array($data) ? $data : [];
    }
}
