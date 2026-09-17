<?php
/**
 * Мастер настройки: что нужно сервису, чтобы запуститься с нуля (модуль 034).
 *
 * Установка панели на новый хостинг до сих пор была устной традицией: администратор
 * открывал «Все параметры» и заполнял их по памяти, а чего именно не хватает —
 * выяснялось на первом же письме клиента.
 *
 * Мастер — это не отдельный экран настроек. Он СПРАШИВАЕТ те же ключи
 * `Settings::SPEC`, показывает, откуда их взять (прямая ссылка на страницу
 * создания ключа, с нужными правами прямо в тексте), и сам проверяет, работает
 * ли то, что ввели. Ни одного значения он не хранит у себя: единственное его
 * состояние — где остановились и что пропустили.
 *
 * Шаг проверяется ПО ФАКТУ, а не по галочке «заполнено»: ящик есть, логотип
 * загружен, у провайдера нейросети есть ключ. Поэтому мастер можно перезапустить
 * на работающем сервисе — он покажет ровно то, чего не хватает.
 */
final class SetupWizard {

    /** Состояние живёт строкой в `settings` без префикса `cfg.` — это не настройка. */
    private const STATE_KEY = 'setup_wizard';

    /** Текст, который подставляется в проверочный запрос. */
    public const TRIAL_TEXT = "Здравствуйте!\n\n"
        . "Просьба выставить коммерческое предложение на бронежилет скрытого ношения — 10 шт.\n"
        . "и шлем защитный — 5 шт. Нужны сроки поставки и условия оплаты.\n\n"
        . "С уважением,\nИванов Иван\nООО «Ромашка», ИНН 7810964292\n+7 900 000-00-00";

    /**
     * Шаги по порядку. `keys` — ключи `Settings::SPEC`, которые шаг спрашивает;
     * панель рисует поля из самого спека, а не из второго списка подписей.
     *
     * `links` — куда идти за ключом. Адрес НАСТОЯЩИЙ и ведёт сразу на нужную
     * страницу: «зайдите в настройки аккаунта» — это не инструкция.
     */
    public static function steps(): array {
        return [
            [
                'key'   => 'general',
                'title' => 'Сервис и адрес',
                'why'   => 'Адрес нужен вебхукам МойСклад и ссылкам в письмах и уведомлениях.',
                'keys'  => ['APP_URL', 'TIMEZONE'],
                'links' => [],
                'state' => self::check('general'),
            ],
            [
                'key'   => 'managers',
                'title' => 'Менеджеры',
                'why'   => 'Письма, КП и счета подписываются человеком. Администратор уже есть — заведите тех, кто будет работать.',
                'keys'  => [],
                'links' => [['label' => 'Завести менеджера', 'url' => '#settings/managers',
                             'note'  => 'Внутри панели: имя, логин, пароль и подпись']],
                'state' => self::check('managers'),
            ],
            [
                'key'   => 'moysklad',
                'title' => 'МойСклад',
                'why'   => 'Оттуда берутся каталог, цены, остатки, реквизиты и счета. Без токена КП не назовёт ни одной цены.',
                'keys'  => ['MOYSKLAD_TOKEN', 'MOYSKLAD_ORG_ID', 'MOYSKLAD_STORES'],
                'links' => [
                    ['label' => 'Создать токен в МойСклад',
                     'url'   => 'https://online.moysklad.ru/app/#admin/settings',
                     'note'  => 'Настройки → Обмен данными → Приложения и интеграции → «Новый токен доступа»'],
                    ['label' => 'ID организации',
                     'url'   => 'https://online.moysklad.ru/app/#company',
                     'note'  => 'Откройте организацию — её id стоит в адресе строки браузера'],
                ],
                'test'  => 'test_moysklad',
                'state' => self::check('moysklad'),
            ],
            [
                'key'   => 'llm',
                'title' => 'Нейросети',
                'why'   => 'Разбор писем, черновики ответов и подбор позиций. Хватит одного провайдера, второй — запасной.',
                'keys'  => ['LLM_PROVIDER_PRIORITY', 'YANDEX_API_KEY', 'YANDEX_FOLDER_ID', 'YANDEX_MODEL',
                            'OPENROUTER_API_KEY', 'OPENROUTER_MODEL'],
                'links' => [
                    ['label' => 'Yandex Cloud: сервисный аккаунт и API-ключ',
                     'url'   => 'https://console.yandex.cloud/',
                     'note'  => 'Сервисному аккаунту нужны роли ai.languageModels.user и ai.embeddings.user; '
                              . 'Folder ID виден в адресе консоли'],
                    ['label' => 'OpenRouter: ключ',
                     'url'   => 'https://openrouter.ai/settings/keys',
                     'note'  => 'С российского хостинга обычно нужен прокси — «Настройки → Нейросети»'],
                ],
                'test'  => 'test_llm',
                'state' => self::check('llm'),
            ],
            [
                'key'   => 'mail',
                'title' => 'Почта',
                'why'   => 'Входящие письма становятся карточками, ответы уходят с рабочего адреса.',
                'keys'  => ['MAIL_OUTGOING_FROM'],
                'links' => [['label' => 'Добавить ящик', 'url' => '#settings/mail',
                             'note'  => 'IMAP и SMTP: Яндекс 360, Mail.ru для бизнеса или cPanel хостинга']],
                'state' => self::check('mail'),
            ],
            [
                'key'   => 'branding',
                'title' => 'Логотипы',
                'why'   => 'КП без знака читается как черновик. Тот же файл идёт на иконку приложения и значок вкладки.',
                'keys'  => [],
                'links' => [['label' => 'Загрузить логотипы', 'url' => '#settings/branding',
                             'note'  => 'PNG или JPEG: для документа, для шапки панели и для значка']],
                'state' => self::check('branding'),
            ],
            [
                'key'   => 'github',
                'title' => 'GitHub: вики и поддержка',
                'why'   => 'Вики компании подмешивается в промпты, правки менеджеров уезжают в архив, '
                         . 'а жалобы из панели заводятся как issue.',
                'keys'  => ['GITHUB_TOKEN', 'KNOWLEDGE_REPO', 'SUPPORT_REPO', 'SUPPORT_TOKEN'],
                'links' => [
                    ['label' => 'Создать fine-grained токен',
                     'url'   => 'https://github.com/settings/personal-access-tokens/new',
                     'note'  => 'Repository access — только нужные репозитории. Права: Contents: Read and write '
                              . '(вики и выгрузка правок), Issues: Read and write (обращения из панели), '
                              . 'Metadata: Read (ставится сама)'],
                ],
                'state' => self::check('github'),
            ],
            [
                'key'      => 'bitrix',
                'title'    => 'Сайт (Битрикс)',
                'why'      => 'Ссылки и QR-коды на товары в КП. Без сайта КП печатается без ссылок.',
                'keys'     => ['BITRIX_ENABLED', 'BITRIX_SITE_URL', 'BITRIX_WEBHOOK_URL'],
                'links'    => [['label' => 'Модуль для сайта', 'url' => '#settings/catalog',
                                'note'  => 'Модуль лежит в репозитории: bitrix-module/atlant.kpsync']],
                'optional' => true,
                'state'    => self::check('bitrix'),
            ],
            [
                'key'   => 'trial',
                'title' => 'Проверочные КП и письмо',
                'why'   => 'Всё настроено — проверьте на живом запросе: вставьте текст, посмотрите КП и письмо '
                         . 'и скажите, годится ли качество.',
                'keys'  => [],
                'links' => [],
                'state' => self::check('trial'),
            ],
        ];
    }

