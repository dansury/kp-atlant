<?php
/**
 * Подпись под коммерческим предложением (модуль 022).
 *
 * Подпись была одна на всю компанию и печаталась как
 * «_______________ / Сурков Кирилл Александрович» — прочерк под каждым КП,
 * кто бы его ни сделал. Прочерк убран: пустое место под подпись в подписанном
 * документе читается как незаполненный бланк.
 *
 * Теперь подпись принадлежит МЕНЕДЖЕРУ, и он сам выбирает, чем подписывать
 * свои КП (модуль 051): «Без подписи» (по умолчанию), «Моя подпись» или
 * «Подпись организации». Подписи организации по умолчанию больше нет.
 *
 * Картинка кладётся в `storage/signatures/manager-<id>.<ext>` — вне
 * репозитория, как и логотипы: деплой перезаписывает `public/`.
 */
final class Signatures {

    public const DEFAULT_NAME = 'Сурков Кирилл Александрович';
    public const MODES = ['none', 'own', 'company'];
    private const EXTENSIONS = ['png', 'jpg', 'jpeg'];

    public static function dir(): string {
        $dir = ROOT . '/storage/signatures';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Чем подписывается это КП: картинка (data:URI или '') и расшифровка —
     * по выбору менеджера КП. КП без менеджера не подписывается.
     *
     * @return array{image:string,name:string,manager_id:int,mode:string}
     */
    public static function forProposal(int $proposalId, array $legal = []): array {
        $managerId = (int)(Db::val("SELECT manager_id FROM proposals WHERE id=?", [$proposalId]) ?: 0);
        return self::forManager($managerId, $legal);
    }

    /** @return array{image:string,name:string,manager_id:int,mode:string} */
    public static function forManager(int $managerId, array $legal = []): array {
        $manager = $managerId > 0
            ? Db::one("SELECT id, name, signatory_name, signature_path, kp_signature_mode FROM managers WHERE id=?", [$managerId])
            : null;
        $mode = self::modeOf($manager);

        [$name, $image] = match ($mode) {
            'own'     => [trim((string)($manager['signatory_name'] ?? '')) ?: trim((string)($manager['name'] ?? '')),
                          trim((string)($manager['signature_path'] ?? ''))],
            'company' => [self::companyName($legal), trim((string)($legal['signature_path'] ?? ''))],
            default   => ['', ''],
        };

        return [
            'image'      => self::dataUri(array_filter([$image])),
            'name'       => $name,
            'manager_id' => $managerId,
            'mode'       => $mode,
        ];
    }

    /** Выбор менеджера; ничего не выбрано — без подписи. */
    public static function modeOf(?array $manager): string {
        $mode = (string)($manager['kp_signature_mode'] ?? '');
        return in_array($mode, self::MODES, true) ? $mode : 'none';
    }

    public static function setMode(int $managerId, string $mode): void {
        if (!in_array($mode, self::MODES, true)) throw new InvalidArgumentException('Неизвестный вид подписи');
        Db::update('managers', ['kp_signature_mode' => $mode, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$managerId]);
    }

    /** Ключ настройки: расшифровка организации, которую МойСклад не перепишет (модуль 048). */
    public const COMPANY_NAME_SETTING = 'kp_signatory_name';

    /** Расшифровка организации: из настроек, затем подписант МойСклад, затем по умолчанию. */
    public static function companyName(array $legal = []): string {
        $name = trim((string)(Db::val("SELECT value FROM settings WHERE key=?", [self::COMPANY_NAME_SETTING]) ?? ''));
        if ($name === '') $name = trim((string)($legal['signatory_name'] ?? ''));
        return $name !== '' ? $name : self::DEFAULT_NAME;
    }

    /** Общая подпись организации — для панели администратора. */
    public static function describeCompany(): array {
        $legal = Db::one("SELECT signatory_name, signature_path FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];
        $path = trim((string)($legal['signature_path'] ?? ''));
        return [
            'signatory_name' => (string)(Db::val("SELECT value FROM settings WHERE key=?", [self::COMPANY_NAME_SETTING]) ?? ''),
            'moysklad_name'  => trim((string)($legal['signatory_name'] ?? '')),
            'effective_name' => self::companyName($legal),
            'has_image'      => $path !== '' && is_file($path),
        ];
    }

    public static function saveCompanyName(string $name): void {
        Db::q("INSERT OR REPLACE INTO settings (key, value) VALUES (?, ?)", [self::COMPANY_NAME_SETTING, trim($name)]);
    }

    /** Сохранить общую подпись организации (только администратор). */
    public static function storeCompany(array $file, array $opts = []): string {
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, self::EXTENSIONS, true)) throw new RuntimeException('Поддерживаются файлы: png, jpg');
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Файл не загрузился');
        if (@getimagesize($tmp) === false) throw new RuntimeException('Это не картинка');

        self::forgetCompany();
        $dest = self::dir() . '/signature.' . $ext;
        $moved = ($opts['move'] ?? true) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved && !@copy($tmp, $dest)) throw new RuntimeException('Файл не сохранился в storage/signatures');
        @chmod($dest, 0644);
        Db::q("UPDATE legal_entities SET signature_path=? WHERE is_active=1", [$dest]);
        Logger::info('managers', 'Загружена общая подпись организации');
        return $dest;
    }

    public static function forgetCompany(): void {
        foreach (glob(self::dir() . '/signature.*') ?: [] as $old) @unlink($old);
        Db::q("UPDATE legal_entities SET signature_path=NULL WHERE is_active=1");
    }

    /** Первый существующий файл как data:URI — то, что печатают mPDF и Word. */
    private static function dataUri(array $paths): string {
        require_once __DIR__ . '/branding.php';
        foreach ($paths as $path) {
            if ($path === '' || !is_file($path)) continue;
            $uri = Branding::fileAsDocumentImage($path);
            if ($uri !== '') return $uri;
        }
        return '';
    }

    /**
     * Сохранить подпись менеджера. `$opts['move'] = false` — копировать, а не
     * `move_uploaded_file()`: так этим пользуются тесты и CLI.
     */
    public static function store(int $managerId, array $file, array $opts = []): string {
        if ($managerId <= 0) throw new RuntimeException('Не указан менеджер');

        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext === 'jpeg') $ext = 'jpg';
        if (!in_array($ext, self::EXTENSIONS, true)) {
            throw new RuntimeException('Поддерживаются файлы: png, jpg');
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('Файл не загрузился');
        if (@getimagesize($tmp) === false) throw new RuntimeException('Это не картинка');

        self::forget($managerId);
        $dest = self::dir() . '/manager-' . $managerId . '.' . $ext;
        $moved = ($opts['move'] ?? true) ? @move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
        if (!$moved && !@copy($tmp, $dest)) throw new RuntimeException('Файл не сохранился в storage/signatures');
        @chmod($dest, 0644);

        Db::update('managers', ['signature_path' => $dest, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$managerId]);
        Logger::info('managers', 'Загружена подпись менеджера', ['manager_id' => $managerId]);
        return $dest;
    }

    /** Убрать картинку подписи — расшифровка остаётся. */
    public static function forget(int $managerId): void {
        foreach (glob(self::dir() . '/manager-' . $managerId . '.*') ?: [] as $old) @unlink($old);
        Db::update('managers', ['signature_path' => null], 'id=?', [$managerId]);
    }

    /** Состояние для панели: что подпишет КП этого менеджера. */
    public static function describe(int $managerId): array {
        $legal = Db::one("SELECT signatory_name, signature_path FROM legal_entities WHERE is_active=1 LIMIT 1") ?: [];
        $row = Db::one("SELECT signatory_name, signature_path, kp_signature_mode FROM managers WHERE id=?", [$managerId]) ?: [];
        $resolved = self::forManager($managerId, $legal);
        return [
            'manager_id'     => $managerId,
            'mode'           => self::modeOf($row),
            'signatory_name' => (string)($row['signatory_name'] ?? ''),
            'has_image'      => trim((string)($row['signature_path'] ?? '')) !== ''
                                && is_file((string)$row['signature_path']),
            'effective_name' => $resolved['name'],
            'default_name'   => self::companyName($legal),
            'has_company_image' => trim((string)($legal['signature_path'] ?? '')) !== ''
                                   && is_file((string)$legal['signature_path']),
        ];
    }
}
