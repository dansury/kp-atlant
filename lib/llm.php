<?php
/**
 * LLM wrapper (adapted from NeuroPro lib/llm.php).
 * Dual-provider: OpenRouter + Yandex FM. Fallback chain, json-mode, retry.
 */
class LLM {
    /**
     * Model catalog shown in the admin panel (same idea as NeuroPro AVAILABLE_MODELS).
     * Grouped so the picker can use <optgroup>; a slug can still be typed by hand,
     * and «Обновить каталог OpenRouter» replaces this list with the live one.
     *
     * `tier` — 1 дешёвая, 2 рабочая, 3 самая дорогая. Это порядок, по которому
     * «палец вниз» предлагает модель ПОДОРОЖЕ (модуль 038), когда настоящей
     * цены нет: у Yandex прайса в API вовсе нет, а каталог OpenRouter мог ни
     * разу не обновляться. Обновлённый каталог несёт `price` и решает сам.
     */
    public const CATALOG = [
        // Yandex slugs are stored WITHOUT the version segment: the version is
        // added when the request is built (gpt://<folder>/<slug>/latest), the way
        // careerhack does it. A slug that carries its own version still works —
        // «yandexgpt/rc» keeps the rc.
        // Список — это КАНДИДАТЫ, а не факт: открытые модели (Llama, DeepSeek,
        // Qwen, Gemma) включены не в каждом облаке и не в каждом регионе, и
        // слаг, которого у провайдера нет, отвечает 404 «unknown model».
        // Что каталог отдаёт на самом деле, спрашивает «Проверить каталог
        // Yandex» (verifyYandexModels) — у Models API, а если тот не ответил,
        // то по одному короткому запросу на слаг, — и запоминает в
        // `yandex_models` вместе с моделями, которых в этом списке нет.
        'yandex' => [
            ['id' => 'yandexgpt-5.1',         'label' => 'YandexGPT Pro 5.1',         'group' => 'YandexGPT', 'tier' => 3],
            ['id' => 'yandexgpt-5-pro',       'label' => 'YandexGPT Pro 5',           'group' => 'YandexGPT', 'tier' => 3],
            ['id' => 'yandexgpt-5-lite',      'label' => 'YandexGPT Lite 5 — дешевле','group' => 'YandexGPT', 'tier' => 1],
            ['id' => 'yandexgpt',             'label' => 'YandexGPT Pro — алиас последней версии', 'group' => 'YandexGPT', 'tier' => 2],
            ['id' => 'yandexgpt-lite',        'label' => 'YandexGPT Lite — алиас последней версии', 'group' => 'YandexGPT', 'tier' => 1],
            ['id' => 'aliceai-llm',           'label' => 'Alice AI LLM',              'group' => 'Alice AI', 'tier' => 3],
            ['id' => 'aliceai-llm-flash',     'label' => 'Alice AI LLM Flash',        'group' => 'Alice AI', 'tier' => 2],
            ['id' => 'deepseek-v4-flash',     'label' => 'DeepSeek V4 Flash',         'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'gpt-oss-120b',          'label' => 'gpt-oss-120b',              'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'gpt-oss-20b',           'label' => 'gpt-oss-20b',               'group' => 'Открытые модели', 'tier' => 1],
            ['id' => 'qwen3-235b-a22b-fp8',   'label' => 'Qwen3 235B',                'group' => 'Открытые модели', 'tier' => 3],
            ['id' => 'qwen3.6-35b-a3b',       'label' => 'Qwen3.6 35B A3B',           'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'qwen2.5-72b-instruct',  'label' => 'Qwen2.5 72B Instruct',      'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'qwen2.5-32b-instruct',  'label' => 'Qwen2.5 32B Instruct',      'group' => 'Открытые модели', 'tier' => 1],
            ['id' => 'qwen2.5-7b-instruct',   'label' => 'Qwen2.5 7B Instruct',       'group' => 'Открытые модели', 'tier' => 1],
            ['id' => 'llama-3.3-70b-instruct','label' => 'Llama 3.3 70B Instruct',    'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'llama-3.1-70b-instruct','label' => 'Llama 3.1 70B Instruct',    'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'gemma-3-27b-it',        'label' => 'Gemma 3 27B IT',            'group' => 'Открытые модели', 'tier' => 2],
            ['id' => 'gemma-3-12b-it',        'label' => 'Gemma 3 12B IT',            'group' => 'Открытые модели', 'tier' => 1],
            ['id' => 'gemma-3-4b-it',         'label' => 'Gemma 3 4B IT',             'group' => 'Открытые модели', 'tier' => 1],
            ['id' => 'gemma-3-1b-it',         'label' => 'Gemma 3 1B IT',             'group' => 'Открытые модели', 'tier' => 1],
        ],
        'openrouter' => [
            ['id' => 'anthropic/claude-sonnet-4.5',       'label' => 'Claude Sonnet 4.5',      'group' => 'Anthropic', 'tier' => 3],
            ['id' => 'anthropic/claude-haiku-4.5',        'label' => 'Claude Haiku 4.5',       'group' => 'Anthropic', 'tier' => 2],
            ['id' => 'anthropic/claude-opus-4.1',         'label' => 'Claude Opus 4.1',        'group' => 'Anthropic', 'tier' => 3],
            ['id' => 'openai/gpt-5',                      'label' => 'GPT-5',                  'group' => 'OpenAI', 'tier' => 3],
            ['id' => 'openai/gpt-5-mini',                 'label' => 'GPT-5 mini',             'group' => 'OpenAI', 'tier' => 2],
            ['id' => 'openai/gpt-4.1',                    'label' => 'GPT-4.1',                'group' => 'OpenAI', 'tier' => 3],
            ['id' => 'openai/gpt-4.1-mini',               'label' => 'GPT-4.1 mini',           'group' => 'OpenAI', 'tier' => 2],
            ['id' => 'openai/gpt-4o-mini',                'label' => 'GPT-4o mini',            'group' => 'OpenAI', 'tier' => 1],
            ['id' => 'google/gemini-2.5-pro',             'label' => 'Gemini 2.5 Pro',         'group' => 'Google', 'tier' => 3],
            ['id' => 'google/gemini-2.5-flash',           'label' => 'Gemini 2.5 Flash',       'group' => 'Google', 'tier' => 2],
            ['id' => 'google/gemini-2.5-flash-lite',      'label' => 'Gemini 2.5 Flash Lite',  'group' => 'Google', 'tier' => 1],
            ['id' => 'deepseek/deepseek-chat-v3.1',       'label' => 'DeepSeek V3.1',          'group' => 'DeepSeek', 'tier' => 1],
            ['id' => 'deepseek/deepseek-r1',              'label' => 'DeepSeek R1',            'group' => 'DeepSeek', 'tier' => 2],
            ['id' => 'qwen/qwen3-235b-a22b',              'label' => 'Qwen3 235B',             'group' => 'Qwen', 'tier' => 2],
            ['id' => 'qwen/qwen-2.5-72b-instruct',        'label' => 'Qwen 2.5 72B',           'group' => 'Qwen', 'tier' => 1],
            ['id' => 'meta-llama/llama-3.3-70b-instruct', 'label' => 'Llama 3.3 70B',          'group' => 'Meta', 'tier' => 1],
            ['id' => 'mistralai/mistral-large',           'label' => 'Mistral Large',          'group' => 'Mistral', 'tier' => 2],
            ['id' => 'x-ai/grok-4',                       'label' => 'Grok 4',                 'group' => 'xAI', 'tier' => 3],
        ],
    ];

    public const PROVIDER_LABELS = ['yandex' => 'Yandex Foundation Models', 'openrouter' => 'OpenRouter'];

    /** Live OpenRouter catalog, refreshed by a button and cached here. */
    private const OR_CACHE_KEY = 'openrouter_models';

