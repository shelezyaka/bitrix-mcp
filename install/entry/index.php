<?php
/**
 * Точка входа MCP-сервера. Вся логика — в модуле itb.mcp.
 *
 * NO_KEEP_STATISTIC и NO_AGENT_CHECK обязательны: обращения робота не должны
 * попадать в статистику и запускать агентов.
 */
// Этот файл — ШАБЛОН: установщик копирует его в /mcp/. Из папки модуля он
// работать не должен, иначе получается второй эндпоинт, о котором владелец сайта
// не знает и на который не действуют правила, навешенные на /mcp/.
// Проверка стоит до пролога и не зависит от веб-сервера, в отличие от .htaccess.
if (strpos(str_replace('\\', '/', __DIR__), '/modules/') !== false) {
	http_response_code(404);
	die();
}

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
