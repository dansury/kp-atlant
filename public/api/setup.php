<?php
/**
 * API: мастер настройки (модуль 038).
 *
 * Ключи, ящики и логотипы ломают сервис, а не формулировку, — поэтому весь
 * мастер админский, как и «Все параметры».
 */
require_once __DIR__ . '/../../lib/bootstrap.php';
require_once ROOT . '/lib/llm.php';
require_once ROOT . '/lib/support.php';
require_once ROOT . '/lib/setup_wizard.php';

$action = $_GET['action'] ?? '';
$admin  = requireAdmin();
$input  = in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true) ? getInput() : [];

try {
    switch ($action) {

        case 'state':
            jsonData(SetupWizard::progress() + ['trial_text' => SetupWizard::TRIAL_TEXT]);

        case 'save':
            jsonOk(SetupWizard::saveStep((string)($input['step'] ?? ''), (array)($input['values'] ?? []),
                                         (int)$admin['id']));

        case 'skip':
            jsonOk(SetupWizard::skipStep((string)($input['step'] ?? '')));

        case 'start':
            SetupWizard::start((int)$admin['id']);
            jsonOk(SetupWizard::progress());

        case 'restart':
            SetupWizard::restart((int)$admin['id']);
            jsonOk(SetupWizard::progress());

        case 'finish':
            jsonOk(SetupWizard::finish((int)$admin['id']));

        /**
         * Оценка проверочных КП и письма. «Палец вниз» возвращает модели
         * ДОРОЖЕ нынешней: предложение «возьмите получше» без списка — это
         * предложение читать прайсы двух провайдеров.
         */
        case 'vote': {
            $vote = (string)($input['vote'] ?? '');
            $res  = SetupWizard::vote((string)($input['what'] ?? 'kp'), $vote, (int)$admin['id'], [
                'comment' => (string)($input['comment'] ?? ''),
                'model'   => (string)($input['model'] ?? ''),
            ]);
            $current = LLM::currentModel();
            jsonOk([
                'ticket'  => $res['ticket'],
                'current' => $current,
                'pricier' => $vote === 'down' ? LLM::pricier((string)($input['model'] ?? '')) : [],
            ] + SetupWizard::progress());
        }

        /**
         * Выбранная модель становится моделью ПО УМОЛЧАНИЮ у своего провайдера
         * и поднимает его в начало цепочки: иначе «выбрал подороже» осталось бы
         * выбором на один запрос.
         */
        case 'model': {
            [$provider, $model] = array_pad(explode(':', trim((string)($input['spec'] ?? '')), 2), 2, '');
            if (!isset(LLM::CATALOG[$provider]) || $model === '') jsonError('Неизвестная модель');

            Settings::set($provider === 'yandex' ? 'YANDEX_MODEL' : 'OPENROUTER_MODEL', $model);
            $order = array_values(array_filter(array_map('trim',
                explode(',', (string)Settings::get('LLM_PROVIDER_PRIORITY', 'yandex')))));
            $order = array_values(array_unique(array_merge([$provider], $order)));
            Settings::set('LLM_PROVIDER_PRIORITY', implode(',', $order));
            LLM::init(Settings::effective());

            Logger::info('setup', 'Модель по умолчанию: ' . $provider . ':' . $model,
                         ['manager_id' => $admin['id']]);
            jsonOk(['current' => LLM::currentModel()]);
        }

        default:
            jsonError('Unknown action', 400);
    }
} catch (Throwable $e) {
    Logger::exception('setup', $e, ['action' => $action, 'manager_id' => $admin['id']]);
    jsonError($e->getMessage());
}
