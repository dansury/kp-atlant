<?php
/** Shown once, right after installing: the address and the token to copy. */
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

$token = (string)Option::get('atlant.kpsync', 'TOKEN', '');
$host  = (string)($_SERVER['HTTP_HOST'] ?? 'atlant-armour.ru');
$https = ($_SERVER['HTTPS'] ?? '') === 'on';
$url   = ($https ? 'https://' : 'http://') . $host . '/bitrix/tools/atlant.kpsync/kp.php';
$full  = $url . ($token !== '' ? '?token=' . $token : '');
?>
<p><?= Loc::getMessage('ATLANT_KPSYNC_STEP_INTRO') ?></p>
<p><b><?= Loc::getMessage('ATLANT_KPSYNC_STEP_WEBHOOK') ?></b></p>
<p><input type="text" size="90" readonly value="<?= htmlspecialcharsbx($full) ?>"></p>
<p><?= Loc::getMessage('ATLANT_KPSYNC_STEP_NEXT') ?></p>