    /**
     * Готов ли шаг — по факту, а не по «в поле что-то есть».
     *
     * @return array{status:string,note:string}
     */
    private static function check(string $key): array {
        $ok   = fn(string $note) => ['status' => 'ok', 'note' => $note];
        $todo = fn(string $note) => ['status' => 'todo', 'note' => $note];

        switch ($key) {
            case 'general':
                $url = trim((string)Settings::get('APP_URL', ''));
                return $url !== '' ? $ok('Адрес: ' . $url) : $todo('Адрес сервиса не указан');

            case 'managers': {
                $all   = (int)Db::val("SELECT COUNT(*) FROM managers WHERE COALESCE(is_active,1)=1");
                $plain = (int)Db::val("SELECT COUNT(*) FROM managers WHERE COALESCE(is_admin,0)=0 AND COALESCE(is_active,1)=1");
                return $plain > 0
                    ? $ok("Менеджеров: $plain, всего учётных записей: $all")
                    : $todo('Кроме администратора никого нет');
            }

            case 'moysklad': {
                if (trim((string)Settings::get('MOYSKLAD_TOKEN', '')) === '') return $todo('Токен не введён');
                $products = (int)Db::val("SELECT COUNT(*) FROM products_cache");
                return $products > 0
                    ? $ok("Каталог: позиций $products")
                    : $todo('Токен есть, но каталог ещё не синхронизирован');
            }

            case 'llm': {
                require_once ROOT . '/lib/llm.php';
                $ready = [];
                foreach (LLM::status() as $p) if (!empty($p['ready'])) $ready[] = (string)$p['label'];
                return $ready ? $ok('Отвечают: ' . implode(', ', $ready)) : $todo('Ни у одного провайдера нет ключа');
            }

            case 'mail': {
                $boxes = (int)Db::val("SELECT COUNT(*) FROM mailboxes WHERE is_active=1");
                return $boxes > 0 ? $ok("Ящиков включено: $boxes") : $todo('Ни одного почтового ящика');
            }

            case 'branding': {
                require_once ROOT . '/lib/branding.php';
                $kp = Branding::uploaded('kp');
                return $kp ? $ok('Логотип для КП загружен') : $todo('Логотип КП не загружен — печатается встроенный');
            }

            case 'github': {
                if (trim((string)Settings::get('GITHUB_TOKEN', '')) === ''
                    && trim((string)Settings::get('SUPPORT_TOKEN', '')) === '') {
                    return $todo('Токен не введён — вики и обращения выключены');
                }
                $sections = (int)Db::val("SELECT COUNT(*) FROM knowledge_sections");
                return $sections > 0 ? $ok("Вики: разделов $sections") : $ok('Токен есть, вики ещё не синхронизирована');
            }

            case 'bitrix':
                return (int)Settings::get('BITRIX_ENABLED', 0) === 1
                    && trim((string)Settings::get('BITRIX_SITE_URL', '')) !== ''
                    ? $ok('Сайт подключён')
                    : $todo('Не подключён — КП печатается без ссылок и QR');

            case 'trial': {
                $state = self::state();
                $id = (int)($state['trial_request_id'] ?? 0);
                if (!$id) return $todo('Проверочный запрос ещё не создавали');
                $votes = $state['trial_votes'] ?? [];
                $done  = !empty($votes['kp']) && !empty($votes['letter']);
                return $done
                    ? $ok('Оценка получена: КП — ' . self::voteWord((string)$votes['kp'])
                          . ', письмо — ' . self::voteWord((string)$votes['letter']))
                    : $todo('Запрос #' . $id . ' создан — оцените КП и письмо');
            }
        }
        return $todo('');
    }

