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

    /**
     * Cover letter for a КП.
     *
     * $substitutions — positions where we offer an analogue instead of what was
     * asked for; the letter must name them, because a client who asked for one
     * manufacturer and gets a price list of another reads it as a mistake.
     * $pastSubstitutions — how the manager explained such a swap before, so the
     * wording is the office's own and not the model's invention (module 011).
     */
    public static function generateCoverLetter(array $items, string $orgName, string $tov,
                                               array $corrections = [], array $substitutions = [],
                                               array $pastSubstitutions = []): string {
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

        if ($substitutions) {
            $fewShot .= "\n\nВ этом КП есть замены на аналоги — назови их в письме прямо, "
                      . "одной фразой на позицию, без извинений. Для каждой замены обязательно "
                      . "перечисли, каким требованиям запроса наша позиция соответствует — "
                      . "строго по списку ниже, ничего не добавляя от себя:\n";
            foreach (array_slice($substitutions, 0, 8) as $s) {
                $fewShot .= "Просили: {$s['requested']} → предлагаем: {$s['offered']}"
                          . ($s['note'] !== '' ? " ({$s['note']})" : '') . "\n";
                // Which of the client's own requirements this analogue was
                // PROVED to meet (module 013). The model may repeat these and
                // may not invent any others.
                foreach (array_slice($s['matched'] ?? [], 0, 6) as $m) {
                    $fewShot .= "    соответствует: {$m['requirement']}"
                              . (($m['ours'] ?? '') !== '' ? " — у нас: {$m['ours']}" : '') . "\n";
                }
            }
        }
        if ($pastSubstitutions) {
            $fewShot .= "\nТак менеджер объяснял замены раньше — держись этих формулировок:\n";
            foreach (array_slice($pastSubstitutions, 0, 5) as $c) {
                $fewShot .= "Просили: {$c['auto_text']} → писали: {$c['manager_text']}\n";
            }
        }

        // The wiki knows our products — pull in what this KP is actually about
        $system = Knowledge::augment('cover_letter', ['tov' => $tov, 'few_shot' => $fewShot], "$orgName\n$itemList");

        $user = "Контрагент: $orgName\nПозиции КП:\n$itemList";
        return LLM::chatText($system, $user, 0.4);
    }

    // Normalize product names for fuzzy matching
    public static function normalizeNames(array $rawNames): array {
        if (empty($rawNames)) return [];

        $user = json_encode($rawNames, JSON_UNESCAPED_UNICODE);
        $system = Knowledge::augment('normalize_names', [], implode(' ', array_map('strval', $rawNames)));
        return LLM::chatJson($system, $user);
    }

    // Generate follow-up email text
    public static function generateFollowup(array $proposal, string $orgName, int $daysSince, string $emailRules, string $tov): string {
        $itemSummary = '';
        if (!empty($proposal['items'])) {
            $itemSummary = implode(', ', array_map(fn($i) => $i['product_name'], $proposal['items']));
        }
        $system = Knowledge::augment('followup', ['email_rules' => $emailRules, 'tov' => $tov], "$orgName $itemSummary");

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
        // Retrieval query = what the client actually wrote: subject, body, attachments
        $query = trim((string)($message['subject'] ?? '') . "\n"
            . self::clip((string)($message['body_text'] ?? ''), 4000) . "\n"
            . self::clip((string)($ctx['attachments'] ?? ''), 1500));
        $system = Knowledge::augment('mail_reply', [
            'email_rules' => $ctx['email_rules'] ?? '',
            'tov'         => $ctx['tov'] ?? '',
        ], $query);

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
