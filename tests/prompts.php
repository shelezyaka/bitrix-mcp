<?php
// Готовые сценарии: состав по правам и подстановка аргументов.
//
// Сценарий, который предлагает позвать недоступный инструмент, хуже отсутствия
// сценария: человек выбирает пункт меню и получает отказ.
require __DIR__ . '/../lib/Prompts.php';
use Itb\Mcp\Prompts;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	$g = var_export($got, true); $w = var_export($want, true);
	if ($g === $w) { $ok++; return; }
	$bad++; echo "ПРОВАЛ: $what\n  получено: $g\n  ожидалось: $w\n";
}
function has_(string $what, bool $cond) {
	global $ok, $bad;
	if ($cond) { $ok++; return; }
	$bad++; echo "ПРОВАЛ: $what\n";
}

echo "=== состав по правам ===\n";
is_('без прав — ни одного сценария', Prompts::schema([]), []);
is_('только отчёты', array_column(Prompts::schema(['reports']), 'name'),
	['sales_summary', 'yesterday', 'stock_check', 'search_gaps']);
is_('только заказы', array_column(Prompts::schema(['orders']), 'name'), ['order_trace']);
is_('только API', array_column(Prompts::schema(['api']), 'name'), ['code_trace']);
is_('каталог сам по себе сценариев не даёт', Prompts::schema(['catalog']), []);
has_('всё сразу — шесть сценариев', count(Prompts::schema(['reports', 'orders', 'api'])) === 6);

echo "=== выдача сценария ===\n";
is_('чужой сценарий', Prompts::get('order_trace', ['order' => '185145'], ['reports']), null);
is_('несуществующий', Prompts::get('нет-такого', [], ['reports', 'orders', 'api']), null);

$p = Prompts::get('order_trace', ['order' => '185145'], ['orders']);
has_('сценарий выдан', is_array($p));
has_('роль user', ($p['messages'][0]['role'] ?? '') === 'user');
has_('тип text', ($p['messages'][0]['content']['type'] ?? '') === 'text');
has_('номер заказа подставлен',
	strpos($p['messages'][0]['content']['text'], '185145') !== false);

echo "=== подстановка периода ===\n";
$text = static function (array $args) {
	$p = Prompts::get('sales_summary', $args, ['reports']);
	return $p['messages'][0]['content']['text'];
};
has_('обе даты', strpos($text(['from' => '01.08.2026', 'to' => '12.08.2026']),
	'за период с 01.08.2026 по 12.08.2026') !== false);
has_('только начало', strpos($text(['from' => '01.08.2026']), ' с 01.08.2026') !== false);
has_('только конец', strpos($text(['to' => '12.08.2026']), ' по 12.08.2026') !== false);
// Пустой аргумент не оставляет в тексте дыру вроде «за период с  по».
has_('без дат оборот исчезает', strpos($text([]), 'Собери сводку продаж.') !== false);
has_('фигурных скобок в тексте не остаётся', strpos($text([]), '{') === false);

$days = Prompts::get('stock_check', ['days' => 60], ['reports']);
has_('дни подставлены', strpos($days['messages'][0]['content']['text'], 'за 60 дн.') !== false);
$nodays = Prompts::get('stock_check', [], ['reports']);
has_('без дней оборот исчезает',
	strpos($nodays['messages'][0]['content']['text'], '{days}') === false);

echo "=== описание сценариев ===\n";
foreach (Prompts::schema(['reports', 'orders', 'api']) as $p) {
	has_('у «' . $p['name'] . '» есть название', ($p['title'] ?? '') !== '');
	has_('у «' . $p['name'] . '» есть описание', ($p['description'] ?? '') !== '');
	has_('у «' . $p['name'] . '» аргументы списком', is_array($p['arguments'] ?? null));
}

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
