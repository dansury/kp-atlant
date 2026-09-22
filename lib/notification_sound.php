<?php
/**
 * Звук уведомления — свой у каждого менеджера (issue #60): один и тот же звук
 * на всех раздражает тех, кому конкретно этот звук не по душе. Пусто — общий
 * звук из настроек (MAIL_SOUND/MAIL_SOUND_VOLUME), как было раньше. Тот же
 * приоритет, что у подписи в письмах (lib/mail_signature.php).
 */
final class NotificationSound {

    /** Чем на самом деле прозвучит уведомление этому менеджеру, и что он выбрал сам. */
    public static function forManager(int $managerId): array {
        $own = $managerId > 0
            ? Db::one("SELECT notification_sound, notification_sound_volume FROM managers WHERE id=?", [$managerId])
            : null;
        $ownFile = trim((string)($own['notification_sound'] ?? ''));
        $ownVolume = $own !== null && $own['notification_sound_volume'] !== null
            ? max(0, min(100, (int)$own['notification_sound_volume']))
            : null;

        return [
            'file'        => $ownFile !== '' ? $ownFile : (string)Settings::get('MAIL_SOUND', ''),
            'volume'      => $ownVolume ?? max(0, min(100, (int)Settings::get('MAIL_SOUND_VOLUME', 60))),
            'own_file'    => $ownFile,
            'own_volume'  => $ownVolume,
        ];
    }

    public static function save(int $managerId, string $file, ?int $volume): array {
        if ($managerId <= 0) throw new InvalidArgumentException('Не указан менеджер');
        Db::update('managers', [
            'notification_sound'        => $file !== '' ? $file : null,
            'notification_sound_volume' => $volume !== null ? max(0, min(100, $volume)) : null,
            'updated_at'                => date('Y-m-d H:i:s'),
        ], 'id=?', [$managerId]);
        return self::forManager($managerId);
    }
}
