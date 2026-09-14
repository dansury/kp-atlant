<?php
/**
 * Product catalog import from a MoySklad Excel export (module 008).
 *
 * The API is the primary source of the catalog; this is the way in when it is
 * unavailable — a revoked token, a blocked host — or when the operator simply
 * wants today's price list in the base without waiting for a sync. The file is
 * the standard «Экспорт товаров» of MoySklad: one header row, then products,
 * bundles, services and variants of the same nomenclature.
 *
 * Stock is NOT imported: the export carries «Неснижаемый остаток», which is a
 * threshold, not what lies on the shelf. Whatever the API sync wrote stays.
 */
final class CatalogImport {

    /** Header → field. Only these columns are read; the rest of the export is ignored. */
    private const COLUMNS = [
        'UUID'                     => 'moysklad_id',
        'Тип'                      => 'row_type',
        'Код'                      => 'code',
        'Наименование'             => 'name',
        'Артикул'                  => 'article',
        'Единица измерения'        => 'unit',
        'Описание'                 => 'description',
        'Группы'                   => 'category',
        'Изображение'              => 'images',
        'Архивный'                 => 'archived',
        'UUID товара модификации'  => 'parent_id',
        'Код товара модификации'   => 'parent_code',
    ];

    /** Row types of the export, in our own vocabulary. */
    private const TYPES = [
        'товар'        => 'product',
        'комплект'     => 'bundle',
        'услуга'       => 'service',
        'модификация'  => 'variant',
    ];

    /**
     * Read a file and write it into products_cache.
     * $opts: price_column, with_archived, prune (drop cached rows the file lacks).
     * @return array{total:int,imported:int,skipped:int,variants:int,images:int,pruned:int,price_column:string,warnings:array}
     */
    public static function run(string $path, string $filename, array $opts = []): array {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'xlsx'        => self::readXlsx($path),
            'csv', 'txt'  => self::readCsv($path),
            default       => throw new CatalogImportException(
                'Поддерживаются только .xlsx и .csv. Выгрузите каталог из МойСклад в Excel и загрузите файл целиком.'),
        };
        // Exports sometimes open with a blank line or a title — the header is the
        // first row that actually carries text
        while ($rows && count(array_filter($rows[0], fn($v) => trim((string)$v) !== '')) < 2) {
            array_shift($rows);
        }
        if (!$rows) throw new CatalogImportException('В файле нет строк — проверьте, что выгрузка не пустая.');

        $header = array_shift($rows);
        // Колонка цены не выбрана — берём ту, что уже стоит типом цены по
        // умолчанию: это один и тот же выбор, а не две настройки (модуль 019)
        $wantColumn = trim((string)($opts['price_column'] ?? ''));
        if ($wantColumn === '') $wantColumn = trim((string)Settings::get('CATALOG_DEFAULT_PRICE_TYPE', ''));
        $map = self::mapHeader($header, $wantColumn);
        if (!isset($map['name']) && !isset($map['moysklad_id'])) {
            throw new CatalogImportException(
                'Это не похоже на выгрузку товаров МойСклад: в первой строке нет колонок «Наименование» и «UUID».');
        }

        $withArchived = !empty($opts['with_archived']);
        $report = ['total' => 0, 'imported' => 0, 'skipped' => 0, 'variants' => 0,
                   'images' => 0, 'pruned' => 0, 'price_column' => $map['price_label'] ?? '',
                   'price_types' => array_values($map['prices'] ?? []), 'warnings' => []];

        // uuid AND code → [id, name]: the export links a variant to its product by
        // «Код товара модификации» — the UUID it prints there is NOT the UUID of
        // the product's own row, so the code is the only key that actually joins
        $names   = [];
        $pending = [];   // variants whose parent has not been seen yet
        $seen    = [];

        Db::begin();
        try {
            foreach ($rows as $raw) {
                $row = self::pick($raw, $map);
                if ($row === null) continue;
                $report['total']++;

                if ($row['name'] !== '') {
                    $names[$row['moysklad_id']] = [$row['moysklad_id'], $row['name']];
                    if ($row['code'] !== '') $names[$row['code']] = [$row['moysklad_id'], $row['name']];
                }

                $isVariant = $row['type'] === 'variant';
                if ($isVariant) {
                    $parent = self::parent($row, $names);
                    if ($parent === null) { $pending[] = $row; continue; }
                    [$row['parent_id'], $parentName] = $parent;
                    $row['name'] = self::variantName($parentName, $row['characteristics']);
                }
                if ($row['name'] === '') { $report['skipped']++; continue; }
                if (!$withArchived && $row['archived']) { $report['skipped']++; continue; }

                self::upsert($row);
                $seen[$row['moysklad_id']] = true;
                $report['imported']++;
                if ($isVariant) $report['variants']++;
                if ($row['images']) $report['images']++;
            }

            // Variants listed before their product — resolve them on the second pass
            foreach ($pending as $row) {
                $parent = self::parent($row, $names);
                if ($parent === null) { $report['skipped']++; continue; }
                [$row['parent_id'], $parentName] = $parent;
                $row['name'] = self::variantName($parentName, $row['characteristics']);
                if (!$withArchived && $row['archived']) { $report['skipped']++; continue; }
                self::upsert($row);
                $seen[$row['moysklad_id']] = true;
                $report['imported']++;
                $report['variants']++;
                if ($row['images']) $report['images']++;
            }

            if (!empty($opts['prune']) && $seen) {
                $report['pruned'] = self::prune(array_keys($seen));
            }
            Db::commit();
        } catch (Throwable $e) {
            Db::rollback();
            throw $e;
        }

