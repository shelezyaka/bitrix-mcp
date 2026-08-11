<?php
/**
 * Состав модуля — файлы, без которых он не работает.
 *
 * ⚠️ Список ОДИН на установщик и на страницу диагностики. Две копии однажды
 * разойдутся, и тогда диагностика скажет «всё на месте» там, где установка
 * падает, — то есть будет вредна ровно в тот момент, ради которого написана.
 *
 * Пути — от корня модуля.
 */
return [
	'include.php',
	'options.php',
	'lib/Protocol.php',
	'lib/Transport.php',
	'lib/Schema.php',
	'lib/Tool.php',
	'lib/ToolError.php',
	'lib/AuthError.php',
	'lib/Registry.php',
	'lib/Auth.php',
	'lib/Token.php',
	'lib/Audit.php',
	'lib/Setup.php',
	'lib/Tools.php',
	'lib/Server.php',
	'lib/Orm/TokenTable.php',
	'lib/Orm/LogTable.php',
];
