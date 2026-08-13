<?php
// Прогон решающей части токенов БЕЗ базы и БЕЗ Битрикса: выпуск, срок, отзыв,
// белый список. Проверять просрочку вручную («подождём до завтра») — не проверка.
require __DIR__ . '/../lib/Token.php';
// Tools нужен ради pick() — правила «сайт И токен». Битрикс он трогает только
// внутри методов, которые здесь не зовутся.
require __DIR__ . '/../lib/Tools.php';
use Itb\Mcp\Token;
use Itb\Mcp\Tools;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	$g = var_export($got, true); $w = var_export($want, true);
	if ($g === $w) { $ok++; return; }
	$bad++; echo "ПРОВАЛ: $what\n  получено: $g\n  ожидалось: $w\n";
}

$NOW = mktime(12, 0, 0, 8, 11, 2026);   // 11.08.2026 12:00

echo "=== Выпуск ===\n";
$a = Token::generate();
$b = Token::generate();
is_('токен с опознаваемым префиксом', strncmp($a['token'], 'bxmcp_', 6) === 0, true);
is_('два выпуска не совпадают', $a['token'] === $b['token'], false);
is_('длина без сюрпризов', strlen($a['token']), 6 + 43);
is_('только безопасные для URL символы', (bool)preg_match('~^bxmcp_[A-Za-z0-9_-]+$~', $a['token']), true);
is_('хеш — sha256 в hex', (bool)preg_match('~^[0-9a-f]{64}$~', $a['hash']), true);
is_('в хеше нет самого токена', strpos($a['hash'], substr($a['token'], 6)) === false, true);
is_('хеш воспроизводится', Token::hash($a['token']), $a['hash']);
is_('хвост — последние 6 символов', substr($a['token'], -6), $a['hint']);

echo "\n=== Срок и отзыв ===\n";
is_('живой бессрочный',      Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => null], $NOW), null);
is_('пустой срок = бессрочно', Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => ''], $NOW), null);
is_('отозванный',            Token::why(['ACTIVE' => 'N', 'EXPIRES_AT' => null], $NOW), 'токен отозван');
is_('отозванный важнее срока',
	Token::why(['ACTIVE' => 'N', 'EXPIRES_AT' => $NOW + 99999], $NOW), 'токен отозван');
is_('срок в будущем',        Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => $NOW + 60], $NOW), null);
is_('срок в прошлом',        Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => $NOW - 1], $NOW), 'срок действия истёк');
is_('ровно в момент истечения ещё живой',
	Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => $NOW], $NOW), null);
is_('ACTIVE отсутствует = не пускаем',
	Token::why(['EXPIRES_AT' => null], $NOW), 'токен отозван');

echo "\n=== Разбор даты ===\n";
is_('ДД.ММ.ГГГГ разбирается как день.месяц',
	Token::expiresTs(['EXPIRES_AT' => '01.02.2027']), mktime(0, 0, 0, 2, 1, 2027));
is_('ДД.ММ.ГГГГ ЧЧ:ММ:СС',
	Token::expiresTs(['EXPIRES_AT' => '31.12.2026 23:59:59']), mktime(23, 59, 59, 12, 31, 2026));
is_('объект с getTimestamp',
	Token::expiresTs(['EXPIRES_AT' => new class { public function getTimestamp() { return 777; } }]), 777);
is_('число как строка',      Token::expiresTs(['EXPIRES_AT' => '1700000000']), 1700000000);
is_('пусто — бессрочно',     Token::expiresTs(['EXPIRES_AT' => null]), null);
is_('мусор — НЕ бессрочно, а испорчено',
	Token::expiresTs(['EXPIRES_AT' => 'когда-нибудь']), false);
is_('испорченный срок закрывает доступ',
	Token::why(['ACTIVE' => 'Y', 'EXPIRES_AT' => 'когда-нибудь'], $NOW), 'срок действия не читается');

