<?php
/**
 * Точка входа MCP-сервера.
 *
 * ⚠️ Четыре строки — и это весь код, который сайт исполняет ради MCP. Логика
 * лежит в модуле `itb.mcp`. Обработчиков событий модуль не вешает, поэтому на
 * страницах магазина не выполняется НИЧЕГО из него: адрес отдельный, и мимо
 * этого файла запросы MCP не ходят.
 *
 * ⚠️ `NO_KEEP_STATISTIC` и `NO_AGENT_CHECK` обязательны: обращения робота не
 * должны попадать в статистику посещаемости и, тем более, запускать агентов —
 * агент, поднятый запросом от модели, пошёл бы делать боевую работу в момент,
 * которого никто не выбирал.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);
define('DisableEventsCheck', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (!\Bitrix\Main\Loader::includeModule('itb.mcp')) {
	http_response_code(503);
	header('Content-Type: text/plain; charset=utf-8');
	die('Модуль itb.mcp не установлен');
}

\Itb\Mcp\Server::handle();
