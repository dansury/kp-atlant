<?php
// Копируется в /bitrix/admin при установке: страница экспорта живёт в модуле
$base = $_SERVER['DOCUMENT_ROOT'];
$page = is_file($base . '/local/modules/atlant.kpsync/admin/export.php')
    ? $base . '/local/modules/atlant.kpsync/admin/export.php'
    : $base . '/bitrix/modules/atlant.kpsync/admin/export.php';
require $page;
