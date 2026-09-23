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
require_once ROOT . '/lib/kp_requirements.php';
require_once ROOT . '/lib/markup.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/notifier.php';
require_once ROOT . '/lib/crm.php';
require_once ROOT . '/lib/request_shape.php';
require_once ROOT . '/lib/requisites.php';
require_once ROOT . '/lib/kp_terms.php';
require_once ROOT . '/lib/kp_set.php';
require_once ROOT . '/lib/kp_editor.php';

/**
 * Собрать новое КП запроса из таблицы подбора: позиции, письмо, карточки,
 * реквизиты, PDF. Им пользуются «Сформировать КП» и «🔄 Пересобрать» по уже
 * отправленному КП (модуль 048).
 *
 * @return array{0:int,1:string} id КП и сопроводительное письмо
 */
function buildProposal(array $req, array $manager): array {
    $requestId = (int)$req['id'];
    // Ensure MoySklad is initialized
    MoySklad::init((string)Settings::get('MOYSKLAD_TOKEN', ''));

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

    $proposalId = KpSet::create($requestId, (int)$manager['id']);

    // Insert items
    foreach ($matched as $i => $m) {
        Db::insert('proposal_items', KpSet::itemRow($m, $i + 1) + ['proposal_id' => $proposalId]);
    }

    // Клиент просил указать что-то в самом КП — абзац документа на это
    // (модуль 046). Сбой модели КП не отменяет: абзаца просто не будет
    KpRequirements::apply($proposalId);

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
    // Сопроводительное письмо пишет модель — и это единственная часть сборки,
    // которой нужна сеть. Молчащий провайдер не должен отменять ДОКУМЕНТ:
    // раньше `generate` падал целиком, и «Сформировать КП» выглядело как
    // «ничего не происходит» (модуль 034). Письмо менеджер допишет сам.
    $coverLetter = '';
    try {
        $coverLetter = RequestParser::generateCoverLetter($items, $orgName, $tov, $corrections, $swaps,
                                                          $pastSwaps, $unmatched);
    } catch (Throwable $e) {
        Logger::warning('kp', 'КП собрано без сопроводительного письма: ' . $e->getMessage(),
                        ['proposal_id' => $proposalId, 'request_id' => $requestId]);
    }
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

    return [$proposalId, $coverLetter];
}

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

/**
 * Ошибка предпросмотра — страницей, а не JSON (модуль 034).
 *
 * Предпросмотр КП живёт в рамке под письмом. JSON, отданный в рамку, рисуется
 * в ней как строка `{"error":"…"}` или как пустое окно — ровно то «ничего не
 * происходит», на которое жаловались. Здесь ошибка написана словами и говорит,
 * что делать дальше.
 */
function kpPreviewError(int $id, string $title, string $detail, int $code): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $e = fn(string $t): string => htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="ru"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>КП №' . $id . '</title><style>'
       . 'body{font:15px/1.5 -apple-system,Segoe UI,Roboto,sans-serif;color:#222;margin:0;padding:24px}'
       . 'h1{font-size:17px;margin:0 0 8px;color:#c00}p{margin:0 0 8px}code{font-size:13px;color:#555}'
       . '</style></head><body><h1>' . $e($title) . '</h1><p>' . $e($detail) . '</p>'
       . '<p><code>КП №' . $id . '</code></p></body></html>';
    exit;
}

