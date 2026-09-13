<?php
/**
 * Class autoloading for the module. Bitrix maps a namespace to a folder and
 * loads a class the first time it is named — nothing here runs on a page that
 * does not use the module.
 */
use Bitrix\Main\Loader;

Loader::registerAutoLoadClasses('atlant.kpsync', [
    'Atlant\\KpSync\\Config'   => 'lib/config.php',
    'Atlant\\KpSync\\Catalog'  => 'lib/catalog.php',
    'Atlant\\KpSync\\Response' => 'lib/response.php',
]);
