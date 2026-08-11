<?php
/** Автозагрузка классов модуля. Список явный — так виден его состав. */
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
	'Itb\\Mcp\\Expose'    => 'lib/Expose.php',
	'Itb\\Mcp\\Data'      => 'lib/Data.php',
	'Itb\\Mcp\\Tools'     => 'lib/Tools.php',
	'Itb\\Mcp\\Server'    => 'lib/Server.php',

	'Itb\\Mcp\\Orm\\TokenTable' => 'lib/Orm/TokenTable.php',
	'Itb\\Mcp\\Orm\\LogTable'   => 'lib/Orm/LogTable.php',
]);
