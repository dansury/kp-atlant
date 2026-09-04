<?php
/**
 * Request parser: extracts items, org, contact from free-form text via LLM.
 * Also generates cover letter drafts.
 */
class RequestParser {

    /**
     * Parse request text into structured data and classify its type (C-009).
     * $attachmentText — text extracted from email attachments (FR-022).
     */
    public static function parse(string $text, string $attachmentText = ''): array {
        $system = Prompts::render('parse_request');

        $user = $text;
        if (trim($attachmentText) !== '') {
            $user .= "\n\n===== ТЕКСТ ВЛОЖЕНИЙ =====\n" . $attachmentText;
        }

        $parsed = LLM::chatJson($system, $user);

        // Normalize the type so the rest of the system can rely on it
        $type = ($parsed['request_type'] ?? '') === 'order' ? 'order' : 'kp_request';
        $parsed['request_type'] = $type;
        return $parsed;
    }

    // Generate cover letter for KP
    public static function generateCoverLetter(array $items, string $orgName, string $tov, array $corrections = []): string {
        $itemList = implode("\n", array_map(
            fn($i) => "- {$i['product_name']} ({$i['quantity']} {$i['unit']})",
            $items
        ));

        $fewShot = '';
        if ($corrections) {
            $examples = array_slice($corrections, 0, 5);
            $fewShot = "\n\nПримеры корректур менеджера (учитывай стиль):\n";
            foreach ($examples as $c) {
                $fewShot .= "Было: {$c['auto_text']}\nСтало: {$c['manager_text']}\n\n";
            }
        }

        $system = Prompts::render('cover_letter', ['tov' => $tov, 'few_shot' => $fewShot]);

        $user = "Контрагент: $orgName\nПозиции КП:\n$itemList";
        return LLM::chatText($system, $user, 0.4);
    }

    // Normalize product names for fuzzy matching
    public static function normalizeNames(array $rawNames): array {
        if (empty($rawNames)) return [];

        $system = Prompts::render('normalize_names');
        $user = json_encode($rawNames, JSON_UNESCAPED_UNICODE);
        return LLM::chatJson($system, $user);
    }

    // Generate follow-up email text
    public static function generateFollowup(array $proposal, string $orgName, int $daysSince, string $emailRules, string $tov): string {
        $system = Prompts::render('followup', ['email_rules' => $emailRules, 'tov' => $tov]);
        $itemSummary = '';
        if (!empty($proposal['items'])) {
            $itemSummary = implode(', ', array_map(fn($i) => $i['product_name'], $proposal['items']));
        }

        $user = "Контрагент: $orgName\nКП отправлено $daysSince дней назад\nПозиции: $itemSummary\nЗаказ не создан.";
        return LLM::chatText($system, $user, 0.4);
    }

    /**
     * Draft a reply to an incoming letter. Called only from «Создать ответ» —
     * mail sync itself never asks the model for a reply.
     * $ctx: org_name, attachments (text), thread (array of ['direction','date_at','body_text']),
     *       email_rules, tov.
     */
    public static function generateReply(array $message, array $ctx = []): string {
        $system = Prompts::render('mail_reply', [
            'email_rules' => $ctx['email_rules'] ?? '',
            'tov'         => $ctx['tov'] ?? '',
        ]);

        $user = '';
        if (!empty($ctx['org_name']))  $user .= "Компания: {$ctx['org_name']}\n";
        if (!empty($message['from_name'])) $user .= "Контакт: {$message['from_name']}\n";
        if (!empty($ctx['thread'])) {
            $user .= "\n===== ПРЕДЫДУЩАЯ ПЕРЕПИСКА =====\n";
            foreach ($ctx['thread'] as $t) {
                $who = ($t['direction'] ?? 'in') === 'in' ? 'Клиент' : 'Мы';
                $user .= "[$who, {$t['date_at']}] " . self::clip((string)($t['body_text'] ?? ''), 800) . "\n\n";
            }
        }
        $user .= "\n===== ПИСЬМО, НА КОТОРОЕ ОТВЕЧАЕМ =====\n";
        $user .= "Тема: " . (string)($message['subject'] ?? '') . "\n";
        $user .= self::clip((string)($message['body_text'] ?? ''), 6000) . "\n";
        if (trim((string)($ctx['attachments'] ?? '')) !== '') {
            $user .= "\n===== ТЕКСТ ВЛОЖЕНИЙ =====\n" . self::clip((string)$ctx['attachments'], 6000) . "\n";
        }

        return LLM::chatText($system, $user, 0.4);
    }

    /** Keep the prompt bounded — a quoted thread can be megabytes long. */
    private static function clip(string $text, int $max): string {
        $text = trim($text);
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max) . "\n[...]" : $text;
    }
}