    /** What the folder really serves, filled by verifyYandexModels(). */
    private const YX_CACHE_KEY = 'yandex_models';

    /** Точный список моделей каталога: Models API, как советует документация. */
    private const YX_MODELS_URL = 'https://llm.api.cloud.yandex.net/foundationModels/v1/models';

    /** Слаг, на который откатываемся, когда выбранного у провайдера нет. */
    private const YX_FALLBACK = 'yandexgpt';

    // Yandex serves its models through two different endpoints: the YandexGPT
    // family answers on the Foundation Models URL, most open models only on the
    // OpenAI-compatible one. Which slug needs which is learned, not guessed.
    private const YX_URL_FM     = 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion';
    private const YX_URL_OPENAI = 'https://llm.api.cloud.yandex.net/v1/chat/completions';

    private static array $cfg = [];
    private static array $providers = [];
    /** One-shot model override: ['provider' => …, 'model' => …] — see useModel(). */
    private static ?array $override = null;
    /** Last raw HTTP exchange per provider, for the diagnostics card. */
    private static array $lastHttp = [];
    /** Who answered the last call: provider, slug and route — for the journal. */
    private static array $lastCall = [];
    /** How the provider ended the last answer: length/TRUNCATED means cut off. */
    private static string $lastFinish = '';

    // Init with config array
    public static function init(array $cfg): void {
        self::$cfg = $cfg;
        self::$providers = array_values(array_filter(
            array_map('trim', explode(',', (string)($cfg['LLM_PROVIDER_PRIORITY'] ?? 'openrouter')))
        ));
    }

    /**
     * Answer the next calls with this exact model instead of the fallback chain.
     * The manager picks it in the reply window; the choice lives for one request
     * only and never becomes a stored setting.
     */
    public static function useModel(?string $provider, ?string $model): void {
        $provider = trim((string)$provider);
        $model    = trim((string)$model);
        self::$override = ($provider !== '' && $model !== '' && isset(self::CATALOG[$provider]))
            ? ['provider' => $provider, 'model' => $model]
            : null;
    }

    /** «provider:slug» as the reply window sends it. */
    public static function useModelSpec(?string $spec): void {
        $spec = trim((string)$spec);
        if ($spec === '') { self::$override = null; return; }
        [$provider, $model] = array_pad(explode(':', $spec, 2), 2, '');
        self::useModel($provider, $model);
    }

    public static function currentModel(): array {
        if (self::$override) return self::$override;
        $provider = self::$providers[0] ?? 'openrouter';
        return ['provider' => $provider, 'model' => self::modelOf($provider)];
    }

