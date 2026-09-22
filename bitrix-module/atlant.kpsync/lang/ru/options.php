<?php
$MESS['ATLANT_KPSYNC_TAB']        = 'Настройки';
$MESS['ATLANT_KPSYNC_TAB_TITLE']  = 'Связь с сервисом коммерческих предложений';
$MESS['ATLANT_KPSYNC_OPT_ENDPOINT'] = 'Адрес для КП (вебхук)';
$MESS['ATLANT_KPSYNC_OPT_SECTION']  = 'Параметры выгрузки';

$MESS['ATLANT_KPSYNC_OPT_ENABLED']        = 'Выгрузка включена';
$MESS['ATLANT_KPSYNC_HINT_ENABLED']       = 'Выключено — адрес отвечает отказом, ссылки в КП просто не печатаются';
$MESS['ATLANT_KPSYNC_OPT_TOKEN']          = 'Токен';
$MESS['ATLANT_KPSYNC_HINT_TOKEN']         = 'Передаётся в запросе как ?token=… Пусто — адрес открыт всем, кто его знает';
$MESS['ATLANT_KPSYNC_OPT_IBLOCK_IDS']     = 'ID инфоблоков каталога';
$MESS['ATLANT_KPSYNC_HINT_IBLOCK_IDS']    = 'Через запятую. Пусто — все инфоблоки, зарегистрированные как торговый каталог';
$MESS['ATLANT_KPSYNC_OPT_ARTICLE_PROP']   = 'Свойство с артикулом';
$MESS['ATLANT_KPSYNC_HINT_ARTICLE_PROP']  = 'Код свойства элемента. CML2_ARTICLE — то, куда артикул кладёт стандартный обмен с 1С';
$MESS['ATLANT_KPSYNC_OPT_SEARCH_BY_NAME'] = 'Искать по названию, если артикул не нашёлся';
$MESS['ATLANT_KPSYNC_HINT_SEARCH_BY_NAME']= 'Сначала точное совпадение названия, затем вхождение. Артикул всегда важнее названия';
$MESS['ATLANT_KPSYNC_OPT_ACTIVE_ONLY']    = 'Только активные товары';
$MESS['ATLANT_KPSYNC_HINT_ACTIVE_ONLY']   = 'Снятый с публикации товар не должен попасть в подписанный документ';
$MESS['ATLANT_KPSYNC_OPT_SITE_URL']       = 'Адрес сайта для ссылок';
$MESS['ATLANT_KPSYNC_HINT_SITE_URL']      = 'Например https://atlant-armour.ru — без слеша в конце. Пусто — берётся из запроса';
$MESS['ATLANT_KPSYNC_OPT_EXPORT_LIMIT']   = 'Товаров в одной странице выгрузки';
$MESS['ATLANT_KPSYNC_HINT_EXPORT_LIMIT']  = 'По умолчанию 500. Меньше — если хостинг не успевает отдать страницу';
$MESS['ATLANT_KPSYNC_EXPORT']             = 'Выгрузка каталога';
$MESS['ATLANT_KPSYNC_EXPORT_BTN']         = 'Экспорт товаров в Excel';
$MESS['ATLANT_KPSYNC_EXPORT_HINT']        = 'Файл .xlsx: внешний код, название, модификации с характеристиками, описание, ссылка на сайте. Те же инфоблоки и «только активные», что ниже';
$MESS['ATLANT_KPSYNC_EXPORT_FAIL']        = 'Выгрузка не собралась:';
