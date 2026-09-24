<?php
/**
 * «Настройки модуля» in the Bitrix admin panel.
 *
 * Everything the КП service needs to be told about this particular shop:
 * which iblocks are the catalog, which property holds the артикул, and the
 * token. The endpoint address is printed here too, because that is the one
 * value an administrator has to carry over to kp.atlant-armour.ru.
 */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$module_id = 'atlant.kpsync';
Loader::includeModule($module_id);

$request = \Bitrix\Main\Context::getCurrent()->getRequest();
$rights  = $APPLICATION->GetGroupRight($module_id);
if ($rights < 'R') $APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));

$fields = [
    'ENABLED'        => ['type' => 'checkbox'],
    'TOKEN'          => ['type' => 'text', 'size' => 50],
    'IBLOCK_IDS'     => ['type' => 'text', 'size' => 30],
    'ARTICLE_PROP'   => ['type' => 'text', 'size' => 30],
    'SEARCH_BY_NAME' => ['type' => 'checkbox'],
    'ACTIVE_ONLY'    => ['type' => 'checkbox'],
    'SITE_URL'       => ['type' => 'text', 'size' => 50],
    'EXPORT_LIMIT'   => ['type' => 'text', 'size' => 8],
];

// Выгрузка каталога в Excel (issue #67) — прямо со страницы настроек
if ($request->get('export_xlsx') === 'Y' && $rights >= 'R' && check_bitrix_sessid()) {
    $APPLICATION->RestartBuffer();
    try {
        \Atlant\KpSync\Export::download();
    } catch (\Throwable $e) {
        header('Content-Type: text/plain; charset=UTF-8');
        echo Loc::getMessage('ATLANT_KPSYNC_EXPORT_FAIL') . ' ' . $e->getMessage();
    }
    die();
}

if ($request->isPost() && $request->getPost('Update') && $rights === 'W' && check_bitrix_sessid()) {
    foreach ($fields as $name => $meta) {
        $value = (string)$request->getPost($name);
        if ($meta['type'] === 'checkbox') $value = $value === 'Y' ? 'Y' : 'N';
        Option::set($module_id, $name, $value);
    }
    LocalRedirect($APPLICATION->GetCurPage()
        . '?mid=' . urlencode($module_id) . '&lang=' . LANGUAGE_ID . '&tabControl_active_tab=edit');
}

$token = (string)Option::get($module_id, 'TOKEN', '');
$host  = (string)($_SERVER['HTTP_HOST'] ?? '');
$https = ($_SERVER['HTTPS'] ?? '') === 'on';
$endpoint = ($https ? 'https://' : 'http://') . $host . '/bitrix/tools/atlant.kpsync/kp.php'
          . ($token !== '' ? '?token=' . $token : '');

// Какая копия модуля работает: старая в /local/modules перекрывает /bitrix/modules
$version = \Atlant\KpSync\Config::version();
$root    = rtrim(str_replace('\\', '/', (string)$_SERVER['DOCUMENT_ROOT']), '/');
$folder  = str_replace('\\', '/', __DIR__);
if ($root !== '' && str_starts_with($folder, $root)) $folder = substr($folder, strlen($root));
$exportPage = is_file($_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin/atlant_kpsync_export.php');

$tabControl = new CAdminTabControl('tabControl', [
    ['DIV' => 'edit', 'TAB' => Loc::getMessage('ATLANT_KPSYNC_TAB'),
     'TITLE' => Loc::getMessage('ATLANT_KPSYNC_TAB_TITLE')],
]);
$tabControl->Begin();
?>
<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>">
<?= bitrix_sessid_post() ?>
<?php $tabControl->BeginNextTab(); ?>

    <tr>
        <td width="40%"><?= Loc::getMessage('ATLANT_KPSYNC_VERSION') ?></td>
        <td><b><?= htmlspecialcharsbx($version) ?></b> · <?= htmlspecialcharsbx($folder) ?></td>
    </tr>
    <tr>
        <td width="40%"><?= Loc::getMessage('ATLANT_KPSYNC_OPT_ENDPOINT') ?></td>
        <td><input type="text" size="70" readonly value="<?= htmlspecialcharsbx($endpoint) ?>"></td>
    </tr>
    <tr>
        <td width="40%"><?= Loc::getMessage('ATLANT_KPSYNC_EXPORT') ?></td>
        <td>
            <a class="adm-btn" href="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>&amp;export_xlsx=Y&amp;<?= bitrix_sessid_get() ?>"
               ><?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_BTN') ?></a>
            <div style="color:#888;font-size:11px"><?= Loc::getMessage('ATLANT_KPSYNC_EXPORT_HINT') ?></div>
            <div style="color:#888;font-size:11px"><?= Loc::getMessage($exportPage ? 'ATLANT_KPSYNC_EXPORT_MENU' : 'ATLANT_KPSYNC_EXPORT_REINSTALL') ?></div>
        </td>
    </tr>
    <tr class="heading"><td colspan="2"><?= Loc::getMessage('ATLANT_KPSYNC_OPT_SECTION') ?></td></tr>

<?php foreach ($fields as $name => $meta):
    $value = (string)Option::get($module_id, $name, \Atlant\KpSync\Config::DEFAULTS[$name] ?? ''); ?>
    <tr>
        <td width="40%"><?= Loc::getMessage('ATLANT_KPSYNC_OPT_' . $name) ?></td>
        <td>
            <?php if ($meta['type'] === 'checkbox'): ?>
                <input type="hidden" name="<?= $name ?>" value="N">
                <input type="checkbox" name="<?= $name ?>" value="Y" <?= $value === 'Y' ? 'checked' : '' ?>>
            <?php else: ?>
                <input type="text" name="<?= $name ?>" size="<?= (int)$meta['size'] ?>"
                       value="<?= htmlspecialcharsbx($value) ?>">
            <?php endif; ?>
            <div style="color:#888;font-size:11px"><?= Loc::getMessage('ATLANT_KPSYNC_HINT_' . $name) ?></div>
        </td>
    </tr>
<?php endforeach; ?>

<?php $tabControl->Buttons(); ?>
    <input type="submit" name="Update" value="<?= Loc::getMessage('MAIN_SAVE') ?>"
           class="adm-btn-save" <?= $rights === 'W' ? '' : 'disabled' ?>>
<?php $tabControl->End(); ?>
</form>
