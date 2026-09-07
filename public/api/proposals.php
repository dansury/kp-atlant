<?php
/**
 * API: Proposals — generate, update, preview, confirm, send.
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/matcher.php';
require_once ROOT . '/lib/request_items.php';
require_once ROOT . '/lib/pdf.php';
require_once ROOT . '/lib/kp_content.php';
require_once ROOT . '/lib/mail.php';
require_once ROOT . '/lib/notifier.php';
require_once ROOT . '/lib/crm.php';

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
        // Which positions are analogues rather than what was asked for — the
        // editor asks the manager to explain exactly those (module 011)
        $swaps = [];
        foreach (KpContent::substitutions($proposal['items']) as $sw) $swaps[$sw['requested']] = true;
        foreach ($proposal['items'] as &$it) {
            $it['is_substitution'] = isset($swaps[trim((string)($it['requested_name'] ?? ''))]);
        }
        unset($it);
        $proposal['addons'] = Db::all("SELECT * FROM proposal_addons WHERE proposal_id=? ORDER BY position", [$id]);
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
            // «Чтобы всё, что мы дописываем, система учитывала»: whatever the
            // manager typed around the table last time is already here, so a
            // house rule is written once instead of retyped on every КП
            'pre_table_text'  => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='pre_table' ORDER BY id DESC LIMIT 1") ?: ''),
            'post_table_text' => (string)(Db::val("SELECT manager_text FROM corrections WHERE field='post_table' ORDER BY id DESC LIMIT 1") ?: ''),
        ]);

        // Insert items
        foreach ($matched as $i => $m) {
            $match = $m['match'];
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
                // The manager's own note wins over the automatic «под заказ»
                'notes' => $m['notes'] ?? (($match && ($match['stock'] ?? 0) == 0) ? 'под заказ' : null),
            ]);
        }

        // Generate cover letter
        $tov = file_exists(ROOT . '/reference/tov.md') ? file_get_contents(ROOT . '/reference/tov.md') : '';
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
        $coverLetter = RequestParser::generateCoverLetter($items, $orgName, $tov, $corrections, $swaps, $pastSwaps);
        Db::update('proposals', ['cover_letter' => $coverLetter], 'id=?', [$proposalId]);

        // Pull descriptions, specs and photos for the product cards (FR-040, FR-042)
        KpContent::enrichItems($proposalId);

        // Pre-fill the upsell table with modules from the addon folder (FR-044)
        KpContent::seedAddons($proposalId);

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
                  'warranty_text', 'images_note', 'show_images', 'show_upsell', 'upsell_intro', 'upsell_note'] as $f) {
            if (array_key_exists($f, $input)) $fields[$f] = $input[$f];
        }
        if (array_key_exists('cover_letter_final', $input)) {
            $fields['cover_letter_final'] = $input['cover_letter_final'];
        }
        if ($fields) {
            $fields['updated_at'] = date('Y-m-d H:i:s');
            Db::update('proposals', $fields, 'id=?', [$id]);
        }

        // Update items
        if (!empty($input['items'])) {
            foreach ($input['items'] as $itemData) {
                $itemId = $itemData['id'] ?? 0;
                $upd = [];
                foreach (['quantity', 'price', 'product_name', 'is_confirmed', 'notes', 'vat_rate', 'moysklad_product_id',
                          'description_text', 'specs_text', 'included_text', 'show_images', 'price_from', 'qty_from'] as $f) {
                    if (array_key_exists($f, $itemData)) $upd[$f] = $itemData[$f];
                }
                // Which photos of this product go into the KP (FR-046). An empty
                // array is a decision too — «этой позиции фото не нужны».
                if (array_key_exists('selected_images', $itemData) && is_array($itemData['selected_images'])) {
                    $upd['selected_images'] = json_encode(array_values($itemData['selected_images']), JSON_UNESCAPED_UNICODE);
                }
                if ($upd) Db::update('proposal_items', $upd, 'id=? AND proposal_id=?', [$itemId, $id]);
            }
        }

        // Upsell rows are replaced wholesale — the editor always sends the full list
        if (array_key_exists('addons', $input) && is_array($input['addons'])) {
            KpContent::setAddons($id, $input['addons']);
        }

        // Regenerate PDF
        PdfGenerator::generate($id);

        jsonOk(['pdf_preview_url' => "/api/proposals.php?action=preview&id=$id"]);

    case 'preview':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT pdf_path FROM proposals WHERE id=?", [$id]);
        if (!$proposal || !$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) {
            jsonError('PDF not found', 404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="KP-' . $id . '.pdf"');
        readfile($proposal['pdf_path']);
        exit;

    case 'confirm':
        $manager = requireAuth();
        $id = (int)($_GET['id'] ?? 0);
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$id]);
        if (!$proposal) jsonError('Not found', 404);

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
        if (!$proposal['pdf_path'] || !file_exists($proposal['pdf_path'])) jsonError('PDF not generated');

        $input = getInput();
        $to = $input['to'] ?? $proposal['email_from'] ?? $proposal['contact_email'] ?? '';
        if (!$to) jsonError('Recipient email required');

        $subject = $input['subject'] ?? 'Коммерческое предложение от Atlant Armour';
        $body = $proposal['cover_letter_final'] ?? $proposal['cover_letter'] ?? '';
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
            'attachments'     => [$proposal['pdf_path']],
        ]);

        // Feed entry — an outbound message clears the unanswered highlight (FR-038)
        $manager = currentManager();
        Crm::logEvent($proposal['counterparty_id'] ? (int)$proposal['counterparty_id'] : null, 'out', $body, [
            'request_id' => $proposal['request_id'],
            'subject'    => $subject,
            'email_to'   => $to,
            'manager_id' => $manager['id'] ?? null,
            'event_type' => 'kp_sent',
            'meta'       => ['proposal_id' => (int)$id, 'pdf' => basename($proposal['pdf_path'])],
        ]);
        Db::q("UPDATE correspondence SET has_attachment=1, attachment_path=? WHERE id=(SELECT MAX(id) FROM correspondence)", [$proposal['pdf_path']]);

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
