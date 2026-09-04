<?php
/**
 * Technical prompts. The defaults live in code (so a fresh deploy works), the
 * admin panel overrides them in the DB, and every save keeps the previous text
 * in prompt_history so a bad edit can be rolled back.
 */
final class Prompts {
    /** key => [title, description, placeholders[], default text] */
    public static function registry(): array {
        return [
            'parse_request' => [
                'Разбор входящего запроса',
                'Системный промпт: извлекает позиции, организацию, контакты и определяет тип (запрос КП или заказ).',
                [],
                <<<'PROMPT'
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
PROMPT,
            ],

            'cover_letter' => [
                'Сопроводительное письмо к КП',
                'Системный промпт генерации сопроводительного письма. {{tov}} — правила тона, {{few_shot}} — примеры корректур менеджера.',
                ['tov', 'few_shot'],
                <<<'PROMPT'
Ты пишешь сопроводительные письма к коммерческим предложениям Atlant Armour.
Правила тона (ToV):
{{tov}}

Формат: короткое деловое письмо на русском. 3-5 предложений. Без пафоса, с фактами.
Структура: приветствие → по вашему запросу готовы поставить → перечень кратко → готовы ответить на вопросы.{{few_shot}}
PROMPT,
            ],

            'normalize_names' => [
                'Нормализация наименований',
                'Приводит названия из запроса к виду, по которому ищется товар в каталоге МойСклад.',
                [],
                <<<'PROMPT'
Normalize product names from a tactical equipment request for search matching.
For each name: expand abbreviations, fix transliteration, remove quantities and units.
Return JSON array: [{original: string, normalized: string, category: string}]
Categories: armor, helmets, medical, pouches, backpacks, accessories, other
PROMPT,
            ],

            'followup' => [
                'Письмо-напоминание (follow-up)',
                'Системный промпт для письма вдогонку по неотвеченному КП. {{email_rules}} — правила переписки, {{tov}} — тон.',
                ['email_rules', 'tov'],
                <<<'PROMPT'
Сгенерируй follow-up письмо по правилам:
{{email_rules}}

Тон (ToV):
{{tov}}
PROMPT,
            ],
        ];
    }

    /** Effective text: admin override → built-in default. */
    public static function text(string $key): string {
        $reg = self::registry();
        if (!isset($reg[$key])) throw new InvalidArgumentException("Unknown prompt: $key");
        $row = Db::one("SELECT content FROM prompts WHERE key=?", [$key]);
        $content = $row['content'] ?? '';
        return trim($content) !== '' ? $content : $reg[$key][3];
    }

    /** Effective text with {{placeholders}} filled in. */
    public static function render(string $key, array $vars = []): string {
        $text = self::text($key);
        foreach ($vars as $name => $value) {
            $text = str_replace('{{' . $name . '}}', (string)$value, $text);
        }
        // Any placeholder left unfilled would confuse the model more than an empty string
        return trim(preg_replace('/\{\{\w+\}\}/', '', $text));
    }

    /** All prompts for the admin panel, with their source and edit history size. */
    public static function describe(): array {
        $out = [];
        foreach (self::registry() as $key => [$title, $desc, $vars, $default]) {
            $row = Db::one("SELECT p.*, m.name AS manager_name FROM prompts p
                            LEFT JOIN managers m ON m.id = p.updated_by WHERE p.key=?", [$key]);
            $custom = $row && trim((string)$row['content']) !== '';
            $out[] = [
                'key'          => $key,
                'title'        => $title,
                'description'  => $desc,
                'placeholders' => $vars,
                'content'      => $custom ? $row['content'] : $default,
                'default'      => $default,
                'is_custom'    => $custom,
                'updated_at'   => $row['updated_at'] ?? null,
                'updated_by'   => $row['manager_name'] ?? null,
                'history'      => (int)Db::val("SELECT COUNT(*) FROM prompt_history WHERE key=?", [$key]),
            ];
        }
        return $out;
    }

    /** Save an override, keeping the previous text in the history. */
    public static function save(string $key, string $content, ?int $managerId): void {
        if (!isset(self::registry()[$key])) throw new InvalidArgumentException("Unknown prompt: $key");
        $prev = Db::one("SELECT content FROM prompts WHERE key=?", [$key]);
        if ($prev && $prev['content'] !== $content) {
            Db::insert('prompt_history', ['key' => $key, 'content' => $prev['content'], 'manager_id' => $managerId]);
        }
        $now = date('Y-m-d H:i:s');
        if ($prev) {
            Db::update('prompts', ['content' => $content, 'updated_at' => $now, 'updated_by' => $managerId], 'key=?', [$key]);
        } else {
            Db::insert('prompts', ['key' => $key, 'content' => $content, 'updated_at' => $now, 'updated_by' => $managerId]);
        }
        Logger::info('prompts', "Промпт «{$key}» изменён", ['manager_id' => $managerId]);
    }

    /** Drop the override — the built-in default takes over again. */
    public static function reset(string $key, ?int $managerId): void {
        $prev = Db::one("SELECT content FROM prompts WHERE key=?", [$key]);
        if ($prev) {
            Db::insert('prompt_history', ['key' => $key, 'content' => $prev['content'], 'manager_id' => $managerId]);
            Db::q("DELETE FROM prompts WHERE key=?", [$key]);
        }
        Logger::info('prompts', "Промпт «{$key}» возвращён к встроенному", ['manager_id' => $managerId]);
    }

    public static function history(string $key, int $limit = 20): array {
        return Db::all("SELECT h.id, h.content, h.created_at, m.name AS manager_name
                        FROM prompt_history h LEFT JOIN managers m ON m.id = h.manager_id
                        WHERE h.key=? ORDER BY h.id DESC LIMIT ?", [$key, $limit]);
    }
}
