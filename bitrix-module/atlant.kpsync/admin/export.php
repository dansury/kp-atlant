<?php
/**
 * Страница «Экспорт товаров в Excel» (Сервисы → Атлант).
 * Файл отдаётся до вывода шапки админки, поэтому проверки — до prolog_admin_after.
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_before.php';

use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$module_id = 'atlant.kpsync';
if (!Loader::includeModule($module_id)) {
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';
    CAdminMessage::ShowMessage(Loc::getMessage('ATLANT_KPSYNC_EXPORT_NO_MODULE'));
    require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
    die();
}
if ($APPLICATION->GetGroupRight($module_id) < 'R') $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$error = '';
if ($request->get('download') === 'Y' && check_bitrix_sessid()) {
    try {
        \Atlant\KpSync\Export::download();
        die();
    } catch (\Throwable $e) {
        $error = Loc::getMessage('ATLANT_KPSYNC_EXPORT_FAIL') . ' ' . $e->getMessage();
    }
}

$APPLICATION->SetTitle(Loc::getMessage('ATLANT_KPSYNC_EXPORT_TITLE'));
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_admin_after.php';

if ($error !== '') CAdminMessage::ShowMessage($error);
$href = $APPLICATION->GetCurPage() . '?lang=' . LANGUAGE_ID . '&download=Y&' . bitrix_sessid_get();
?>
<div class="adm-info-message-wrap"><div class="adm-info-message">
    <?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_INTRO') ?>
    <ul>
        <?php foreach (\Atlant\KpSync\Export::HEAD as $col): ?>
            <li><?= htmlspecialcharsbx($col) ?></li>
        <?php endforeach; ?>
    </ul>
    <?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_SCOPE') ?>
</div></div>
<p>
    <a class="adm-btn adm-btn-save" href="<?= htmlspecialcharsbx($href) ?>"><?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_BTN') ?></a>
    &nbsp; <a href="settings.php?mid=<?= urlencode($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>"><?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_SETTINGS') ?></a>
</p>
<?php
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/epilog_admin.php';
