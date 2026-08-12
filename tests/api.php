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

echo "=== разбор докблока ===\n";
// \R без модификатора u считает переводом строки байт 0x85, а он стоит вторым
// в букве «х» (D1 85): строка резалась посреди символа и ломала весь ответ.
$doc = "\t/**\n\t * Секунд до ближайших 10:00 МСК.\n\t * Прочее.\n\t */";
$line = Api::firstDocLine($doc);
is_('строка не обрывается на «х»', $line, 'Секунд до ближайших 10:00 МСК.');
is_('и остаётся валидным UTF-8', (bool)preg_match('//u', (string)$line), true);

is_('переводы CRLF', Api::firstDocLine("/**\r\n * Первая\r\n * Вторая\r\n */"), 'Первая');
is_('переводы CR',   Api::firstDocLine("/**\r * Первая\r */"), 'Первая');
is_('строки с @ пропускаются', Api::firstDocLine("/**\n * @param int \$x\n * Смысл\n */"), 'Смысл');
is_('пустой докблок', Api::firstDocLine(''), null);
is_('не строка', Api::firstDocLine(false), null);
// Докблок бывает и не в UTF-8 — разбор обязан пережить это, а не вернуть false.
is_('докблок в CP1251 не роняет разбор',
	is_string(Api::firstDocLine("/**\n * \xCF\xF0\xEE\xF7\xE5\xE5\n */")), true);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
