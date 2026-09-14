<?php
/**
 * Правки, на которых сервис учится (модуль 022).
 *
 * Раньше правки жили по углам: корректуры сопроводительного письма — в таблице
 * `corrections`, исправленная категория письма — нигде, а «ответ подобран не
 * тот» вообще нельзя было сказать. В итоге одна и та же ошибка повторялась
 * письмо за письмом, и единственным способом её исправить было переписать
 * промпт.
 *
 * Здесь у всех правок одно место и одна форма: ЧТО спросили, ЧТО ответила
 * машина и КАК должно было быть. Из этого получаются два разных продукта:
 *
 *   — few-shot: свежие правки подмешиваются в промпт, и классификатор с
 *     ответами начинают повторять решения менеджера, а не свои прошлые ошибки;
 *   — архив: всё накопленное выгружается в вики компании
 *     (`dansury/Atlant`, `GRAPH/RAW/NEW`), где из него делают знания.
 *
 * Выгрузка помечает строки `exported_at`, и следующий архив собирается ТОЛЬКО
 * из новых: иначе каждая выгрузка была бы копией предыдущей плюс немного.
 */
final class Learning {

    /** Что за правка. Ключ живёт в базе — переименовывать нельзя. */
    public const KINDS = [
        'category' => 'Классификация письма',
        'reply'    => 'Ответ на письмо',
        'answer'   => 'Проверка подбора',
        'kp'       => 'Текст КП',
        'prompt'   => 'Промпт',
        'knowledge'=> 'База знаний',
        'tov'      => 'Tone of Voice',
    ];

    public static function label(string $kind): string {
        return self::KINDS[$kind] ?? $kind;
    }

    /**
     * Записать правку.
     *
     * Пустая «правильная» часть — это не правка, а просто лог: такие строки не
     * пишем вовсе. Совпадающие «было» и «стало» — тоже: менеджер нажал кнопку,
     * ничего не изменив.
     */
    public static function record(string $kind, array $data): int {
        if (!isset(self::KINDS[$kind])) return 0;

        $correct = trim((string)($data['correct_answer'] ?? ''));
        $auto    = trim((string)($data['auto_answer'] ?? ''));
        if ($correct === '' || $correct === $auto) return 0;

        // Тот же вопрос с тем же правильным ответом второй раз — не новая
        // правка, а повторное нажатие. В базе от него ничего не прибавится, а
        // в промпте одинаковые примеры вытесняют разные.
        $question = self::clip((string)($data['question'] ?? ''), 8000);
        $twin = Db::val(
            "SELECT id FROM learning_samples WHERE kind=? AND question=? AND correct_answer=? LIMIT 1",
            [$kind, $question, self::clip($correct, 8000)]
        );
        if ($twin) return 0;

        $id = (int)Db::insert('learning_samples', [
            'kind'           => $kind,
            'subject'        => self::clip((string)($data['subject'] ?? ''), 300),
            'question'       => $question,
            'auto_answer'    => self::clip($auto, 8000),
            'correct_answer' => self::clip($correct, 8000),
            'comment'        => self::clip((string)($data['comment'] ?? ''), 2000),
            'context_json'   => !empty($data['context']) ? json_encode($data['context'], JSON_UNESCAPED_UNICODE) : null,
            'manager_id'     => !empty($data['manager_id']) ? (int)$data['manager_id'] : null,
        ]);
        Logger::info('learning', 'Правка сохранена: ' . self::label($kind), ['id' => $id]);
        return $id;
    }

    /** Список для панели. `$filter`: kind, only_new, page, per_page. */
    public static function query(array $filter = []): array {
        $where = [];
        $params = [];
        $kind = trim((string)($filter['kind'] ?? ''));
        if ($kind !== '' && isset(self::KINDS[$kind])) { $where[] = 'kind = ?'; $params[] = $kind; }
        if (!empty($filter['only_new'])) $where[] = 'exported_at IS NULL';

        $sql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
        $perPage = max(1, min(200, (int)($filter['per_page'] ?? 25)));
        $page    = max(1, (int)($filter['page'] ?? 1));

        $total = (int)Db::val("SELECT COUNT(*) FROM learning_samples$sql", $params);
        $rows = Db::all(
            "SELECT s.*, m.name AS manager_name FROM learning_samples s
             LEFT JOIN managers m ON m.id = s.manager_id
             $sql ORDER BY s.id DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );
        foreach ($rows as &$row) {
            $row['kind_label'] = self::label((string)$row['kind']);
            $row['context'] = $row['context_json'] ? (json_decode((string)$row['context_json'], true) ?: null) : null;
            unset($row['context_json']);
        }
        return [
            'items'   => $rows,
            'total'   => $total,
            'page'    => $page,
            'pending' => (int)Db::val("SELECT COUNT(*) FROM learning_samples WHERE exported_at IS NULL"),
            'kinds'   => array_map(fn($k, $l) => ['key' => $k, 'label' => $l],
                                   array_keys(self::KINDS), array_values(self::KINDS)),
        ];
    }