        if ($report['price_column'] === '') {
            $report['warnings'][] = 'Колонка с ценой не найдена — цены остались прежними.';
        } else {
            // Тип цены по умолчанию — это и есть колонка, из которой взята цена.
            // Две разные настройки означали каталог, где «Тип цены по умолчанию»
            // не совпадал ни с одной ценой в базе, и КП уходил по прайсу из
            // `products_cache.price` молча.
            Settings::set('CATALOG_DEFAULT_PRICE_TYPE', $report['price_column']);
            Settings::set('CATALOG_PRICE_COLUMN', $report['price_column']);
        }
        $report['warnings'][] = 'Остатки из файла не берутся: в выгрузке лежит неснижаемый остаток, '
                              . 'а не наличие. Наличие обновляется синхронизацией с МойСклад.';

        Logger::info('catalog', "Импорт каталога из файла: {$report['imported']} позиций", [
            'file' => $filename, 'report' => $report,
        ]);
        return $report;
    }

    // ---- Row handling ----

    /** Header row → [field => column index], plus the resolved price column. */
    private static function mapHeader(array $header, string $priceColumn): array {
        $map = [];
        $titles = [];
        foreach ($header as $i => $title) {
            $title = trim((string)$title);
            $titles[$i] = $title;
            if (isset(self::COLUMNS[$title])) $map[self::COLUMNS[$title]] = $i;
            // «Характеристика:Размер» and friends make up a variant's name
            if (str_starts_with($title, 'Характеристика:')) {
                $map['characteristics'][$i] = mb_substr($title, mb_strlen('Характеристика:'));
            }
        }

        // Все «Цена: …» разом — это типы цен МойСклад, и в базе они лежат в
        // prices_json под теми же именами, что и после синхронизации по API.
        // Иначе каталог из Excel знал ровно одну цену и «Тип цены по умолчанию»
        // выбирать было не из чего.
        foreach ($titles as $i => $title) {
            if (str_starts_with($title, 'Цена:')) $map['prices'][$i] = self::priceTypeName($title);
        }

        // Price: the operator's column first, then the usual wholesale/retail ones.
        // Имя сравнивается и с заголовком целиком, и без приставки «Цена: » —
        // настройка хранит тип цены («Опт безнал»), а в файле стоит колонка.
        $wanted = array_values(array_filter([$priceColumn, 'Опт безнал', 'Опт', 'Розница']));
        foreach ($wanted as $candidate) {
            $want = mb_strtolower(self::priceTypeName($candidate));
            foreach ($map['prices'] ?? [] as $i => $label) {
                if (mb_strtolower($label) === $want) { $map['price'] = $i; $map['price_label'] = $label; break 2; }
            }
        }
        // Any «Цена: …» will do if none of the expected ones is there
        if (!isset($map['price'])) {
            foreach ($map['prices'] ?? [] as $i => $label) {
                $map['price'] = $i; $map['price_label'] = $label; break;
            }
        }
        return $map;
    }

    /** «Цена: Опт безнал» → «Опт безнал» — так тип цены называется в МойСклад. */
    private static function priceTypeName(string $title): string {
        $t = trim($title);
        return trim((string)preg_replace('/^Цена\s*:\s*/ui', '', $t));
    }

    /** One export row → the fields products_cache stores. */
    private static function pick(array $raw, array $map): ?array {
        $get = fn(string $f) => isset($map[$f]) ? trim((string)($raw[$map[$f]] ?? '')) : '';

        $id = $get('moysklad_id');
        if ($id === '') return null;                       // a spacer row, not a product

        $chars = [];
        foreach ((array)($map['characteristics'] ?? []) as $i => $label) {
            $v = trim((string)($raw[$i] ?? ''));
            if ($v !== '' && $v !== '-') $chars[] = "$label: $v";
        }

        $prices = [];
        foreach ((array)($map['prices'] ?? []) as $i => $label) {
            $v = self::money((string)($raw[$i] ?? ''));
            if ($v > 0) $prices[$label] = $v;
        }

        $images = [];
        if (isset($map['images'])) {
            foreach (preg_split('/[;\s]+/', (string)($raw[$map['images']] ?? '')) ?: [] as $url) {
                $url = trim($url);
                if (str_starts_with($url, 'http')) $images[] = $url;
            }
        }

        return [
            'moysklad_id'     => $id,
            'type'            => self::TYPES[mb_strtolower($get('row_type'))] ?? 'product',
            'name'            => $get('name'),
            'code'            => $get('code'),
            'article'         => $get('article') ?: $get('code'),
            'unit'            => $get('unit') ?: 'шт.',
            'description'     => $get('description'),
            'category'        => $get('category'),
            'price'           => self::money(isset($map['price']) ? (string)($raw[$map['price']] ?? '') : ''),
            'prices'          => $prices,
            'archived'        => in_array(mb_strtolower($get('archived')), ['да', 'yes', '1', 'true'], true),
            'parent_id'       => $get('parent_id'),
            'parent_code'     => $get('parent_code'),
            'characteristics' => implode(', ', $chars),
            'images'          => $images,
        ];
    }

    /** «Бронежилет Бр1 (Размер: L)» — the way a manager reads a variant. */
    private static function variantName(string $parent, string $characteristics): string {
        return $characteristics === '' ? $parent : "$parent ($characteristics)";
    }

    /**
     * The product a variant belongs to, as [its id in our base, its title].
     * The stored parent_id is the id of the row we actually have, so a variant
     * can borrow its product's photos and description later on.
     */
    private static function parent(array $row, array $names): ?array {
        foreach ([$row['parent_id'], $row['parent_code']] as $key) {
            if ($key !== '' && isset($names[$key])) return $names[$key];
        }
        foreach ([
            ["SELECT moysklad_id, name FROM products_cache WHERE moysklad_id=?", $row['parent_id']],
            ["SELECT moysklad_id, name FROM products_cache WHERE code=? AND product_type<>'variant'", $row['parent_code']],
        ] as [$sql, $key]) {
            if ($key === '') continue;
            $found = Db::one($sql, [$key]);
            if ($found && $found['name'] !== '') return [$found['moysklad_id'], $found['name']];
        }
        return null;
    }

    /**
     * Write one row. Stock, reserve and the photos already downloaded from the
     * API are left alone — the file knows nothing about them.
     */
    private static function upsert(array $row): void {
        $normalized = mb_strtolower(preg_replace('/[\s\-\"\'«»()]+/u', ' ', $row['name']));
        $addonCategory = (string)(Db::val("SELECT value FROM settings WHERE key='addon_category'") ?: '');
        $isAddon = ($addonCategory !== '' && $row['category'] === $addonCategory) ? 1 : 0;

        Db::q(
            "INSERT INTO products_cache
                (moysklad_id, name, name_normalized, article, code, price, prices_json, unit, description,
                 category, product_type, parent_id, characteristics, image_urls, is_archived,
                 is_addon, source, imported_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'excel', datetime('now'), datetime('now'))
             ON CONFLICT(moysklad_id) DO UPDATE SET
                name=excluded.name, name_normalized=excluded.name_normalized,
                article=excluded.article, code=excluded.code, price=excluded.price,
                prices_json=excluded.prices_json,
                unit=excluded.unit, description=excluded.description, category=excluded.category,
                product_type=excluded.product_type, parent_id=excluded.parent_id,
                characteristics=excluded.characteristics, image_urls=excluded.image_urls,
                is_archived=excluded.is_archived, is_addon=excluded.is_addon,
                source='excel', imported_at=datetime('now'), updated_at=datetime('now')",
            [
                $row['moysklad_id'], $row['name'], trim((string)$normalized), $row['article'], $row['code'],
                $row['price'],
                $row['prices'] ? json_encode($row['prices'], JSON_UNESCAPED_UNICODE) : null,
                $row['unit'], $row['description'], $row['category'], $row['type'],
                $row['parent_id'], $row['characteristics'],
                $row['images'] ? json_encode($row['images'], JSON_UNESCAPED_UNICODE) : null,
                $row['archived'] ? 1 : 0, $isAddon,
            ]
        );
    }

    /** Drop cached rows the file does not mention — «в базе только то, что в файле». */
    private static function prune(array $keepIds): int {
        $removed = 0;
        $all = Db::all("SELECT moysklad_id FROM products_cache");
        $keep = array_flip($keepIds);
        foreach ($all as $r) {
            if (isset($keep[$r['moysklad_id']])) continue;
            // A product quoted in a KP or an order stays: deleting it would break the link
            $used = Db::val("SELECT COUNT(*) FROM proposal_items WHERE moysklad_product_id=?", [$r['moysklad_id']]);
            if ($used) continue;
            Db::q("DELETE FROM products_cache WHERE moysklad_id=?", [$r['moysklad_id']]);
            $removed++;
        }
        return $removed;
    }

    /** «12 500,00» / «12500.00» / «12 500 ₽» → 12500.0 */
    private static function money(string $raw): float {
        $s = preg_replace('/[^\d,.\-]/u', '', $raw);
        if ($s === '' || $s === null) return 0.0;
        // Russian format uses a comma for the decimal part and a space for thousands
        $s = str_replace(',', '.', $s);
        if (substr_count($s, '.') > 1) {
            $parts = explode('.', $s);
            $last = array_pop($parts);
            $s = implode('', $parts) . '.' . $last;
        }
        return round((float)$s, 2);
    }

    // ---- File readers ----

    /**
     * XLSX without a library: the sheet is streamed with XMLReader, so a 10 MB
     * export does not have to fit in memory as a DOM tree. Both string forms of
     * the format are handled — sharedStrings and inline <is><t>.
     */
    private static function readXlsx(string $path): array {
        if (!class_exists('ZipArchive')) {
            throw new CatalogImportException('На сервере нет расширения PHP zip — сохраните выгрузку в CSV и загрузите её.');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new CatalogImportException('Файл не открывается как .xlsx — возможно, это .xls старого формата. Пересохраните его в Excel как «Книга Excel (.xlsx)».');
        }

        $shared = self::sharedStrings($zip);
        $sheet = $zip->getFromName(self::firstSheetPath($zip));
        $zip->close();
        if ($sheet === false || $sheet === '') {
            throw new CatalogImportException('В файле не нашлось листа с данными.');
        }

        $rows = [];
        $reader = new XMLReader();
        $reader->XML($sheet, 'UTF-8', LIBXML_NOENT | LIBXML_NONET);
        $row = [];
        $col = 0;
        $type = '';
        $value = '';

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT) {
                switch ($reader->name) {
                    case 'row':
                        $row = [];
                        if ($reader->isEmptyElement) $rows[] = [];
                        break;
                    case 'c':
                        $col = self::colIndex((string)$reader->getAttribute('r'));
                        $type = (string)$reader->getAttribute('t');
                        $value = '';
                        break;
                    case 'v':
                    case 't':
                        $value .= $reader->readString();
                        break;
                }
            } elseif ($reader->nodeType === XMLReader::END_ELEMENT) {
                if ($reader->name === 'c') {
                    $row[$col] = $type === 's' ? ($shared[(int)$value] ?? '') : $value;
                } elseif ($reader->name === 'row') {
                    $rows[] = self::dense($row);
                }
            }
        }
        $reader->close();
        return $rows;
    }

    private static function sharedStrings(ZipArchive $zip): array {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false || $xml === '') return [];
        $out = [];
        $reader = new XMLReader();
        $reader->XML($xml, 'UTF-8', LIBXML_NOENT | LIBXML_NONET);
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'si') {
                // A styled cell splits its text over several <t> runs
                $node = new SimpleXMLElement($reader->readOuterXml());
                $text = '';
                foreach ($node->xpath('//*[local-name()="t"]') ?: [] as $t) $text .= (string)$t;
                $out[] = $text;
            }
        }
        $reader->close();
        return $out;
    }

    private static function firstSheetPath(ZipArchive $zip): string {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) return $name;
        }
        return 'xl/worksheets/sheet1.xml';
    }

    /** «BK12» → 62 (zero-based column number). */
    private static function colIndex(string $ref): int {
        $n = 0;
        for ($i = 0, $len = strlen($ref); $i < $len; $i++) {
            $c = strtoupper($ref[$i]);
            if ($c < 'A' || $c > 'Z') break;
            $n = $n * 26 + (ord($c) - 64);
        }
        return max(0, $n - 1);
    }

    /** Sparse cells → a list with holes filled, so column indexes line up. */
    private static function dense(array $row): array {
        if (!$row) return [];
        $max = max(array_keys($row));
        $out = [];
        for ($i = 0; $i <= $max; $i++) $out[$i] = $row[$i] ?? '';
        return $out;
    }

    private static function readCsv(string $path): array {
        $rows = [];
        $fh = fopen($path, 'r');
        if (!$fh) throw new CatalogImportException('Файл не читается.');
        // MoySklad writes UTF-8 with a BOM and a ';' separator
        $first = fgets($fh);
        if ($first === false) { fclose($fh); return []; }
        $first = preg_replace('/^\xEF\xBB\xBF/', '', $first);
        $sep = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        rewind($fh);
        while (($cells = fgetcsv($fh, 0, $sep)) !== false) {
            if ($cells === [null]) continue;
            $cells[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$cells[0]);
            $rows[] = array_map(fn($c) => utf8Text((string)$c), $cells);
        }
        fclose($fh);
        return $rows;
    }
}

class CatalogImportException extends RuntimeException {}
