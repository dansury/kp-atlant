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
        $mpdf->WriteHTML($html);

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

        // Таблица соответствия: запрос клиента слева, наш ответ справа.
        // Появляется, когда запрос пришёл таблицей, — решение принято по письму.
        $showMatchTable = KpContent::showMatchTable($proposal);
        $matchTable = $showMatchTable ? KpContent::matchTableRows($proposalId) : [];
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

        // Calc totals. A single "от" price makes the whole total a floor,
        // the way the reference KP prints "Итого: от 40 000 руб".
        $total = 0;
        $totalIsFrom = false;
        foreach ($items as &$item) {
            // Цена, которая печатается: базовая, затем ручная скидка, затем
            // скидка за ожидание — обе считаются друг на друга (модуль 023)
            $item['effective_price'] = Terms::price($item);
            $item['wait_note'] = Terms::note($item);
            $discount = Terms::totalDiscount($item);
            $item['discount_shown'] = $discount > 0 ? rtrim(rtrim(number_format($discount, 2, ',', ''), '0'), ',') : '';
            $item['sum'] = $item['effective_price'] * $item['quantity'];
            $total += $item['sum'];
            if (!empty($item['price_from'])) $totalIsFrom = true;
            // Photos are embedded as data URIs — mPDF cannot read storage/ paths.
            // Which of them go in is the manager's pick (proposal_items.selected_images)
            $item['gallery'] = !empty($item['show_images'])
                ? KpContent::itemGallery($item, $maxImages)
                : [];
            // The same link as a picture, for a КП that gets printed (module 017)
            $item['site_qr'] = KpContent::itemQr($item);
            // An analogue carries its own evidence into the card
            $item['alt_matched'] = KpContent::matchedSpecs($item);
            $item['alt_differs'] = KpContent::unmatchedSpecs($item);
        }
        unset($item);

        // Доставка отдельной строкой: она не входит в цену товара
        $delivery = null;
        if ((int)($proposal['delivery_on'] ?? 0) === 1) {
            $delivery = [
                'name'  => trim((string)($proposal['delivery_name'] ?? '')) ?: 'Доставка',
                'price' => (float)($proposal['delivery_price'] ?? 0),
            ];
            $total += $delivery['price'];
        }

        // The rate is МойСклад's answer, not a house default: the организация
        // says whether we charge VAT at all, and the catalog says at what rate
        $vat = $requisites['vat'] ?? [];
        $paysVat = !array_key_exists('pays_vat', $vat) || (bool)$vat['pays_vat'];
        $vatRate = $paysVat ? (int)($vat['rate'] ?? ($proposal['vat_rate'] ?? 5)) : 0;
        $vatStatement = (string)($vat['statement'] ?? ('в т.ч. НДС ' . $vatRate . '%'));
        $vatAmount = ($paysVat && $vatRate > 0) ? $total - ($total / (1 + $vatRate / 100)) : 0.0;

        // Default intro
        $introText = $proposal['intro_text'] ?: sprintf(
            'По Вашему запросу %s имеет возможность поставить следующее вещевое имущество:',
            $legal['full_name']
        );

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
            'total' => $total,
            'vatRate' => $vatRate,
            'vatAmount' => $vatAmount,
            'paysVat' => $paysVat,
            'vatStatement' => $vatStatement,
            'requisites' => $requisites,
            'showRequisites' => (int)Settings::get('KP_REQUISITES_BLOCK', 1) === 1,
            'qrSize' => max(50, (int)Settings::get('KP_QR_SIZE', 90)),
            'showMatchTable' => $showMatchTable && $matchTable,
            'matchTable' => $matchTable,
            'matchTableNote' => $matchTableNote,
            'unmatched' => $unmatched,
            'unmatchedNote' => (string)Settings::get('KP_UNMATCHED_NOTE',
                'По этим позициям запроса мы уточняем наличие, сроки и цену и вернёмся с ответом отдельно.'),
            'showSiteLink' => (int)Settings::get('KP_SHOW_SITE_LINK', 1) === 1,
            'qrHint' => trim((string)Settings::get('KP_QR_HINT', '')),
            'pageBreakPerItem' => (int)Settings::get('KP_PAGE_BREAK', 1) === 1,
            'showVatTotal' => (bool)($proposal['show_vat_total'] ?? false),
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

    // Generate KP number: YYYY-NNN
    private static function generateNumber(): string {
        $year = date('Y');
        $count = Db::val(
            "SELECT COUNT(*) FROM proposals WHERE number LIKE ?",
            ["$year-%"]
        );
        return sprintf('%s-%03d', $year, $count + 1);
    }
}