    /** Поправить правку руками — админ видит их все и может переписать. */
    public static function update(int $id, array $fields): void {
        $upd = [];
        foreach (['subject', 'question', 'auto_answer', 'correct_answer', 'comment'] as $f) {
            if (array_key_exists($f, $fields)) $upd[$f] = self::clip((string)$fields[$f], 8000);
        }
        if (isset($fields['kind']) && isset(self::KINDS[$fields['kind']])) $upd['kind'] = $fields['kind'];
        if (!$upd) return;
        $upd['updated_at'] = date('Y-m-d H:i:s');
        Db::update('learning_samples', $upd, 'id=?', [$id]);
    }

    public static function delete(int $id): void {
        Db::q("DELETE FROM learning_samples WHERE id=?", [$id]);
    }

    /**
     * Свежие правки этого вида — то, что подмешивается в промпт.
     *
     * Берутся ПОСЛЕДНИЕ, а не лучшие: менеджер меняет мнение, и правило,
     * записанное сегодня, важнее такого же прошлогоднего.
     */
    public static function fewShot(string $kind, int $limit = 8): array {
        if (!isset(self::KINDS[$kind])) return [];
        return Db::all(
            "SELECT subject, question, auto_answer, correct_answer, comment
             FROM learning_samples WHERE kind=? ORDER BY id DESC LIMIT ?",
            [$kind, max(1, $limit)]
        );
    }

    // ------------------------------------------------------------- выгрузка

    /** Куда уходит архив: `GRAPH/RAW/NEW` репозитория вики. */
    public static function exportTarget(): array {
        return [
            'repo'   => trim((string)Settings::get('LEARNING_EXPORT_REPO', (string)Settings::get('KNOWLEDGE_REPO', 'dansury/Atlant'))),
            'branch' => trim((string)Settings::get('LEARNING_EXPORT_BRANCH', (string)Settings::get('KNOWLEDGE_BRANCH', 'Main'))),
            'path'   => trim((string)Settings::get('LEARNING_EXPORT_PATH', 'GRAPH/RAW/NEW'), '/'),
        ];
    }

