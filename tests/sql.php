<?php
// Границы произвольного SELECT.
//
// Инструмент выполняет текст, пришедший от модели, поэтому проверок здесь
// больше, чем кода. Проверяются те же методы, что стоят в бою.
require __DIR__ . '/../lib/ToolError.php';
require __DIR__ . '/../lib/Sql.php';
use Itb\Mcp\Sql;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	if ($got === $want) { $ok++; return; }
	$bad++;
	printf("  - %s: %s вместо %s\n", $what, var_export($got, true), var_export($want, true));
}

/** Запрос разрешён. */
function may(string $q, array $allow = []) {
	global $ok, $bad;
	$why = Sql::why(Sql::clean($q), $allow);
	if ($why === null) { $ok++; return; }
	$bad++;
	printf("  - «%s» должен пройти, а отказ: %s\n", $q, $why);
}

/** Запрос обязан быть отвергнут. */
function mayNot(string $q, array $allow = []) {
	global $ok, $bad;
	$why = Sql::why(Sql::clean($q), $allow);
	if ($why !== null) { $ok++; return; }
	$bad++;
	printf("  - «%s» ПРОШЁЛ, а не должен\n", $q);
}

echo "=== очистка запроса ===\n";
is_('хвостовая точка с запятой', Sql::clean('SELECT 1;'), 'SELECT 1');
is_('блочный комментарий', Sql::clean('SELECT /* тут */ 1'), 'SELECT 1');
is_('строчный комментарий', Sql::clean("SELECT 1 -- хвост\n"), 'SELECT 1');
is_('решётка', Sql::clean("SELECT 1 # хвост\n"), 'SELECT 1');
is_('переносы и отступы', Sql::clean("SELECT\n\tID\nFROM b_iblock"), 'SELECT ID FROM b_iblock');
// «--» без пробела комментарием в MySQL не является, значит и вырезать нечего.
is_('двойной минус без пробела', Sql::clean('SELECT 1--2'), 'SELECT 1--2');

echo "=== только чтение ===\n";
may('SELECT ID, NAME FROM b_iblock');
may('select * from b_iblock_element where IBLOCK_ID = 18');
may("WITH t AS (SELECT ID FROM b_iblock) SELECT * FROM t");
may('SELECT COUNT(*) FROM b_sale_order WHERE DATE_INSERT > "2026-08-01"');

mayNot('UPDATE b_iblock SET NAME = "x"');
mayNot('DELETE FROM b_iblock');
mayNot('INSERT INTO b_iblock (NAME) VALUES ("x")');
mayNot('DROP TABLE b_iblock');
mayNot('TRUNCATE b_iblock');
mayNot('ALTER TABLE b_iblock ADD COLUMN x INT');
mayNot('CREATE TABLE x (id INT)');
mayNot('GRANT ALL ON *.* TO root');
mayNot('SET @@global.read_only = 0');
mayNot('CALL some_procedure()');
mayNot('  ');

echo "=== вторая инструкция ===\n";
mayNot('SELECT 1; DROP TABLE b_iblock');
mayNot('SELECT 1; UPDATE b_iblock SET NAME = "x"');
// Комментарий не должен прятать точку с запятой: проверяется очищенный текст.
mayNot('SELECT 1 /* тут */ ; DROP TABLE b_iblock');
mayNot("SELECT 1 -- x\n; DROP TABLE b_iblock");

echo "=== выход за пределы чтения ===\n";
mayNot('SELECT * FROM b_iblock INTO OUTFILE "/tmp/x"');
mayNot('SELECT * FROM b_iblock INTO DUMPFILE "/tmp/x"');
mayNot('SELECT LOAD_FILE("/etc/passwd")');
mayNot('SELECT SLEEP(30)');
mayNot('SELECT BENCHMARK(100000000, MD5("x"))');
mayNot('SELECT GET_LOCK("x", 100)');
// В этих представлениях лежат тексты чужих запросов вместе со значениями.
mayNot('SELECT * FROM information_schema.PROCESSLIST');
mayNot('SELECT * FROM information_schema.INNODB_TRX');
mayNot('SELECT * FROM performance_schema.events_statements_history');
mayNot('SELECT * FROM sys.session');
mayNot('SELECT * FROM mysql.user');
// Комментарий внутри конструкции не должен её маскировать.
mayNot('SELECT * FROM b_iblock INTO/**/OUTFILE "/tmp/x"');
// Блокирующее чтение встанет поперёк оформления заказов на работающем магазине.
mayNot('SELECT * FROM b_sale_order FOR UPDATE');
mayNot('SELECT * FROM b_sale_order LOCK IN SHARE MODE');
mayNot('SELECT NEXTVAL(my_seq)');

