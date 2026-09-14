<?php
/**
 * API: Settings — legal entity, email rules, signature/logo upload.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

$action = $_GET['action'] ?? '';
require_once ROOT . '/lib/moysklad.php';

/** The КП and mail-processing defaults an admin edits by hand. */
function generalSettings(): array {
    $keys = ['default_conditions_text','default_execution_days','default_validity_days','default_vat_rate',
             'ocr_enabled','ocr_max_pages','attachment_max_mb','unanswered_critical_h','invoice_email_subject',
             'default_warranty_text','kp_images_note','kp_upsell_intro','kp_upsell_note',
             'addon_category','kp_show_images','kp_show_upsell','kp_max_images_per_item'];
    $out = [];
    foreach ($keys as $k) {
        $out[$k] = Db::val("SELECT value FROM settings WHERE key=?", [$k]) ?: '';
    }
    return $out;
}

switch ($action) {
    case 'legal_entity':
        $manager = requireAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $entity = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1");
            jsonData($entity ?: []);
        }
        // PUT — update
        $input = getInput();
        $entity = Db::one("SELECT id FROM legal_entities WHERE is_active=1 LIMIT 1");
        if (!$entity) jsonError('No legal entity configured');

        $fields = [];
        foreach (['entity_type','full_name','short_name','inn','ogrnip','ogrn','city','address','signatory_name','bank_details','phone','email'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if ($fields) Db::update('legal_entities', $fields, 'id=?', [$entity['id']]);
        jsonOk();

    case 'upload_signature':
        $manager = requireAuth();
        if (empty($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['png','jpg','jpeg'])) jsonError('PNG or JPG only');

        $dest = ROOT . '/storage/signatures/signature.' . $ext;
        move_uploaded_file($file['tmp_name'], $dest);

        Db::q("UPDATE legal_entities SET signature_path=? WHERE is_active=1", [$dest]);
        jsonOk(['path' => $dest]);

    // Старый адрес загрузки логотипа КП. Теперь все три знака — КП, приложение
    // и значок вкладки — живут в `Branding` и грузятся через api/branding.php;
    // здесь оставлена совместимость для сохранённых ссылок (модуль 021).
    case 'upload_logo':
        requireAuth();
        require_once ROOT . '/lib/branding.php';
        if (empty($_FILES['file'])) jsonError('No file uploaded');
        try {
            $res = Branding::store('kp', $_FILES['file']);
        } catch (Throwable $e) {
            jsonError($e->getMessage());
        }
        jsonOk(['path' => $res['path']]);

    case 'email_rules':
        $manager = requireAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            $rules = Db::one("SELECT * FROM email_rules ORDER BY id DESC LIMIT 1");
            jsonData($rules ?: ['content' => '']);
        }
        // PUT — update
        $input = getInput();
        $content = $input['content'] ?? '';
        if (!$content) jsonError('Content required');
        Db::insert('email_rules', ['content' => $content, 'updated_by' => $manager['id']]);
        jsonOk();

    case 'general':
        $manager = requireAuth();
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            jsonData(generalSettings());
        }
        // PUT
        $input = getInput();
        foreach ($input as $k => $v) {
            Db::q("INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value", [$k, $v]);
        }
        jsonOk();

    /**
     * «Оформление КП»: the typed-in defaults together with the requisites and the
     * VAT МойСклад actually holds, so the tab shows what will be PRINTED and not
     * only what somebody once typed (module 013).
     */
    case 'kp':
        requireAuth();
        require_once ROOT . '/lib/requisites.php';
        jsonData(['general' => generalSettings()] + Requisites::kpDefaults());

    // Pull the организация from МойСклад on demand — the same sync a КП runs
    case 'kp_requisites_sync':
        requireAdmin();
        require_once ROOT . '/lib/requisites.php';
        MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));
        try {
            Requisites::syncOrganization();
        } catch (Throwable $e) {
            Logger::exception('moysklad', $e, ['stage' => 'kp_requisites_sync']);
            jsonError('МойСклад: ' . $e->getMessage());
        }
        jsonOk(Requisites::kpDefaults());

    /**
     * Model picker data for anyone who may write a reply — not just admins.
     * No keys travel here: only slugs, labels and the group they belong to.
     */
    case 'llm_models':
        requireAuth();
        $out = [];
        foreach (array_keys(LLM::CATALOG) as $provider) {
            $out[] = [
                'provider' => $provider,
                'label'    => LLM::PROVIDER_LABELS[$provider] ?? $provider,
                'ready'    => LLM::ready($provider),
                'models'   => LLM::catalog($provider),
            ];
        }
        jsonData([
            'providers' => $out,
            'current'   => LLM::currentModel(),
            'picker'    => (string)Settings::get('LLM_MODEL_PICKER', '1') === '1',
        ]);

    // MoySklad integration status and webhooks (FR-029, FR-039)
    case 'moysklad':
        requireAuth();
        require_once ROOT . '/lib/sync.php';
        MsSync::init();

        $perms = [];
        $error = null;
        try {
            $perms = MoySklad::checkPermissions();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        $secret = (string)Db::val("SELECT value FROM settings WHERE key='moysklad_webhook_secret'");
        $appUrl = rtrim($cfg['APP_URL'] ?? '', '/');
        $hooks = [];
        if (!empty($perms['webhooks'])) {
            try {
                // Includes hooks left from an older secret, flagged as `current` => false
                $hooks = MsSync::ourWebhooks();
            } catch (Throwable $e) {
                $error = $error ?? $e->getMessage();
            }
        }
        $hooksCurrent = array_values(array_filter($hooks, fn($w) => !empty($w['current'])));

        jsonData([
            'permissions'  => $perms,
            // NOT 'error': the JS api() helper treats a top-level `error` as a failed request
            'ms_error'     => $error,
            'diag'         => MoySklad::getDiagnostics(),
            // Which layer the token came from: «из интерфейса» is editable right
            // here, «из config.php» lives on the server
            'token_source' => Settings::source('MOYSKLAD_TOKEN'),
            'webhook_url'  => $appUrl . '/api/moysklad_hook.php?secret=' . $secret,
            'webhooks'     => $hooksCurrent,
            'webhooks_all' => $hooks,
            'app_url_ok'   => str_starts_with($appUrl, 'https://'),
            'last_webhook' => Db::one("SELECT entity_type, action, result, created_at FROM webhook_log ORDER BY id DESC LIMIT 1"),
        ]);

    case 'webhooks_register':
        requireAuth();
        require_once ROOT . '/lib/sync.php';
        $res = MsSync::ensureWebhooks();
        if (empty($res['ok'])) jsonError($res['error'] ?? 'Не удалось зарегистрировать вебхуки', 400);
        jsonOk($res);

    case 'webhooks_remove':
        requireAuth();
        require_once ROOT . '/lib/sync.php';
        jsonOk(['removed' => MsSync::removeWebhooks()]);

    default:
        jsonError('Unknown action', 400);
}
