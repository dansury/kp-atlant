<?php
/**
 * The КП edited by hand as an A4 page (module 045, issue #60).
 *
 * «Открыть» shows the document as HTML the manager can type into. A save
 * stores the whole page in `proposals.html_override`; while it is set, the PDF
 * and the Word file are built from it instead of the template
 * (`PdfGenerator::html()`). «Вернуть автоматическую сборку» clears it.
 *
 * Photos travel as data URIs, megabytes each. Before the page reaches the
 * browser they are swapped for short URLs of files in `data/kp_img/` (named
 * by content hash), and swapped back before mPDF / Html2Docx see the page — so
 * the editor round-trips kilobytes, not the photos.
 */
require_once __DIR__ . '/kp_fields.php';

final class KpEditor {

    /** Largest page accepted from the editor, photos already externalized. */
    public const MAX_BYTES = 2_000_000;

    private const IMG_URL = '/api/proposals.php?action=kp_img&h=';

    public static function imgDir(): string {
        return ROOT . '/data/kp_img';
    }

    /** A sent КП is a signed document: it is read, not edited. */
    public static function editable(array $proposal): bool {
        return !in_array((string)($proposal['status'] ?? ''), ['sent', 'order_created'], true);
    }

    /** The page for the editor: template (with field marks, module 051) or saved edit, photos as URLs. */
    public static function page(int $proposalId): string {
        return self::externalize(PdfGenerator::html($proposalId, true));
    }

    /** data: images → files in data/kp_img, src → short URL. */
    public static function externalize(string $html): string {
        return (string)preg_replace_callback(
            '#src="data:(image/(?:png|jpe?g|gif|webp|svg\+xml));base64,([A-Za-z0-9+/=\s]+)"#i',
            function (array $m): string {
                $bin = base64_decode(preg_replace('/\s+/', '', $m[2]) ?? '', true);
                if ($bin === false || $bin === '') return $m[0];
                $hash = sha1($bin);
                $dir = self::imgDir();
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $file = "$dir/$hash";
                if (!is_file($file)) {
                    file_put_contents($file, $bin);
                    file_put_contents("$file.type", strtolower($m[1]));
                }
                return 'src="' . self::IMG_URL . $hash . '"';
            },
            $html
        );
    }

    /**
     * Short URLs → data: images again, for mPDF and Word. Unknown hash → no image.
     * The editor's marks go too: an empty text slot prints nothing, the
     * zero-width space of an empty placeholder is dropped (module 051).
     */
    public static function internalize(string $html): string {
        $html = str_replace(KpFields::ZWSP, '', $html);
        $html = (string)preg_replace('#<div\b[^>]*\bdata-kp-field="[^"]*"[^>]*>(?:\s|&nbsp;|<br\s*/?>)*</div>#i', '', $html);
        return (string)preg_replace_callback('#<img\b[^>]*>#i', function (array $m): string {
            if (!preg_match('#\bsrc="[^"]*?action=kp_img&(?:amp;)?h=([0-9a-f]{40})"#i', $m[0], $h)) return $m[0];
            $data = self::image($h[1]);
            if ($data === null) return '';
            return str_replace($h[0], 'src="data:' . $data['type'] . ';base64,' . base64_encode($data['bin']) . '"', $m[0]);
        }, $html);
    }

    /** @return array{type:string,bin:string}|null */
    public static function image(string $hash): ?array {
        if (!preg_match('/^[0-9a-f]{40}$/', $hash)) return null;
        $file = self::imgDir() . '/' . $hash;
        if (!is_file($file)) return null;
        $type = is_file("$file.type") ? trim((string)file_get_contents("$file.type")) : 'image/png';
        if (!preg_match('#^image/[a-z0-9+.-]+$#', $type)) $type = 'image/png';
        return ['type' => $type, 'bin' => (string)file_get_contents($file)];
    }

    /**
     * What the editor sent, made safe to store and print: no scripts, no
     * frames, no event handlers, no javascript: links, no editor chrome.
     */
    public static function sanitize(string $html): string {
        $html = (string)preg_replace('#<(script|iframe|object|embed|form)\b.*?</\1\s*>#is', '', $html);
        $html = (string)preg_replace('#<(script|iframe|object|embed|form|base|meta\s+http-equiv)\b[^>]*>#i', '', $html);
        $html = (string)preg_replace('#<style\b[^>]*data-kp-editor[^>]*>.*?</style>#is', '', $html);
        // Разбивка на страницы редактора (модуль 051): разделитель — div со
        // span внутри, в таблице — строка с одной ячейкой
        $html = (string)preg_replace('#<tr\b[^>]*data-kp-editor-ui[^>]*>.*?</tr>#is', '', $html);
        $html = (string)preg_replace('#<div\b[^>]*data-kp-editor-ui[^>]*>.*?</div>#is', '', $html);
        // Attributes are cleaned inside tags only — the text of the КП is left alone
        $html = (string)preg_replace_callback('#<[a-z][^>]*>#i', function (array $m): string {
            $tag = (string)preg_replace('#\s+(on[a-z]+|contenteditable|spellcheck)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $m[0]);
            return (string)preg_replace('#\b(href|src)\s*=\s*(["\'])\s*javascript:[^"\']*\2#i', '$1="#"', $tag);
        }, $html);
        return trim($html);
    }

    /**
     * Store the edited page and rebuild the PDF from it. Texts marked on the
     * page go back into the КП and become defaults for the next КП (module 051).
     * @return list<string> labels of the texts that became defaults
     */
    public static function save(int $proposalId, string $html, int $managerId = 0): array {
        $html = self::sanitize($html);
        if ($html === '' || stripos($html, '<body') === false) {
            throw new InvalidArgumentException('Пустой документ — сохранять нечего');
        }
        if (strlen($html) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Документ слишком большой для сохранения');
        }
        if (stripos($html, '<!doctype') !== 0) $html = "<!DOCTYPE html>\n" . $html;
        $learned = KpFields::apply($proposalId, KpFields::extract($html), $managerId);
        Db::update('proposals', [
            'html_override'    => $html,
            'html_override_at' => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ], 'id=?', [$proposalId]);
        PdfGenerator::generate($proposalId);
        return $learned;
    }

    /** Back to the template: the next PDF/Word is built from the database again. */
    public static function reset(int $proposalId): void {
        Db::update('proposals', ['html_override' => null, 'html_override_at' => null,
                                 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$proposalId]);
        PdfGenerator::generate($proposalId);
    }
}
