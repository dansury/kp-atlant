<?php
/**
 * API: admin console — settings, mailboxes, managers, prompts, logs, connection tests.
 * Everything here is admin-only (module 004).
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/push.php';
require_once ROOT . '/lib/managers.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/mailsync.php';

$admin  = requireAdmin();
$action = $_GET['action'] ?? '';
$input  = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        // ---------- Settings ----------

        case 'settings':
            if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                jsonData([
                    'groups'      => Settings::GROUPS,
                    'items'       => Settings::describe(),
                    'config_file' => file_exists(ROOT . '/config.php'),
                    'encrypted'   => Crypt::available(),
                    // Автообновление кода: что показать в карточке «Автообновление кода»
                    'autopull'    => autopullState(),
                ]);
            }
            // PUT — write overrides. '' on a secret keeps the stored value.
            $saved = 0;
            foreach ((array)($input['values'] ?? []) as $key => $value) {
                if (!isset(Settings::SPEC[$key])) continue;
                if (Settings::isSecret($key) && (string)$value === '') continue;
                Settings::set($key, is_bool($value) ? (int)$value : (string)$value);
                $saved++;
            }
            foreach ((array)($input['reset'] ?? []) as $key) {
                if (isset(Settings::SPEC[$key])) { Settings::forget($key); $saved++; }
            }
            Logger::info('settings', "Настройки изменены ($saved)", ['manager_id' => $admin['id']]);
            LLM::init(Settings::effective());
            jsonOk(['saved' => $saved]);

        // Разовая проверка обновления — независимо от галочки (модуль 014)
        case 'autopull_check': {
            $report = AutoPull::check(autopullOpts(), true);
            Logger::info('deploy', 'Проверка обновления: ' . ($report['ok'] ? $report['note'] : $report['error']),
                ['manager_id' => $admin['id'], 'head' => $report['head']]);
            jsonOk([
                'report' => [
                    'ok'       => $report['ok'],
                    'note'     => $report['note'],
                    'error'    => $report['error'],
                    'head'     => substr($report['head'], 0, 7),
                    'deployed' => $report['deployed_now'],
                ],
                'state' => autopullState(),
            ]);
        }

        // ---------- Connection tests ----------

        case 'test_moysklad':
            require_once ROOT . '/lib/sync.php';
            MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));
            $perms = MoySklad::checkPermissions();
            jsonOk([
                'permissions' => $perms,
                'diag'        => MoySklad::getDiagnostics(),
                'ok_any'      => (bool)array_filter($perms),
            ]);

        // Реквизиты, НДС и договор из МойСклад в КП (module 013)
        case 'requisites_sync':
            require_once ROOT . '/lib/requisites.php';
            MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));
            $legal = Requisites::syncOrganization();
            jsonOk([
                'legal_entity' => [
                    'full_name'     => $legal['full_name'] ?? '',
                    'inn'           => $legal['inn'] ?? '',
                    'kpp'           => $legal['kpp'] ?? '',
                    'legal_address' => $legal['legal_address'] ?? '',
                    'bank_details'  => $legal['bank_details'] ?? '',
                    'pays_vat'      => (int)($legal['pays_vat'] ?? 1) === 1,
                    'synced_at'     => $legal['synced_at'] ?? '',
                ],
            ]);

        // Ссылки на товары на сайте: что получится и на чём (module 013)
        case 'bitrix_diagnose':
            require_once ROOT . '/lib/bitrix.php';
            jsonOk(['diag' => Bitrix::diagnose()]);

        case 'bitrix_refresh_urls':
            require_once ROOT . '/lib/bitrix.php';
            jsonOk(['resolved' => Bitrix::refreshUrls(max(1, (int)($input['limit'] ?? 50)))]);

        // Весь каталог за один разговор — когда на сайте стоит модуль
        // atlant.kpsync (module 017)
        case 'bitrix_sync_catalog':
            require_once ROOT . '/lib/bitrix.php';
            jsonOk(['sync' => Bitrix::syncFromSite()]);

        case 'test_llm':
            $provider = (string)($input['provider'] ?? $_GET['provider'] ?? '');
            try {
                jsonOk(['result' => LLM::test($provider)]);
            } catch (Throwable $e) {
                Logger::exception('llm', $e, ['provider' => $provider]);
                jsonError('Нейросеть не ответила: ' . $e->getMessage());
            }

        // Why a provider refuses to answer: the key, or something on the way.
        // On a Russian host the API is often answered by a filter, not by the
        // provider — and then no key can help, only a proxy.
        case 'llm_diagnose':
            $provider = (string)($input['provider'] ?? $_GET['provider'] ?? 'openrouter');
            if (!isset(LLM::CATALOG[$provider])) jsonError('Неизвестный провайдер');
            jsonOk(['result' => LLM::diagnose($provider)]);

        // Live OpenRouter catalog: after this the picker lists every model the
        // account can actually call, free ones in their own group
        case 'openrouter_models_refresh':
            try {
                $payload = LLM::refreshOpenRouterModels();
            } catch (Throwable $e) {
                Logger::exception('llm', $e);
                jsonError('Каталог OpenRouter не обновился: ' . $e->getMessage());
            }
            Logger::info('llm', 'Каталог OpenRouter обновлён: ' . count($payload['models']) . ' моделей');
            jsonOk(['count' => count($payload['models']), 'synced_at' => $payload['synced_at']]);

        case 'openrouter_models_forget':
            LLM::forgetOpenRouterModels();
            jsonOk();

        // Re-read letters archived before the header decoder knew about charsets
        case 'mail_repair_encoding':
            jsonOk(['result' => MailArchive::repairEncoding()]);

        // Rebuild the «одна тема — одна переписка» grouping over the whole archive
        case 'mail_rethread':
            require_once ROOT . '/lib/mail_threads.php';
            jsonOk(['threaded' => MailThreads::backfill(true)]);

        /**
         * Where a mailbox's «Отправленные» really is. The copy of an outgoing
         * letter used to vanish because the folder in the settings did not exist
         * on the server, and nothing said so — this prints the server's own list
         * and the name the code will use.
         */
        case 'mailbox_sent_check': {
            $box = mailboxFromInput($input);
            if (!EmailReader::available()) jsonError('На сервере нет расширения PHP imap');
            try {
                $reader = new EmailReader(Mailboxes::cfg($box));
                $reader->connect($box['imap_folder_in'] ?: 'INBOX');
                $folders = $reader->folders();
                $resolved = $reader->findSentFolder((string)($box['imap_folder_sent'] ?? ''));
                $reader->close();
            } catch (Throwable $e) {
                Logger::exception('mail', $e, ['mailbox_id' => $box['id'] ?? null]);
                jsonError('IMAP: ' . $e->getMessage());
            }
            $configured = (string)($box['imap_folder_sent'] ?? '');
            if ($resolved && $resolved !== $configured && !empty($box['id'])) {
                Db::update('mailboxes', ['imap_folder_sent' => $resolved], 'id=?', [$box['id']]);
            }
            jsonOk(['result' => [
                'folders'    => $folders,
                'configured' => $configured,
                'resolved'   => $resolved,
                'fixed'      => $resolved && $resolved !== $configured,
                'recent'     => Db::all(
                    "SELECT id, subject, to_emails, date_at, sent_state, folder FROM mail_messages
                     WHERE direction='out' AND mailbox_id=? ORDER BY id DESC LIMIT 10",
                    [(int)($box['id'] ?? 0)]
                ),
            ]]);
        }

        case 'test_imap':
            $box = mailboxFromInput($input);
            try {
                jsonOk(['result' => MailSync::testImap($box)]);
            } catch (Throwable $e) {
                Logger::exception('mail', $e, ['mailbox_id' => $box['id'] ?? null]);
                jsonError('IMAP: ' . $e->getMessage());
            }

        case 'test_smtp':
            $box = mailboxFromInput($input);
            $cfgBox = Mailboxes::cfg($box);
            try {
                $sender = new EmailSender($cfgBox);
                $info = $sender->testConnection();
                $to = trim((string)($input['send_to'] ?? ''));
                if ($to !== '') {
                    $sender->sendNotification($to, 'Проверка почты Atlant Armour КП',
                        "Это тестовое письмо из панели администратора.\nЯщик: " . ($box['name'] ?? '') . "\nВремя: " . date('d.m.Y H:i'));
                    $info['sent_to'] = $to;
                }
                jsonOk(['result' => $info]);
            } catch (Throwable $e) {
                Logger::exception('mail', $e, ['mailbox_id' => $box['id'] ?? null]);
                jsonError('SMTP: ' . $e->getMessage());
            }

        // ---------- Mailboxes ----------

        case 'mailboxes':
            jsonData([
                'items'         => Mailboxes::describe(),
                'blank'         => Mailboxes::blank(),
                'providers'     => MailProviders::describe(),
                'managers'      => Db::all("SELECT id, name FROM managers WHERE COALESCE(is_active,1)=1 ORDER BY name"),
                'imap_available'=> EmailReader::available(),
            ]);

        case 'mailbox_save':
            $id = isset($input['id']) && $input['id'] ? (int)$input['id'] : null;
            jsonOk(['id' => Mailboxes::save($input, $id, (int)$admin['id'])]);

        // Ящик можно просто выключить: настройки и пароли остаются, опрос
        // прекращается, письма по желанию уходят с экрана вместе с ним.
        case 'mailbox_toggle':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указан ящик');
            $letters = (string)($input['letters'] ?? 'keep');
            if (!in_array($letters, ['keep', 'hide', 'delete'], true)) $letters = 'keep';
            jsonOk(['result' => Mailboxes::setActive($id, !empty($input['is_active']), $letters)]);

        // `letters`: keep — письма остаются (и уходят с экрана вместе с ящиком),
        // delete — уходят вместе с ним. Без этого выбора удаление ящика с
        // архивом падало на FOREIGN KEY constraint.
        case 'mailbox_delete':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            if (!$id) jsonError('Не указан ящик');
            $letters = ((string)($input['letters'] ?? 'keep')) === 'delete' ? 'delete' : 'keep';
            jsonOk(['result' => Mailboxes::delete($id, $letters)]);

        case 'mailbox_sync':
            $id = (int)($input['id'] ?? $_GET['id'] ?? 0);
            jsonOk(['report' => MailSync::run($id ?: null)]);

        // ---------- Full archive download ----------

        // One step of «скачать весь архив». The panel calls it until done=true —
        // a shared host would kill a single request that walks 20 000 letters.
        case 'mailbox_backfill':
            $box = Mailboxes::get((int)($input['id'] ?? $_GET['id'] ?? 0));
            if (!$box) jsonError('Ящик не найден', 404);
            if (!empty($input['restart'])) MailSync::backfillReset((int)$box['id']);
            jsonOk(['result' => MailSync::backfill(
                $box,
                isset($input['seconds']) ? (int)$input['seconds'] : null,
                isset($input['batch']) ? (int)$input['batch'] : null
            )]);

        // ---------- Import of an mbox archive (module 021) ----------

        /**
         * What is lying in `storage/mbox`, what has already been imported, and how
         * far the fingerprinting of the old archive has got. A Gmail export is
         * measured in gigabytes: the panel uploads what fits through PHP and the
         * rest is put next to it over FTP — both show up in the same list.
         */
        case 'mbox_files':
            require_once ROOT . '/lib/mbox.php';
            jsonData([
                'files'     => MboxImport::files(),
                'imports'   => MboxImport::all(),
                'dir'       => 'storage/mbox',
                'mailboxes' => array_map(fn($b) => ['id' => (int)$b['id'], 'name' => $b['name'], 'email' => $b['email']],
                                          Mailboxes::all()),
                'dedup'     => [
                    'enabled'   => MailArchive::dedupEnabled(),
                    'content'   => (string)Settings::get('MAIL_DEDUP_CONTENT', 1) === '1',
                    'unhashed'  => (int)Db::val("SELECT COUNT(*) FROM mail_messages WHERE dedup_hash IS NULL"),
                    'total'     => (int)Db::val("SELECT COUNT(*) FROM mail_messages"),
                ],
                'upload_max' => ini_get('upload_max_filesize'),
            ]);

        case 'mbox_upload':
            require_once ROOT . '/lib/mbox.php';
            if (empty($_FILES['file']) || ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                jsonError('Файл не загрузился. Сервер принимает не больше ' . ini_get('upload_max_filesize')
                    . ' — архив крупнее положите в storage/mbox по FTP, он появится в списке сам.');
            }
            jsonOk(['filename' => MboxImport::accept($_FILES['file'])]);

        case 'mbox_start':
            require_once ROOT . '/lib/mbox.php';
            jsonOk(['import' => MboxImport::register((string)($input['filename'] ?? ''), [
                'mailbox_id'       => (int)($input['mailbox_id'] ?? 0),
                'create_companies' => !empty($input['create_companies']),
                'manager_id'       => (int)$admin['id'],
            ])]);

        // One step of the import. The panel calls it until done=true.
        case 'mbox_step':
            require_once ROOT . '/lib/mbox.php';
            jsonOk(['result' => MboxImport::step(
                (int)($input['id'] ?? 0),
                isset($input['seconds']) ? (int)$input['seconds'] : null,
                isset($input['limit']) ? (int)$input['limit'] : null
            )]);

        case 'mbox_reset':
            require_once ROOT . '/lib/mbox.php';
            MboxImport::reset((int)($input['id'] ?? 0));
            jsonOk(['import' => MboxImport::get((int)($input['id'] ?? 0))]);

        case 'mbox_delete':
            require_once ROOT . '/lib/mbox.php';
            MboxImport::remove((int)($input['id'] ?? 0), !empty($input['with_file']));
            jsonOk();

        // Hash the letters archived before module 021, in steps — until this is
        // done they are deduplicated by Message-ID alone.
        case 'mail_fingerprints':
            jsonOk(['result' => MailArchive::backfillFingerprints(
                (int)($input['limit'] ?? 1000),
                (float)($input['seconds'] ?? 10)
            )]);

        // Archive of a mailbox as an .mbox file — importable into any mail client
        case 'mailbox_export':
            $id  = (int)($_GET['id'] ?? 0);
            $box = $id ? Mailboxes::get($id) : null;
            if ($id && !$box) jsonError('Ящик не найден', 404);
            $name = $box ? preg_replace('/[^A-Za-z0-9._-]+/', '-', (string)($box['email'] ?: $box['name'])) : 'all';
            header('Content-Type: application/mbox; charset=UTF-8');
            header('Content-Disposition: attachment; filename="mail-' . trim($name, '-') . '-' . date('Y-m-d') . '.mbox"');
            header('X-Accel-Buffering: no');
            while (ob_get_level()) ob_end_flush();
            MailArchive::exportMbox($id ?: null);
            Logger::info('mail', 'Архив писем выгружен', ['mailbox_id' => $id ?: null, 'manager_id' => $admin['id']]);
            exit;

        // ---------- Managers ----------

        case 'managers':
            jsonData(['items' => Managers::all()]);

        case 'manager_save':
            $id = isset($input['id']) && $input['id'] ? (int)$input['id'] : null;
            jsonOk(['id' => Managers::save($input, $id, (int)$admin['id'])]);

        case 'manager_delete':
            jsonOk(['result' => Managers::delete((int)($input['id'] ?? $_GET['id'] ?? 0), (int)$admin['id'])]);

        // ---------- Prompts ----------

        case 'prompts':
            jsonData(['items' => Prompts::describe()]);

        case 'prompt_save':
            Prompts::save((string)($input['key'] ?? ''), (string)($input['content'] ?? ''), (int)$admin['id']);
            jsonOk();

        case 'prompt_reset':
            Prompts::reset((string)($input['key'] ?? $_GET['key'] ?? ''), (int)$admin['id']);
            jsonOk();

        case 'prompt_history':
            jsonData(['items' => Prompts::history((string)($_GET['key'] ?? ''))]);

        // ---------- Knowledge base (module 005) ----------

        case 'knowledge':
            jsonData(Knowledge::status());

        // Manual «проверить и подтянуть»; force re-reads every file
        case 'knowledge_sync':
            try {
                jsonOk(['report' => Knowledge::sync(!empty($input['force']))]);
            } catch (Throwable $e) {
                Logger::exception('knowledge', $e, ['manager_id' => $admin['id']]);
                jsonError('База знаний: ' . $e->getMessage());
            }

        // Rebuild the FTS index by hand — after a schema change or a doubtful search
        case 'knowledge_reindex':
            jsonOk(['indexed' => Knowledge::reindex(), 'fts' => Knowledge::ftsAvailable()]);

        // ---- Wiki vectors: the same engine as the catalog (modules 005 + 009) ----

        case 'knowledge_vector_stats':
            require_once ROOT . '/lib/embeddings.php';
            jsonData(Embeddings::knowledgeStats());

        // One resumable step, called in a loop by the panel — a wiki of two
        // hundred sections never needs one long request
        case 'knowledge_vector_index':
            require_once ROOT . '/lib/embeddings.php';
            // Nothing to embed until the sections exist: a wiki synced before
            // the section index appeared would otherwise report «готово»
            if (!(int)Db::val("SELECT COUNT(*) FROM knowledge_sections")) Knowledge::reindex();
            $report = Embeddings::indexKnowledge([
                'budget' => (int)($input['budget'] ?? Settings::get('VECTOR_BUDGET_SEC', 20)),
            ]);
            if ($report['error'] && !$report['indexed']) jsonError($report['error'], 400);
            jsonOk(['report' => $report]);

        case 'knowledge_vector_reset':
            require_once ROOT . '/lib/embeddings.php';
            jsonOk(['removed' => Embeddings::resetKnowledge()]);

        // What would land in the prompt for this text — the admin can see «когда необходимо»
        case 'knowledge_preview':
            $task = (string)($input['task'] ?? $_GET['task'] ?? 'mail_reply');
            if (!isset(Knowledge::TASKS[$task])) jsonError('Неизвестная задача');
            jsonData([
                'task'    => $task,
                'enabled' => Knowledge::taskEnabled($task),
                'items'   => Knowledge::preview((string)($input['query'] ?? $_GET['query'] ?? ''), $task),
            ]);

        // ---------- Logs ----------

        case 'logs':
            jsonData(Logger::query([
                'level'   => $_GET['level'] ?? '',
                'channel' => $_GET['channel'] ?? '',
                'q'       => $_GET['q'] ?? '',
                'limit'   => $_GET['limit'] ?? 100,
                'offset'  => $_GET['offset'] ?? 0,
            ]));

        case 'logs_counts':
            jsonData(Logger::counts());

        case 'logs_clear':
            jsonOk(['deleted' => Logger::clear($input['level'] ?? null)]);

        // ---------- Overview ----------

        case 'overview':
            $boxes = Mailboxes::all();
            jsonData([
                'llm'        => LLM::status(),
                // Where model requests go out and how fresh the OpenRouter list is —
                // per provider now that the proxy toggle is (item 6)
                'llm_route'  => ['openrouter' => LLM::routeLabel('openrouter'), 'yandex' => LLM::routeLabel('yandex')],
                'llm_catalog'=> ['count' => count(LLM::openRouterCache()['models'] ?? []),
                                 'synced_at' => LLM::openRouterCache()['synced_at'] ?? null],
                'log'        => Logger::counts(),
                'mailboxes'  => count($boxes),
                'mail_errors'=> array_values(array_filter(array_map(
                    fn($b) => $b['last_error'] ? ['name' => $b['name'], 'error' => $b['last_error'], 'at' => $b['last_check_at']] : null,
                    $boxes
                ))),
                'managers'   => (int)Db::val("SELECT COUNT(*) FROM managers WHERE COALESCE(is_active,1)=1"),
                'imap'       => EmailReader::available(),
                'config_file'=> file_exists(ROOT . '/config.php'),
                'schema'     => (string)Db::val("SELECT value FROM settings WHERE key='schema_version'"),
                'knowledge'  => array_diff_key(Knowledge::status(), ['docs' => 1, 'tasks' => 1]),
                // What the mailbox actually consists of — the point of module 006
                'triage'     => ['enabled' => Triage::enabled(), 'categories' => Triage::stats(30)],
                'push'       => [
                    'enabled'     => Push::enabled(),
                    'reason'      => Push::unavailableReason(),
                    'subscribers' => (int)Db::val("SELECT COUNT(DISTINCT manager_id) FROM push_subscriptions"),
                    'devices'     => (int)Db::val("SELECT COUNT(*) FROM push_subscriptions"),
                ],
            ]);

        default:
            jsonError('Unknown action', 400);
    }
} catch (InvalidArgumentException $e) {
    jsonError($e->getMessage(), 400);
} catch (Throwable $e) {
    Logger::exception('admin', $e, ['action' => $action]);
    jsonError($e->getMessage(), 500);
}

