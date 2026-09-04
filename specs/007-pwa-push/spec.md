# 007 — Панель как приложение на телефоне: PWA и web push

## Проблема

Запросы приходят круглосуточно, а колокольчик в шапке звонит только пока открыта
вкладка. Администратор узнаёт о срочном письме, когда садится за компьютер, —
письмо про «еду в командировку, когда отправите шлем» к этому моменту уже сутки лежит.

В `kraskiweb` эта задача решена: панель ставится на экран «Домой» и присылает push
даже с закрытым браузером, на чистом PHP без единой зависимости. Модуль переносит
ту же технологию, а не изобретает свою.

## Область

- Установка панели как приложения (PWA): манифест, service worker, иконки.
- Web push администраторам: подписка на устройство, отключение по типам, тест.
- Отправка push из тех же событий, что уже создают уведомления в панели.

Вне области: push менеджерам клиента, уведомления по расписанию, офлайн-работа
с данными (кешируется только оболочка, API — никогда).

## Установка (FR-070)

- `public/manifest.webmanifest` — `display: standalone`, иконки 192/512 и maskable,
  ярлыки на «Запросы» и «Почту», тёмная тема `#17181c`.
- `public/sw.js` — кеш оболочки, `network-first` для навигации, `cache-first` для
  статики. **Запросы к `/api/` не кешируются никогда**: менеджер не должен
  действовать по устаревшему списку запросов.
- `.htaccess` отдаёт `.webmanifest` правильным типом, `sw.js` — с `no-cache`
  и `Service-Worker-Allowed: /`, иначе браузер закрепит старый воркер.
- «Настройки → Приложение на телефоне»: вердикт по-русски, кнопка установки
  (`beforeinstallprompt`), инструкция для iOS и копируемая диагностика.

## Push (FR-071)

`lib/webpush.php` — RFC 8291 (`aes128gcm`) + RFC 8292 (VAPID) на голом PHP:
`openssl` (ECDH через `openssl_pkey_derive`, ES256 через `openssl_sign`),
`hash_hkdf`, `openssl_encrypt('aes-128-gcm')`. Composer не нужен.

Пара VAPID генерируется один раз и живёт в настройках (`PUSH_VAPID_PUBLIC` /
`PUSH_VAPID_PRIVATE`, приватный ключ шифруется `Crypt`, в браузер не уходит).

`lib/push.php` — подписки, отключения, рассылка:

| Тип | Что присылает |
|---|---|
| `new_request` | новые запросы и письма |
| `order` | заказы и счета |
| `followup` | напоминания по КП |
| `system` | ошибки и служебные события |

Строка в `push_mutes` глушит один тип; `kind = NULL` — все. Отправка идёт из
`Notifier::notify()`, то есть автоматически из всех мест, которые уже уведомляют
менеджера. Ошибка push никогда не роняет синхронизацию почты: она логируется
в канал `push`. Ответ 404/410 удаляет мёртвую подписку.

## API (FR-072)

Все требуют входа:

- `GET  /api/push.php?action=key` → `{key}` — публичный ключ VAPID;
- `POST /api/push.php?action=subscribe` `{subscription:{endpoint,keys:{p256dh,auth}}}`;
- `POST /api/push.php?action=unsubscribe` `{endpoint}`;
- `GET  /api/push.php?action=prefs` → типы, отключённые, число устройств, доступность;
- `POST /api/push.php?action=mute` `{kind?, muted}`;
- `POST /api/push.php?action=test` → `{sent}` — проверка всей цепочки с этого устройства.

## Диагностика

«Уведомления не приходят» — вопрос, на который нельзя ответить без фактов, поэтому
и клиент, и сервер их выкладывают:

- клиент (в «Настройках»): протокол, `isSecureContext`, наличие `serviceWorker` /
  `PushManager` / `Notification`, выданное разрешение, ошибка регистрации воркера,
  последняя ошибка подписки, User-Agent;
- сервер («Админ → Обзор»): включён ли push, причина недоступности
  (`openssl_pkey_derive`, `curl`, выключено в настройках), число подписчиков и устройств.

Самая частая причина — HTTP вместо HTTPS: без защищённого контекста браузер
не даёт ни service worker, ни установку, ни push. Это сказано прямым текстом.

## Данные

```
push_subscriptions(id, manager_id, endpoint UNIQUE, p256dh, auth, user_agent, last_used_at, created_at)
push_mutes(id, manager_id, kind, created_at)   -- kind NULL = все типы
```

Версия схемы 9.

## Настройки

Группа `push`: `PUSH_ENABLED`, `PUSH_VAPID_PUBLIC`, `PUSH_VAPID_PRIVATE` (секрет),
`PUSH_VAPID_SUBJECT`.

## Файлы

`lib/webpush.php`, `lib/push.php`, `public/api/push.php`, `public/sw.js`,
`public/manifest.webmanifest`, `public/assets/icons/*`, `public/index.php`,
`public/assets/js/app.js`, `lib/notifier.php`, `lib/settings.php`,
`lib/bootstrap.php` (миграция v9), `.htaccess`.

## Происхождение

Порт `src/WebPush.php`, `src/Push.php`, `sw.js` и клиентского блока из
`dansury/kraskiweb` (см. там `spec/notifications.md`). Отличия: подписки привязаны
к `managers`, а не к `users`; глушение только по типу (компаний здесь нет);
ключи VAPID лежат в `Settings`, а не в `app_state`.