echo "=== закрытые таблицы ===\n";
mayNot('SELECT * FROM b_user');
mayNot('SELECT LOGIN, PASSWORD FROM b_user WHERE ID = 1');
mayNot('select * from `b_user`');
mayNot('SELECT * FROM b_sale_order o JOIN b_user u ON u.ID = o.USER_ID');
mayNot('SELECT * FROM sitedb.b_user');
mayNot('SELECT * FROM b_option');
mayNot('SELECT * FROM b_user_stored_auth');
mayNot('SELECT * FROM itb_mcp_token');
// В настройках магазина лежат доступы к эквайрингу: логин и пароль шлюза
// банка, секретный ключ кассы. Названия служб отдаёт sale_directories.
mayNot('SELECT CODE_KEY, PROVIDER_VALUE FROM b_sale_bizval');
mayNot('SELECT * FROM b_sale_cashbox');
mayNot('SELECT PARAMS FROM b_sale_pay_system_action');
mayNot('SELECT CONFIG FROM b_sale_delivery_srv');
mayNot('SELECT o.ID FROM b_sale_order o JOIN b_sale_delivery_srv d ON d.ID = o.DELIVERY_ID');
// Соседние таблицы заказов остаются открытыми.
may('SELECT * FROM b_sale_order');
may('SELECT * FROM b_sale_basket');
may('SELECT * FROM b_sale_order_coupons');
// Соседние таблицы с тем же префиксом закрывать не нужно: граница по слову.
may('SELECT * FROM b_user_field');
may('SELECT * FROM b_user_group WHERE USER_ID = 1');
may('SELECT * FROM b_option_site');

echo "=== белый список ===\n";
may('SELECT * FROM b_iblock', ['b_iblock', 'b_iblock_element']);
may('SELECT * FROM b_iblock_element e JOIN b_iblock i ON i.ID = e.IBLOCK_ID', ['b_iblock', 'b_iblock_element']);
mayNot('SELECT * FROM b_sale_order', ['b_iblock']);
mayNot('SELECT * FROM b_iblock UNION SELECT * FROM b_sale_order', ['b_iblock']);
// Соединение через запятую — тоже соединение: вторая таблица не должна
// проскочить мимо белого списка.
mayNot('SELECT * FROM b_iblock, b_sale_order', ['b_iblock']);
mayNot('SELECT * FROM b_iblock i, b_sale_order o WHERE i.ID = o.ID', ['b_iblock']);
mayNot('SELECT * FROM b_iblock STRAIGHT_JOIN b_sale_order', ['b_iblock']);
mayNot('SELECT * FROM b_iblock WHERE ID IN (SELECT ID FROM b_sale_order)', ['b_iblock']);
mayNot('SELECT * FROM b_iblock LEFT JOIN b_sale_order o ON o.ID = 1', ['b_iblock']);
may('SELECT * FROM b_iblock, b_iblock_element', ['b_iblock', 'b_iblock_element']);
// Закрытая таблица закрыта и тогда, когда её вписали в белый список.
mayNot('SELECT * FROM b_user', ['b_user']);

echo "=== разбор таблиц и лимита ===\n";
is_('таблицы из FROM и JOIN',
	Sql::tablesIn('SELECT * FROM b_iblock i JOIN b_iblock_element e ON e.IBLOCK_ID = i.ID'),
	['b_iblock', 'b_iblock_element']);
is_('подзапрос не считается таблицей',
	Sql::tablesIn('SELECT * FROM (SELECT ID FROM b_iblock) t'), ['b_iblock']);
is_('обратные кавычки', Sql::tablesIn('SELECT * FROM `b_iblock`'), ['b_iblock']);
is_('перечисление через запятую',
	Sql::tablesIn('SELECT * FROM b_iblock, b_sale_order'), ['b_iblock', 'b_sale_order']);
is_('запятая с псевдонимами',
	Sql::tablesIn('SELECT * FROM b_iblock i, b_sale_order o WHERE i.ID = o.ID'),
	['b_iblock', 'b_sale_order']);
is_('LEFT JOIN', Sql::tablesIn('SELECT * FROM a LEFT JOIN b ON b.ID = a.ID'), ['a', 'b']);
is_('свой лимит', Sql::declaredLimit('SELECT * FROM b_iblock LIMIT 10'), 10);
is_('лимит со сдвигом', Sql::declaredLimit('SELECT * FROM b_iblock LIMIT 100, 20'), 20);
is_('лимит с OFFSET', Sql::declaredLimit('SELECT * FROM b_iblock LIMIT 10 OFFSET 100'), 10);
is_('лимита нет', Sql::declaredLimit('SELECT * FROM b_iblock'), null);
is_('limit в середине не считается', Sql::declaredLimit('SELECT * FROM b_iblock WHERE NAME = "limit 5"'), null);

echo "=== белый список из настройки ===\n";
is_('через запятую', Sql::parse('b_iblock, b_sale_order'), ['b_iblock', 'b_sale_order']);
is_('через пробел и перенос', Sql::parse("b_iblock\n b_sale_order"), ['b_iblock', 'b_sale_order']);
is_('регистр к нижнему', Sql::parse('B_IBLOCK'), ['b_iblock']);
// Всё с пунктуацией отбрасывается: в белый список попадают только имена.
is_('мусор отбрасывается', Sql::parse('b_iblock, b_sale_order;, --, `x`'), ['b_iblock']);
is_('повторы схлопываются', Sql::parse('b_iblock, b_iblock'), ['b_iblock']);
is_('пусто', Sql::parse('   '), []);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
