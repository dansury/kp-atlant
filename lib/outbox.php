<?php
/**
 * Файлы, которые менеджер прикладывает к письму сам (модуль 023).
 *
 * Собранный сервисом КП — не последнее слово. Менеджер мог переделать документ
 * руками, приложить спецификацию заказчика или счёт из МойСклад. До сих пор
 * такое письмо приходилось отправлять из обычного почтового клиента — то есть
 * мимо архива, мимо карточки компании и мимо всей истории переписки.
 *
 * Файл кладётся в `storage/outbox/<менеджер>/` и живёт там до отправки. Наружу
 * уходит только ИМЯ файла: путь собирается здесь, из имени, очищенного
 * `basename()`, внутри папки этого менеджера, — чужой файл по такому имени не
 * достанешь, и «../» из него не выйдешь.
 */
final class Outbox {

    /** Сколько ждать неотправленные файлы, прежде чем убрать их. */
    private const KEEP_HOURS = 48;

    public static function dir(int $managerId): string {
        $dir = ROOT . '/storage/outbox/' . max(0, $managerId);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Принять загруженный файл. Возвращает то, что показывается менеджеру и
     * возвращается назад при отправке.
     *
     * @return array{name:string,filename:string,size:int}
     */
    public static function accept(array $file, int $managerId): array {
        $error = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) throw new RuntimeException('Файл не загрузился (код ' . $error . ')');

        $maxMb = max(1, (int)Settings::get('MAIL_ATTACH_MAX_MB', 25));
        $size = (int)($file['size'] ?? 0);
        if ($size > $maxMb * 1024 * 1024) {
            throw new RuntimeException("Файл больше $maxMb МБ — столько почта не принимает");
        }

        $original = self::safeName((string)($file['name'] ?? 'file'));
        // Имя на диске — своё: два менеджера, приложившие «Счёт.pdf», не должны
        // затирать файлы друг друга, а имя во вложении остаётся человеческим
        $stored = bin2hex(random_bytes(8)) . '__' . $original;
        $path = self::dir($managerId) . '/' . $stored;

        $tmp = (string)($file['tmp_name'] ?? '');
        $moved = is_uploaded_file($tmp) ? move_uploaded_file($tmp, $path) : @rename($tmp, $path);
        if (!$moved) throw new RuntimeException('Файл не удалось сохранить на сервере');

        self::sweep($managerId);
        return ['name' => $stored, 'filename' => $original, 'size' => $size];
    }

    /**
     * Положить в список вложений файл, который сервис собрал сам, — счёт из
     * МойСклад, КП, что угодно с диска (issue #38).
     *
     * Отличается от `accept()` только источником: там пришедший из браузера
     * `$_FILES`, здесь готовый путь. Оригинал остаётся на месте — копия
     * живёт своей жизнью и убирается вместе с остальными черновиками.
     */
    public static function adopt(string $path, string $filename, int $managerId): array {
        if (!is_file($path)) throw new RuntimeException('Файла нет на сервере: ' . basename($path));

        $maxMb = max(1, (int)Settings::get('MAIL_ATTACH_MAX_MB', 25));
        $size = (int)filesize($path);
        if ($size > $maxMb * 1024 * 1024) {
            throw new RuntimeException("Файл больше $maxMb МБ — столько почта не принимает");
        }

        $original = self::safeName($filename !== '' ? $filename : basename($path));
        $stored = bin2hex(random_bytes(8)) . '__' . $original;
        if (!@copy($path, self::dir($managerId) . '/' . $stored)) {
            throw new RuntimeException('Файл не удалось положить к письму');
        }

        self::sweep($managerId);
        return ['name' => $stored, 'filename' => $original, 'size' => $size];
    }

    /**
     * Имена → пути на диске. Всё, чего нет, молча пропускается: письмо не
     * должно упасть из-за файла, который уже убрали.
     *
     * @param array<int,string|array> $names
     * @return array<int,string>
     */
    public static function resolve(array $names, int $managerId): array {
        $dir = self::dir($managerId);
        $out = [];
        foreach ($names as $entry) {
            $name = is_array($entry) ? (string)($entry['name'] ?? '') : (string)$entry;
            $name = basename(trim($name));
            if ($name === '' || $name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;
            // Имя НА ДИСКЕ и имя В ПИСЬМЕ — разные вещи (модуль 040). Клиент
            // получал «14eeebfbc5257884__Счет_на_турникеты.pdf»: приставка
            // существует, чтобы два «Счёт.pdf» не затирали друг друга на
            // сервере, и в письме ей делать нечего.
            if (is_file($path)) $out[] = ['path' => $path, 'name' => self::displayName($name)];
        }
        return $out;
    }

    /** Человеческое имя файла: без служебной приставки, которой он лежит на диске. */
    public static function displayName(string $stored): string {
        return (string)preg_replace('/^[0-9a-f]{16}__/', '', basename($stored));
    }

    /** Убрать то, что приложили и не отправили. */
    public static function sweep(int $managerId): void {
        $dir = self::dir($managerId);
        $deadline = time() - self::KEEP_HOURS * 3600;
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path) && filemtime($path) < $deadline) @unlink($path);
        }
    }

    /**
     * Имя файла, безопасное для диска и для заголовка вложения.
     *
     * Разделители пути заменяются подчёркиванием, а НЕ отрезаются: «Счёт
     * №5/2026.pdf» — обычное человеческое имя счёта, и `basename()` оставил бы
     * от него «2026.pdf». После замены слэшей внутри имени их нет вовсе, так
     * что выйти из папки менеджера им всё равно нельзя.
     */
    public static function safeName(string $raw): string {
        $name = trim($raw);
        $name = (string)preg_replace('#[\\\\/:*?"<>|]+#u', '_', $name);
        $name = (string)preg_replace('/\s+/u', ' ', $name);
        // «..» в начале — попытка выйти наверх, а не часть названия
        $name = (string)preg_replace('/^[._ ]+/u', '', $name);
        $name = trim($name, ". \t");
        if ($name === '') $name = 'file';
        return mb_substr(basename($name), 0, 120);
    }
}
