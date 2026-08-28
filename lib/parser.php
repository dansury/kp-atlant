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
        $system = <<<PROMPT
You parse incoming B2B messages for a tactical equipment supplier. Input is Russian.
Return JSON with fields:
- request_type: "order" | "kp_request"
- type_confidence: number 0..1
- type_reason: short Russian phrase explaining the classification
- org_name: organization name (string or null)
- inn: INN of the client organization, digits only (string or null)
- contact_person: contact name (string or null)
- contact_email: email if present (string or null)
- contact_phone: phone if present (string or null)
- delivery_terms: delivery conditions if mentioned (string or null)
- items: array of {name: string, qty: int, raw_text: string}

Classification rules:
- "order" = the client is placing an order or confirming a purchase: "просим отгрузить",
  "заявка на поставку", "подтверждаем заказ", "просим выставить счёт", "оплатим по счёту",
  signed КП / спецификация / заявка attached, client requisites given for invoicing.
- "kp_request" = the client is asking for a quote: "просим выставить КП", "прошу рассчитать
  стоимость", "интересует цена", "пришлите коммерческое предложение".
- If both readings fit, prefer "kp_request" and lower type_confidence.

Extraction rules:
- Text after "--- Вложение: <name> ---" comes from an attached file; treat it as part of the request.
- Positions may appear ONLY in an attachment (спецификация, заявка) — extract them there.
- Normalize product names: expand abbreviations (бж=бронежилет, ИРП=индивидуальный рацион питания)
- qty defaults to 1 if not specified
- raw_text = original text fragment for this item
- Do NOT take the supplier's own INN (Atlant Armour / ИП Сурков) as the client INN
- If no items found, return empty items array
PROMPT;

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

        $system = <<<PROMPT
Ты пишешь сопроводительные письма к коммерческим предложениям Atlant Armour.
Правила тона (ToV):
$tov

Формат: короткое деловое письмо на русском. 3-5 предложений. Без пафоса, с фактами.
Структура: приветствие → по вашему запросу готовы поставить → перечень кратко → готовы ответить на вопросы.$fewShot
PROMPT;

        $user = "Контрагент: $orgName\nПозиции КП:\n$itemList";
        return LLM::chatText($system, $user, 0.4);
    }

    // Normalize product names for fuzzy matching
    public static function normalizeNames(array $rawNames): array {
        if (empty($rawNames)) return [];

        $system = <<<PROMPT
Normalize product names from a tactical equipment request for search matching.
For each name: expand abbreviations, fix transliteration, remove quantities and units.
Return JSON array: [{original: string, normalized: string, category: string}]
Categories: armor, helmets, medical, pouches, backpacks, accessories, other
PROMPT;
        $user = json_encode($rawNames, JSON_UNESCAPED_UNICODE);
        return LLM::chatJson($system, $user);
    }

    // Generate follow-up email text
    public static function generateFollowup(array $proposal, string $orgName, int $daysSince, string $emailRules, string $tov): string {
        $system = <<<PROMPT
Сгенерируй follow-up письмо по правилам:
$emailRules

Тон (ToV):
$tov
PROMPT;
        $itemSummary = '';
        if (!empty($proposal['items'])) {
            $itemSummary = implode(', ', array_map(fn($i) => $i['product_name'], $proposal['items']));
        }

        $user = "Контрагент: $orgName\nКП отправлено $daysSince дней назад\nПозиции: $itemSummary\nЗаказ не создан.";
        return LLM::chatText($system, $user, 0.4);
    }
}
