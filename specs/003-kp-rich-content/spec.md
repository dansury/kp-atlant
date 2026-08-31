# Spec 003 — Rich КП: product cards, photos and upsell

**Status**: Implemented
**Depends on**: `specs/001-kp-automation/spec.md` (КП generation, PDF template, MoySklad product cache)

## Context

The reference КП used by the owner (paper sample, 25.08.2026, «Жилет противоосколочный
в базовой комплектации») is far richer than what module 001 generates. Comparing the two:

| Sample КП | Module 001 output |
|---|---|
| Header with address, phone, e-mail | ИНН/ОГРН and city only |
| 5 pages of product photos | no images at all |
| Descriptive paragraph per product | product name only |
| «Характеристики» bullet list | none (free-text block only) |
| «Комплектация» list | none |
| Warranty and service paragraph | none |
| «от 40 000 руб», «от 1 шт.» | fixed price and quantity |
| Photo disclaimer footnote | none |
| Upsell: modules to add later (горжет, наплечники, защита паха, бёдер, пятиточечник) + full-kit photos | none |

Everything in the right column is what a manager currently has to paste by hand, which is
the exact work module 001 set out to remove. This module closes the gap.

## User Scenarios & Testing

### User Story 12 — КП с фотографиями продукции (Priority: P1)

Менеджер генерирует КП. Система подтягивает фотографии товара из МойСклад и вставляет
их в PDF под таблицей, с оговоркой о том, что изображения приведены для примера.

**Acceptance**
1. При генерации КП для товара с фото в МойСклад PDF содержит до 5 изображений на позицию.
2. Под галереей печатается оговорка (редактируемая на уровне КП).
3. Флаг «Включать фото» выключает галерею, не трогая остальной документ.
4. Недоступность МойСклад или отсутствие фото не ломает генерацию — КП собирается без картинок.
5. Кнопка «Перезагрузить фото из МойСклад» сбрасывает кеш и скачивает заново.

### User Story 13 — Карточка товара в КП (Priority: P1)

Под таблицей для каждой позиции печатается описание, «Характеристики» и «Комплектация»;
менеджер правит любой из блоков перед отправкой.

**Acceptance**
1. Описание и характеристики берутся из описания товара в МойСклад.
2. Если описание содержит заголовки «Характеристики:» / «Комплектация:», текст разносится
   по соответствующим блокам; иначе всё попадает в описание.
3. Правки менеджера не затираются при повторной генерации PDF.
4. Позиция без описания и фото не печатает пустую карточку.

### User Story 14 — Апселл: доукомплектование модулями (Priority: P1)

В конце КП печатается таблица дополнительных модулей, которые клиент может докупить
сразу или позже, с фотографией полной комплектации.

**Acceptance**
1. При генерации КП таблица предзаполняется товарами из папки МойСклад «Модули для бронежилетов».
2. Позиции, уже включённые в КП, в апселл не попадают.
3. Менеджер добавляет, удаляет и правит строки; снятая галочка убирает строку из PDF.
4. Модуль без цены печатается как «по запросу».
5. Флаг «Показывать блок» полностью убирает апселл из КП.

### User Story 15 — Цена «от» и гарантия (Priority: P2)

Менеджер помечает позицию как «цена от» / «количество от»; в КП печатается «от 40 000 руб.»
и «от 1 шт.», итог тоже становится «от». Отдельным блоком печатается гарантия.

**Acceptance**
1. Флаг «Цена от» у любой позиции делает «Итого» тоже «от».
2. Текст гарантии берётся из настроек и правится на уровне КП.

### Edge Cases

- Фотография удалена с диска после кеширования → путь отбрасывается, PDF собирается без неё.
- Товар без `moysklad_product_id` (ручная позиция) → карточка заполняется только вручную.
- Папка модулей переименована в МойСклад → апселл не предзаполняется; настройка `addon_category` правится в интерфейсе.
- Токен МойСклад без прав на изображения (403) → фото пропускаются молча, КП генерируется.

## Requirements

### Functional Requirements

- **FR-040**: Система MUST загружать фотографии товара из МойСклад, кешировать их на диске
  и вставлять в PDF КП (до N на позицию, N настраивается).
- **FR-041**: Система MUST печатать в шапке КП адрес, телефон и e-mail юрлица (редактируются в настройках).
- **FR-042**: Система MUST формировать карточку товара под таблицей: описание, «Характеристики»,
  «Комплектация» — с подстановкой из МойСклад и ручной правкой.
- **FR-043**: Система MUST печатать блок гарантии и обслуживания (значение по умолчанию в настройках).
- **FR-044**: Система MUST предлагать блок доукомплектования (апселл) из товаров категории-модулей
  МойСклад, с ручным редактированием состава и фотографией полной комплектации.
- **FR-045**: Система MUST поддерживать формат «от <цена>» и «от <кол-во>» на уровне позиции,
  с переносом «от» в строку «Итого».

### Non-Functional Requirements

- **NFR-009**: Фотографии MUST кешироваться на диске (`storage/product_images/`) и обновляться
  не чаще раза в 30 дней, чтобы генерация КП не зависела от времени ответа МойСклад.
- **NFR-010**: Любой сбой при получении картинок MUST быть неблокирующим — КП всегда генерируется.

## Key Entities

- **proposal_addons** — строки апселла конкретного КП: название, ед.изм., цена, признак включения.
- **products_cache.images_json / images_synced_at / is_addon** — кеш фотографий и признак товара-модуля.
- **proposal_items.description_text / specs_text / included_text / images_json** — контент карточки товара.
- **proposals.warranty_text / images_note / upsell_intro / upsell_note / show_images / show_upsell** — блоки документа.

## File map

| File | Role |
|---|---|
| `lib/kp_content.php` | Enrich items, split MoySklad descriptions, suggest and store upsell rows, build data URIs |
| `lib/moysklad.php` | `fetchProductImages()` / `productImages()` — authenticated image download + disk cache |
| `lib/pdf.php` | Assembles the new template variables |
| `templates/kp.html` | Product cards, gallery, warranty and upsell blocks |
| `public/api/proposals.php` | `addons_suggest`, `refresh_images`, extended `update` |
| `public/assets/js/app.js` | Editor for cards, photos and upsell |
| `lib/bootstrap.php` | Schema v3 migration and defaults |

## Settings

| Key | Default |
|---|---|
| `kp_images_note` | Оговорка под фотографиями |
| `kp_upsell_intro` | Вступление к блоку доукомплектования |
| `kp_upsell_note` | Подпись под фото полной комплектации |
| `default_warranty_text` | Текст гарантии и обслуживания |
| `addon_category` | `Модули для бронежилетов` |
| `kp_max_images_per_item` | `5` |
| `kp_show_images` / `kp_show_upsell` | `1` |

## Out of Scope

- Загрузка фотографий вручную через интерфейс (пока только из МойСклад).
- LLM-генерация описаний товаров — используется описание из МойСклад как есть.
- Разные наборы модулей под разные категории товара — одна папка-источник на все КП.