/** Options for the deploy check: the switch from the panel, the creds from pull-config.php. */
function autopullOpts(): array {
    $cfg = Settings::effective();
    return AutoPull::options($cfg, [
        'root'      => ROOT,
        'state_dir' => dirname((string)($cfg['DB_PATH'] ?? ROOT . '/data/kp.db')),
    ]);
}

/** What the admin card shows: what is tracked and how the last check ended. */
function autopullState(): array {
    $opts   = autopullOpts();
    $status = AutoPull::status($opts);
    $pull   = AutoPull::pullConfig(AutoPull::root($opts));
    return [
        'configured' => $pull !== null,
        'repo'       => $pull['repo'] ?? '',
        'ref'        => $pull === null ? ''
            : ($pull['source'] === 'pr' ? 'PR #' . $pull['pr_number'] : 'ветка ' . $pull['branch']),
        'checked_at' => $status['checked_at'] > 0 ? date('Y-m-d H:i:s', $status['checked_at']) : '',
        'note'       => $status['note'],
        'error'      => $status['error'],
        'head'       => substr($status['head'], 0, 7),
        'deployed'   => substr($status['deployed'], 0, 7),
    ];
}

/** A saved mailbox by id, or the unsaved form values — so «проверить» works before saving. */
function mailboxFromInput(array $input): array {
    $id = isset($input['id']) && $input['id'] ? (int)$input['id'] : 0;
    $box = $id ? Mailboxes::get($id) : null;
    if (!$box) $box = Mailboxes::blank() + ['id' => null];

    foreach (Mailboxes::FIELDS as $f) {
        if (array_key_exists($f, $input)) $box[$f] = $input[$f];
    }
    // The same preset the save applies, so «Проверить IMAP» works on a form
    // where the admin typed only the address and the application password
    $provider = (string)($box['provider'] ?? '');
    if ($provider !== '' && $provider !== 'custom') {
        foreach (MailProviders::apply($provider, (string)($box['email'] ?? '')) as $f => $v) {
            if (trim((string)($box[$f] ?? '')) === '') $box[$f] = $v;
        }
    }
    foreach (['imap_password', 'smtp_password'] as $f) {
        if (($input[$f] ?? '') !== '') $box[$f] = Crypt::encrypt((string)$input[$f]);
    }
    return $box;
}
