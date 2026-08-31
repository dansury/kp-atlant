<?php
/**
 * PDF generator: renders KP template with mPDF.
 */
use Mpdf\Mpdf;

require_once __DIR__ . '/kp_content.php';

class PdfGenerator {

    // Generate PDF for a proposal, return file path
    public static function generate(int $proposalId): string {
        $proposal = Db::one("SELECT * FROM proposals WHERE id=?", [$proposalId]);
        if (!$proposal) throw new RuntimeException("Proposal $proposalId not found");

        $items = Db::all("SELECT * FROM proposal_items WHERE proposal_id=? ORDER BY position", [$proposalId]);
        $legal = Db::one("SELECT * FROM legal_entities WHERE is_active=1 LIMIT 1");
        if (!$legal) throw new RuntimeException('No active legal entity configured');

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
            // Photos are embedded as data URIs — mPDF cannot read storage/ paths
            $item['gallery'] = !empty($item['show_images'])
                ? KpContent::imagesForPdf($item['images_json'] ?? null, $maxImages)
                : [];
        }
        unset($item);

        $vatRate = $proposal['vat_rate'] ?? 5;
        $vatAmount = $total - ($total / (1 + $vatRate / 100));

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

        // Logo path (base64 for mPDF)
        $logo = '';
        $logoFile = $legal['logo_path'] ?? ROOT . '/public/assets/img/logo.png';
        if (file_exists($logoFile)) {
            $logoData = base64_encode(file_get_contents($logoFile));
            $ext = pathinfo($logoFile, PATHINFO_EXTENSION);
            $logo = "data:image/$ext;base64,$logoData";
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
        $html = ob_get_clean();

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
