<?php
/**
 * Автозагрузка классов модуля.
 *
 * ⚠️ Список явный, а не сканирование папки: так видно состав модуля, и опечатка
 * в имени класса всплывает при чтении файла, а не в бою.
 */
\Bitrix\Main\Loader::registerAutoLoadClasses('itb.mcp', [
	'Itb\\Mcp\\AuthError' => 'lib/AuthError.php',
	'Itb\\Mcp\\ToolError' => 'lib/ToolError.php',
	'Itb\\Mcp\\Schema'    => 'lib/Schema.php',
	'Itb\\Mcp\\Tool'      => 'lib/Tool.php',
	'Itb\\Mcp\\Registry'  => 'lib/Registry.php',
	'Itb\\Mcp\\Protocol'  => 'lib/Protocol.php',
	'Itb\\Mcp\\Transport' => 'lib/Transport.php',
	'Itb\\Mcp\\Auth'      => 'lib/Auth.php',
	'Itb\\Mcp\\Token'     => 'lib/Token.php',
	'Itb\\Mcp\\Audit'     => 'lib/Audit.php',
	'Itb\\Mcp\\Setup'     => 'lib/Setup.php',
	'Itb\\Mcp\\Tools'     => 'lib/Tools.php',
	'Itb\\Mcp\\Server'    => 'lib/Server.php',

	'Itb\\Mcp\\Orm\\TokenTable' => 'lib/Orm/TokenTable.php',
	'Itb\\Mcp\\Orm\\LogTable'   => 'lib/Orm/LogTable.php',
]);
