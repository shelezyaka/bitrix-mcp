<?php
// Прогон решающей части токенов БЕЗ базы и БЕЗ Битрикса: выпуск, срок, отзыв,
// белый список. Проверять просрочку вручную («подождём до завтра») — не проверка.
require __DIR__ . '/../lib/Token.php';
use Itb\Mcp\Token;

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

echo "\n=== Белый список инструментов ===\n";
is_('пусто = все разрешённые настройкой', Token::allowed(['TOOLS' => '']), null);
is_('поля нет вовсе = все',               Token::allowed([]), null);
is_('json-список',        Token::allowed(['TOOLS' => '["a","b"]']), ['a', 'b']);
is_('список через запятую', Token::allowed(['TOOLS' => 'a, b ,c']), ['a', 'b', 'c']);
is_('список через строки',  Token::allowed(['TOOLS' => "a\nb"]), ['a', 'b']);
is_('пустой json-список — это НЕ «всё»', Token::allowed(['TOOLS' => '[]']), []);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