    private static function modelOf(string $provider): string {
        if (self::$override && self::$override['provider'] === $provider) return self::$override['model'];
        return (string)(self::$cfg[$provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL'] ?? '');
    }

    /** Providers in their fallback order, with model and key status for the panel. */
    public static function status(): array {
        $out = [];
        foreach (array_keys(self::CATALOG) as $provider) {
            $key = $provider === 'yandex' ? 'YANDEX_API_KEY' : 'OPENROUTER_API_KEY';
            $modelKey = $provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL';
            $pos = array_search($provider, self::$providers, true);
            $out[] = [
                'provider' => $provider,
                'label'    => self::PROVIDER_LABELS[$provider] ?? $provider,
                'enabled'  => $pos !== false,
                'order'    => $pos === false ? null : $pos + 1,
                'model'    => (string)(self::$cfg[$modelKey] ?? ''),
                'models'   => self::catalog($provider),
                'key_set'  => (string)(self::$cfg[$key] ?? '') !== '',
                'ready'    => self::ready($provider),
            ];
        }
        return $out;
    }

    /**
     * Model list for the picker: the built-in catalog plus whatever the last
     * «Обновить каталог OpenRouter» downloaded. No network call happens here —
     * the cached list is read from settings on every request, like NeuroPro does.
     */
    public static function catalog(string $provider): array {
        $rows = self::CATALOG[$provider] ?? [];
        if ($provider === 'yandex') {
            $cache   = self::yandexCache();
            $checked = (array)($cache['checked'] ?? []);
            $routes  = (array)($cache['routes'] ?? []);
            foreach ($rows as &$row) {
                $state = (string)($checked[$row['id']] ?? '');
                $row['state'] = $state !== '' ? $state : 'unknown';
                $row['route'] = ((string)($routes[$row['id']] ?? '')) === 'openai' ? 'openai' : 'fm';
                if ($state === 'missing') $row['label'] .= ' — нет в этом облаке';
                elseif ($row['route'] === 'openai') $row['label'] .= ' — по OpenAI-совместимому API';
            }
            unset($row);
            // Модели, которые каталог облака перечислил, а список кандидатов не знает.
            $seen = array_column($rows, 'id');
            foreach ((array)($cache['models'] ?? []) as $m) {
                if (!is_array($m) || empty($m['id']) || in_array($m['id'], $seen, true)) continue;
                $rows[] = [
                    'id'    => (string)$m['id'],
                    'label' => (string)($m['label'] ?? $m['id']),
                    'group' => (string)($m['group'] ?? 'Yandex · каталог облака'),
                    'state' => 'ok',
                    'route' => ((string)($routes[$m['id']] ?? '')) === 'openai' ? 'openai' : 'fm',
                ];
            }
            return $rows;
        }
        if ($provider !== 'openrouter') return $rows;

        $seen = array_column($rows, 'id');
        foreach (self::cachedOpenRouterModels() as $m) {
            if (in_array($m['id'], $seen, true)) continue;
            $rows[] = $m;
        }
        return $rows;
    }

    /** What the last catalog refresh stored: rows + when it happened. */
    public static function openRouterCache(): array {
        $raw = Db::val("SELECT value FROM settings WHERE key=?", [self::OR_CACHE_KEY]);
        $data = $raw ? json_decode((string)$raw, true) : null;
        return is_array($data) ? $data + ['models' => [], 'synced_at' => null] : ['models' => [], 'synced_at' => null];
    }

    private static function cachedOpenRouterModels(): array {
        $rows = [];
        foreach ((array)(self::openRouterCache()['models'] ?? []) as $m) {
            if (!is_array($m) || empty($m['id'])) continue;
            $rows[] = [
                'id'    => (string)$m['id'],
                'label' => (string)($m['label'] ?? $m['id']),
                'group' => (string)($m['group'] ?? 'OpenRouter'),
                'price' => isset($m['price']) ? (float)$m['price'] : null,
            ];
        }
        return $rows;
    }

    /**
     * Download the real OpenRouter catalog and store it. Free models get their
     * own group, so the operator can pick one without reading price tables.
     */
    public static function refreshOpenRouterModels(): array {
        [$code, $body] = self::httpGet(self::openRouterBase() . '/models', self::openRouterHeaders(), 'openrouter');
        if ($code >= 400 || $body === '') {
            throw new LLMException(self::explain('openrouter', $code, $body));
        }
        $data = json_decode($body, true);
        $list = $data['data'] ?? $data['models'] ?? (is_array($data) ? $data : []);
        if (!is_array($list)) throw new LLMException('OpenRouter вернул неожиданный формат каталога');

        $rows = [];
        foreach ($list as $m) {
            $id = is_array($m) ? (string)($m['id'] ?? '') : (string)$m;
            if (!preg_match('~^[\w.\-]+/[\w.\-:]+$~', $id)) continue;
            $name  = is_array($m) ? trim((string)($m['name'] ?? '')) : '';
            $price = is_array($m) ? (float)($m['pricing']['prompt'] ?? 0) + (float)($m['pricing']['completion'] ?? 0) : 0.0;
            $free  = $price <= 0 || str_ends_with($id, ':free');
            $rows[] = [
                'id'    => $id,
                'label' => ($name !== '' ? $name : $id) . ($free ? ' — бесплатно' : ''),
                'group' => $free ? 'OpenRouter · бесплатные' : 'OpenRouter · ' . (explode('/', $id)[0]),
                // Цена за токен, как её называет сам OpenRouter: по ней «палец
                // вниз» и находит модель дороже нынешней (модуль 038)
                'price' => $price,
            ];
        }
        if (!$rows) throw new LLMException('В ответе OpenRouter не нашлось ни одной модели');

        usort($rows, fn($a, $b) => [$a['group'], $a['label']] <=> [$b['group'], $b['label']]);
        $payload = ['models' => $rows, 'synced_at' => date('Y-m-d H:i:s')];
        Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [self::OR_CACHE_KEY, json_encode($payload, JSON_UNESCAPED_UNICODE)]);
        return $payload;
    }

    public static function forgetOpenRouterModels(): void {
        Db::q("DELETE FROM settings WHERE key=?", [self::OR_CACHE_KEY]);
    }

    /**
     * Модели ДОРОЖЕ нынешней — что предложить, когда качество не устроило
     * (модуль 038).
     *
     * «Возьмите модель получше» без списка — это предложение читать прайсы
     * двух провайдеров. Порядок считается по настоящей цене там, где она есть
     * (обновлённый каталог OpenRouter), и по `tier` там, где её нет. Модели
     * провайдера без ключа в список не попадают: предлагать то, чем нельзя
     * воспользоваться, — это тупик, а не выбор.
     *
     * @param string $spec «provider:slug»; пусто — нынешняя модель цепочки
     * @return array<int,array{provider:string,model:string,label:string,spec:string,price:?float,tier:int}>
     */
    public static function pricier(string $spec = '', int $limit = 8): array {
        [$provider, $model] = array_pad(explode(':', trim($spec), 2), 2, '');
        if ($provider === '' || $model === '') {
            $cur = self::currentModel();
            [$provider, $model] = [(string)$cur['provider'], (string)$cur['model']];
        }

        $rank = fn(array $row): array => [$row['price'] ?? null, (int)($row['tier'] ?? 2)];
        $now  = [null, 2];
        $rows = [];
        foreach (array_keys(self::CATALOG) as $p) {
            if (!self::ready($p)) continue;
            foreach (self::catalog($p) as $m) {
                $row = [
                    'provider' => $p,
                    'model'    => (string)$m['id'],
                    'label'    => (string)($m['label'] ?? $m['id']),
                    'group'    => (string)($m['group'] ?? ''),
                    'spec'     => $p . ':' . $m['id'],
                    'price'    => isset($m['price']) ? (float)$m['price'] : null,
                    'tier'     => (int)($m['tier'] ?? 2),
                    'state'    => (string)($m['state'] ?? ''),
                ];
                if ($p === $provider && $row['model'] === $model) $now = $rank($row);
                if ($row['state'] === 'missing') continue;   // этого слага в облаке нет
                $rows[] = $row;
            }
        }

        $dearer = array_values(array_filter($rows, function (array $row) use ($rank, $now, $provider, $model) {
            if ($row['provider'] === $provider && $row['model'] === $model) return false;
            [$price, $tier] = $rank($row);
            // Цены сравнимы только между собой; где одной из них нет — решает ступень
            if ($price !== null && $now[0] !== null) return $price > $now[0];
            return $tier > $now[1];
        }));

        usort($dearer, function (array $a, array $b) use ($rank) {
            [$ap, $at] = $rank($a);
            [$bp, $bt] = $rank($b);
            return [$at, $ap ?? 0, $a['label']] <=> [$bt, $bp ?? 0, $b['label']];
        });
        return array_slice($dearer, 0, max(1, $limit));
    }

    // ---- Каталог Yandex: слаг проверяется у провайдера, а не берётся на веру ----
    //
    // Так же это решено в CGM-diet (`spec/models.md`): каталог там приходит от
    // провайдера в `free_catalog`, а `model_selection.is_known()` не отдаёт
    // модель слоту, который её не перечисляет, — выбор, которого у провайдера
    // нет, до запроса не доходит. Здесь список отдаёт сам Yandex — Models API,
    // — а если тот не ответил, роль каталога играет проба: ответ на короткий
    // запрос по каждому слагу.

    /**
     * Что каталог уже выяснил:
     * {checked: {slug: ok|missing}, models, routes: {slug: openai}, synced_at}.
     */
    public static function yandexCache(): array {
        $raw  = Db::val("SELECT value FROM settings WHERE key=?", [self::YX_CACHE_KEY]);
        $data = $raw ? json_decode((string)$raw, true) : null;
        $empty = ['checked' => [], 'models' => [], 'routes' => [], 'synced_at' => null];
        return is_array($data) ? $data + $empty : $empty;
    }

    /**
     * Which endpoint this slug answers on: `fm` (Foundation Models) or `openai`
     * (the OpenAI-compatible URL). Unknown means «not tried yet» — the FM route
     * is the one to try first, and a 400 about gRPC settles the question.
     */
    public static function yandexRoute(string $model): string {
        $routes = (array)(self::yandexCache()['routes'] ?? []);
        return ((string)($routes[trim($model, " /")] ?? '')) === 'openai' ? 'openai' : 'fm';
    }

    /** Remember the endpoint a slug answers on, so the next call goes there at once. */
    private static function markYandexRoute(string $model, string $route): void {
        $cache = self::yandexCache();
        $cache['routes'][trim($model, " /")] = $route;
        $cache['synced_at'] = date('Y-m-d H:i:s');
        self::saveYandexCache($cache);
    }

    /**
     * «Model is not available via gRPC API. Please use HTTP OpenAI API instead»
     * — the model exists, it is simply served by the other endpoint.
     */
    private static function isOpenAiOnlyModel(string $message): bool {
        return str_contains($message, 'HTTP 400')
            && (stripos($message, 'via gRPC') !== false || stripos($message, 'OpenAI API') !== false);
    }

    private static function saveYandexCache(array $data): void {
        Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
              [self::YX_CACHE_KEY, json_encode($data, JSON_UNESCAPED_UNICODE)]);
    }

    public static function forgetYandexModels(): void {
        Db::q("DELETE FROM settings WHERE key=?", [self::YX_CACHE_KEY]);
    }

    /**
     * Прогнать каталог Yandex: сперва спросить сам каталог, потом — пробой.
     *
     * Кнопка в «Нейросетях». Точный список моделей, доступных именно в этом
     * каталоге, отдаёт Models API (`GET /foundationModels/v1/models`) — его и
     * спрашиваем: что там перечислено, то `ok`, чего нет — `missing`, а
     * модели, которых нет в нашем списке кандидатов, попадают в `models` и
     * дальше в выпадающий список, чтобы выбрать можно было и то, о чём код не
     * знал. Если Models API не ответил (старое облако, прокси, 404), работает
     * прежний способ: по одному короткому запросу на слаг.
     */
    public static function verifyYandexModels(): array {
        if (!self::ready('yandex')) throw new LLMException('Yandex: не задан ключ или Folder ID');

        try {
            $listed = self::fetchYandexCatalog();
        } catch (LLMException $e) {
            Logger::warning('llm', 'Models API не ответил — проверяем слаги по одному: ' . $e->getMessage());
            return self::probeYandexModels() + ['source' => 'probe'];
        }

        $known = array_column(self::CATALOG['yandex'], 'id');
        $checked = [];
        foreach ($known as $slug) {
            $checked[$slug] = in_array($slug, $listed, true) ? 'ok' : 'missing';
        }
        $extra = [];
        foreach ($listed as $slug) {
            $checked[$slug] = 'ok';
            if (!in_array($slug, $known, true)) {
                $extra[] = ['id' => $slug, 'label' => $slug, 'group' => 'Yandex · каталог облака'];
            }
        }
        $ok      = array_values(array_keys(array_filter($checked, static fn($s) => $s === 'ok')));
        $missing = array_values(array_keys(array_filter($checked, static fn($s) => $s === 'missing')));

        // Список моделей — не то же, что адрес запроса: какой слаг на каком
        // из двух адресов отвечает, выяснено раньше и переживает обновление.
        $routes  = (array)(self::yandexCache()['routes'] ?? []);
        $payload = ['checked' => $checked, 'models' => $extra, 'routes' => $routes,
                    'synced_at' => date('Y-m-d H:i:s')];
        self::saveYandexCache($payload);

        $openai = array_values(array_intersect($ok, array_keys(array_filter($routes, fn($r) => $r === 'openai'))));
        Logger::info('llm', 'Каталог Yandex получен: моделей ' . count($listed) . ', из списка нет ' . count($missing),
                     ['ok' => $ok, 'missing' => $missing, 'extra' => array_column($extra, 'id'), 'openai' => $openai]);
        return $payload + ['ok' => $ok, 'missing' => $missing, 'unclear' => [],
                           'openai' => $openai, 'source' => 'models_api'];
    }

