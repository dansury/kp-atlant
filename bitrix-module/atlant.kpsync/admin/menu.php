<?php
/**
 * Пункт «Сервисы → Атлант: экспорт товаров в Excel».
 * Без страницы в /bitrix/admin (модуль не переустановлен) ведёт в настройки модуля,
 * где та же кнопка.
 */
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

global $APPLICATION;
if ($APPLICATION->GetGroupRight('atlant.kpsync') < 'R') return false;

$page = is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/atlant_kpsync_export.php')
    ? 'atlant_kpsync_export.php?lang=' . LANGUAGE_ID
    : 'settings.php?mid=atlant.kpsync&lang=' . LANGUAGE_ID;

return [
    'parent_menu' => 'global_menu_services',
    'section'     => 'atlant_kpsync',
    'sort'        => 900,
    'text'        => Loc::getMessage('ATLANT_KPSYNC_MENU_EXPORT'),
    'title'       => Loc::getMessage('ATLANT_KPSYNC_MENU_EXPORT_TITLE'),
    'url'         => $page,
    'icon'        => 'iblock_menu_icon_types',
    'items_id'    => 'menu_atlant_kpsync',
    'more_url'    => ['atlant_kpsync_export.php'],
];