    /**
     * Собрать архив из новых правок и положить его в репозиторий.
     *
     * Порядок здесь важен и он такой: сначала архив уходит на GitHub, и только
     * ПОСЛЕ подтверждённой записи строки помечаются выгруженными. Пометить
     * раньше — значит потерять правки, если GitHub ответил ошибкой.
     *
     * @return array{file:string,url:string,count:int,bytes:int}
     */
    public static function export(int $managerId): array {
        $rows = Db::all("SELECT s.*, m.name AS manager_name FROM learning_samples s
                         LEFT JOIN managers m ON m.id = s.manager_id
                         WHERE s.exported_at IS NULL ORDER BY s.id");
        if (!$rows) throw new RuntimeException('Новых правок нет — выгружать нечего');

        $target = self::exportTarget();
        if ($target['repo'] === '') throw new RuntimeException('Не указан репозиторий для выгрузки');
        if (trim((string)Settings::get('GITHUB_TOKEN', '')) === '') {
            throw new RuntimeException('Нужен токен GitHub с правом Contents: Write — «Настройки → База знаний»');
        }

        $batch = date('Y-m-d_His');
        // Имя берём У АРХИВА, а не считаем второй раз: без `ZipArchive` он
        // собирается как `.json`, и файл лёг бы в репозиторий под именем `.zip`
        $archive = self::buildArchive($rows, $batch);

        $url = self::putFile($target, $archive['name'], $archive['bytes'],
            'Правки панели Атлант: ' . count($rows) . ' шт., ' . $batch);

        $now = date('Y-m-d H:i:s');
        foreach ($rows as $row) {
            Db::update('learning_samples',
                ['exported_at' => $now, 'export_batch' => $batch], 'id=?', [(int)$row['id']]);
        }
        // Это состояние, а не настройка: строкой в `settings`, без префикса
        // `cfg.` — в панели «Все параметры» ему делать нечего
        Db::q("INSERT INTO settings (key, value) VALUES ('learning_last_export', ?)
               ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [$now . ' · ' . $archive['name'] . ' · ' . count($rows)]);

        Logger::info('learning', 'Правки выгружены в репозиторий: ' . $name,
                     ['count' => count($rows), 'repo' => $target['repo'], 'manager_id' => $managerId]);

        return [
            'file'  => $archive['name'],
            'url'   => $url,
            'count' => count($rows),
            'bytes' => strlen($archive['bytes']),
        ];
    }

    /**
     * Архив: по файлу `.jsonl` на каждый вид правок плюс `README.md`, чтобы
     * человек, открывший его через год, понял, что это.
     *
     * Без `ZipArchive` архив не собрать — тогда уходит один `.json`: лучше
     * выгрузить не в том формате, чем не выгрузить вовсе.
     */
    private static function buildArchive(array $rows, string $batch): array {
        $byKind = [];
        foreach ($rows as $row) {
            $byKind[(string)$row['kind']][] = self::exportRow($row);
        }

        $readme = "# Правки панели Атлант\n\n"
            . "Выгрузка: $batch\n"
            . 'Записей: ' . count($rows) . "\n\n"
            . "Каждый файл — JSON Lines, по правке в строке:\n\n"
            . "- `question` — что было на входе (письмо, запрос, текст);\n"
            . "- `auto_answer` — что ответила машина;\n"
            . "- `correct_answer` — как должно было быть, по словам менеджера;\n"
            . "- `comment` — пояснение менеджера, если он его оставил.\n\n"
            . "## Виды правок\n\n";
        foreach ($byKind as $kind => $items) {
            $readme .= '- `' . $kind . '.jsonl` — ' . self::label($kind) . ': ' . count($items) . "\n";
        }

        if (!class_exists('ZipArchive')) {
            $json = json_encode(['batch' => $batch, 'items' => array_map([self::class, 'exportRow'], $rows)],
                                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            return ['name' => 'atlant-learning-' . $batch . '.json', 'bytes' => (string)$json];
        }

        $tmp = ROOT . '/data/tmp';
        if (!is_dir($tmp)) @mkdir($tmp, 0755, true);
        $path = $tmp . '/learning-' . $batch . '.zip';

        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Не удалось собрать архив в data/tmp');
        }
        $zip->addFromString('README.md', $readme);
        foreach ($byKind as $kind => $items) {
            $lines = array_map(fn($i) => json_encode($i, JSON_UNESCAPED_UNICODE), $items);
            $zip->addFromString($kind . '.jsonl', implode("\n", $lines) . "\n");
        }
        $zip->close();

        $bytes = (string)file_get_contents($path);
        @unlink($path);
        return ['name' => 'atlant-learning-' . $batch . '.zip', 'bytes' => $bytes];
    }

    private static function exportRow(array $row): array {
        return [
            'id'             => (int)$row['id'],
            'kind'           => (string)$row['kind'],
            'kind_label'     => self::label((string)$row['kind']),
            'subject'        => (string)($row['subject'] ?? ''),
            'question'       => (string)($row['question'] ?? ''),
            'auto_answer'    => (string)($row['auto_answer'] ?? ''),
            'correct_answer' => (string)($row['correct_answer'] ?? ''),
            'comment'        => (string)($row['comment'] ?? ''),
            'context'        => $row['context_json'] ? json_decode((string)$row['context_json'], true) : null,
            'manager'        => (string)($row['manager_name'] ?? ''),
            'created_at'     => (string)($row['created_at'] ?? ''),
        ];
    }

    /**
     * Положить файл в репозиторий через GitHub Contents API.
     *
     * Имя архива содержит время до секунды, поэтому файл всегда новый и `sha`
     * существующего не нужен. Если он всё же занят — читаем `sha` и
     * перезаписываем: молча падать на «уже существует» здесь не за что.
     */
    private static function putFile(array $target, string $name, string $bytes, string $message): string {
        $path = ($target['path'] !== '' ? $target['path'] . '/' : '') . $name;
        $endpoint = 'contents/' . implode('/', array_map('rawurlencode', explode('/', $path)));

        $body = [
            'message' => $message,
            'content' => base64_encode($bytes),
            'branch'  => $target['branch'],
        ];
        $existing = self::api($target['repo'], $endpoint . '?ref=' . rawurlencode($target['branch']), 'GET', null, true);
        if (is_array($existing) && !empty($existing['sha'])) $body['sha'] = $existing['sha'];

        $resp = self::api($target['repo'], $endpoint, 'PUT', $body);
        return (string)($resp['content']['html_url'] ?? '');
    }

    /** GitHub REST. `$soft` — 404 это не ошибка, а «файла ещё нет». */
    private static function api(string $repo, string $endpoint, string $method = 'GET',
                               ?array $body = null, bool $soft = false): ?array {
        $url = 'https://api.github.com/repos/' . $repo . '/' . $endpoint;
        $headers = [
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: atlant-kp-learning',
        ];
        $token = trim((string)Settings::get('GITHUB_TOKEN', ''));
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
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) throw new RuntimeException("GitHub недоступен: $err");
        if ($code === 404 && $soft) return null;
        if ($code === 401 || $code === 403) {
            throw new RuntimeException("GitHub отклонил запрос (HTTP $code). Токену нужно право Contents: Write "
                . 'на репозиторий ' . $repo);
        }
        if ($code >= 400) {
            $data = json_decode((string)$resp, true);
            throw new RuntimeException('GitHub вернул HTTP ' . $code . ': '
                . (string)($data['message'] ?? mb_substr((string)$resp, 0, 200)));
        }
        $data = json_decode((string)$resp, true);
        return is_array($data) ? $data : [];
    }

    private static function clip(string $text, int $max): string {
        $text = trim($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . '…' : $text;
    }
}