    /**
     * Список моделей каталога — так, как его отдаёт провайдер. Ответ читается
     * терпимо: `models` / `data` / `items`, строка-слаг или объект с
     * `modelUri` / `uri` / `id` / `name`; `gpt://<каталог>/<слаг>/latest`
     * приводится к слагу, чужой каталог и не-`gpt://` адреса (`art://`)
     * пропускаются.
     */
    public static function fetchYandexCatalog(): array {
        $key    = (string)(self::$cfg['YANDEX_API_KEY'] ?? '');
        $folder = (string)(self::$cfg['YANDEX_FOLDER_ID'] ?? '');
        if ($key === '' || $folder === '') throw new LLMException('Yandex: не задан ключ или Folder ID');

        [$code, $body, $err] = self::httpGet(
            self::YX_MODELS_URL . '?folderId=' . rawurlencode($folder),
            ['Accept: application/json', 'Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder],
            'yandex'
        );
        if ($code >= 400 || $body === '') throw new LLMException(self::explain('yandex', $code, $body, $err));

        $slugs = self::yandexSlugsFrom(json_decode($body, true), $folder);
        if (!$slugs) throw new LLMException('В ответе Models API не нашлось ни одной модели');
        return $slugs;
    }

    /** Разобранный ответ Models API → слаги моделей этого каталога. */
    public static function yandexSlugsFrom($data, string $folder): array {
        if (!is_array($data)) return [];
        $list = [];
        foreach (['models', 'data', 'items'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $list = $data[$k]; break; }
        }
        $slugs = [];
        foreach ($list as $m) {
            $raw = '';
            if (is_string($m)) {
                $raw = $m;
            } elseif (is_array($m)) {
                foreach (['modelUri', 'uri', 'id', 'name'] as $k) {
                    if (isset($m[$k]) && is_string($m[$k])) { $raw = $m[$k]; break; }
                }
            }
            $raw = trim($raw);
            if ($raw === '') continue;
            if (str_starts_with($raw, 'gpt://')) {
                $parts = explode('/', substr($raw, 6));
                if (count($parts) < 2) continue;
                if ($parts[0] !== $folder && $parts[0] !== '') continue;   // чужой каталог
                $raw = $parts[1];
            }
            $slug = (string)preg_replace('~/(latest|rc|deprecated)$~', '', $raw);
            if (!preg_match('~^[A-Za-z0-9][A-Za-z0-9._-]*$~', $slug)) continue;   // art://, emb://, мусор
            if (!in_array($slug, $slugs, true)) $slugs[] = $slug;
        }
        return $slugs;
    }

    /**
     * Запасной способ: по одному короткому запросу на слаг. Отвечает — `ok`,
     * 404 «unknown model» — `missing`, всё остальное (нет ключа, прокси,
     * лимит) слаг не судит: это не про модель, и в кэш такой ответ не
     * пишется, иначе одна сетевая неудача вычеркнула бы весь каталог.
     */
    private static function probeYandexModels(): array {
        $cache = self::yandexCache();
        $checked = $cache['checked'] ?? [];
        $ok = $missing = $unclear = [];
        foreach (self::CATALOG['yandex'] as $row) {
            $slug = (string)$row['id'];
            try {
                self::callYandex('Отвечай одним словом.', 'Скажи «готово».', 0, false, $slug, false);
                $checked[$slug] = 'ok';
                $ok[] = $slug;
            } catch (LLMException $e) {
                // «Only on the OpenAI-compatible route» judges the endpoint, not
                // the slug: the model is there, and callYandex has just recorded
                // where it answers — so the probe scores it `ok`.
                if (self::isOpenAiOnlyModel($e->getMessage())) {
                    $checked[$slug] = 'ok';
                    $ok[] = $slug;
                } elseif (self::isUnknownModel($e->getMessage())) {
                    $checked[$slug] = 'missing';
                    $missing[] = $slug;
                } else {
                    $unclear[] = $slug . ': ' . mb_substr($e->getMessage(), 0, 120);
                }
            }
        }
        // Маршруты, которые проба только что выяснила, уже лежат в кэше —
        // перечитываем его, а не пишем `checked` поверх них
        $fresh   = self::yandexCache();
        $routes  = (array)($fresh['routes'] ?? []);
        $payload = ['checked' => $checked, 'models' => (array)($fresh['models'] ?? []),
                    'routes' => $routes, 'synced_at' => date('Y-m-d H:i:s')];
        self::saveYandexCache($payload);

        $openai = array_values(array_intersect($ok, array_keys(array_filter($routes, fn($r) => $r === 'openai'))));
        Logger::info('llm', 'Каталог Yandex проверен пробой: доступно ' . count($ok) . ', нет ' . count($missing),
                     ['ok' => $ok, 'missing' => $missing, 'unclear' => $unclear, 'openai' => $openai]);
        return $payload + ['ok' => $ok, 'missing' => $missing, 'unclear' => $unclear, 'openai' => $openai];
    }

    /** «404 unknown model» — единственная ошибка, которая судит именно слаг. */
    private static function isUnknownModel(string $message): bool {
        return str_contains($message, 'HTTP 404')
            && (str_contains($message, 'unknown model') || str_contains($message, 'model not found'));
    }

    /**
     * Знаем ли мы, что этот слаг у провайдера есть. Непроверенный — не «нет»:
     * проба могла ни разу не запускаться, и запрещать из-за этого работу
     * значило бы сломать то, что работало.
     */
    public static function isKnown(string $provider, string $model): bool {
        if ($provider !== 'yandex') return true;
        $checked = self::yandexCache()['checked'] ?? [];
        return ($checked[trim($model, " /")] ?? '') !== 'missing';
    }

    /** Слаг, который уходит в запрос: вычеркнутый пробой заменяется на рабочий. */
    private static function yandexSlug(): string {
        $model = trim((string)self::modelOf('yandex'), " /") ?: self::YX_FALLBACK;
        if (self::isKnown('yandex', $model)) return $model;
        Logger::warning('llm', "Модель Yandex «{$model}» помечена как отсутствующая — берём «"
                              . self::YX_FALLBACK . '»', ['model' => $model]);
        return self::YX_FALLBACK;
    }

    /** Запомнить, что слага у провайдера нет: в списке он станет зачёркнутым. */
    private static function markYandexMissing(string $slug): void {
        $cache = self::yandexCache();
        $cache['checked'][trim($slug, " /")] = 'missing';
        $cache['synced_at'] = date('Y-m-d H:i:s');
        self::saveYandexCache($cache);
    }

    public static function ready(string $provider): bool {
        return match ($provider) {
            'yandex'     => (string)(self::$cfg['YANDEX_API_KEY'] ?? '') !== '' && (string)(self::$cfg['YANDEX_FOLDER_ID'] ?? '') !== '',
            'openrouter' => (string)(self::$cfg['OPENROUTER_API_KEY'] ?? '') !== '',
            default      => false,
        };
    }

    /** One short round-trip — the «проверить нейросеть» button in the panel. */
    public static function test(string $provider): array {
        $started = microtime(true);
        $answer = match ($provider) {
            'openrouter' => self::callOpenRouter('Отвечай одним словом.', 'Скажи «готово».', 0, false),
            'yandex'     => self::callYandex('Отвечай одним словом.', 'Скажи «готово».', 0, false),
            default      => throw new LLMException("Неизвестный провайдер: $provider"),
        };
        return [
            'provider' => $provider,
            'model'    => $provider === 'yandex' ? self::yandexSlug() : self::modelOf($provider),
            'answer'   => mb_substr(trim($answer), 0, 200),
            'ms'       => (int)round((microtime(true) - $started) * 1000),
            'route'    => self::routeLabel($provider),
        ];
    }

    /**
     * Where the request actually lands. A key can be perfectly valid and still
     * get a 403 from something on the way (hosting WAF, national filtering) —
     * this probe separates «ключ не тот» from «запрос не доехал».
     */
    public static function diagnose(string $provider): array {
        $url = $provider === 'yandex'
            ? 'https://llm.api.cloud.yandex.net/foundationModels/v1/completion'
            : self::openRouterBase() . '/models';
        $headers = $provider === 'yandex'
            ? ['Authorization: Api-Key ' . (string)(self::$cfg['YANDEX_API_KEY'] ?? '')]
            : self::openRouterHeaders();

        $started = microtime(true);
        [$code, $body, $err] = self::httpGet($url, $headers, $provider, true);
        $intercepted = self::looksIntercepted($code, $body);

        return [
            'provider'    => $provider,
            'url'         => $url,
            'route'       => self::routeLabel($provider),
            'http_code'   => $code,
            'curl_error'  => $err,
            'body_head'   => mb_substr(trim($body), 0, 300),
            'intercepted' => $intercepted,
            'ms'          => (int)round((microtime(true) - $started) * 1000),
            'hint'        => self::explain($provider, $code, $body, $err),
        ];
    }

    /** «напрямую» / «через прокси …» — printed next to every probe result. */
    public static function routeLabel(string $provider): string {
        $p = self::proxyFor($provider);
        return $p === null ? 'напрямую' : 'через прокси ' . preg_replace('~^\w+://~', '', $p['proxy']);
    }

    /**
     * Proxy address + credentials for one provider's requests, or null when
     * this provider talks to its API directly (item 6 of the mobile/UX pass).
     * Both providers share one proxy address and one set of credentials
     * (LLM_PROXY / LLM_PROXY_AUTH) — only whether it is actually used is
     * per-provider: OpenRouter is typically blocked from a Russian IP, so its
     * toggle defaults on; Yandex Cloud is usually reachable directly from a
     * Russian host, so its toggle defaults off.
     */
    public static function proxyFor(string $provider): ?array {
        $toggleKey = $provider === 'yandex' ? 'LLM_PROXY_YANDEX' : 'LLM_PROXY_OPENROUTER';
        $default = $provider === 'yandex' ? 0 : 1;
        $enabled = (int)(self::$cfg[$toggleKey] ?? $default) === 1;
        if (!$enabled) return null;
        $proxy = trim((string)(self::$cfg['LLM_PROXY'] ?? ''));
        if ($proxy === '') return null;
        return ['proxy' => $proxy, 'auth' => trim((string)(self::$cfg['LLM_PROXY_AUTH'] ?? ''))];
    }

    // Text completion — returns plain string
    public static function chatText(string $system, string $user, float $temp = 0.3): string {
        $raw = self::call($system, $user, $temp, false);
        // Strip <think>...</think> tags
        $raw = preg_replace('/<think>.*?<\/think>/s', '', $raw);
        // Strip markdown fences
        $raw = preg_replace('/^```\w*\n|```$/m', '', $raw);
        return trim($raw);
    }

    /**
     * JSON completion — returns parsed array.
     *
     * Three attempts with the same prompt are three identical answers: a model
     * that wrote a phrase before the brace, or hit the length limit, does it
     * again. So every next attempt KNOWS how the previous one ended — what could
     * not be parsed, and whether the answer was cut off.
     */
    public static function chatJson(string $system, string $user, float $temp = 0.1): array {
        $maxRetries = 3;
        $raw = '';
        $why = 'пустой ответ';
        $hint = '';
        $truncated = false;
        for ($i = 0; $i < $maxRetries; $i++) {
            $raw = self::call($system . $hint, $user, $temp, true);
            $data = self::decodeJson($raw);
            if (is_array($data)) return $data;
            // json_last_error() is reset by any json_encode() further down (logging,
            // for one), so the reason has to be captured right here
            $why = json_last_error() === JSON_ERROR_NONE ? 'ответ не является объектом JSON' : json_last_error_msg();
            $truncated = self::lastTruncated();
            if ($truncated) $why .= ' (ответ оборвался по пределу длины)';
            $hint = self::jsonRetryHint($why, $truncated);
            // Retry with lower temp
            $temp = 0.05;
        }
        $call = self::lastCall();
        Logger::error('llm', 'Модель вернула не-JSON после ' . $maxRetries . ' попыток: ' . $why
            . ($truncated ? '. Предел длины ответа — настройка LLM_MAX_TOKENS' : ''), [
            'provider'  => $call['provider'] ?? '',
            'model'     => $call['model'] ?? '',
            'route'     => $call['route'] ?? '',
            'truncated' => $truncated,
            // The head shows a phrase before the object, the tail shows a cut-off
            // mid-word: one end alone does not always tell what happened
            'head'      => mb_substr($raw, 0, 400),
            'tail'      => mb_substr($raw, -400),
        ]);
        throw new LLMException("Failed to parse JSON after $maxRetries attempts: $why");
    }

    /** What the next attempt adds to the system prompt. */
    private static function jsonRetryHint(string $why, bool $truncated): string {
        $hint = "\n\n===== ПОВТОРНАЯ ПОПЫТКА =====\n"
              . "Предыдущий ответ не удалось разобрать: {$why}.\n"
              . 'Верни ТОЛЬКО один объект JSON: без пояснений, без ограды ```, без текста до и после. '
              . 'Переводы строк внутри значений экранируй как \\n, кавычки внутри строк — как \\".';
        if ($truncated) {
            $hint .= "\nОтвет обязан уместиться целиком: пиши короче — только обязательные поля, "
                   . 'без длинных описаний и повторов исходного текста.';
        }
        return $hint;
    }

    /**
     * Ответ модели как массив — или null, если это и правда не JSON (модуль 034).
     *
     * Модель отдаёт объект, но вокруг него бывает всё что угодно: ограда
     * ```json, рассуждение в <think>, фраза «вот результат:» перед скобкой,
     * «умные» кавычки вместо прямых, запятая перед закрывающей скобкой. Хуже
     * того — ответ обрывается на полуслове, когда упирается в `maxTokens`: это
     * и был «Failed to parse JSON after 3 attempts: Syntax error» в журнале,
     * причём три попытки подряд обрывались одинаково.
     *
     * Разбор идёт от самого дешёвого к самому терпеливому и останавливается на
     * первом, который дал массив. Ничего не придумывается: закрываются только
     * те скобки, которые модель ОТКРЫЛА.
     */
    public static function decodeJson(string $raw): ?array {
        // Broken bytes go first: any /u pattern returns null on them, and the
        // whole answer used to be declared «not JSON» (module 043)
        $raw = self::onlyUtf8($raw);
        // Ограда и рассуждения снимаются всегда
        $raw = (string)preg_replace('/<think>.*?<\/think>/su', '', trim($raw));
        $raw = (string)preg_replace('/```[a-z]*\s*/iu', '', $raw);
        $raw = trim(str_replace('```', '', $raw));
        if ($raw === '') return null;

        $tries = [$raw];
        // Объект или массив внутри фразы — берём самый широкий кусок
        if (preg_match('/[{\[].*[}\]]/su', $raw, $m)) $tries[] = $m[0];
        // Обрыв на полуслове: дописываем скобки, которые модель открыла
        $repaired = self::repairJson($raw);
        if ($repaired !== null) $tries[] = $repaired;

        foreach ($tries as $candidate) {
            $data = json_decode($candidate, true);
            if (is_array($data)) return $data;
            // Хвостовая запятая и «ёлочки» вместо прямых кавычек
            $clean = preg_replace('/,\s*([}\]])/u', '$1', $candidate);
            $clean = strtr((string)$clean, ['“' => '"', '”' => '"', '„' => '"', '«' => '"', '»' => '"']);
            $data = json_decode((string)$clean, true);
            if (is_array($data)) return $data;
            // A raw line break inside a value is the commonest way a model breaks
            // its own JSON: escape what it left unescaped
            $data = json_decode(self::escapeControls((string)$clean), true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    /**
     * Bytes that are not valid UTF-8, dropped.
     *
     * A single broken byte makes every `/u` pattern return null, and the answer
     * was declared «not JSON» without ever being looked at.
     */
    private static function onlyUtf8(string $raw): string {
        if ($raw === '' || mb_check_encoding($raw, 'UTF-8')) return $raw;
        $out = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        return $out === false ? (string)mb_convert_encoding($raw, 'UTF-8', 'UTF-8') : $out;
    }

    /**
     * Raw control characters inside string literals — escaped, not dropped.
     *
     * JSON forbids a bare line break inside a string; a model writing a
     * multi-line description puts one there anyway. Outside strings control
     * characters are ordinary whitespace and stay as they are.
     */
    private static function escapeControls(string $json): string {
        $out = '';
        $inString = false;
        $len = strlen($json);
        for ($i = 0; $i < $len; $i++) {
            $c = $json[$i];
            if ($inString && $c === '\\' && $i + 1 < $len) { $out .= $c . $json[$i + 1]; $i++; continue; }
            if ($c === '"') { $inString = !$inString; $out .= $c; continue; }
            if ($inString && ord($c) < 0x20) {
                $out .= match ($c) {
                    "\n" => '\\n', "\r" => '\\r', "\t" => '\\t',
                    default => sprintf('\\u%04x', ord($c)),
                };
                continue;
            }
            $out .= $c;
        }
        return $out;
    }

    /**
     * Оборванный ответ — закрыть то, что открыто, и обрубить хвост.
     *
     * Ровно то, что делает человек, глядя на обрезанный JSON: отрезать
     * недописанное значение и закрыть скобки в обратном порядке. Отрезаем по
     * последнему месту, где значение БЫЛО ДОПИСАНО, — ключ без значения и
     * оборванная строка уходят вместе с хвостом. Ничего не придумывается:
     * закрываются только те скобки, которые модель открыла.
     */
    private static function repairJson(string $raw): ?string {
        $start = strcspn($raw, '{[');
        $len = strlen($raw);
        if ($start >= $len) return null;
        $raw = substr($raw, $start);
        $len = strlen($raw);

        $stack = [];
        $best = null;                       // [позиция, копия стека] после целого значения
        $i = 0;
        while ($i < $len) {
            $c = $raw[$i];
            if ($c === '{' || $c === '[') { $stack[] = $c === '{' ? '}' : ']'; $i++; continue; }
            if ($c === '}' || $c === ']') { array_pop($stack); $best = [++$i, $stack]; continue; }
            if ($c === ',' || $c === ':' || ctype_space($c)) { $i++; continue; }
            if ($c === '"') {
                $j = self::endOfString($raw, $i);
                if ($j === null) break;     // строка оборвалась — дальше только мусор
                $i = $j;
                // Строка перед двоеточием — ключ, а не значение: обрезать по ней нельзя
                if (!self::nextIsColon($raw, $i)) $best = [$i, $stack];
                continue;
            }
            // Число, true, false, null
            $j = $i;
            while ($j < $len && !str_contains(",:{}[] \t\r\n", $raw[$j])) $j++;
            if ($j === $i) { $i++; continue; }
            $i = $j;
            $best = [$i, $stack];
        }

        if ($best === null || !$best[1]) return null;   // чинить нечего
        return substr($raw, 0, $best[0]) . implode('', array_reverse($best[1]));
    }

    /** Индекс сразу за закрывающей кавычкой строки; null — строка не закрыта. */
    private static function endOfString(string $raw, int $at): ?int {
        $len = strlen($raw);
        for ($i = $at + 1; $i < $len; $i++) {
            if ($raw[$i] === '\\') { $i++; continue; }
            if ($raw[$i] === '"') return $i + 1;
        }
        return null;
    }

    /** Следом за этим местом стоит двоеточие — значит, слева был ключ. */
    private static function nextIsColon(string $raw, int $from): bool {
        $len = strlen($raw);
        for ($i = $from; $i < $len; $i++) {
            if (ctype_space($raw[$i])) continue;
            return $raw[$i] === ':';
        }
        return false;
    }

    // Core call with fallback chain
    private static function call(string $system, string $user, float $temp, bool $jsonMode): string {
        // A model picked by hand answers alone: falling back to another one would
        // quietly ignore the choice the manager just made
        $chain = self::$override ? [self::$override['provider']] : self::$providers;

        $lastErr = null;
        foreach ($chain as $provider) {
            // Провайдер, который только что отваливался по таймауту, не
            // спрашивается снова ближайшие минуты (модуль 040). Тридцать
            // секунд ожидания на КАЖДОМ письме — это почта, которая не
            // забирается, и кнопка, которая не возвращается.
            if (self::isCoolingDown($provider)) {
                $lastErr = new LLMException(self::coolDownMessage($provider));
                continue;
            }
            try {
                return match ($provider) {
                    'openrouter' => self::callOpenRouter($system, $user, $temp, $jsonMode),
                    'yandex' => self::callYandex($system, $user, $temp, $jsonMode),
                    default => throw new LLMException("Unknown provider: $provider"),
                };
            } catch (LLMException $e) {
                $lastErr = $e;
                Logger::warning('llm', "Провайдер $provider не ответил: " . $e->getMessage(), ['provider' => $provider]);
                self::noteFailure($provider, $e->getMessage());
                // Continue to next provider
            }
        }
        $message = 'All LLM providers failed: ' . ($lastErr ? $lastErr->getMessage() : 'none configured');
        Logger::error('llm', $message, ['providers' => $chain]);
        throw new LLMException($message);
    }

    /**
     * ==== Провайдер на паузе (модуль 040) ====
     *
     * Сеть до провайдера не доходит — фильтр по дороге, отвалившийся прокси,
     * просто таймаут. Каждое следующее письмо честно ждало свои тридцать
     * секунд, и разбор почты превращался в минуты ожидания на ровном месте.
     *
     * Подряд идущие СЕТЕВЫЕ неудачи ставят провайдера на паузу: ключ, квота и
     * отказ модели сюда не попадают — это ответы, а не молчание, и повторять
     * их незачем. Пауза короткая: провайдер должен вернуться сам, без правки
     * настроек.
     */
    private const COOLDOWN_AFTER = 2;
    private const COOLDOWN_SEC   = 180;

    private static array $failures = [];
    private static array $pausedUntil = [];

    private static function isCoolingDown(string $provider): bool {
        $until = self::$pausedUntil[$provider] ?? 0;
        if ($until <= time()) return false;
        return true;
    }

    private static function coolDownMessage(string $provider): string {
        $left = max(1, (int)ceil(((self::$pausedUntil[$provider] ?? 0) - time()) / 60));
        return "$provider: не отвечал подряд, пропущен на ~$left мин. — сеть до него не доходит или модель не успевает ответить за таймаут";
    }

    private static function noteFailure(string $provider, string $message): void {
        // Отказ с ответом — это ответ: ключ, квота, неизвестная модель
        if (!preg_match('/(timed out|timeout|could not resolve|connection|cURL|сеть|не доходит)/iu', $message)) {
            self::$failures[$provider] = 0;
            return;
        }
        $n = (self::$failures[$provider] ?? 0) + 1;
        self::$failures[$provider] = $n;
        if ($n < self::COOLDOWN_AFTER) return;
        self::$pausedUntil[$provider] = time() + self::COOLDOWN_SEC;
        Logger::warning('llm', "Провайдер $provider пропускается " . (self::COOLDOWN_SEC / 60)
            . " мин.: подряд не отвечает", ['provider' => $provider]);
    }

    /** Base address of the OpenRouter API — a mirror can be put here instead. */
    private static function openRouterBase(): string {
        $base = trim((string)(self::$cfg['OPENROUTER_BASE_URL'] ?? ''));
        return rtrim($base !== '' ? $base : 'https://openrouter.ai/api/v1', '/');
    }

    private static function openRouterHeaders(): array {
        return [
            'Authorization: Bearer ' . (string)(self::$cfg['OPENROUTER_API_KEY'] ?? ''),
            'HTTP-Referer: ' . (string)(self::$cfg['APP_URL'] ?? ''),
            'X-Title: Atlant Armour KP',
        ];
    }

    // OpenRouter API call
    private static function callOpenRouter(string $system, string $user, float $temp, bool $jsonMode): string {
        $key = self::$cfg['OPENROUTER_API_KEY'] ?? '';
        if (!$key) throw new LLMException('OPENROUTER_API_KEY not set');
        $model = self::modelOf('openrouter') ?: 'google/gemini-2.5-flash';

        self::$lastCall = ['provider' => 'openrouter', 'model' => $model, 'route' => 'openai'];
        $body = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => self::noThink($model, $system)],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temp,
        ];
        if ($jsonMode) {
            $body['response_format'] = ['type' => 'json_object'];
        }

        return self::httpPost(self::openRouterBase() . '/chat/completions', $body, self::openRouterHeaders(), 'openrouter');
    }

    /**
     * Model address for Yandex: gpt://<folder>/<slug>/<version> — the same shape
     * careerhack builds. The version segment is what the provider resolves the
     * model by, so it is always there: «latest» unless the operator typed his own
     * («yandexgpt/rc», «yandexgpt/deprecated»).
     */
    private static function yandexModelUri(string $folder, string $model): string {
        $model = trim($model, " /") ?: 'yandexgpt';
        if (!preg_match('~/(latest|rc|deprecated)$~', $model)) $model .= '/latest';
        return "gpt://$folder/$model";
    }

    /**
     * Yandex Foundation Models API call.
     *
     * $slug — проба каталога спрашивает конкретную модель; обычный вызов берёт
     * настроенную. $retry — один откат на рабочий слаг, когда провайдер
     * ответил «unknown model»: выбор менеджера при этом не теряется молча —
     * слаг вычёркивается из каталога, и в журнале остаётся запись.
     */
    private static function callYandex(string $system, string $user, float $temp, bool $jsonMode,
                                       ?string $slug = null, bool $retry = true): string {
        $key = self::$cfg['YANDEX_API_KEY'] ?? '';
        $folder = self::$cfg['YANDEX_FOLDER_ID'] ?? '';
        if (!$key || !$folder) throw new LLMException('YANDEX_API_KEY or YANDEX_FOLDER_ID not set');
        $model = $slug !== null ? $slug : self::yandexSlug();
        $uri = self::yandexModelUri($folder, $model);

        // Open models answer only on the OpenAI-compatible endpoint. Where that
        // is already known, the request goes there straight away.
        if (self::yandexRoute($model) === 'openai') {
            return self::callYandexOpenAI($system, $user, $temp, $jsonMode, $model, $uri, $key, $folder);
        }
        self::$lastCall = ['provider' => 'yandex', 'model' => $model, 'route' => 'fm'];

        $body = [
            'modelUri' => $uri,
            'completionOptions' => [
                'stream' => false,
                'temperature' => $temp,
                'maxTokens' => self::maxTokens(),
            ],
            'messages' => [
                ['role' => 'system', 'text' => self::noThink($model, $system)],
                ['role' => 'user', 'text' => $user],
            ],
        ];
        if ($jsonMode) {
            $body['completionOptions']['responseFormat'] = ['type' => 'json_object'];
        }

        try {
            return self::httpPost(self::YX_URL_FM, $body,
                                  ['Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder], 'yandex');
        } catch (LLMException $e) {
            // «Use HTTP OpenAI API instead» — the model is there, the endpoint is
            // the wrong one. Remember the route and repeat at once: the manager's
            // choice stands and the answer still arrives.
            if (self::isOpenAiOnlyModel($e->getMessage())) {
                self::markYandexRoute($model, 'openai');
                Logger::info('llm', "Модель Yandex «{$model}» отвечает по OpenAI-совместимому API — "
                                  . 'повторяем запрос по нему', ['model' => $model]);
                return self::callYandexOpenAI($system, $user, $temp, $jsonMode, $model, $uri, $key, $folder);
            }
            if (!self::isUnknownModel($e->getMessage())) throw $e;
            self::markYandexMissing($model);
            $fallback = trim(self::YX_FALLBACK, " /");
            if (!$retry || $model === $fallback) throw $e;
            Logger::warning('llm', "Yandex не знает модель «{$model}» — отвечаем моделью «{$fallback}»",
                            ['model' => $model, 'uri' => $uri]);
            return self::callYandex($system, $user, $temp, $jsonMode, $fallback, false);
        }
    }

    /**
     * The same folder and the same key, the OpenAI-shaped request: this is how
     * Yandex serves the open models (Llama, Qwen, Gemma, DeepSeek). The model is
     * still addressed by its `gpt://folder/slug/version` URI.
     */
    private static function callYandexOpenAI(string $system, string $user, float $temp, bool $jsonMode,
                                             string $model, string $uri, string $key, string $folder): string {
        self::$lastCall = ['provider' => 'yandex', 'model' => $model, 'route' => 'openai'];
        $body = [
            'model' => $uri,
            'messages' => [
                ['role' => 'system', 'content' => self::noThink($model, $system)],
                ['role' => 'user', 'content' => $user],
            ],
            'temperature' => $temp,
            'max_tokens' => self::maxTokens(),
            'stream' => false,
        ];
        if ($jsonMode) $body['response_format'] = ['type' => 'json_object'];

        return self::httpPost(self::YX_URL_OPENAI, $body,
                              ['Authorization: Api-Key ' . $key, 'x-folder-id: ' . $folder],
                              'yandex', 'openai');
    }

    /**
     * Answer length limit. It bounds the Yandex request, where the API demands a
     * number — OpenRouter keeps the provider's own default, so nothing that fits
     * today starts being cut off.
     */
    private static function maxTokens(): int {
        return max(256, min(32000, (int)(self::$cfg['LLM_MAX_TOKENS'] ?? 4096) ?: 4096));
    }

    /**
     * Outbound proxy for every model request. On a Russian shared host the API
     * itself is reachable only through one — without it the request is answered
     * by the filter on the way, not by the provider.
     */
    private static function applyTransport(\CurlHandle $ch, string $provider): void {
        $p = self::proxyFor($provider);
        if ($p === null) return;

        curl_setopt($ch, CURLOPT_PROXY, $p['proxy']);
        curl_setopt($ch, CURLOPT_HTTPPROXYTUNNEL, true);
        // socks5h:// in the URL makes cURL resolve the host on the proxy side —
        // the point of the exercise when local DNS is the thing being poisoned
        if (str_starts_with($p['proxy'], 'socks5h://') || str_starts_with($p['proxy'], 'socks5://')) {
            curl_setopt($ch, CURLOPT_PROXYTYPE, CURLPROXY_SOCKS5_HOSTNAME);
        }
        if ($p['auth'] !== '') curl_setopt($ch, CURLOPT_PROXYUSERPWD, $p['auth']);
    }

    private static function timeout(): int {
        return max(5, (int)(self::$cfg['LLM_TIMEOUT_SEC'] ?? 90));
    }

    /** Соединение — отдельно и коротко: мёртвый маршрут не ждёт полторы минуты (модуль 051). */
    private static function connectTimeout(): int {
        return min(15, self::timeout());
    }

    /**
     * Qwen3 по умолчанию сначала рассуждает — десятки секунд до первого байта,
     * а рассуждение всё равно вырезается из ответа (модуль 051).
     */
    public static function noThink(string $model, string $system): string {
        if (stripos($model, 'qwen3') === false || str_contains($system, '/no_think')) return $system;
        return rtrim($system) . "\n\n/no_think";
    }

    /** GET used by the catalog refresh and the connectivity probe. */
    private static function httpGet(string $url, array $headers, string $provider, bool $short = false): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $short ? min(15, self::timeout()) : self::timeout(),
            CURLOPT_CONNECTTIMEOUT => self::connectTimeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'AtlantArmourKP/1.0',
        ]);
        self::applyTransport($ch, $provider);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);

        $body = $resp === false ? '' : (string)$resp;
        self::$lastHttp[$provider] = ['code' => $code, 'body' => mb_substr($body, 0, 500), 'error' => $err];
        return [$code, $body, $err];
    }

