<?php
/**
 * API: Proposals — generate, update, preview, confirm, send.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/variants.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/terms.php';
require_once ROOT . '/lib/kp_text.php';
require_once ROOT . '/lib/outbox.php';
require_once ROOT . '/lib/docx.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/markup.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/notifier.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/request_shape.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/kp_terms.php';

/**
 * SC-005 with teeth (module 018).
 *
 * «Ни одно КП не уходит клиенту без подтверждения менеджером» was a click, not
 * a statement about the document: a КП with «Итого: 0,00 руб.» was confirmed and
 * sent exactly like a priced one. Nothing is blocked dead here — the manager is
 * asked once, by position, and his answer is kept on the proposal so
 * «Подтвердить» and «Отправить» do not ask twice for the same document. A price
 * edited afterwards drops the answer and the question comes back.
 */
function requireNoPriceAck(int $proposalId, array $input, array $manager): void {
    $gaps = KpContent::priceGaps($proposalId);
    if (!$gaps['items'] && !$gaps['empty']) return;

    $stored = json_decode((string)(Db::val("SELECT no_price_ack_json FROM proposals WHERE id=?", [$proposalId]) ?: ''), true);
    $ackedIds = is_array($stored) ? array_map('intval', (array)($stored['items'] ?? [])) : [];
    $ackedEmpty = is_array($stored) && !empty($stored['empty']);

    $gapIds = array_map('intval', array_column($gaps['items'], 'id'));
    $openIds = array_values(array_diff($gapIds, $ackedIds));
    // Every position already answered for, and an empty КП answered for as such
    if (!$openIds && (!$gaps['empty'] || $ackedEmpty)) return;

    if (empty($input['no_price_ack'])) {
        $msg = $gaps['empty']
            ? 'В КП нет ни одной позиции — подтвердите, что отправляем его таким.'
            : 'Без цены: ' . count($gaps['items']) . ' поз. Подтвердите, что отправляем КП без цены по ним.';
        jsonError($msg, 409, ['no_price' => [
            'items' => $gaps['items'],
            'total' => $gaps['total'],
            'empty' => $gaps['empty'],
        ]]);
    }

    Db::update('proposals', ['no_price_ack_json' => json_encode([
        'items'      => $gapIds,
        'empty'      => $gaps['empty'],
        'manager_id' => (int)$manager['id'],
        'at'         => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE)], 'id=?', [$proposalId]);

    Logger::warning('kp', 'КП подтверждено без цены по ' . count($gaps['items']) . ' поз.', [
        'proposal_id' => $proposalId, 'manager_id' => (int)$manager['id'],
        'positions'   => array_column($gaps['items'], 'position'),
    ]);
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'get':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT p.*, c.name as counterparty_name, c.contact_email, r.email_from
                              FROM proposals p
                              LEFT JOIN counterparties c ON p.counterparty_id = c.id
                              LEFT JOIN requests r ON p.request_id = r.id
                              WHERE p.id=?", [$id]);
        if (!$proposal) jsonError('Not found', 404);
        $proposal['items'] = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$id]);
        // What each analogue was proved to meet, so the editor shows the same
        // evidence the client will read
        foreach ($proposal['items'] as &$row) {
            $row['alt_matched'] = KpContent::matchedSpecs($row);
            $row['alt_differs'] = KpContent::unmatchedSpecs($row);
        }
        unset($row);
        $proposal['show_match_table_effective'] = KpContent::showMatchTable($proposal);
        // Условия так, как их правят: с {execution_days}/{validity_days} на месте.
        // У КП, собранного до модуля 026, своего блока нет — он складывается из
        // прежних четырёх полей, чтобы документ не изменился задним числом.
        $proposal['terms_text_edit'] = KpTerms::rawForProposal($proposal);
        $proposal['request_shape'] = $proposal['request_id']
            ? RequestShape::of((int)$proposal['request_id']) : RequestShape::TEXT;
        // Which positions are analogues rather than what was asked for — the
        // editor asks the manager to explain exactly those (module 011)
        $swaps = [];
        foreach (KpContent::substitutions($proposal['items']) as $sw) $swaps[$sw['requested']] = true;
        foreach ($proposal['items'] as &$it) {
            $it['is_substitution'] = isset($swaps[trim((string)($it['requested_name'] ?? ''))]);
        }
        unset($it);
        $proposal['addons'] = Db::all("SELECT * FROM proposal_addons WHERE proposal_id=? ORDER BY position", [$id]);
        // What the editor warns about before the manager reaches «Подтвердить»:
        // positions with no money on them, positions the catalog never answered,
        // and a buyer whose name is still an e-mail address (module 018)
        $proposal['price_gaps'] = KpContent::priceGaps($id);
        $proposal['unmatched']  = KpContent::unmatchedRows($id);
        $proposal['buyer']      = Requisites::forProposal($id)['buyer'] ?? null;
        jsonData($proposal);

    case 'generate':
        $manager = requireAuth();
        $requestId = (int)($_GET['request_id'] ?? 0);
        $req = Db::one("SELECT * FROM requests WHERE id=?", [$requestId]);
        if (!$req) jsonError('Request not found', 404);

        // Ensure MoySklad is initialized
        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

        // Refresh product cache for fresh prices (Constitution II). Best-effort:
        // with a dead token the catalog imported from Excel still stands, and a
        // KP built on it beats no KP at all.
        try {
            MoySklad::refreshProductCache();
        } catch (Throwable $e) {
            Logger::warning('catalog', 'Каталог не обновился перед КП: ' . $e->getMessage());
        }
        ProductMatcher::forgetCatalog();

        // The КП is built from «Подходящие позиции» — the table the manager
        // checked on the request card. Nothing there yet: match on the spot.
        $matched = RequestItems::toProposalItems(RequestItems::ensure($requestId, true));
        if (!$matched) {
            $parsed = $req['parsed_json'] ? json_decode($req['parsed_json'], true) : RequestParser::parse($req['raw_text']);
            $matched = ProductMatcher::matchItems($parsed['items'] ?? []);
        }

        // Create proposal
        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1");
        $proposalId = Db::insert('proposals', [
            'request_id' => $requestId,
            'counterparty_id' => $req['counterparty_id'],
            'manager_id' => $manager['id'],
            'vat_rate' => (int)(Db::val("SELECT value FROM settings WHERE key='default_vat_rate'") ?: 5),
            'execution_days' => (int)(Db::val("SELECT value FROM settings WHERE key='default_execution_days'") ?: 30),
            'validity_days' => (int)(Db::val("SELECT value FROM settings WHERE key='default_validity_days'") ?: 14),
            'conditions_text' => Db::val("SELECT value FROM settings WHERE key='default_conditions_text'") ?: '',
            // Условия — одним блоком, и ровно тем, который менеджер правил в
            // прошлый раз: house rule пишется один раз (модуль 026)
            'terms_text' => KpTerms::defaultText(),
            // «Чтобы всё, что мы дописываем, система учитывала»: whatever the
            // manager typed around the table last time is already here, so a
            // house rule is written once instead of retyped on every КП
            'pre_table_text'  => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='pre_table' ORDER BY id DESC LIMIT 1") ?: ''),
            'post_table_text' => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='post_table' ORDER BY id DESC LIMIT 1") ?: ''),
            // Запрос пришёл таблицей — КП открывается таблицей соответствия.
            // Решается по самому письму (module 013), без вопроса менеджеру.
            'show_match_table' => (RequestShape::of($requestId) === RequestShape::TABLE) ? 1 : 0,
        ]);

        // Insert items
        foreach ($matched as $i => $m) {
            $match = $m['match'];
            // «Сколько можем отгрузить» — свободный остаток. Из «Подходящих
            // позиций» он уже свободный (`reserved` там учтён), из прямого
            // подбора приходит сырой остаток и резерв отдельно; оба пути
            // складываются в одну и ту же пару колонок КП.
            $free = $match ? max(0, (int)($match['stock'] ?? 0) - (int)($match['reserved'] ?? 0)) : 0;
            // Товар с модификациями отвечает их суммой: своего остатка у него нет,
            // и КП уходило «под заказ» при полном складе размеров (модуль 026)
            if ($match && !empty($match['moysklad_id'])) {
                $byVariants = Variants::stockOf((string)$match['moysklad_id']);
                if ($byVariants !== null) {
                    $free = $byVariants['free'];
                    $match['stock'] = $free;
                    $match['reserved'] = 0;   // резерв модификаций уже вычтен
                }
            }
            Db::insert('proposal_items', [
                'proposal_id' => $proposalId,
                'position' => $i + 1,
                'product_name' => $match ? $match['name'] : $m['raw_name'],
                // What the client actually wrote. An analogue offered instead of
                // the asked-for brand is only visible against this line, and the
                // КП has to say so out loud (module 011).
                'requested_name' => $m['raw_name'] ?? null,
                'moysklad_product_id' => $match['moysklad_id'] ?? null,
                'unit' => $match['unit'] ?? 'шт.',
                'quantity' => $m['quantity'],
                'price' => $match['price'] ?? 0,
                'stock_available' => $match['stock'] ?? null,
                'stock_reserved' => $match['reserved'] ?? null,
                'match_confidence' => $match['score'] ?? null,
                'match_variants' => !empty($m['variants']) ? json_encode($m['variants'], JSON_UNESCAPED_UNICODE) : null,
                'is_confirmed' => $m['is_confirmed'] ? 1 : 0,
                // Своё примечание менеджера сильнее автоматического «под заказ»,
                // а автоматическое пересчитывается по сегодняшнему остатку, а не
                // переезжает из строки подбора как есть (модуль 026)
                'notes' => Terms::stockNote($m['notes'] ?? null, $free, (bool)$match),
                // An analogue offered because we could not ship what was asked
                // for, and the proof that it fits (module 013)
                // Комментарий по товару и деньги, проставленные на карточке
                // письма, переезжают в КП как есть (модуль 023)
                'comment_text'     => $m['comment_text'] ?? null,
                'discount_percent' => (float)($m['discount_percent'] ?? 0),
                'price_is_manual'  => (int)($m['price_is_manual'] ?? 0),
                'wait_on'          => (int)($m['wait_on'] ?? 0),
                'wait_months'      => $m['wait_months'] ?? null,
                'wait_discount'    => $m['wait_discount'] ?? null,
                'wait_prepay'      => $m['wait_prepay'] ?? null,
                'is_alternative' => !empty($m['is_alternative']) ? 1 : 0,
                'alt_reason'     => $m['alt_specs']['reason'] ?? null,
                'alt_specs_json' => !empty($m['alt_specs'])
                    ? json_encode($m['alt_specs'], JSON_UNESCAPED_UNICODE) : null,
            ]);
        }

        // Generate cover letter
        $tov = Tov::read();
        $corrections = Db::all(
            "SELECT auto_text, manager_text FROM corrections WHERE field='cover_letter' ORDER BY created_at DESC LIMIT 5"
        );
        // How this office has explained an analogue before — the model repeats
        // the manager's own wording instead of inventing a new apology
        $pastSwaps = Db::all(
            "SELECT auto_text, manager_text FROM corrections WHERE field='item_substitution' ORDER BY created_at DESC LIMIT 8"
        );
        $orgName = '';
        if ($req['counterparty_id']) {
            $cp = Db::one("SELECT name FROM counterparties WHERE id=?", [$req['counterparty_id']]);
            $orgName = $cp['name'] ?? '';
        }

        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId]);
        $swaps = KpContent::substitutions($items);
        // The letter names the same products the table does, and says out loud
        // what the catalog never answered (module 018)
        $unmatched = KpContent::unmatchedRows($proposalId);
        $coverLetter = RequestParser::generateCoverLetter($items, $orgName, $tov, $corrections, $swaps,
                                                          $pastSwaps, $unmatched);
        Db::update('proposals', ['cover_letter' => $coverLetter], 'id=?', [$proposalId]);

        // Pull descriptions, specs and photos for the product cards (FR-040, FR-042)
        KpContent::enrichItems($proposalId);

        // Позициям, которых нет на складе, проставляются срок ожидания, скидка
        // за ожидание и предоплата — готовыми, но выключенными: цену они не
        // двигают, пока менеджер их не включит (модуль 023)
        Terms::prepareProposal($proposalId);

        // Pre-fill the upsell table with modules from the addon folder (FR-044)
        KpContent::seedAddons($proposalId);

        // НДС, реквизиты, адреса, банк и договор — из МойСклад и ЗАМОРОЖЕНЫ на
        // этом КП (module 013). Переоткрытый через полгода документ печатается с
        // теми реквизитами, с которыми был подписан, а не с сегодняшними.
        if ((int)Settings::get('REQUISITES_AUTOSYNC', 1) === 1) {
            try {
                Requisites::syncOrganization();
                if ($req['counterparty_id']) Requisites::syncCounterparty((int)$req['counterparty_id']);
            } catch (Throwable $e) {
                // A dead token leaves the last synced copy in charge — a КП is
                // never blocked by МойСклад being unreachable
                Logger::warning('moysklad', 'Реквизиты не обновились перед КП: ' . $e->getMessage());
            }
        }
        Requisites::freeze($proposalId);

        // Generate PDF draft
        PdfGenerator::generate($proposalId);

        // Update request status
        Db::update('requests', ['status' => 'draft_ready', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$requestId]);

        // Return full proposal
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId]);
        jsonData([
            'id' => $proposalId,
            'status' => $proposal['status'],
            'items' => $items,
            'addons' => Db::all("SELECT * FROM proposal_addons WHERE proposal_id=? ORDER BY position", [$proposalId]),
            'cover_letter' => $coverLetter,
            'pdf_preview_url' => "/api/proposals.php?action=preview&id=$proposalId",
        ]);

    case 'update':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$proposal) jsonError('Not found', 404);

        $input = getInput();

        // Update proposal fields
        $fields = [];
        foreach (['pre_table_text', 'post_table_text', 'intro_text', 'conditions_text', 'execution_days', 'validity_days', 'vat_rate', 'show_vat_total',
                  'warranty_text', 'images_note', 'show_images', 'show_upsell', 'upsell_intro', 'upsell_note',
                  'show_match_table', 'match_table_note',
                  // Условия одним блоком и доставка отдельной строкой (модуль 026)
                  'terms_text', 'delivery_on', 'delivery_name', 'delivery_price',
                  // Сколько фото печатать в ЭТОМ КП; пусто — общая настройка
                  'photos_per_item'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if (array_key_exists('cover_letter_final', $input)) {
            $fields['cover_letter_final'] = $input['cover_letter_final'];
        }
        // Последняя правка условий — значение по умолчанию для следующих КП
        if (array_key_exists('terms_text', $fields)) KpTerms::remember((string)$fields['terms_text']);
        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Db::update('proposals', $fields, 'id=?', [$id]);
        }

        // Update items
        if (!empty($input['items'])) {
            // What the money looked like before the edit — a price that moves
            // invalidates the manager's «отправляем без цены» answer (module 018)
            $moneyBefore = Db::all("SELECT id, price, quantity FROM proposal_items WHERE proposal_id=? ORDER BY id", [$id]);
            foreach ($input['items'] as $itemData) {
                $itemId = $itemData['id'] ?? 0;
                $upd = [];
                foreach (['quantity', 'price', 'product_name', 'is_confirmed', 'notes', 'vat_rate', 'moysklad_product_id',
                          'description_text', 'specs_text', 'included_text', 'show_images', 'price_from', 'qty_from',
                          'alt_reason', 'site_url', 'is_excluded',
                          // Позиция «под заказ» и деньги, которые менеджер ставит руками (модуль 023)
                          'comment_text', 'discount_percent', 'price_is_manual',
                          'wait_on', 'wait_months', 'wait_discount', 'wait_prepay', 'position'] as $f) {
                    if (array_key_exists($f, $itemData)) $upd[$f] = $itemData[$f];
                }
                // Цену, проставленную руками, пересборка КП больше не перетирает
                if (array_key_exists('price', $itemData) && !array_key_exists('price_is_manual', $itemData)) {
                    $upd['price_is_manual'] = 1;
                }
                // Комментарий правится как текст, а печатается как разметка —
                // ровно так же, как описание позиции
                if (isset($upd['comment_text'])) $upd['comment_text'] = Markup::toMarkdown((string)$upd['comment_text']);
                // Карточка правится как текст, а печатается как разметка: что бы
                // ни вставили в поле — HTML из МойСклад или из письма, — в базу
                // ложится Markdown, и в PDF он уходит списком, а не тегами.
                foreach (['description_text', 'specs_text', 'included_text'] as $f) {
                    if (isset($upd[$f])) $upd[$f] = Markup::toMarkdown((string)$upd[$f]);
                }
                // Which photos of this product go into the KP (FR-046). An empty
                // array is a decision too — «этой позиции фото не нужны».
                if (array_key_exists('selected_images', $itemData) && is_array($itemData['selected_images'])) {
                    $upd['selected_images'] = json_encode(array_values($itemData['selected_images']), JSON_UNESCAPED_UNICODE);
                }
                if ($upd) Db::update('proposal_items', $upd, 'id=? AND proposal_id=?', [$itemId, $id]);
            }
            $moneyAfter = Db::all("SELECT id, price, quantity FROM proposal_items WHERE proposal_id=? ORDER BY id", [$id]);
            if ($moneyAfter !== $moneyBefore) {
                Db::update('proposals', ['no_price_ack_json' => null], 'id=?', [$id]);
            }
        }

        // Upsell rows are replaced wholesale — the editor always sends the full list
        if (array_key_exists('addons', $input) && is_array($input['addons'])) {
            KpContent::setAddons($id, $input['addons']);
        }

        // Остатки перечитываются перед пересборкой: «под заказ» в документе
        // должно отвечать сегодняшнему складу, а не дню сборки КП (модуль 026)
        KpContent::refreshStock($id);

        // Regenerate PDF
        PdfGenerator::generate($id);

        jsonOk(['pdf_preview_url' => "/api/proposals.php?action=preview&id=$id"]);

    /**
     * Текст документа — как он идёт в документе (issue #38).
     *
     * «Редактировать docx и pdf прямо в интерфейсе» — это про содержание, а не
     * про байты файла: менеджер хочет переписать абзац и переотправить КП, не
     * выгружая Word и не загружая его обратно. Документ целиком описан базой,
     * поэтому править надо базу — но показывать её надо ПОРЯДКОМ ДОКУМЕНТА, а
     * не полями таблицы. Отсюда этот список: сверху вниз, как читает клиент.
     */
    case 'doc_text': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$p) jsonError('КП не найдено', 404);

        $blocks = [];
        $add = function (string $key, string $label, ?string $value, string $hint = '', int $rows = 3)
                        use (&$blocks): void {
            $blocks[] = ['key' => $key, 'label' => $label, 'hint' => $hint,
                         'rows' => $rows, 'value' => (string)($value ?? '')];
        };

        $add('cover_letter_final', 'Сопроводительное письмо',
             $p['cover_letter_final'] !== null && $p['cover_letter_final'] !== ''
                 ? $p['cover_letter_final'] : $p['cover_letter'],
             'Текст письма, с которым уходит КП', 6);
        $add('intro_text',      'Вступление в документе', $p['intro_text'] ?? '');
        $add('pre_table_text',  'Текст перед таблицей',   $p['pre_table_text'] ?? '');

        foreach (Db::all("SELECT id, position, product_name, comment_text, notes
                          FROM proposal_items WHERE proposal_id=? ORDER BY position", [$id]) as $n => $it) {
            $no = $n + 1;
            $add("item.{$it['id']}.product_name", "Позиция $no · название в документе", $it['product_name'], '', 1);
            $add("item.{$it['id']}.comment_text", "Позиция $no · комментарий",          $it['comment_text'],
                 'Уйдёт и в документ, и в письмо клиенту', 3);
            $add("item.{$it['id']}.notes",        "Позиция $no · примечание в таблице", $it['notes'], '', 1);
        }

        $add('post_table_text',  'Текст после таблицы',     $p['post_table_text'] ?? '');
        $add('match_table_note', 'Пояснение над таблицей соответствия', $p['match_table_note'] ?? '');
        $add('terms_text',       'Условия поставки',        KpTerms::rawForProposal($p),
             '{execution_days} и {validity_days} подставляются из полей КП. '
             . 'Последняя правка станет заготовкой для следующих КП', 5);
        $add('images_note',      'Оговорка под фотографиями', $p['images_note'] ?? '', '', 2);
        $add('upsell_intro',     'Доукомплектование · вступление', $p['upsell_intro'] ?? '', '', 2);
        $add('upsell_note',      'Доукомплектование · подпись',    $p['upsell_note'] ?? '', '', 2);

        jsonData(['id' => $id, 'blocks' => $blocks]);
    }

    /**
     * Сохранить правки текста документа и пересобрать файлы.
     *
     * Правки видит админ: каждая уходит в ленту «Что изменили менеджеры» тем же
     * способом, что и правки оформления КП, — «падали админу» из issue #38.
     */
    case 'doc_text_save': {
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$p) jsonError('КП не найдено', 404);

        $blocks = $input['blocks'] ?? [];
        if (!is_array($blocks) || !$blocks) jsonError('Нечего сохранять');

        $ownFields = ['cover_letter_final', 'intro_text', 'pre_table_text', 'post_table_text',
                      'match_table_note', 'terms_text', 'conditions_text', 'warranty_text', 'images_note',
                      'upsell_intro', 'upsell_note'];
        $itemFields = ['product_name', 'comment_text', 'notes'];

        $fields = [];
        $changed = 0;
        foreach ($blocks as $b) {
            $key   = (string)($b['key'] ?? '');
            $value = (string)($b['value'] ?? '');
            if (str_starts_with($key, 'item.')) {
                [, $itemId, $field] = array_pad(explode('.', $key, 3), 3, '');
                if (!in_array($field, $itemFields, true)) continue;
                $itemId = (int)$itemId;
                $before = (string)Db::val("SELECT $field FROM proposal_items WHERE id=? AND proposal_id=?",
                                          [$itemId, $id]);
                // Комментарий печатается разметкой, а правится текстом — как везде
                $store = $field === 'comment_text' ? Markup::toMarkdown($value) : $value;
                if ($store === $before) continue;
                Db::update('proposal_items', [$field => $store], 'id=? AND proposal_id=?', [$itemId, $id]);
                $changed++;
                continue;
            }
            if (!in_array($key, $ownFields, true)) continue;
            // У КП, собранного до модуля 026, своего блока условий нет, и
            // редактор показывает сложенный из прежних полей. Сравниваем с тем
            // же текстом — иначе нетронутый блок считался бы правкой и уезжал
            // в заготовку следующих КП.
            $before = $key === 'terms_text' ? KpTerms::rawForProposal($p) : (string)($p[$key] ?? '');
            if ($before === $value) continue;
            $fields[$key] = $value;
            $changed++;
        }

        if (isset($fields['terms_text'])) KpTerms::remember((string)$fields['terms_text']);
        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Db::update('proposals', $fields, 'id=?', [$id]);
        }
        if (!$changed) jsonOk(['changed' => 0, 'pdf_preview_url' => "/api/proposals.php?action=preview&id=$id"]);

        ContentLog::record('kp', "proposal.$id", "Текст КП #$id правил менеджер",
                           (int)$manager['id'], '', "изменено блоков: $changed");
        Logger::info('kp', "Текст КП #$id отредактирован в браузере ($changed бл.)",
                     ['proposal_id' => $id, 'manager_id' => (int)$manager['id']]);

        // Файлы пересобираются сразу: предпросмотр и Word должны показывать то,
        // что менеджер только что написал, а не прошлую версию
        // Word собирается на каждое скачивание заново, так что чинить надо
        // только PDF: он лежит файлом и иначе показал бы прошлую версию
        PdfGenerator::generate($id);

        jsonOk(['changed' => $changed, 'pdf_preview_url' => "/api/proposals.php?action=preview&id=$id"]);
    }

    case 'preview':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
        if (!$proposal) jsonError('КП не найдено', 404);

        // Файла нет на диске — это не «нет КП». Имя файла содержит дату, деплой
        // чистит `data/`, а строка в базе всё ещё указывает на вчерашний путь:
        // раньше предпросмотр отвечал на это «PDF not found» и менеджер упирался
        // в стену. Собираем заново — документ полностью описан базой (модуль 023).
        if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) {
            try {
                PdfGenerator::generate($id);
                $proposal = Db::one("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
            } catch (Throwable $e) {
                Logger::exception('kp', $e, ['proposal_id' => $id, 'stage' => 'preview_rebuild']);
                jsonError('КП не удалось собрать: ' . $e->getMessage(), 500);
            }
            if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) {
                jsonError('КП не удалось собрать', 500);
            }
        }
        header('Content-Type: application/pdf');
        // Имя видно и во вкладке предпросмотра, и в «Сохранить как» (модуль 022)
        $name = PdfGenerator::fileName($id, 'pdf');
        header('Content-Disposition: inline; filename="KP-' . $id . '.pdf"; '
             . "filename*=UTF-8''" . rawurlencode($name));
        readfile($proposal['pdf_path']);
        exit;

    // The КП as a Word file (module 016). Built on demand: a manager who only
    // wants the PDF should not pay for a second render on every save.
    case 'docx':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::val("SELECT 1 FROM proposals WHERE id=?", [$id])) jsonError('Not found', 404);
        $path = DocxGenerator::generate($id);
        $name = DocxGenerator::filename($id);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="KP-' . $id . '.docx"; '
             . "filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;

    /**
     * КП отдельными файлами — по одному на позицию, одним архивом (модуль 026).
     *
     * Закупщик кладёт каждую позицию в свою строку сметы, и документ на шесть
     * позиций он режет руками. Здесь он получает шесть документов, каждый —
     * полноценное КП со своей шапкой, реквизитами и подписью.
     */
    case 'split_files': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::val("SELECT 1 FROM proposals WHERE id=?", [$id])) jsonError('КП не найдено', 404);
        $format = ($_GET['format'] ?? 'docx') === 'pdf' ? 'pdf' : 'docx';

        try {
            $files = DocxGenerator::perItem($id, $format);
        } catch (Throwable $e) {
            jsonError('Не удалось собрать отдельные файлы: ' . $e->getMessage(), 400);
        }

        // Один файл архивом не заворачиваем — отдаём как есть
        if (count($files) === 1) {
            $one = $files[0];
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="KP-' . $id . '.' . $format . '"; '
                 . "filename*=UTF-8''" . rawurlencode($one['name']));
            header('Content-Length: ' . (string)filesize($one['path']));
            readfile($one['path']);
            exit;
        }

        $zipPath = ROOT . '/data/tmp/kp-' . $id . '-' . bin2hex(random_bytes(4)) . '.zip';
        if (!is_dir(dirname($zipPath))) mkdir(dirname($zipPath), 0755, true);
        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            jsonError('Архив не создался на сервере', 500);
        }
        foreach ($files as $f) $zip->addFile($f['path'], $f['name']);
        $zip->close();

        $zipName = 'КП_отдельными_файлами_' . $id . '.zip';
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="KP-' . $id . '-split.zip"; '
             . "filename*=UTF-8''" . rawurlencode($zipName));
        header('Content-Length: ' . (string)filesize($zipPath));
        readfile($zipPath);
        @unlink($zipPath);
        exit;
    }

    case 'confirm':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$proposal) jsonError('Not found', 404);

        // A КП that prices nothing is not confirmed on the same click as one
        // that does — the manager answers for those positions by name (SC-005)
        requireNoPriceAck($id, getInput(), $manager);

        // Save corrections (US4). Everything the manager wrote by hand is a
        // lesson: the letter he rewrote, the paragraphs he added around the
        // table, and every position where he offered an analogue instead of the
        // brand the client asked for (module 011).
        $learn = function (string $field, string $auto, string $text, array $ctx = []) use ($proposal, $manager) {
            if (trim($text) === '' || trim($auto) === trim($text)) return;
            // Confirming the same КП twice must not teach the same lesson twice
            // «IS» rather than «=»: a manual КП has no request, and NULL = NULL is not a match
            if (Db::one("SELECT id FROM corrections WHERE field=? AND auto_text=? AND manager_text=? AND request_id IS ? LIMIT 1",
                        [$field, $auto, $text, $proposal['request_id']])) return;
            Db::insert('corrections', [
                'request_id'   => $proposal['request_id'],
                'field'        => $field,
                'auto_text'    => $auto,
                'manager_text' => $text,
                'manager_id'   => $manager['id'],
                'context_json' => json_encode($ctx + ['counterparty_id' => $proposal['counterparty_id']], JSON_UNESCAPED_UNICODE),
            ]);
        };

        if ($proposal['cover_letter'] && $proposal['cover_letter_final']) {
            $learn('cover_letter', (string)$proposal['cover_letter'], (string)$proposal['cover_letter_final']);
        }
        // The previous КП is what these were generated from — a paragraph that
        // did not change is not a correction and must not be learned twice
        $prev = Db::one("SELECT pre_table_text, post_table_text FROM proposals
                         WHERE id<>? AND status<>'draft' ORDER BY id DESC LIMIT 1", [$id]);
        $learn('pre_table',  (string)($prev['pre_table_text'] ?? ''),  (string)$proposal['pre_table_text']);
        $learn('post_table', (string)($prev['post_table_text'] ?? ''), (string)$proposal['post_table_text']);

        foreach (KpContent::substitutions(Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$id])) as $s) {
            $learn('item_substitution', $s['requested'],
                   $s['offered'] . ($s['note'] !== '' ? ' — ' . $s['note'] : ''),
                   ['proposal_id' => $id]);
        }

        // Regenerate final PDF
        KpContent::refreshStock($id);
        PdfGenerator::generate($id);

        Db::update('proposals', ['status' => 'confirmed', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        $proposal = Db::one("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
        jsonOk(['pdf_path' => $proposal['pdf_path']]);

    case 'send':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT p.*, r.email_from, c.name as counterparty_name, c.contact_email
                             FROM proposals p
                             JOIN requests r ON p.request_id = r.id
                             LEFT JOIN counterparties c ON p.counterparty_id = c.id
                             WHERE p.id=?", [$id]);
        if (!$proposal) jsonError('Not found', 404);
        $input = getInput();
        $to = $input['to'] ?? $proposal['email_from'] ?? $proposal['contact_email'] ?? '';
        if (!$to) jsonError('Recipient email required');

        // The same question the confirm asked. Answered there and unchanged
        // since, it does not come back; a price edited in between brings it back.
        requireNoPriceAck($id, $input, $manager);

        // 35 of the 37 КП in the archive left as a Word file — a закупщик puts
        // our positions into his own form, and cannot do that with a printout
        // (module 016). The manager's choice for THIS letter beats the setting.
        // `text` — КП прямо в теле письма, без файла: то же самое, теми же
        // цифрами, только без QR (модуль 023). Человеку, спросившему «сколько
        // стоит шлем», вложение мешает, а закупщику по-прежнему нужен Word.
        $format = (string)($input['format'] ?? Settings::get('KP_ATTACH_FORMAT', 'docx'));
        if (!in_array($format, ['docx', 'pdf', 'both', 'text'], true)) $format = 'docx';
        // «Отдельными файлами» — по документу на позицию (модуль 026): клиенту,
        // который раскладывает позиции по разным заявкам, один файл на шесть
        // строк приходится резать руками
        $split = !empty($input['split']) && $format !== 'text';

        $attachments = [];
        $docxPath = null;
        if ($split) {
            $each = $format === 'pdf' ? ['pdf'] : ($format === 'both' ? ['docx', 'pdf'] : ['docx']);
            foreach ($each as $ext) {
                foreach (DocxGenerator::perItem($id, $ext) as $f) $attachments[] = $f['path'];
            }
        } else {
            if ($format === 'docx' || $format === 'both') {
                $docxPath = DocxGenerator::generate($id);
                $attachments[] = $docxPath;
            }
            if ($format === 'pdf' || $format === 'both') {
                if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) PdfGenerator::generate($id);
                $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]) + $proposal;
                $attachments[] = $proposal['pdf_path'];
            }
        }

        // Файлы, которые менеджер приложил сам: он мог переделать документ
        // руками и прислать свой (модуль 023)
        foreach (Outbox::resolve((array)($input['files'] ?? []), (int)$manager['id']) as $path) {
            $attachments[] = $path;
        }

        $subject = $input['subject'] ?? 'Коммерческое предложение от Atlant Armour';
        $body = $proposal['cover_letter_final'] ?? $proposal['cover_letter'] ?? '';
        if ($format === 'text') {
            $kp = KpText::render($id);
            $body = trim($body) !== '' ? rtrim($body) . "\n\n" . $kp['text'] : $kp['text'];
        }
        $htmlBody = '<p>' . nl2br(htmlspecialchars($body)) . '</p>';

        // Goes out through the manager's mailbox and lands in the mail archive
        Mailer::send([
            'to'              => $to,
            'subject'         => $subject,
            'html'            => $htmlBody,
            'text'            => $body,
            'mailbox_id'      => $input['mailbox_id'] ?? null,
            'manager_id'      => (int)$manager['id'],
            'counterparty_id' => $proposal['counterparty_id'] ? (int)$proposal['counterparty_id'] : null,
            'request_id'      => (int)$proposal['request_id'],
            'attachments'     => $attachments,
        ]);

        // Feed entry — an outbound message clears the unanswered highlight (FR-038)
        $manager = currentManager();
        Crm::logEvent($proposal['counterparty_id'] ? (int)$proposal['counterparty_id'] : null, 'out', $body, [
            'request_id' => $proposal['request_id'],
            'subject'    => $subject,
            'email_to'   => $to,
            'manager_id' => $manager['id'] ?? null,
            'event_type' => 'kp_sent',
            'meta'       => ['proposal_id' => (int)$id, 'format' => $format, 'split' => $split ? 1 : 0,
                             'files' => array_map('basename', $attachments)],
        ]);
        Db::q("UPDATE correspondence SET has_attachment=1, attachment_path=? WHERE id=(SELECT MAX(id) FROM correspondence)",
              [$attachments[0] ?? $proposal['pdf_path']]);

        $now = date('Y-m-d H:i:s');
        Db::update('proposals', ['status' => 'sent', 'sent_at' => $now, 'updated_at' => $now], 'id=?', [$id]);
        Db::update('requests', ['status' => 'sent', 'updated_at' => $now], 'id=?', [$proposal['request_id']]);

        jsonOk(['sent_at' => $now]);

    // Photos available for one KP position, with the manager's current pick
    case 'item_images':
        requireAuth();
        $itemId = (int)($_GET['item_id'] ?? 0);
        $item = Db::one("SELECT id, moysklad_product_id, selected_images FROM proposal_items WHERE id=?", [$itemId]);
        if (!$item) jsonError('Позиция не найдена', 404);

        $msId = (string)($item['moysklad_product_id'] ?? '');
        $available = $msId === '' ? [] : array_map(fn($img) => [
            'key' => $img['key'],
            'url' => '/api/products.php?action=image&id=' . rawurlencode($msId) . '&key=' . rawurlencode($img['key']),
        ], KpContent::productImageList($msId));

        $selected = json_decode((string)($item['selected_images'] ?? ''), true);
        jsonData([
            'available' => $available,
            // null — «выбор не делали»: в КП идут все найденные фото
            'selected'  => is_array($selected) ? $selected : null,
        ]);

    case 'addons_suggest':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM proposals WHERE id=?", [$id])) jsonError('Not found', 404);
        jsonData(['items' => KpContent::suggestAddons($id)]);

    case 'refresh_images':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::one("SELECT id FROM proposals WHERE id=?", [$id])) jsonError('Not found', 404);
        MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');
        // Drop the cached photos so enrichItems re-fetches them from MoySklad
        Db::q("UPDATE proposal_items SET images_json=NULL WHERE proposal_id=?", [$id]);
        Db::q("UPDATE products_cache SET images_synced_at=NULL WHERE moysklad_id IN
               (SELECT moysklad_product_id FROM proposal_items WHERE proposal_id=? AND moysklad_product_id IS NOT NULL)", [$id]);
        KpContent::enrichItems($id);
        PdfGenerator::generate($id);
        $withPhotos = (int)Db::val("SELECT COUNT(*) FROM proposal_items WHERE proposal_id=? AND images_json IS NOT NULL AND images_json != '[]'", [$id]);
        jsonOk(['items_with_photos' => $withPhotos]);

    default:
        jsonError('Unknown action', 400);
}
