<?php
/**
 * PDF generator: renders KP template with mPDF.
 */
use Mpdf\Mpdf;

require_once __DIR__ . '/kp_content.php';
require_once __DIR__ . '/requisites.php';

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

        $proposal = Db::one("SELECT number FROM proposals WHERE id=?", [$proposalId]);
        $number = $proposal['number'] ?: self::generateNumber();
        $filename = "KP-$number.pdf";
        $path = "$dir/$filename";
        $mpdf->Output($path, \Mpdf\Output\Destination::FILE);

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

        $maxImages = (int)(Db::val("SELECT value FROM settings WHERE key='kp_max_images_per_item'") ?: 5);
        $addons = Db::all(
            "SELECT * FROM proposal_addons WHERE proposal_id=? AND is_selected=1 ORDER BY position",
            [$proposalId]
        );

        // Calc totals. A single "от" price makes the whole total a floor,
        // the way the reference KP prints "Итого: от 40 000 руб".
        $total = 0;
        $totalIsFrom = false;
        foreach ($items as &$item) {
            $item['sum'] = $item['price'] * $item['quantity'];
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

        // Default conditions
        $conditionsText = $proposal['conditions_text']
            ?: Db::val("SELECT value FROM settings WHERE key='default_conditions_text'")
            ?: 'Стоимость включает расходы на упаковку, маркировку, хранение, погрузку и страхование грузов.';

        // Blocks introduced with the product-card layout. Proposal value wins,
        // then the editable default in settings.
        $warrantyText = $proposal['warranty_text']
            ?: Db::val("SELECT value FROM settings WHERE key='default_warranty_text'") ?: '';
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

        // Логотип слева вверху документа (модуль 020).
        //
        // Раньше путь брался как `$legal['logo_path'] ?? <по умолчанию>`, а в
        // базе там стоит пустая строка, а не NULL: `??` её пропускал, запасной
        // путь не проверялся, и КП уходило вообще без логотипа. Теперь адрес
        // ищется по очереди — загруженный в «Настройках», затем встроенный, —
        // и берётся первый, который существует на диске.
        $logo = '';
        foreach ([trim((string)($legal['logo_path'] ?? '')), ...self::bundledLogos()] as $candidate) {
            if ($candidate === '' || !is_file($candidate)) continue;
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) ?: 'png';
            if ($ext === 'jpg') $ext = 'jpeg';
            if ($ext === 'svg') $ext = 'svg+xml';
            $logo = 'data:image/' . $ext . ';base64,' . base64_encode((string)file_get_contents($candidate));
            break;
        }

        // Signature path
        $signaturePath = '';
        if (!empty($legal['signature_path']) && file_exists($legal['signature_path'])) {
            $sigData = base64_encode(file_get_contents($legal['signature_path']));
            $ext = pathinfo($legal['signature_path'], PATHINFO_EXTENSION);
            $signaturePath = "data:image/$ext;base64,$sigData";
        }

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
            'showVatTotal' => (bool)($proposal['show_vat_total'] ?? false),
            'conditionsText' => $conditionsText,
            'executionDays' => $proposal['execution_days'] ?? 30,
            'validityDays' => $proposal['validity_days'] ?? 14,
            'date' => date('d.m.Y') . 'г.',
            'signaturePath' => $signaturePath,
            'totalIsFrom' => $totalIsFrom,
            'showImages' => (bool)($proposal['show_images'] ?? 1),
            'imagesNote' => $imagesNote,
            'warrantyText' => $warrantyText,
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
     * Логотип, который лежит в репозитории. Он же — то, что печатается, пока
     * в «Настройках» не загрузили свой файл: КП без логотипа выглядит как
     * черновик, а имя отправителя в шапке — не замена знаку.
     */
    private static function bundledLogos(): array {
        return [
            // Загруженный в «Настройках» — сначала новый адрес, затем тот, по
            // которому логотипы лежали раньше: деплой их не перезаписывает
            ...glob(ROOT . '/storage/logo/logo.*') ?: [],
            ROOT . '/public/assets/img/logo.png',
            ROOT . '/public/assets/img/logo.jpg',
            ROOT . '/public/assets/img/logo.svg',
            // Встроенный запасной знак: КП не уходит клиенту без логотипа
            ROOT . '/public/assets/img/logo-default.png',
        ];
    }

    // Preview: return PDF as string (for streaming)
    public static function preview(int $proposalId): string {
        $path = self::generate($proposalId);
        return file_get_contents($path);
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
