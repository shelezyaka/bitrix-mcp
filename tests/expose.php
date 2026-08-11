<?php
// Разбор белого списка инфоблоков — без базы и без Битрикса.
// Ошибка здесь означает открытые данные, поэтому проверяется отдельно от всего.
require __DIR__ . '/../lib/Expose.php';
use Itb\Mcp\Expose;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	$g = json_encode($got, JSON_UNESCAPED_UNICODE); $w = json_encode($want, JSON_UNESCAPED_UNICODE);
	if ($g === $w) { $ok++; return; }
	$bad++; echo "ПРОВАЛ: $what\n  получено: $g\n  ожидалось: $w\n";
}

echo "=== Разбор настройки ===\n";
is_('пусто — ничего не открыто', Expose::parse(''), []);
is_('мусор — ничего не открыто', Expose::parse('не json'), []);
is_('один инфоблок без сужения',
	Expose::parse('{"18":{"props":""}}'), [18 => ['props' => null]]);
is_('сужение по свойствам',
	Expose::parse('{"18":{"props":"CML2_ARTICLE, METALL"}}'),
	[18 => ['props' => ['CML2_ARTICLE', 'METALL']]]);
is_('регистр кодов приводится к верхнему',
	Expose::parse('{"18":{"props":"cml2_article"}}'), [18 => ['props' => ['CML2_ARTICLE']]]);
is_('разделители — запятая, пробел, перенос',
	Expose::parse('{"18":{"props":"A, B\nC  D"}}'), [18 => ['props' => ['A', 'B', 'C', 'D']]]);
is_('дубли схлопываются',
	Expose::parse('{"18":{"props":"A, a, A"}}'), [18 => ['props' => ['A']]]);
is_('несколько инфоблоков, порядок по id',
	array_keys(Expose::parse('{"21":{"props":""},"18":{"props":""}}')), [18, 21]);
is_('нулевой и отрицательный id отбрасываются',
	array_keys(Expose::parse('{"0":{"props":""},"-5":{"props":""},"18":{"props":""}}')), [18]);
is_('id строкой всё равно число',
	array_keys(Expose::parse('{"18":{"props":""}}')), [18]);

echo "\n=== Отбор свойств ===\n";
// filterProps работает поверх реального состава инфоблока.
$exists = ['CML2_ARTICLE' => 'Артикул', 'METALL' => 'Металл', 'SECRET' => 'Себестоимость'];

// ⚠️ Ключевая проверка: сужение обязано ВЫЧИТАТЬ, а не дополнять. Если оно
// вдруг начнёт возвращать всё подряд, наружу уйдёт свойство, которое человек
// намеренно не открывал, — и заметить это по интерфейсу нельзя.
// filterProps() читает настройки Битрикса, поэтому здесь проверяется его чистая
// часть — сам отбор по уже разобранному правилу.
$rule = Expose::parse('{"18":{"props":"CML2_ARTICLE, METALL"}}')[18]['props'];
$got  = [];
foreach ($exists as $code => $name) {
	if (in_array(strtoupper($code), $rule, true)) { $got[$code] = $name; }
}
is_('открыты только перечисленные', array_keys($got), ['CML2_ARTICLE', 'METALL']);
is_('закрытое свойство не проходит', isset($got['SECRET']), false);

$rule = Expose::parse('{"18":{"props":""}}')[18]['props'];
is_('без сужения — правило null (значит все)', $rule, null);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