echo "\n=== Ввод срока действия ===\n";
is_('пусто = бессрочно',      Token::normalizeExpires(''), null);
is_('пробелы = бессрочно',    Token::normalizeExpires('   '), null);
is_('ДД.ММ.ГГГГ',             Token::normalizeExpires('11.02.2027'), mktime(0, 0, 0, 2, 11, 2027));
is_('ISO из <input type=date>', Token::normalizeExpires('2027-02-11'), mktime(0, 0, 0, 2, 11, 2027));
$caught = null;
try { Token::normalizeExpires('31.31.2027'); } catch (\InvalidArgumentException $e) { $caught = 'отказ'; }
is_('несуществующая дата — ОТКАЗ, а не «бессрочно»', $caught, 'отказ');
$caught = null;
try { Token::normalizeExpires('как-нибудь потом'); } catch (\InvalidArgumentException $e) { $caught = 'отказ'; }
is_('мусор — ОТКАЗ, а не «бессрочно»', $caught, 'отказ');
// ⚠️ mktime пересчитывает выход за диапазон молча: без checkdate «31.31.2027»
// становилось июлем 2029, а «32.01» — первым февраля. Это не придирка к вводу:
// такой срок выглядит настоящим, и понять, откуда он взялся, нельзя.
is_('31-й месяц не существует',  Token::expiresTs(['EXPIRES_AT' => '31.31.2027']), false);
is_('32-е число не существует',  Token::expiresTs(['EXPIRES_AT' => '32.01.2027']), false);
is_('29 февраля 2027 не бывает', Token::expiresTs(['EXPIRES_AT' => '29.02.2027']), false);
is_('29 февраля 2028 бывает',
	Token::expiresTs(['EXPIRES_AT' => '29.02.2028']), mktime(0, 0, 0, 2, 29, 2028));
is_('25 часов не бывает',        Token::expiresTs(['EXPIRES_AT' => '01.01.2027 25:00']), false);

echo "\n=== Права токена: группы инструментов ===\n";
// Права выдаются перечислением. Пусто — ни одной группы, остаётся site_info:
// иначе включение новой группы в настройках сайта молча расширяло бы уже
// выданные токены.
is_('пусто = ни одной группы',  Token::groups(['TOOLS' => '']), []);
is_('поля нет вовсе = ни одной', Token::groups([]), []);
is_('пустой json-список = ни одной', Token::groups(['TOOLS' => '[]']), []);
is_('json-список',         Token::groups(['TOOLS' => '["catalog","api"]']), ['catalog', 'api']);
is_('список через запятую', Token::groups(['TOOLS' => 'catalog, api']), ['catalog', 'api']);
is_('только одна группа',   Token::groups(['TOOLS' => '["api"]']), ['api']);

echo "\n=== Набор инструментов: сайт И токен ===\n";
// Выключенная в настройках группа приходит пустым списком. Право токена на неё
// не должно давать ничего — ни сразу, ни до перезапуска, ни после.
$off = ['site' => ['site_info'], 'catalog' => [], 'orders' => [], 'api' => [], 'sql' => []];
$on  = ['site' => ['site_info'], 'catalog' => ['element_get'], 'orders' => ['order_get'],
	'api' => [], 'sql' => ['sql_select']];

is_('всё выключено, у токена все права',
	Tools::pick($off, ['catalog', 'orders', 'api', 'sql']), ['site_info']);
// array_merge, а не «+»: объединение массивов оставляет значение ЛЕВОГО,
// и выключенная группа осталась бы включённой прямо в проверке.
is_('группа выключена, право есть',
	Tools::pick(array_merge($on, ['sql' => []]), ['sql']), ['site_info']);
is_('группа включена, права нет',
	Tools::pick($on, []), ['site_info']);
is_('группа включена и выдана',
	Tools::pick($on, ['catalog']), ['site_info', 'element_get']);
is_('две группы',
	Tools::pick($on, ['catalog', 'sql']), ['site_info', 'element_get', 'sql_select']);
is_('право на несуществующую группу',
	Tools::pick($on, ['выдумка']), ['site_info']);
// site_info не отключается: без него модель не знает, что ей открыто.
is_('site_info остаётся всегда', Tools::pick($off, []), ['site_info']);

echo "\n=== Права токена: инфоблоки ===\n";
is_('пусто = весь белый список сайта', Token::iblocks(['IBLOCKS' => '']), null);
is_('поля нет вовсе = весь список',    Token::iblocks([]), null);
is_('json-список чисел',   Token::iblocks(['IBLOCKS' => '[18,21]']), [18, 21]);
is_('строки приводятся к числам', Token::iblocks(['IBLOCKS' => '["18","21"]']), [18, 21]);
is_('через запятую',       Token::iblocks(['IBLOCKS' => '18, 21']), [18, 21]);
is_('мусор отбрасывается', Token::iblocks(['IBLOCKS' => '18, ноль, 0, -3, 21']), [18, 21]);
is_('пустой список — ни одного инфоблока', Token::iblocks(['IBLOCKS' => '[]']), []);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
