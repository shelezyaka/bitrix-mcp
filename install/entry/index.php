<?php
/**
 * Точка входа MCP-сервера. Вся логика — в модуле itb.mcp.
 *
 * NO_KEEP_STATISTIC и NO_AGENT_CHECK обязательны: обращения робота не должны
 * попадать в статистику и запускать агентов.
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
