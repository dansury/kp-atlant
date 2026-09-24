<?php
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

/**
 * Installer for «Атлант: выгрузка каталога для КП».
 *
 * Installing copies the endpoint into /bitrix/tools, the export page stub into
 * /bitrix/admin, and writes a random token. Nothing in the shop's data is touched, on install or on
 * removal: the module only reads the catalog, so uninstalling it can never
 * cost anything.
 */
class atlant_kpsync extends CModule
{
    public $MODULE_ID = 'atlant.kpsync';
    public $MODULE_VERSION;
    public $MODULE_VERSION_DATE;
    public $MODULE_NAME;
    public $MODULE_DESCRIPTION;
    public $MODULE_GROUP_RIGHTS = 'N';
    public $PARTNER_NAME;
    public $PARTNER_URI;

    /** Where the endpoint lands, relative to the document root. */
    public const TOOLS_PATH = '/bitrix/tools/atlant.kpsync';

    public function __construct()
    {
        $arModuleVersion = [];
        include __DIR__ . '/version.php';

        $this->MODULE_VERSION      = $arModuleVersion['VERSION'] ?? '1.0.0';
        $this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '';
        $this->MODULE_NAME         = Loc::getMessage('ATLANT_KPSYNC_MODULE_NAME');
        $this->MODULE_DESCRIPTION  = Loc::getMessage('ATLANT_KPSYNC_MODULE_DESC');
        $this->PARTNER_NAME        = Loc::getMessage('ATLANT_KPSYNC_PARTNER_NAME');
        $this->PARTNER_URI         = 'https://atlant-armour.ru';
    }

    public function DoInstall(): bool
    {
        global $APPLICATION;

        ModuleManager::registerModule($this->MODULE_ID);
        $this->InstallFiles();

        // An endpoint with no token is open to anyone who guesses the path, so
        // one is generated here and shown once. An administrator may clear it
        // deliberately on a closed network.
        if (\Bitrix\Main\Config\Option::get($this->MODULE_ID, 'TOKEN', '') === '') {
            \Bitrix\Main\Config\Option::set($this->MODULE_ID, 'TOKEN', bin2hex(random_bytes(16)));
        }

        $APPLICATION->IncludeAdminFile(
            Loc::getMessage('ATLANT_KPSYNC_INSTALL_TITLE'),
            __DIR__ . '/step.php'
        );
        return true;
    }

    public function DoUninstall(): bool
    {
        $this->UnInstallFiles();
        // Settings are left in place: reinstalling should not cost the
        // administrator the token and the iblock list again
        ModuleManager::unRegisterModule($this->MODULE_ID);
        return true;
    }

    public function InstallFiles(): bool
    {
        CopyDirFiles(
            __DIR__ . '/tools',
            $_SERVER['DOCUMENT_ROOT'] . self::TOOLS_PATH,
            true,
            true
        );
        // «Сервисы → Атлант: экспорт товаров в Excel»
        CopyDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin', true, true);
        return true;
    }

    public function UnInstallFiles(): bool
    {
        DeleteDirFilesEx(self::TOOLS_PATH);
        DeleteDirFiles(__DIR__ . '/admin', $_SERVER['DOCUMENT_ROOT'] . '/bitrix/admin');
        return true;
    }
}