$action = $_GET['action'] ?? '';
// Тело POST-запроса — одно на все действия. `doc_text_save` читал `$input`,
// которого нигде не было, и правки текста КП не сохранялись (модуль 045)
$input = in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['POST', 'PUT'], true) ? getInput() : [];

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

        // У запроса одно рабочее КП — самое новое (модуль 048). Повторный клик
        // (двойной клик, повтор запроса сетью) просто возвращает то, что уже есть;
        // собрать заново из подбора — «🔄 Пересобрать» (action=rebuild).
        $existingId = (int)(Db::val("SELECT id FROM proposals WHERE request_id=? ORDER BY id DESC LIMIT 1", [$requestId]) ?: 0);
        if ($existingId) {
            $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$existingId]);
            jsonData([
                'id' => $existingId,
                'status' => $proposal['status'],
                'items' => Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$existingId]),
                'addons' => Db::all("SELECT * FROM proposal_addons WHERE proposal_id=? ORDER BY position", [$existingId]),
                'cover_letter' => $proposal['cover_letter'],
                'pdf_preview_url' => "/api/proposals.php?action=preview&id=$existingId",
            ]);
        }

        [$proposalId, $coverLetter] = buildProposal($req, $manager);

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
        foreach (['pre_table_text', 'post_table_text', 'intro_text', 'conditions_text', 'execution_days', 'validity_days', 'vat_rate', 'vat_mode',
                  'warranty_text', 'images_note', 'show_images', 'show_upsell', 'upsell_intro', 'upsell_note',
                  'show_match_table', 'match_table_note',
                  // Условия одним блоком и доставка отдельной строкой (модуль 026)
                  'terms_text', 'delivery_on', 'delivery_name', 'delivery_price',
                  // Сколько фото печатать в ЭТОМ КП; пусто — общая настройка
                  'photos_per_item',
                  // «Показать в КП отсутствующую номенклатуру» (модуль 046)
                  'show_out_of_scope'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if (array_key_exists('show_out_of_scope', $fields)) {
            $v = $fields['show_out_of_scope'];
            $fields['show_out_of_scope'] = $v === null || $v === '' ? null : ((int)$v === 1 ? 1 : 0);
        }
        if (array_key_exists('cover_letter_final', $input)) {
            $fields['cover_letter_final'] = $input['cover_letter_final'];
        }
        // Как печатать цену в этом КП: «в т.ч. НДС» или «+ НДС сверху»
        // (модуль 030). Пусто — как в настройках; чужое слово не принимаем.
        if (array_key_exists('vat_mode', $fields)) {
            $mode = trim((string)$fields['vat_mode']);
            $fields['vat_mode'] = in_array($mode, Requisites::VAT_MODES, true) ? $mode : null;
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
                          // Аналог и верх вилки цен (модуль 036)
                          'is_alternative', 'alt_of', 'price_max',
                          // Позиция «под заказ» и деньги, которые менеджер ставит руками (модуль 023)
                          'comment_text', 'discount_percent', 'price_is_manual',
                          'wait_on', 'wait_months', 'wait_discount', 'wait_prepay', 'position'] as $f) {
                    if (array_key_exists($f, $itemData)) $upd[$f] = $itemData[$f];
                }
                // Цену, проставленную руками, пересборка КП больше не перетирает
                if (array_key_exists('price', $itemData) && !array_key_exists('price_is_manual', $itemData)) {
                    $upd['price_is_manual'] = 1;
                }
                // Вписанная руками цена отменяет вилку: «от 1 500 до 1 800»
                // рядом с числом, которое поставил человек, — чужая цена в его
                // строке (модуль 036)
                if (array_key_exists('price', $itemData) && !array_key_exists('price_max', $itemData)) {
                    $upd['price_max'] = 0;
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

        // Сохранение полного редактора — это сборка документа из базы заново:
        // ручная правка страницы A4 (модуль 045) снимается. Одно сопроводительное
        // письмо документ не меняет — его правка страницу не трогает.
        if (array_diff(array_keys($fields), ['cover_letter_final'])
            || array_key_exists('items', $input) || array_key_exists('addons', $input)) {
            Db::update('proposals', ['html_override' => null, 'html_override_at' => null], 'id=?', [$id]);
        }

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
             '{execution_term} — срок исполнения словами: дни из поля КП, а если что-то под заказ, '
             . 'то срок ожидания из таблицы подбора. {validity_days} — срок действия цены. '
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
        // Правка текста по полям — это новая сборка документа: ручная правка
        // страницы (модуль 045) её бы перекрыла, поэтому она снимается
        if ($changed) {
            $fields['html_override'] = null;
            $fields['html_override_at'] = null;
        }
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

    /**
     * ==== КП страницей A4, которую можно править (модуль 045, issue #60) ====
     *
     * `html` — страница для редактора (фото — короткими ссылками `kp_img`),
     * `html_save` — сохранить правку, из неё соберутся PDF и Word,
     * `html_reset` — вернуть автоматическую сборку из базы.
     */
    case 'html': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $p = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$p) jsonError('КП не найдено', 404);
        try {
            $page = KpEditor::page($id);
        } catch (Throwable $e) {
            Logger::exception('kp', $e, ['proposal_id' => $id, 'stage' => 'html_editor']);
            jsonError('КП не собралось: ' . $e->getMessage(), 500);
        }
        jsonData([
            'id'          => $id,
            'html'        => $page,
            'editable'    => KpEditor::editable($p),
            'override_at' => $p['html_override_at'],
            // Галочка «Показать в КП отсутствующую номенклатуру» (модуль 046):
            // есть ли такие строки у запроса и печатаются ли они в этом КП
            // Что клиент просил указать в КП — менеджер сверяет с листом (модуль 046)
            'requirements' => KpRequirements::of((int)$p['request_id']),
            'out_of_scope' => [
                'count' => $p['request_id']
                    ? (int)Db::val("SELECT COUNT(*) FROM request_items WHERE request_id=? AND COALESCE(is_out_of_scope,0)=1",
                                   [$p['request_id']]) : 0,
                'shown' => (bool)KpContent::outOfScopeRows($p),
            ],
        ]);
    }

    case 'html_save': {
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $p = Db::one("SELECT id, status FROM proposals WHERE id=?", [$id]);
        if (!$p) jsonError('КП не найдено', 404);
        if (!KpEditor::editable($p)) jsonError('КП уже отправлено клиенту — отправленный документ не правится', 409);
        try {
            KpEditor::save($id, (string)($input['html'] ?? ''));
        } catch (InvalidArgumentException $e) {
            jsonError($e->getMessage());
        } catch (Throwable $e) {
            Logger::exception('kp', $e, ['proposal_id' => $id, 'stage' => 'html_save']);
            jsonError('Правка сохранилась, но PDF не собрался: ' . $e->getMessage(), 500);
        }
        ContentLog::record('kp', "proposal.$id", "КП #$id поправлено на странице A4",
                           (int)$manager['id'], '', 'ручная правка документа');
        jsonOk(['id' => $id, 'override_at' => date('Y-m-d H:i:s')]);
    }

    case 'html_reset': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $p = Db::one("SELECT id, status FROM proposals WHERE id=?", [$id]);
        if (!$p) jsonError('КП не найдено', 404);
        if (!KpEditor::editable($p)) jsonError('КП уже отправлено клиенту — отправленный документ не правится', 409);
        KpEditor::reset($id);
        jsonOk(['id' => $id]);
    }

    // Фото страницы-редактора: файл по хешу содержимого, неизменяемый
    case 'kp_img': {
        requireAuth();
        $img = KpEditor::image((string)($_GET['h'] ?? ''));
        if (!$img) { http_response_code(404); exit; }
        header('Content-Type: ' . $img['type']);
        header('Cache-Control: private, max-age=31536000, immutable');
        header('X-Content-Type-Options: nosniff');
        echo $img['bin'];
        exit;
    }

    case 'preview':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
        // Предпросмотр открывается в рамке под письмом: JSON с ошибкой рисуется
        // там как пустое окно, и «ничего не происходит» — это оно (модуль 034).
        // Поэтому здесь ошибка отвечает страницей, которую видно словами.
        if (!$proposal) kpPreviewError($id, 'КП №' . $id . ' не найдено',
            'Документ мог быть удалён. Соберите КП заново кнопкой «Сформировать КП» под таблицей позиций.', 404);

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
                kpPreviewError($id, 'КП не удалось собрать', $e->getMessage(), 500);
            }
            if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) {
                kpPreviewError($id, 'КП не удалось собрать',
                    'Документ собрался, но файл не появился на диске — загляните в «Настройки → Журнал».', 500);
            }
        }
        header('Content-Type: application/pdf');
        // Имя видно и во вкладке предпросмотра, и в «Сохранить как» (модуль 022)
        $name = PdfGenerator::fileName($id, 'pdf');
        header('Content-Disposition: inline; filename="' . PdfGenerator::asciiFileName($id, 'pdf') . '"; '
             . "filename*=UTF-8''" . rawurlencode($name));
        readfile($proposal['pdf_path']);
        exit;

    // The КП as a Word file (module 016). Built on demand: a manager who only
    // wants the PDF should not pay for a second render on every save.
    case 'docx':
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::val("SELECT 1 FROM proposals WHERE id=?", [$id])) {
            jsonError('КП №' . $id . ' не найдено — соберите его заново кнопкой «Сформировать КП»', 404);
        }
        try {
            $path = DocxGenerator::generate($id);
        } catch (Throwable $e) {
            Logger::exception('kp', $e, ['proposal_id' => $id, 'stage' => 'docx']);
            jsonError('КП не собралось в Word: ' . $e->getMessage(), 500);
        }
        $name = DocxGenerator::filename($id);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="' . PdfGenerator::asciiFileName($id, 'docx') . '"; '
             . "filename*=UTF-8''" . rawurlencode($name));
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;

    /**
     * ==== Одно КП на запрос (модуль 048) ====
     *
     * Кнопки под таблицей подбора: 🔄 Пересобрать · Открыть · ⬇ Word · ⬇ PDF ·
     * 🧾 Счёт · Убрать. Здесь — то, что им нужно от сервера.
     */

    /** Счета и можно ли убрать — строка кнопок под таблицей подбора. */
    case 'summary': {
        requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        if (!Db::val("SELECT 1 FROM proposals WHERE id=?", [$id])) jsonError('КП не найдено', 404);
        jsonData(KpSet::summary($id));
    }

    /**
     * «🔄 Пересобрать»: позиции КП — заново из таблицы подбора. Отправленное
     * клиенту КП не переписывается: по подбору собирается новое, и рабочим
     * становится оно.
     */
    case 'rebuild': {
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$proposal) jsonError('КП не найдено', 404);
        $req = Db::one("SELECT * FROM requests WHERE id=?", [(int)$proposal['request_id']]);
        if (!$req) jsonError('Запрос не найден', 404);
        try {
            if (KpEditor::editable($proposal)) {
                KpSet::rebuildItems($id);
                $newId = $id;
            } else {
                [$newId] = buildProposal($req, $manager);
            }
        } catch (Throwable $e) {
            Logger::exception('kp', $e, ['proposal_id' => $id, 'stage' => 'rebuild']);
            jsonError('КП не пересобралось: ' . $e->getMessage(), 500);
        }
        Logger::info('kp', "КП #$newId пересобрано из подбора", ['proposal_id' => $newId, 'from' => $id,
                                                                  'manager_id' => (int)$manager['id']]);
        jsonOk(['id' => $newId, 'created' => $newId !== $id] + KpSet::summary($newId));
    }

    /** Убрать КП целиком — пока оно не ушло клиенту и по нему нет счёта. */
    case 'delete': {
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $requestId = (int)(Db::val("SELECT request_id FROM proposals WHERE id=?", [$id]) ?: 0);
        if (!$requestId) jsonError('КП не найдено', 404);
        try {
            KpSet::delete($id);
        } catch (Throwable $e) {
            jsonError($e->getMessage(), 400);
        }
        Logger::info('kp', "КП #$id убрано с карточки запроса #$requestId",
                     ['proposal_id' => $id, 'manager_id' => (int)$manager['id']]);
        jsonOk(['request_id' => $requestId]);
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
        $attachments = [];
        $docxPath = null;
        if ($format === 'docx' || $format === 'both') {
            $docxPath = DocxGenerator::generate($id);
            $attachments[] = $docxPath;
        }
        if ($format === 'pdf' || $format === 'both') {
            if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) PdfGenerator::generate($id);
            $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]) + $proposal;
            $attachments[] = $proposal['pdf_path'];
        }

        // Файлы, которые менеджер приложил сам: он мог переделать документ
        // руками и прислать свой (модуль 023)
        // `resolve()` отдаёт пару «путь + имя в письме»: приставка, под которой
        // файл лежит на диске, клиенту не показывается (модуль 040)
        foreach (Outbox::resolve((array)($input['files'] ?? []), (int)$manager['id']) as $file) {
            $attachments[] = $file;
        }

        $subject = $input['subject'] ?? 'Коммерческое предложение от Atlant Armour';
        $body = $proposal['cover_letter_final'] ?? $proposal['cover_letter'] ?? '';
        if ($format === 'text') {
            $kp = KpText::render($id);
            $body = trim($body) !== '' ? rtrim($body) . "\n\n" . $kp['text'] : $kp['text'];
        }
        // «см. на сайте» — ссылкой со словами, а не голым адресом (модуль 045)
        require_once ROOT . '/lib/mail_text.php';
        $htmlBody = MailText::textToHtml($body);

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
        Crm::logEvent($proposal['counterparty_id'] ? (int)$proposal['counterparty_id'] : null, 'out', $body, [
            'request_id' => $proposal['request_id'],
            'subject'    => $subject,
            'email_to'   => $to,
            'manager_id' => $manager['id'] ?? null,
            'event_type' => 'kp_sent',
            'meta'       => ['proposal_id' => (int)$id, 'format' => $format,
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
