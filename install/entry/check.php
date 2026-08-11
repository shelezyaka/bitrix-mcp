<?php
/**
 * Диагностика установки MCP-модуля. Только для администратора сайта.
 *
 * Открывать браузером, а не из консоли: у консольного PHP другой пользователь,
 * другой php.ini и другой open_basedir.
 */
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_CHECK', true);

require($_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php');

if (!$GLOBALS['USER']->IsAdmin()) {
	http_response_code(403);
	die('Только для администратора сайта');
}

header('Content-Type: text/plain; charset=utf-8');

$root = rtrim((string)\Bitrix\Main\Application::getDocumentRoot(), '/\\');
$mod  = 'itb.mcp';
$want = $root . '/local/modules/' . $mod;

function say(string $label, $value): void
{
	printf("%-38s %s\n", $label . ':', is_bool($value) ? ($value ? 'да' : 'НЕТ') : (string)$value);
}

echo "=== Кто и где ===\n";
say('DOCUMENT_ROOT по мнению Битрикса', $root);
say('DOCUMENT_ROOT по мнению веб-сервера', (string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
say('этот файл лежит в', __DIR__);
say('PHP', PHP_VERSION . ' (' . PHP_SAPI . ')');
say('пользователь процесса', function_exists('posix_geteuid') && function_exists('posix_getpwuid')
	? (posix_getpwuid(posix_geteuid())['name'] ?? '?') : 'неизвестен');
say('BX_PERSONAL_ROOT', defined('BX_PERSONAL_ROOT') ? BX_PERSONAL_ROOT : 'не задан');
say('open_basedir', ini_get('open_basedir') !== '' ? ini_get('open_basedir') : 'не ограничен');

echo "\n=== Путь до модуля, шаг за шагом ===\n";
// По частям: file_exists отвечает «нет» и когда файл есть, но в папку выше нельзя войти.
$parts = ['local', 'local/modules', 'local/modules/' . $mod,
	'local/modules/' . $mod . '/include.php',
	'local/modules/' . $mod . '/lib/Orm/TokenTable.php',
	'local/modules/' . $mod . '/install/index.php'];
foreach ($parts as $p) {
	$full = $root . '/' . $p;
	$note = [];
	if (is_link($full)) { $note[] = 'симлинк → ' . readlink($full); }
	if (file_exists($full)) {
		$note[] = 'права ' . substr(sprintf('%o', fileperms($full)), -4);
		$note[] = is_readable($full) ? 'читается' : 'НЕ ЧИТАЕТСЯ';
		if (is_dir($full)) { $note[] = @opendir($full) ? 'входим' : 'ВОЙТИ НЕЛЬЗЯ'; }
	} else {
		$note[] = 'НЕ НАЙДЕН';
	}
	printf("%-52s %s\n", $p, implode(', ', $note));
}

echo "\n=== Состав модуля ===\n";
$manifest = $want . '/install/manifest.php';
if (!file_exists($manifest)) {
	echo "Нет даже install/manifest.php — модуль не доехал совсем.\n";
	$lost = ['всё'];
} else {
	$lost = [];
	foreach ((array)require $manifest as $f) {
		if (!file_exists($want . '/' . $f)) { $lost[] = $f; }
	}
	echo $lost
		? ('НЕ ХВАТАЕТ ' . count($lost) . ": \n  " . implode("\n  ", $lost) . "\n")
		: "все файлы на месте\n";
}

echo "\n=== Для сравнения — папка bitrix ===\n";
foreach (['bitrix', 'bitrix/modules', 'adm'] as $p) {
	$full = $root . '/' . $p;
	printf("%-52s %s\n", $p, file_exists($full)
		? ('права ' . substr(sprintf('%o', fileperms($full)), -4)
			. (is_link($full) ? ', симлинк → ' . readlink($full) : ''))
		: 'НЕ НАЙДЕН');
}

echo "\n=== Что отвечает механизм поиска Битрикса ===\n";
$found = \Bitrix\Main\Loader::getLocal('modules/' . $mod . '/include.php');
say('Loader::getLocal вернул', $found === false ? 'false' : (string)$found);
say('и этот файл существует', $found !== false && file_exists((string)$found));
say('и это путь внутри local', $found !== false && strpos((string)$found, '/local/') !== false);

echo "\n=== Сессия ===\n";
// Модуль сессией не пользуется и гасит её через SessionInterface::destroy().
try {
	$s = \Bitrix\Main\Application::getInstance()->getSession();
	say('класс сессии', get_class($s));
	say('есть destroy()', method_exists($s, 'destroy'));
	say('стартована сейчас', method_exists($s, 'isStarted') ? $s->isStarted() : 'не спросить');
} catch (\Throwable $e) {
	say('сессия', 'не доступна: ' . $e->getMessage());
}

echo "\n=== Заголовок Authorization ===\n";
say('apache_request_headers доступна', function_exists('apache_request_headers'));
say('getallheaders доступна', function_exists('getallheaders'));
say('в $_SERVER сейчас', isset($_SERVER['HTTP_AUTHORIZATION'])
	? 'есть' : 'нет — это нормально для этой страницы');

echo "\n=== Проверка боем ===\n";
$https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
// HTTP_HOST приходит с портом; для стандартного его убираем, иначе в готовой
// команде оказывается «k-presnya.ru:443».
$host  = (string)($_SERVER['HTTP_HOST'] ?? '');
$host  = preg_replace($https ? '~:443$~' : '~:80$~', '', $host);
$url   = ($https ? 'https://' : 'http://') . $host . '/mcp/';
echo "Подставьте свой токен:\n\n";
echo "curl -i -X POST " . $url . " \\\n"
	. "  -H 'Content-Type: application/json' \\\n"
	. "  -H 'Authorization: Bearer ВАШ_ТОКЕН' \\\n"
	. "  -d '{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"initialize\","
	. "\"params\":{\"protocolVersion\":\"2025-06-18\",\"capabilities\":{},"
	. "\"clientInfo\":{\"name\":\"curl\",\"version\":\"1\"}}}'\n\n";
echo "200 — работает. 401 — токен не дошёл или не принят (смотрите вкладку «Журнал»).\n";
echo "404 — адрес /mcp/ перехвачен веб-сервером, до Битрикса не дошло.\n";

// Разведка перед переходом на D7: /mcp/check.php?d7=1
if (!empty($_GET['d7'])) {
	echo "\n=== D7: что доступно для чтения элементов ===\n";
	\Bitrix\Main\Loader::includeModule('iblock');

	// Диагностика не подключает свой модуль (она должна работать и до установки),
	// поэтому список инфоблоков берём из ?ib=18,21, а без него — из настроек модуля.
	$want = array_filter(array_map('intval', explode(',', (string)($_GET['ib'] ?? ''))));
	if (!$want && \Bitrix\Main\Loader::includeModule('itb.mcp')) {
		$want = \Itb\Mcp\Expose::ids();
	}

	echo "\n-- инфоблоки: API_CODE и версия хранилища свойств --\n";
	echo "   (интересующие: " . ($want ? implode(', ', $want) : 'не заданы, показаны все') . ")\n";
	$rs = CIBlock::GetList([], ['CHECK_PERMISSIONS' => 'N']);
	while ($ib = $rs->Fetch()) {
		if ($want && !in_array((int)$ib['ID'], $want, true)) { continue; }
		printf("  %-4d %-28s CODE=%-14s API_CODE=%-16s VERSION=%s (%s)\n",
			$ib['ID'], mb_substr((string)$ib['NAME'], 0, 28), (string)$ib['CODE'],
			(string)($ib['API_CODE'] ?? '') !== '' ? (string)$ib['API_CODE'] : '— ПУСТО',
			(string)$ib['VERSION'],
			(int)$ib['VERSION'] === 2 ? 'раздельное хранилище' : 'единое хранилище');
	}

	// API_CODE запоминаем: от него зависит имя сгенерированного класса сущности.
	$apiCodes = [];
	$rs = CIBlock::GetList([], ['CHECK_PERMISSIONS' => 'N']);
	while ($ib = $rs->Fetch()) {
		if ($want && !in_array((int)$ib['ID'], $want, true)) { continue; }
		$apiCodes[(int)$ib['ID']] = (string)($ib['API_CODE'] ?? '');
	}

	echo "\n-- классы и методы --\n";
	foreach (['\Bitrix\Iblock\ElementTable', '\Bitrix\Iblock\ElementPropertyTable',
		'\Bitrix\Iblock\PropertyTable', '\Bitrix\Iblock\SectionElementTable',
		'\Bitrix\Iblock\Elements\ElementTable'] as $c) {
		say('class ' . $c, class_exists($c));
	}
	try {
		$r = new ReflectionClass('\Bitrix\Iblock\ElementTable');
		echo "  статические методы ElementTable со словом compile/entity:\n";
		foreach ($r->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $m) {
			if (stripos($m->getName(), 'compile') === false && stripos($m->getName(), 'entity') === false) { continue; }
			$args = [];
			foreach ($m->getParameters() as $p) { $args[] = '$' . $p->getName(); }
			echo "    " . $m->getName() . '(' . implode(', ', $args) . ")\n";
		}
	} catch (\Throwable $e) {
		echo "  рефлексия не удалась: " . $e->getMessage() . "\n";
	}

	echo "\n-- сгенерированные классы по API_CODE --\n";
	foreach ($apiCodes as $id => $code) {
		if ($code === '') { echo "  инфоблок $id: API_CODE пуст\n"; continue; }
		$cls = '\\Bitrix\\Iblock\\Elements\\Element' . ucfirst($code) . 'Table';
		printf("  инфоблок %-4d API_CODE=%-10s %-52s %s\n",
			$id, $code, $cls, class_exists($cls) ? 'ЕСТЬ' : 'нет');
	}

	echo "\n-- \\Bitrix\\Iblock\\Iblock: способ получить сущность по id --\n";
	say('class \Bitrix\Iblock\Iblock', class_exists('\Bitrix\Iblock\Iblock'));
	try {
		$r = new ReflectionClass('\Bitrix\Iblock\Iblock');
		$names = [];
		foreach ($r->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
			$args = [];
			foreach ($m->getParameters() as $p) { $args[] = '$' . $p->getName(); }
			$names[] = ($m->isStatic() ? 'static ' : '') . $m->getName() . '(' . implode(', ', $args) . ')';
		}
		echo "  " . implode("\n  ", $names) . "\n";
	} catch (\Throwable $e) {
		echo "  рефлексия не удалась: " . $e->getMessage() . "\n";
	}

	// Ищем рабочий способ добраться до класса сущности: сперва wakeUp по id,
	// затем сгенерированный класс по API_CODE.
	$first = (int)($want[0] ?? 0);
	$cls   = null;
	echo "\n-- как получаем класс сущности для инфоблока $first --\n";
	try {
		$cls = \Bitrix\Iblock\Iblock::wakeUp($first)->getEntityDataClass();
		echo "  Iblock::wakeUp()->getEntityDataClass() → " . $cls . "\n";
	} catch (\Throwable $e) {
		echo "  Iblock::wakeUp не сработал: " . get_class($e) . ': ' . $e->getMessage() . "\n";
		$try = '\\Bitrix\\Iblock\\Elements\\Element' . ucfirst((string)($apiCodes[$first] ?? '')) . 'Table';
		if (class_exists($try)) { $cls = $try; echo "  берём класс по API_CODE → " . $cls . "\n"; }
	}

	if ($cls) {
		echo "\n-- поля сущности --\n";
		try {
			$names = array_keys($cls::getEntity()->getFields());
			echo "  всего " . count($names) . ": " . implode(', ', array_slice($names, 0, 60))
				. (count($names) > 60 ? ' …' : '') . "\n";
		} catch (\Throwable $e) {
			echo "  не удалось: " . $e->getMessage() . "\n";
		}

		echo "\n-- пробная выборка: элемент со свойством в ОДНОМ запросе --\n";
		// Пробуем три записи select: какая сработает, ту и берём в код.
		$variants = [
			'значение через .VALUE' => ['ID', 'NAME', 'ART_' => 'CML2_ARTICLE.VALUE'],
			'свойство целиком'      => ['ID', 'NAME', 'CML2_ARTICLE'],
			'звёздочка + свойство'  => ['*', 'CML2_ARTICLE'],
		];
		foreach ($variants as $label => $select) {
			try {
				$rs2 = $cls::getList(['select' => $select, 'filter' => ['=ACTIVE' => 'Y'], 'limit' => 1]);
				$row = $rs2->fetch();
				echo "  [" . $label . "] → " . ($row
					? mb_substr(json_encode($row, JSON_UNESCAPED_UNICODE), 0, 300) : 'пусто') . "\n";
			} catch (\Throwable $e) {
				echo "  [" . $label . "] → " . get_class($e) . ': '
					. mb_substr($e->getMessage(), 0, 200) . "\n";
			}
		}
	}
}

// Сверка D7 со старым API на заведомо заполненном элементе: /mcp/check.php?d7cmp=548
if (!empty($_GET['d7cmp'])) {
	$id = (int)$_GET['d7cmp'];
	$ib = (int)($_GET['ib'] ?? 18);
	$codes = array_filter(array_map('trim', explode(',', (string)($_GET['props'] ?? 'CML2_ARTICLE,METALL'))));

	echo "\n=== Сверка D7 и старого API на элементе $id ===\n";
	\Bitrix\Main\Loader::includeModule('iblock');

	echo "\n-- старый API (эталон) --\n";
	$old = [];
	try {
		$rs = CIBlockElement::GetList([], ['ID' => $id], false, false, ['ID', 'NAME', 'IBLOCK_ID']);
		if ($el = $rs->GetNextElement()) {
			$f = $el->GetFields();
			echo "  NAME = " . $f['NAME'] . ", IBLOCK_ID = " . $f['IBLOCK_ID'] . "\n";
			foreach ($el->GetProperties() as $c => $p) {
				if (!in_array($c, $codes, true)) { continue; }
				$v = $p['VALUE'];
				$old[$c] = is_array($v) ? implode('|', $v) : (string)$v;
				echo "  " . $c . " = " . var_export($old[$c], true) . "\n";
			}
		} else {
			echo "  элемент не найден\n";
		}
	} catch (\Throwable $e) {
		echo "  ошибка: " . $e->getMessage() . "\n";
	}

	echo "\n-- D7, те же свойства одним запросом --\n";
	try {
		$cls = \Bitrix\Iblock\Iblock::wakeUp($ib)->getEntityDataClass();
		$select = ['ID', 'NAME'];
		foreach ($codes as $c) { $select[$c . '_V'] = $c . '.VALUE'; }

		$row = $cls::getList(['select' => $select, 'filter' => ['=ID' => $id]])->fetch();
		echo "  " . json_encode($row, JSON_UNESCAPED_UNICODE) . "\n";

		echo "\n  сходится со старым API: ";
		$same = true;
		foreach ($codes as $c) {
			$d7 = (string)($row[$c . '_V'] ?? '');
			if ($d7 !== (string)($old[$c] ?? '')) { $same = false; echo "\n    РАСХОЖДЕНИЕ $c: старый «"
				. ($old[$c] ?? '') . "», D7 «" . $d7 . "»"; }
		}
		echo $same ? "да\n" : "\n";
	} catch (\Throwable $e) {
		echo "  ошибка: " . get_class($e) . ': ' . $e->getMessage() . "\n";
	}

	echo "\n-- D7: множественное свойство (несколько строк на элемент?) --\n";
	try {
		$cls = \Bitrix\Iblock\Iblock::wakeUp($ib)->getEntityDataClass();
		$rs2 = $cls::getList([
			'select' => ['ID', 'V' => 'CML2_ARTICLE.VALUE'],
			'filter' => ['=ID' => $id],
		]);
		$n = 0;
		while ($r2 = $rs2->fetch()) { $n++; }
		echo "  строк на один элемент: " . $n . " (больше одной — значит выдачу надо схлопывать)\n";
	} catch (\Throwable $e) {
		echo "  ошибка: " . $e->getMessage() . "\n";
	}

	echo "\n-- D7: фильтр по значению свойства --\n";
	try {
		$cls = \Bitrix\Iblock\Iblock::wakeUp($ib)->getEntityDataClass();
		$val = (string)($old['CML2_ARTICLE'] ?? '');
		$rs3 = $cls::getList([
			'select' => ['ID', 'NAME'],
			'filter' => ['=CML2_ARTICLE.VALUE' => $val],
			'limit'  => 3,
		]);
		echo "  ищем CML2_ARTICLE = «" . $val . "»\n";
		while ($r3 = $rs3->fetch()) { echo "    " . $r3['ID'] . " " . $r3['NAME'] . "\n"; }
	} catch (\Throwable $e) {
		echo "  ошибка: " . get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 200) . "\n";
	}

	echo "\n-- D7: как приходят даты и картинки --\n";
	try {
		$cls = \Bitrix\Iblock\Iblock::wakeUp($ib)->getEntityDataClass();
		$row = $cls::getList(['select' => ['ID', 'DATE_CREATE', 'TIMESTAMP_X', 'DETAIL_PICTURE'],
			'filter' => ['=ID' => $id]])->fetch();
		foreach ($row as $k => $v) {
			echo "  " . str_pad($k, 16) . " " . (is_object($v) ? get_class($v) . ' → ' . (string)$v
				: var_export($v, true)) . "\n";
		}
	} catch (\Throwable $e) {
		echo "  ошибка: " . $e->getMessage() . "\n";
	}
}

echo "\n=== Итог ===\n";
if (!empty($lost)) {
	echo "Модуль доехал НЕ ЦЕЛИКОМ — обновите файлы и повторите.\n";
} elseif ($found !== false && file_exists((string)$found) && strpos((string)$found, '/local/') !== false) {
	echo "Папка модуля видна, файлы на месте.\n";
	echo "Ставить: Marketplace → Установленные решения → «MCP-сервер (только чтение)».\n";
} elseif (!file_exists($want)) {
	echo "Файлов нет на диске. Ожидаются в: $want\n";
} else {
	echo "Файлы есть, но Битрикс их не видит: смотрите, где выше «НЕ ЧИТАЕТСЯ» или\n";
	echo "«ВОЙТИ НЕЛЬЗЯ», и сравните права с папкой bitrix.\n";
}
