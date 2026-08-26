<?php
/**
 * Cron: Daily follow-up scan — find KPs sent 30+ days ago with no order.
 * Usage: php cron/check_followups.php
 */
require_once __DIR__ . '/../lib/bootstrap.php';
require_once ROOT . '/lib/moysklad.php';
require_once ROOT . '/lib/parser.php';
require_once ROOT . '/lib/notifier.php';

MoySklad::init($cfg['MOYSKLAD_TOKEN'] ?? '');

// Find sent KPs older than 30 days with no order created
$stale = Db::all(
    "SELECT p.id, p.counterparty_id, p.sent_at, p.number,
            c.name as counterparty_name, c.moysklad_id as cp_moysklad_id
     FROM proposals p
     JOIN counterparties c ON p.counterparty_id = c.id
     WHERE p.status = 'sent'
     AND p.sent_at <= datetime('now', '-30 days')
     AND NOT EXISTS (SELECT 1 FROM followups f WHERE f.proposal_id = p.id AND f.status IN ('sent','suggested','draft_ready'))"
);

if (empty($stale)) exit(0);

// Load ToV and email rules
$tov = file_exists(ROOT . '/reference/tov.md') ? file_get_contents(ROOT . '/reference/tov.md') : '';
$emailRules = Db::val("SELECT content FROM email_rules ORDER BY id DESC LIMIT 1") ?: '';

foreach ($stale as $proposal) {
    // Verify no order in MoySklad
    if ($proposal['cp_moysklad_id']) {
        $orders = MoySklad::getOrdersByCounterparty($proposal['cp_moysklad_id'], 60);
        if (!empty($orders)) continue; // order exists — skip
    }

    $daysSince = (int)((time() - strtotime($proposal['sent_at'])) / 86400);

    // Get proposal items for context
    $items = Db::all("SELECT product_name FROM proposal_items WHERE proposal_id=?", [$proposal['id']]);

    // Generate follow-up draft
    try {
        $draft = RequestParser::generateFollowup(
            ['items' => $items],
            $proposal['counterparty_name'],
            $daysSince,
            $emailRules,
            $tov
        );

        Db::insert('followups', [
            'proposal_id' => $proposal['id'],
            'counterparty_id' => $proposal['counterparty_id'],
            'status' => 'suggested',
            'draft_text' => $draft,
        ]);

        // Notify managers
        Notifier::notify(
            'followup_ready',
            "Follow-up: {$proposal['counterparty_name']}",
            "КП #{$proposal['number']} отправлено $daysSince дн. назад, заказ не создан",
            'proposal', $proposal['id']
        );

        echo "Follow-up suggested for KP #{$proposal['number']} ({$proposal['counterparty_name']})\n";
    } catch (\Exception $e) {
        error_log("Follow-up generation failed for proposal #{$proposal['id']}: " . $e->getMessage());
    }
}
