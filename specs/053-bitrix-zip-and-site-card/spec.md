# Module 053 — the Bitrix module ships as a zip from the repo, the site is managed from «Каталог»

Source: follow-up to issue #67 — «архив atlant.kpsync.zip формируется прямо в
репозитории», «откуда грузить описание — с сайта или из МойСклад, в настройках
этого не видно», «в новом модуле Битрикс не вижу выгрузки в Excel».

What was missing after module 046:

- `KP_DESCRIPTION_SOURCE` existed only in «Все параметры → Коммерческое
  предложение», with raw codes (`moysklad_first`) as the choices.
- The whole «Сайт (Битрикс)» group had no screen of its own; the endpoints
  `bitrix_diagnose` and `bitrix_sync_catalog` had no button.
- The Excel export lived only as a button on the module's settings page — no
  admin-menu entry, and no way to tell which module version the site runs.
- The module had to be zipped by hand.

## 1. Zip built in the repository

- `tools/build_bitrix_zip.php` packs `bitrix-module/atlant.kpsync/` into
  `bitrix-module/atlant.kpsync.zip` (root folder `atlant.kpsync/`, files sorted,
  fixed mtime → the same sources give the same bytes).
- `--check` compares every entry with the sources and exits 1 when the zip is
  stale or has extra/missing files. `tests/module_053.php` runs it.
- `.github/workflows/bitrix-zip.yml`: on a push to `main` touching
  `bitrix-module/atlant.kpsync/**` (and on demand) rebuilds the zip and commits
  it when it changed.
- `admin.php?action=bitrix_module_zip` (admin) streams the zip from the deployed
  copy of the repo.

## 2. «Настройки → Каталог товаров» → card «Сайт (Битрикс)» (admin)

- Status line: link on/off, webhook set or not, module version on the site
  (`ping.version`) vs the version in the repo (`Bitrix::bundledVersion()`); an
  older or unknown version says to reinstall from the zip.
- «Откуда брать описание товара» — select with readable labels, saved to
  `KP_DESCRIPTION_SOURCE` immediately:
  `moysklad_first` «Из МойСклад, а если там пусто — с сайта»,
  `bitrix_first` «С сайта, а если там пусто — из МойСклад».
- Counters: products with a site link / with a site description
  (`products.php?action=stats` → `with_site_url`, `with_site_description`).
- Buttons: «Проверить связь» (`bitrix_diagnose`), «Загрузить ссылки и описания с
  сайта» (`bitrix_sync_catalog`, repeated while `done=false`, max 20 rounds),
  «⬇ Модуль для сайта (.zip)», link to all «Сайт (Битрикс)» parameters.
- `Settings` select types accept labels: `select:value=Label,value2=Label2`
  (a value without `=` is its own label). The same labels show in «Все параметры».

## 3. Bitrix module 1.2.0

- Admin menu: «Сервисы → Атлант: экспорт товаров в Excel» (`admin/menu.php`).
  Points to `/bitrix/admin/atlant_kpsync_export.php` when installed, else to
  the module settings page (the old install without the stub).
- `admin/export.php` — page with the column list and the «Скачать .xlsx»
  button (`Export::download()` behind `check_bitrix_sessid()`).
  `install/admin/atlant_kpsync_export.php` — the stub copied to `/bitrix/admin`
  on install, removed on uninstall; it includes the page from `/local/modules`
  or `/bitrix/modules`, whichever exists.
- Settings page: shows the module version and the folder it is loaded from
  (an old copy in `/local/modules` wins over `/bitrix/modules`).
- `action=ping` returns `version`.
- Update path: upload the zip's folder over the old one, then «Удалить» +
  «Установить» in «Установленные решения» (settings and token are kept).