    /**
     * HTTP POST with cURL, parse provider response.
     *
     * $shape — how to read the answer: `openai` (choices[0].message.content) or
     * the provider's own. Yandex speaks both, depending on the endpoint.
     */
    private static function httpPost(string $url, array $body, array $headers, string $provider,
                                     string $shape = ''): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::timeout(),
            CURLOPT_CONNECTTIMEOUT => self::connectTimeout(),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => 'AtlantArmourKP/1.0',
        ]);
        self::applyTransport($ch, $provider);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        // Запрос ушёл, а ответа нет — модель думает дольше таймаута; это не фильтр
        if ($resp === false && curl_errno($ch) === CURLE_OPERATION_TIMEDOUT
            && (float)curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) > 0) {
            self::$lastHttp[$provider] = ['code' => 0, 'body' => '', 'error' => $err];
            self::$lastFinish = '';
            throw new LLMException(self::slowAnswer($provider, $err));
        }

        self::$lastHttp[$provider] = ['code' => (int)$code, 'body' => mb_substr((string)$resp, 0, 500), 'error' => $err];
        self::$lastFinish = '';

        if ($resp === false) throw new LLMException(self::explain($provider, 0, '', $err));
        if ($code >= 400) throw new LLMException(self::explain($provider, (int)$code, (string)$resp));

        $data = json_decode($resp, true);
        if (!$data) throw new LLMException("$provider: invalid JSON response");

        // Extract text from the answer shape this endpoint speaks
        if ($shape === 'openai' || $provider === 'openrouter') {
            self::$lastFinish = (string)($data['choices'][0]['finish_reason'] ?? '');
            return $data['choices'][0]['message']['content']
                ?? throw new LLMException("$provider: no content in response");
        }
        if ($provider === 'yandex') {
            self::$lastFinish = (string)($data['result']['alternatives'][0]['status'] ?? '');
            return $data['result']['alternatives'][0]['message']['text']
                ?? throw new LLMException('yandex: no text in response');
        }
        throw new LLMException("Unknown provider: $provider");
    }

    /**
     * The provider says it stopped because the answer hit the length limit —
     * `finish_reason: length` on the OpenAI shape, `…TRUNCATED_FINAL` on the
     * Foundation Models one. A truncated answer is never valid JSON, and
     * asking again the same way produces the same stump.
     */
    public static function lastTruncated(): bool {
        $f = self::$lastFinish;
        return $f === 'length' || $f === 'max_tokens' || stripos($f, 'TRUNCATED') !== false;
    }

    /** Who answered the last call — for the journal and the diagnostics card. */
    public static function lastCall(): array {
        return self::$lastCall ?: self::currentModel();
    }

    /**
     * A refusal written by something that is not the provider: no model API
     * answers «Access denied by security policy», and none of them speak HTML.
     */
    private static function looksIntercepted(int $code, string $body): bool {
        if ($code === 0) return true;                       // never connected at all
        if ($code < 400) return false;
        $head = mb_strtolower(mb_substr(trim($body), 0, 400));
        if ($head === '') return true;
        if (str_starts_with($head, '<')) return true;        // an HTML block page
        foreach (['security policy', 'access denied', 'заблокирован', 'ограничен доступ',
                  'blocked', 'forbidden by', 'proxy'] as $needle) {
            if (str_contains($head, $needle)) return true;
        }
        return false;
    }

    /** Таймаут после отправленного запроса: соединение было, ответа не дождались. */
    public static function slowAnswer(string $provider, string $curlError): string {
        return "cURL error ($provider): $curlError. Запрос дошёл до провайдера, но модель не успела ответить за "
             . self::timeout() . ' с — это не фильтр и не ключ. Увеличьте «Таймаут запроса» '
             . '(«Настройки → Нейросети», LLM_TIMEOUT_SEC) или выберите модель быстрее.';
    }

    /** Error text a non-developer can act on. */
    private static function explain(string $provider, int $code, string $body, string $curlError = ''): string {
        $detail = mb_substr(trim($body), 0, 400);
        $head = "$provider returned HTTP $code" . ($detail !== '' ? ": $detail" : '');
        if ($curlError !== '') $head = "cURL error ($provider): $curlError";

        $route = self::routeLabel($provider);
        if (self::looksIntercepted($code, $body)) {
            $toggle = $provider === 'yandex' ? 'LLM_PROXY_YANDEX' : 'LLM_PROXY_OPENROUTER';
            return $head . '. Так отвечает не сам провайдер, а фильтр на пути запроса '
                 . "($route): ключ здесь ни при чём. Помогает прокси вне фильтрации — "
                 . "«Настройки → Нейросети → Доступ к API» (адрес в LLM_PROXY, включить для $provider — $toggle), "
                 . 'либо свой зеркальный адрес API в OPENROUTER_BASE_URL.';
        }
        if ($code === 401) return $head . '. Ключ неверный, отозван или скопирован не целиком.';
        if ($code === 402) return $head . '. На счёте провайдера нет средств.';
        if ($code === 404) {
            $where = $provider === 'yandex'
                ? ' Кнопка «Проверить каталог Yandex» в «Настройках → Нейросети» спрашивает '
                  . 'у облака его список моделей и вычёркивает те, которых в нём нет.'
                : ' Проверьте слаг в каталоге.';
            return $head . '. Такой модели у провайдера нет.' . $where;
        }
        if ($code === 429) return $head . '. Провайдер ограничил частоту запросов — попробуйте позже или смените модель.';
        return $head;
    }
}

class LLMException extends RuntimeException {}
