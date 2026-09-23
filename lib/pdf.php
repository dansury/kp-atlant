<?php
/**
 * PDF generator: renders KP template with mPDF.
 */
use Mpdf\Mpdf;

require_once __DIR__ . '/kp_content.php';
require_once __DIR__ . '/requisites.php';
require_once __DIR__ . '/signatures.php';
require_once __DIR__ . '/terms.php';
require_once __DIR__ . '/kp_terms.php';
require_once __DIR__ . '/delivery_share.php';

class PdfGenerator {

    // Generate PDF for a proposal, return file path
    public static function generate(int $proposalId): string {
        $html = self::html($proposalId);
        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];

        // Generate PDF
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 10,
            'margin_bottom' => 15,
            'default_font' => 'dejavusans',
            'tempDir' => ROOT . '/data/tmp',
        ]);
        $mpdf->SetTitle('Коммерческое предложение');
        $mpdf->SetAuthor($legal['short_name'] ?? 'Atlant Armour');
        self::writeHtml($mpdf, $html, $proposalId);

        // Save to file
        $dir = ROOT . '/data/kp';
        if (!is_dir($dir)) mkdir($dir, 0755, true);

        $proposal = Db::one("SELECT number, pdf_path FROM proposals WHERE id=?", [$proposalId]);
        $path = "$dir/" . self::fileName($proposalId, 'pdf');
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

        $number = $proposal['number'] ?: self::generateNumber();

        // В имени файла стоит дата (модуль 022), поэтому перевыпуск назавтра —
        // это НОВЫЙ файл. Вчерашний не оставляем: в `data/kp` иначе копится по
        // документу на каждое нажатие «Сохранить».
        $was = (string)($proposal['pdf_path'] ?? '');
        if ($was !== '' && $was !== $path && is_file($was) && str_starts_with($was, $dir . '/')) @unlink($was);

        // Update proposal
        Db::update('proposals', [
            'number' => $number,
            'pdf_path' => $path,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id=?', [$proposalId]);

        return $path;
    }

    /**
     * Отдать документ mPDF так, чтобы он собрался (модуль 034).
     *
     * mPDF режет HTML регулярными выражениями, а КП несёт фотографии товаров
     * прямо в разметке, в base64: пять карточек по 400 КБ — и разметка
     * перестаёт помещаться в `pcre.backtrack_limit`, который по умолчанию
     * равен одному мегабайту. Сборка падала с «The HTML code size is larger
     * than pcre.backtrack_limit», а менеджер видел «ничего не происходит».
     *
     * Сначала поднимаем предел под размер этого документа. Если хостинг не даёт
     * его поднять (`ini_set` закрыт) — собираем КП без фотографий: документ без
     * картинок отправить можно, а отсутствующий нельзя.
     */
    private static function writeHtml(Mpdf $mpdf, string $html, int $proposalId): void {
        self::raisePcreLimits(strlen($html));
        try {
            $mpdf->WriteHTML($html);
            return;
        } catch (\Throwable $e) {
            if (!str_contains($e->getMessage(), 'pcre.backtrack_limit')) throw $e;
        }

        $light = self::withoutPhotos($html);
        Logger::warning('kp', 'КП собрано без фотографий: разметка с ними не помещается в pcre.backtrack_limit',
                        ['proposal_id' => $proposalId, 'bytes' => strlen($html)]);
        $mpdf->WriteHTML($light);
    }

    /** Поднять пределы PCRE под размер разметки — молча, если хостинг не даёт. */
    private static function raisePcreLimits(int $bytes): void {
        $need = max(1_000_000, $bytes * 4);
        foreach (['pcre.backtrack_limit', 'pcre.recursion_limit'] as $key) {
            if ((int)ini_get($key) < $need) @ini_set($key, (string)$need);
        }
    }

    /** Та же разметка без фотографий товаров: знак, QR и подпись остаются. */
    private static function withoutPhotos(string $html): string {
        return (string)preg_replace_callback(
            '#<img\b[^>]*>#i',
            fn(array $m) => preg_match('/class="[^"]*\b(logo|qr|sign-img)\b/i', $m[0]) ? $m[0] : '',
            $html
        );
    }

    /**
     * The document as HTML, before mPDF turns it into glyphs.
     *
     * Split out from generate() so what the client will read can be asserted on
     * directly: a PDF stores Cyrillic as glyph indices, so nothing can be
     * checked in the file itself. Same variables, same template — this IS the
     * КП, one step earlier.
     */
    public static function html(int $proposalId): string {
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) throw new RuntimeException("Proposal $proposalId not found");

        // КП, поправленное руками в предпросмотре (модуль 045): PDF и Word
        // собираются из него, пока менеджер не вернёт автоматическую сборку
        if (trim((string)($proposal['html_override'] ?? '')) !== '') {
            require_once __DIR__ . '/kp_editor.php';
            return KpEditor::internalize((string)$proposal['html_override']);
        }

        // Свёрнутые позиции в документ не печатаются: ни строкой таблицы, ни
        // карточкой, ни рублём в «Итого». Названы они отдельным блоком —
        // `KpContent::unmatchedRows()` забирает их себе (модуль 020).
        $items = KpContent::printedItems($proposalId);
        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1");
        if (!$legal) throw new RuntimeException('No active legal entity configured');

        // НДС, реквизиты, банк, адреса и договор — снимок, сделанный при
        // создании КП (module 013). Печатается ровно то, с чем документ
        // подписывали; сегодняшние изменения в МойСклад его не переписывают.
        $requisites = Requisites::forProposal($proposalId);

        // Таблица соответствия запросу в документе больше не печатается — её
        // заменило курсивное (теперь жирное) название под нашей позицией
        // (issue #60). Настройка и данные для неё остаются в базе нетронутыми,
        // печать просто больше её не запрашивает.
        $showMatchTable = false;
        $matchTable = [];
        $matchTableNote = (string)($proposal['match_table_note'] ?? '');

        // Позиции запроса, на которые каталог не ответил (module 018). Печатаются
        // отдельным блоком словами клиента: КП с молчаливой дырой — это КП,
        // в котором клиент сам должен заметить, что его просьбу потеряли.
        $unmatched = KpContent::unmatchedRows($proposalId);

        // Сколько фотографий печатать. Настройка задаёт общий потолок, а
        // редактор КП может поставить свой на ЭТОТ документ (модуль 023):
        // раз количество ограничивается в настройках, ограничивать его должно
        // быть можно и на сборке.
        $maxImages = $proposal['photos_per_item'] !== null && $proposal['photos_per_item'] !== ''
            ? max(0, (int)$proposal['photos_per_item'])
            : (int)(Db::val("SELECT value FROM settings WHERE key='kp_max_images_per_item'") ?: 5);
        $addons = Db::all(
            "SELECT * FROM proposal_addons WHERE proposal_id=? AND is_selected=1 ORDER BY position",
            [$proposalId]
        );

        // Ссылку на сайт читает и таблица, и карточка товара, и «есть ли вообще
        // приложение» — значение берётся один раз, до цикла (модуль 034)
        $showSiteLink = (int)Settings::get('KP_SHOW_SITE_LINK', 1) === 1;

        // Calc totals. A single "от" price makes the whole total a floor,
        // the way the reference KP prints "Итого: от 40 000 руб".
        $total = 0;
        $totalIsFrom = false;
        foreach ($items as &$item) {
            // Цена, которая печатается: базовая, затем ручная скидка, затем
            // скидка за ожидание — обе считаются друг на друга (модуль 023)
            $item['effective_price'] = Terms::price($item);
            // Верх вилки, когда цена стоит только на модификациях и они стоят
            // по-разному (модуль 036). Ноль — вилки нет, печатается одна цена
            $item['effective_price_max'] = Terms::priceTop($item);
            $item['wait_note'] = Terms::note($item);
            // Авто-«под заказ» не печатается второй раз перед условиями ожидания,
            // которые начинаются теми же словами (модуль 034)
            $item['notes'] = Terms::itemNote($item);
            $discount = Terms::totalDiscount($item);
            $item['discount_shown'] = $discount > 0 ? rtrim(rtrim(number_format($discount, 2, ',', ''), '0'), ',') : '';
            $item['sum'] = $item['effective_price'] * $item['quantity'];
            $item['sum_max'] = $item['effective_price_max'] * $item['quantity'];
            $total += $item['sum'];
            // «Итого» считается по низу вилки и честно называется «от»: сложить
            // верх с низом — это третья сумма, которой в предложении нет
            if (!empty($item['price_from']) || $item['effective_price_max'] > 0) $totalIsFrom = true;
            // Photos are embedded as data URIs — mPDF cannot read storage/ paths.
            // Which of them go in is the manager's pick (proposal_items.selected_images)
            $item['gallery'] = !empty($item['show_images'])
                ? KpContent::itemGallery($item, $maxImages)
                : [];
            // The same link as a picture, for a КП that gets printed (module 017)
            $item['site_qr'] = KpContent::itemQr($item);
            // Чем клиент называл то, вместо чего стоит наша позиция (модуль
            // 036). Печатается над названием, только когда строка отмечена
            // аналогом; пустое поле означает, что клиент назвал это так же,
            // как мы, — и повторять его нечего
            $item['analog_of'] = (int)($item['is_alternative'] ?? 0) === 1
                ? trim((string)(($item['alt_of'] ?? '') ?: ($item['requested_name'] ?? '')))
                : '';
            if (mb_strtolower($item['analog_of']) === mb_strtolower(trim((string)$item['product_name']))) {
                $item['analog_of'] = '';
            }
            // An analogue carries its own evidence into the card
            $item['alt_matched'] = KpContent::matchedSpecs($item);
            $item['alt_differs'] = KpContent::unmatchedSpecs($item);

            // Описание карточки: комментарий МЕНЕДЖЕРА, если он его написал,
            // иначе описание из МойСклад (модуль 032). Печатается один блок.
            $item['card_desc'] = trim((string)($item['comment_text'] ?? '')) !== ''
                ? (string)$item['comment_text'] : (string)($item['description_text'] ?? '');
            // Есть ли этой позиции что показать в приложении №1 (модуль 034)
            $item['has_card'] = trim((string)$item['card_desc']) !== ''
                || !empty($item['specs_text']) || !empty($item['included_text'])
                || !empty($item['gallery']) || !empty($item['is_alternative'])
                || ($showSiteLink && !empty($item['site_url']));
        }
        unset($item);

        // Приложение печатается, только когда в нём есть хоть одна карточка:
        // пустая страница «Приложение №1» в подписанном документе — брак
        $hasAppendix = false;
        foreach ($items as $row) { if (!empty($row['has_card'])) { $hasAppendix = true; break; } }

        // Доставка — отдельной строкой (не входит в цену товара) либо
        // распределена по позициям (issue #60). Включённая в стоимость, она
        // входит в ЦЕНУ за единицу, а не только в сумму строки: «цена × кол-во»
        // в таблице обязана сходиться с «Суммой» (модуль 045). Те же доли
        // получают текст КП в письме и счёт — `DeliveryShare`.
        $delivery = null;
        if ((int)($proposal['delivery_on'] ?? 0) === 1) {
            $delivery = [
                'name'  => trim((string)($proposal['delivery_name'] ?? '')) ?: 'Доставка',
                'price' => (float)($proposal['delivery_price'] ?? 0),
            ];
            if (DeliveryShare::included($proposal) && $items) {
                $items = array_values($items);
                $shares = DeliveryShare::perUnit(array_map(
                    fn($r) => ['unit' => (float)$r['effective_price'], 'qty' => (float)$r['quantity']], $items),
                    $delivery['price']);
                if (array_sum($shares) > 0) {
                    foreach ($items as $k => &$row) {
                        if ($shares[$k] <= 0) continue;
                        $keep = 1 - Terms::totalDiscount($row) / 100;
                        $row['effective_price'] = round($row['effective_price'] + $shares[$k], 2);
                        $row['price'] = $keep > 0 && $keep < 1
                            ? round($row['effective_price'] / $keep, 2) : $row['effective_price'];
                        if ($row['effective_price_max'] > 0) {
                            $row['effective_price_max'] = round($row['effective_price_max'] + $shares[$k], 2);
                            $row['price_max'] = $keep > 0 && $keep < 1
                                ? round($row['effective_price_max'] / $keep, 2) : $row['effective_price_max'];
                        }
                        $row['sum'] = $row['effective_price'] * (float)$row['quantity'];
                        $row['sum_max'] = $row['effective_price_max'] * (float)$row['quantity'];
                    }
                    unset($row);
                    // Учтена в позициях — отдельной строкой таблицы не печатается
                    $delivery = null;
                }
            }
        }
        // Вилка печатается, только когда модификации стоят по-разному: max ==
        // price — это один товар, «от 600 до 600» (issue #67)
        foreach ($items as &$row) {
            $row['price_top'] = KpContent::hasRange((float)$row['price'], (float)($row['price_max'] ?? 0))
                ? (float)$row['price_max'] : 0.0;
            if (!KpContent::hasRange((float)$row['effective_price'], (float)$row['effective_price_max'])) {
                $row['effective_price_max'] = 0.0;
            }
        }
        unset($row);
        $discountColumn = self::discountColumn($items);

        $total = 0.0;
        foreach ($items as $row) $total += (float)$row['sum'];
        if ($delivery) $total += $delivery['price'];

        // «Не наша номенклатура» (issue #60): строка клиента печатается в
        // таблице серым жирным, с прочерками — видно, что её прочитали
        $outOfScope = KpContent::outOfScopeRows($proposal);
        $tableRows = KpContent::interleave($items, $outOfScope);

        // The rate is МойСклад's answer, not a house default: the организация
        // says whether we charge VAT at all, and the catalog says at what rate.
        // Печатается он ВСЕГДА и в одном из двух видов — «в т.ч. НДС» или «НДС
        // сверху» (модуль 030); считается в одном месте на весь сервис.
        $vat = $requisites['vat'] ?? [];
        if (!array_key_exists('rate', $vat)) $vat['rate'] = (int)($proposal['vat_rate'] ?? 5);
        $vatTotals = Requisites::vatTotals($total, $vat, Requisites::vatMode($proposal));

        // Default intro
        // Короткое имя, а не «ОБЩЕСТВО С ОГРАНИЧЕННОЙ ОТВЕТСТВЕННОСТЬЮ …»: так
        // названа компания в шапке документа и в образце КП (модуль 034)
        $introText = $proposal['intro_text'] ?: self::defaultIntro($requisites, $legal);

        // Условия поставки — один правимый блок (модуль 026). КП, собранное до
        // него, печатает те же четыре абзаца, что и печатало: документ,
        // переоткрытый через полгода, обязан выглядеть как подписанный.
        $termsText = KpTerms::forProposal($proposal);

        $imagesNote = $proposal['images_note']
            ?: Db::val("SELECT value FROM settings WHERE key='kp_images_note'") ?: '';
        $upsellIntro = $proposal['upsell_intro']
            ?: Db::val("SELECT value FROM settings WHERE key='kp_upsell_intro'") ?: '';
        $upsellNote = $proposal['upsell_note']
            ?: Db::val("SELECT value FROM settings WHERE key='kp_upsell_note'") ?: '';

        // "Full kit" photos under the upsell table come from the addon products
        $upsellGallery = [];
        if ((bool)($proposal['show_images'] ?? 1)) {
            foreach ($addons as $addon) {
                if (empty($addon['moysklad_product_id'])) continue;
                $cached = Db::val("SELECT images_json FROM products_cache WHERE moysklad_id=?",
                    [$addon['moysklad_product_id']]);
                foreach (KpContent::imagesForPdf($cached ?: null, 1) as $img) {
                    $upsellGallery[] = $img;
                }
                if (count($upsellGallery) >= 2) break;   // two photos, as in the sample
            }
        }

        // Логотип слева вверху документа (модули 020 и 022).
        //
        // Раньше путь брался как `$legal['logo_path'] ?? <по умолчанию>`, а в
        // базе там стоит пустая строка, а не NULL: `??` её пропускал, запасной
        // путь не проверялся, и КП уходило вообще без логотипа. Затем нашлась
        // вторая, тихая причина: mPDF рисует ПРОЗРАЧНЫЙ PNG только через GD, а
        // без неё выбрасывает картинку без единой строчки в логе. Поэтому знак
        // берётся у `Branding` уже сведённым на белое — `documentImage()`.
        $logo = self::logoDataUri($legal);
        if ($logo === '') {
            Logger::warning('kp', 'КП печатается без логотипа: знак не найден или не читается',
                            ['proposal_id' => $proposalId, 'logo_path' => (string)($legal['logo_path'] ?? '')]);
        }

        // Подпись менеджера, который делает это КП, а не одна на всю компанию
        // (модуль 022): у каждого своя картинка и своя расшифровка, а по
        // умолчанию — подписант организации.
        $signatory = Signatures::forProposal($proposalId, $legal);
        $signaturePath = $signatory['image'];

        // Render template
        $templateVars = [
            'legal' => $legal,
            'logo' => $logo,
            'introText' => $introText,
            'preTableText' => $proposal['pre_table_text'] ?? '',
            'postTableText' => $proposal['post_table_text'] ?? '',
            'items' => $items,
            'tableRows' => $tableRows,
            'discountColumn' => $discountColumn,
            'total' => $total,
            // Подпись колонки цены и строки под таблицей — одним куском оттуда,
            // где налог посчитан: документ не складывает его во второй раз
            'vatStatement' => $vatTotals['column'],
            'vatLines' => $vatTotals['lines'],
            'requisites' => $requisites,
            'showRequisites' => (int)Settings::get('KP_REQUISITES_BLOCK', 1) === 1,
            'qrSize' => max(50, (int)Settings::get('KP_QR_SIZE', 90)),
            'showMatchTable' => $showMatchTable && $matchTable,
            'matchTable' => $matchTable,
            'matchTableNote' => $matchTableNote,
            'unmatched' => $unmatched,
            'outOfScope' => $outOfScope,
            'unmatchedNote' => (string)Settings::get('KP_UNMATCHED_NOTE',
                'По этим позициям запроса мы уточняем наличие, сроки и цену и вернёмся с ответом отдельно.'),
            'showSiteLink' => $showSiteLink,
            'hasAppendix' => $hasAppendix,
            'qrHint' => trim((string)Settings::get('KP_QR_HINT', '')),
            'termsText' => $termsText,
            'delivery' => $delivery,
            'date' => date('d.m.Y') . 'г.',
            'signaturePath' => $signaturePath,
            'signatoryName' => $signatory['name'],
            'totalIsFrom' => $totalIsFrom,
            'showImages' => (bool)($proposal['show_images'] ?? 1),
            'imagesNote' => $imagesNote,
            'showUpsell' => (bool)($proposal['show_upsell'] ?? 1) && $addons,
            'upsellIntro' => $upsellIntro,
            'upsellNote' => $upsellNote,
            'upsellGallery' => $upsellGallery,
            'addons' => $addons,
        ];

        extract($templateVars);
        ob_start();
        include ROOT . '/templates/kp.html';
        return (string)ob_get_clean();
    }

    /**
     * Столбец «Со скидкой» (issue #67): печатается, только если скидка есть;
     * одна на все строки — процент в заголовке, разные — в каждой ячейке.
     * @return array{show:bool,uniform:string}
     */
    public static function discountColumn(array $items): array {
        $found = [];
        foreach ($items as $row) {
            $d = (string)($row['discount_shown'] ?? '');
            if ($d !== '') $found[$d] = true;
        }
        return ['show' => (bool)$found, 'uniform' => count($found) === 1 ? (string)array_key_first($found) : ''];
    }

    /**
     * «{Продавец} по запросу {покупатель} имеет возможность…» (issue #67):
     * продавец — из снимка МойСклад, покупатель — юрлицо клиента из него же.
     */
    public static function defaultIntro(array $requisites, array $legal): string {
        $seller = $requisites['seller'] ?? [];
        $sellerName = trim((string)(($seller['short_name'] ?? '') ?: ($legal['short_name'] ?? '')))
            ?: trim((string)(($seller['full_name'] ?? '') ?: ($legal['full_name'] ?? '')));
        $buyer = trim((string)(($requisites['buyer']['legal_title'] ?? '') ?: ($requisites['buyer']['name'] ?? '')));
        return $buyer !== ''
            ? sprintf('%s по запросу %s имеет возможность поставить следующее вещевое имущество:', $sellerName, $buyer)
            : sprintf('%s по Вашему запросу имеет возможность поставить следующее вещевое имущество:', $sellerName);
    }

    /**
     * Знак для шапки документа как data:URI.
     *
     * Путь из `legal_entities.logo_path` проверяется первым — это то, что
     * выбрал оператор, — и только потом в дело идёт `Branding` с загруженным и
     * встроенным файлом. Прозрачность снимается у всех одинаково: mPDF без GD
     * прозрачный PNG не печатает (модуль 022).
     */
    public static function logoDataUri(array $legal): string {
        require_once __DIR__ . '/branding.php';

        $configured = trim((string)($legal['logo_path'] ?? ''));
        if ($configured !== '' && is_file($configured) && $configured !== Branding::resolve('kp')) {
            $uri = Branding::fileAsDocumentImage($configured);
            if ($uri !== '') return $uri;
        }
        return Branding::documentImage('kp');
    }

    // Preview: return PDF as string (for streaming)
    public static function preview(int $proposalId): string {
        $path = self::generate($proposalId);
        return file_get_contents($path);
    }

    /**
     * Имя файла КП: «КП_Атлант_Армор_для_ООО_Воевода_от_14.09.2026.pdf».
     *
     * Клиент сохраняет вложение в свою папку и через неделю ищет его там среди
     * десятка других — «KP-2026-002.pdf» в такой папке не ищется никак
     * (модуль 022). Поэтому в имени стоит НАШ бренд, имя адресата и дата.
     *
     * Адресат берётся в том же порядке, в каком его знает документ:
     * юридическое название из реквизитов, затем карточка компании, затем имя
     * отправителя письма. Не знаем никого — пишем номер КП: имя файла без
     * адресата всё равно должно оставаться разным у разных документов.
     */
    public static function fileName(int $proposalId, string $ext = 'pdf'): string {
        $brand = self::translitPart((string)Settings::get('KP_FILE_BRAND', 'Атлант Армор')) ?: 'Атлант_Армор';
        $addressee = self::translitPart(self::addresseeName($proposalId));
        $date = date('d.m.Y');

        $name = 'КП_' . $brand;
        if ($addressee !== '') {
            $name .= '_для_' . $addressee;
        } else {
            $number = (string)(Db::val("SELECT number FROM proposals WHERE id=?", [$proposalId]) ?: $proposalId);
            $name .= '_' . self::translitPart($number);
        }
        return $name . '_от_' . $date . '.' . $ext;
    }

    /** Кому адресовано КП: организация или ФИО отправителя запроса. */
    public static function addresseeName(int $proposalId): string {
        $requisites = Requisites::forProposal($proposalId);
        $buyer = $requisites['buyer'] ?? [];
        foreach ([$buyer['legal_title'] ?? '', $buyer['name'] ?? ''] as $candidate) {
            $candidate = trim((string)$candidate);
            if ($candidate !== '') return $candidate;
        }

        $row = Db::one(
            "SELECT c.name AS company, c.contact_person, r.email_from
             FROM proposals p
             LEFT JOIN counterparties c ON c.id = p.counterparty_id
             LEFT JOIN requests r ON r.id = p.request_id
             WHERE p.id=?", [$proposalId]
        ) ?: [];

        foreach (['company', 'contact_person', 'email_from'] as $field) {
            $value = trim((string)($row[$field] ?? ''));
            // Адрес почты в имени файла — крайний случай: «ivanov@mail.ru»
            // читается хуже фамилии, но лучше, чем никакого адресата
            if ($field === 'email_from' && $value !== '') {
                if (preg_match('/[\w.+-]+@[\w.-]+/u', $value, $m)) $value = strstr($m[0], '@', true) ?: $m[0];
            }
            if ($value !== '') return $value;
        }
        return '';
    }

    /**
     * Кусок имени файла: кириллица остаётся кириллицей — её понимают и Windows,
     * и почта, — а всё, что ломает файловые системы и заголовок вложения
     * (слэши, кавычки, двоеточия, пробелы), становится подчёркиванием.
     */
    /**
     * То же имя латиницей — для запасного `filename=` в заголовке (модуль 035).
     *
     * `filename*=UTF-8''` понимают все нынешние браузеры, но в заголовке
     * положено оставить и ASCII-вариант. До сих пор им стояло `KP-32.docx`, и
     * всякий, кто читал заголовок буквально, сохранял файл под этим именем.
     */
    public static function asciiFileName(int $proposalId, string $ext = 'pdf'): string {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh','з'=>'z',
            'и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o','п'=>'p','р'=>'r',
            'с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c','ч'=>'ch','ш'=>'sh','щ'=>'sch',
            'ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $name = self::fileName($proposalId, $ext);
        $lower = mb_strtolower($name);
        $out = '';
        for ($i = 0, $n = mb_strlen($lower); $i < $n; $i++) {
            $ch = mb_substr($lower, $i, 1);
            $was = mb_substr($name, $i, 1);
            $latin = $map[$ch] ?? null;
            if ($latin === null) { $out .= preg_match('/[A-Za-z0-9._-]/', $was) ? $was : '_'; continue; }
            // Заглавная кириллица остаётся заглавной латиницей
            $out .= ($was !== $ch) ? ucfirst($latin) : $latin;
        }
        return (string)preg_replace('/_+/', '_', $out);
    }

    private static function translitPart(string $value): string {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = str_replace(['«', '»', '"', "'", '“', '”'], '', $value);
        $value = (string)preg_replace('#[\\\\/:*?<>|\#%&{}$!@+`=\[\]]+#u', ' ', $value);
        $value = (string)preg_replace('/[\s,.;]+/u', '_', $value);
        $value = trim($value, '_');
        // Имя файла целиком должно пережить почтовый заголовок — 60 символов
        // адресата на это с запасом хватает
        return mb_substr($value, 0, 60);
    }

    /**
     * Номер КП: ГГГГ-NNN, и он НЕ ПОВТОРЯЕТСЯ (модуль 035).
     *
     * Считался как «сколько уже есть, плюс один». Убрали одно КП — и следующее
     * получало номер только что убранного: у двух разных документов, ушедших
     * клиенту, оказывался один номер. Теперь берётся наибольший выданный за год
     * и к нему прибавляется единица; занятый номер пропускается — на случай,
     * если два КП собираются в одну секунду.
     */
    private static function generateNumber(): string {
        $year = date('Y');
        $key = 'kp_number_seq_' . $year;

        // Счётчик идёт ТОЛЬКО вперёд и живёт в настройках: считать по строкам в
        // таблице нельзя — убранное КП уносило свой номер, и его получал
        // следующий документ
        $last = (int)(Db::val("SELECT value FROM settings WHERE key=?", [$key]) ?: 0);
        foreach (Db::all("SELECT number FROM proposals WHERE number LIKE ?", ["$year-%"]) as $row) {
            if (preg_match('/^\d{4}-(\d+)$/', (string)$row['number'], $m)) {
                $last = max($last, (int)$m[1]);
            }
        }
        do {
            $number = sprintf('%s-%03d', $year, ++$last);
        } while (Db::val("SELECT 1 FROM proposals WHERE number=?", [$number]));

        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [$key, (string)$last]);
        return $number;
    }
}
