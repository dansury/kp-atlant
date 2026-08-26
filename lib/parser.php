<?php
/**
 * Request parser: extracts items, org, contact from free-form text via LLM.
 * Also generates cover letter drafts.
 */
class RequestParser {

    // Parse request text into structured data
    public static function parse(string $text): array {
        $system = <<<PROMPT
You parse commercial requests for tactical equipment. Extract structured data from Russian text.
Return JSON with fields:
- org_name: organization name (string or null)
- contact_person: contact name (string or null)
- contact_email: email if present (string or null)
- delivery_terms: delivery conditions if mentioned (string or null)
- items: array of {name: string, qty: int, raw_text: string}

Rules:
- Normalize product names: expand abbreviations (бж=бронежилет, ИРП=индивидуальный рацион питания)
- qty defaults to 1 if not specified
- raw_text = original text fragment for this item
- If no items found, return empty items array
PROMPT;
        return LLM::chatJson($system, $text);
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
