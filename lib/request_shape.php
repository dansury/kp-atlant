<?php
/**
 * Did the request arrive as a TABLE or as TEXT (module 013)?
 *
 * The answer decides one thing and one thing only: whether the КП opens with a
 * «таблица соответствия» — request line on the left, our position on the right.
 * A client who sent a спецификация expects to read the answer the same way they
 * asked; a client who wrote three sentences gets a document, not a grid.
 *
 * The decision is taken from the letter itself and never from the model, so it
 * is the same on every re-generation and cannot become a question to the
 * manager. It is stored on the request (`requests.shape`) the first time it is
 * needed, and the КП editor may override it by hand — that override wins and is
 * never recomputed.
 */
final class RequestShape {

    public const TABLE = 'table';
    public const TEXT  = 'text';

    /** Spreadsheet attachments — a запрос in a file is a table by definition. */
    private const TABLE_EXT = ['xlsx', 'xlsm', 'xls', 'csv', 'ods', 'tsv'];

    /** Header words a специфiкация almost always carries. */
    private const HEADERS = ['наименование', 'кол-во', 'количество', 'ед.изм', 'ед. изм', 'артикул',
                             'позиция', 'номенклатура', 'п/п', 'характеристики', 'цена'];

    /** The stored shape, computing and remembering it on first use. */
    public static function of(int $requestId): string {
        $stored = Db::val("SELECT shape FROM requests WHERE id=?", [$requestId]);
        if ($stored === self::TABLE || $stored === self::TEXT) return (string)$stored;

        $shape = self::detect($requestId);
        Db::update('requests', ['shape' => $shape], 'id=?', [$requestId]);
        return $shape;
    }

    /** Recompute from scratch, ignoring what is stored. */
    public static function detect(int $requestId): string {
        $req = Db::one("SELECT raw_text, parsed_json FROM requests WHERE id=?", [$requestId]);
        if (!$req) return self::TEXT;

        // 1. A spreadsheet in the attachments settles it
        foreach (Db::all("SELECT filename, extracted_text FROM attachments WHERE request_id=?", [$requestId]) as $file) {
            $ext = strtolower(pathinfo((string)$file['filename'], PATHINFO_EXTENSION));
            if (in_array($ext, self::TABLE_EXT, true)) return self::TABLE;
            // A спецификация inside a PDF or a DOCX is still a table; the
            // extractor writes its rows out tab-separated, so they look like one
            if (self::looksTabular((string)$file['extracted_text'])) return self::TABLE;
        }

        // 2. The body of the letter
        if (self::looksTabular((string)$req['raw_text'])) return self::TABLE;

        return self::TEXT;
    }

    /**
     * Does this text hold a table?
     *
     * Two independent readings, because a letter pasted from Excel keeps its
     * tabs while a letter typed by hand numbers its lines instead:
     *   - two or more lines of three or more cells split by tabs / «|» / a run
     *     of spaces, or
     *   - three or more numbered lines that each end in a quantity, or
     *   - a line of specification headers («Наименование … Кол-во …»).
     */
    public static function looksTabular(string $text): bool {
        $text = trim($text);
        if ($text === '') return false;

        $lines = preg_split('/\R/u', $text) ?: [];
        $cellRows = 0;
        $numbered = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') continue;

            // A header row of a спецификация is decisive on its own
            $lower = mb_strtolower($line);
            $headerHits = 0;
            foreach (self::HEADERS as $header) {
                if (str_contains($lower, $header)) $headerHits++;
            }
            if ($headerHits >= 2) return true;

            $cells = preg_split('/\t+|\s*\|\s*|\s{3,}/u', $line) ?: [];
            $cells = array_values(array_filter($cells, fn($c) => trim($c) !== ''));
            if (count($cells) >= 3) $cellRows++;

            // «1. Бронежилет Страж — 10 шт» / «1) Шлем ... 5»
            if (preg_match('/^\d{1,3}[.)]\s+\S.*?(\d+\s*(шт|штук|компл|пар|ед)\b|\d+\s*$)/iu', $line)) {
                $numbered++;
            }
        }

        return $cellRows >= 2 || $numbered >= 3;
    }
}
