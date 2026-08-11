<?php
// Проверка входных значений разведки API.
//
// Имя класса уходит в class_exists(), а тот запускает ВСЕ автозагрузчики сайта:
// построенный из имени путь — это подключение файла. Имя модуля идёт прямо
// в путь на диске. Оба фильтруются по набору символов, и проверяются здесь
// теми же методами, что стоят в коде, а не их копией.
require __DIR__ . '/../lib/ToolError.php';
require __DIR__ . '/../lib/Api.php';
use Itb\Mcp\Api;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	if ($got === $want) { $ok++; return; }
	$bad++;
	printf("  - %s: %s вместо %s\n", $what, var_export($got, true), var_export($want, true));
}

echo "=== имя класса ===\n";
foreach ([
	'CIBlockElement'                               => true,
	'Bitrix\\Main\\Loader'                         => true,
	'Bitrix\\Iblock\\Elements\\ElementCatalogTable' => true,
	'Itb\\Mcp\\Api'                                => true,
	// Точка не разрешена, поэтому «..» отсекается вместе с ней.
	'Bitrix\\Main\\..\\..\\evil'                   => false,
	'../../../etc/passwd'                          => false,
	'Bitrix/Main/Loader'                           => false,
	'Bitrix\\Main\\Loader; DROP TABLE'             => false,
	'Bitrix\\\\Main'                               => false,
	'Bitrix\\'                                     => false,
	'\\Bitrix\\Main'                               => false,
	' '                                            => false,
	''                                             => false,
] as $name => $want) {
	is_('класс «' . $name . '»', Api::validClass($name), $want);
}

echo "=== имя модуля ===\n";
foreach ([
	'iblock'          => true,
	'catalog'         => true,
	'itb.mcp'         => true,
	'acrit.core'      => true,
	'my-module'       => true,
	'..'              => false,
	'../../..'        => false,
	'../../bitrix'    => false,
	'iblock/../../..' => false,
	'iblock/lib'      => false,
	'/etc'            => false,
	'iblock\\..'      => false,
	''                => false,
] as $name => $want) {
	is_('модуль «' . $name . '»', Api::validModule($name), $want);
}

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