    private static function voteWord(string $vote): string {
        return $vote === 'up' ? '👍' : ($vote === 'down' ? '👎' : '—');
    }

    // ---- Состояние ----

    /** @return array{started_at:?string,done_at:?string,step:string,skipped:array,trial_request_id:int} */
    public static function state(): array {
        $raw  = Db::val("SELECT value FROM settings WHERE key=?", [self::STATE_KEY]);
        $data = $raw ? json_decode((string)$raw, true) : null;
        return is_array($data) ? $data + self::blank() : self::blank();
    }

    private static function blank(): array {
        return ['started_at' => null, 'done_at' => null, 'step' => '', 'skipped' => [],
                'trial_request_id' => 0, 'trial_counterparty_id' => 0, 'trial_votes' => []];
    }

    private static function store(array $state): array {
        Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [self::STATE_KEY, json_encode($state, JSON_UNESCAPED_UNICODE)]);
        return $state;
    }

    /**
     * Что показать: шаги, их состояние и на каком остановились.
     *
     * @return array{steps:array,state:array,done:int,total:int,next:string,ready:bool}
     */
    public static function progress(): array {
        $steps   = self::steps();
        $state   = self::state();
        $skipped = (array)($state['skipped'] ?? []);

        $done = 0; $total = 0; $next = '';
        foreach ($steps as &$step) {
            $step['skipped']  = in_array($step['key'], $skipped, true);
            $step['optional'] = !empty($step['optional']);
            if (!$step['optional']) {
                $total++;
                if (($step['state']['status'] ?? '') === 'ok') $done++;
                elseif ($next === '' && !$step['skipped']) $next = (string)$step['key'];
            }
            // Поля шага — из общего спека, чтобы подпись и подсказка были одни
            $step['fields'] = array_values(array_filter(array_map(
                fn(string $k) => self::field($k), (array)$step['keys'])));
        }
        unset($step);

        return [
            'steps' => $steps,
            'state' => $state,
            'done'  => $done,
            'total' => $total,
            'next'  => $next ?: 'trial',
            'ready' => $done >= $total,
        ];
    }

    /** Одно поле настройки так, как его показывает панель (секрет не уезжает). */
    private static function field(string $key): ?array {
        if (!isset(Settings::SPEC[$key])) return null;
        [$group, $label, $type, $secret, $default, $hint] = Settings::SPEC[$key];
        $value = Settings::get($key);
        return [
            'key'    => $key,
            'label'  => $label,
            'type'   => $type,
            'hint'   => $hint,
            'secret' => (bool)$secret,
            'value'  => $secret ? '' : (string)$value,
            'filled' => $secret ? ((string)$value !== '') : null,
            'tail'   => $secret ? Settings::mask((string)$value) : null,
        ];
    }

    /** Мастер запускается сам, пока его ни разу не довели до конца. */
    public static function needed(): bool {
        $state = self::state();
        return empty($state['done_at']);
    }

    public static function start(int $managerId): array {
        $state = self::state();
        if (empty($state['started_at'])) $state['started_at'] = date('Y-m-d H:i:s');
        $state['done_at'] = null;
        Logger::info('setup', 'Мастер настройки запущен', ['manager_id' => $managerId]);
        return self::store($state);
    }

    /** Перезапуск из админки: состояние чистое, НАСТРОЙКИ НЕ ТРОГАЕМ. */
    public static function restart(int $managerId): array {
        Logger::info('setup', 'Мастер настройки перезапущен', ['manager_id' => $managerId]);
        return self::store(['started_at' => date('Y-m-d H:i:s')] + self::blank());
    }

    /**
     * Сохранить значения шага. Пишутся они туда же, куда пишет панель, —
     * в `Settings`: у мастера нет своей копии настроек.
     */
    public static function saveStep(string $key, array $values, int $managerId): array {
        $step = self::step($key);
        if (!$step) throw new RuntimeException('Неизвестный шаг мастера');

        $saved = 0;
        foreach ($values as $k => $v) {
            if (!in_array($k, (array)$step['keys'], true)) continue;
            if (!isset(Settings::SPEC[$k])) continue;
            // Пустое в поле секрета значит «оставить как было», а не «стереть»
            if (Settings::isSecret($k) && (string)$v === '') continue;
            Settings::set($k, is_bool($v) ? (int)$v : (string)$v);
            $saved++;
        }
        if ($saved) {
            require_once ROOT . '/lib/llm.php';
            LLM::init(Settings::effective());
            Logger::info('setup', "Шаг «{$step['title']}»: сохранено параметров $saved",
                         ['manager_id' => $managerId, 'step' => $key]);
        }

        $state = self::state();
        $state['step'] = $key;
        $state['skipped'] = array_values(array_diff((array)$state['skipped'], [$key]));
        self::store($state);
        return ['saved' => $saved] + self::progress();
    }

    public static function skipStep(string $key): array {
        if (!self::step($key)) throw new RuntimeException('Неизвестный шаг мастера');
        $state = self::state();
        $state['skipped'] = array_values(array_unique(array_merge((array)$state['skipped'], [$key])));
        $state['step'] = $key;
        self::store($state);
        return self::progress();
    }

    public static function finish(int $managerId): array {
        $state = self::state();
        $state['done_at'] = date('Y-m-d H:i:s');
        self::store($state);
        Logger::info('setup', 'Мастер настройки завершён', ['manager_id' => $managerId]);
        return self::progress();
    }

    /** Проверочный запрос — какой именно создали и как его открыть. */
    public static function rememberTrial(int $requestId, int $counterpartyId): array {
        $state = self::state();
        $state['trial_request_id']      = $requestId;
        $state['trial_counterparty_id'] = $counterpartyId;
        $state['trial_votes']           = [];
        return self::store($state);
    }

    /**
     * Оценка качества. «Палец вниз» — это не просто цифра: он попадает в
     * обращения поддержки вместе с моделью, которой это сделано, и панель
     * предлагает модель подороже.
     *
     * @return array{state:array,ticket:int}
     */
    public static function vote(string $what, string $vote, int $managerId, array $opts = []): array {
        $what = in_array($what, ['kp', 'letter'], true) ? $what : 'kp';
        $vote = $vote === 'up' ? 'up' : 'down';

        $state = self::state();
        $votes = (array)($state['trial_votes'] ?? []);
        $votes[$what] = $vote;
        $state['trial_votes'] = $votes;
        self::store($state);

        require_once ROOT . '/lib/llm.php';
        $current = LLM::currentModel();
        $model = trim((string)($opts['model'] ?? ($current['provider'] . ':' . $current['model'])), ':');

        require_once ROOT . '/lib/support.php';
        $ticket = Support::submit($managerId, [
            'kind'   => 'quality',
            // «Хорошо» администратору будить незачем — оно просто ложится в список
            'quiet'  => $vote === 'up',
            'title'  => ($what === 'kp' ? 'Качество КП' : 'Качество письма') . ': '
                      . ($vote === 'up' ? 'хорошо' : 'плохо'),
            'body'   => trim((string)($opts['comment'] ?? '')),
            'page'   => '#settings/setup',
            'rating' => $vote,
            'model'  => $model,
        ]);

        return ['state' => $state, 'ticket' => (int)$ticket['id']];
    }

    private static function step(string $key): ?array {
        foreach (self::steps() as $step) if ($step['key'] === $key) return $step;
        return null;
    }
}
