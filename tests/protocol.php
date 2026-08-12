<?php
// Прогон ядра протокола БЕЗ Битрикса. Всё, что здесь проверяется, — требования
// спецификации MCP 2025-06-18; ссылки на пункты в комментариях самих классов.
$lib = __DIR__ . '/../lib/';
foreach (['ToolError', 'AuthError', 'Schema', 'Tool', 'Registry', 'Protocol', 'Transport'] as $f) {
	require $lib . $f . '.php';
}
use Itb\Mcp\{Protocol, Registry, Schema, Tool, ToolError, AuthError, Transport};

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	$g = is_scalar($got) || $got === null ? var_export($got, true) : json_encode($got, JSON_UNESCAPED_UNICODE);
	$w = is_scalar($want) || $want === null ? var_export($want, true) : json_encode($want, JSON_UNESCAPED_UNICODE);
	if ($g === $w) { $ok++; return; }
	$bad++; echo "ПРОВАЛ: $what\n  получено: $g\n  ожидалось: $w\n";
}

// ── Стенд ───────────────────────────────────────────────────────────────────
$reg = new Registry();
$reg->setInstructions('Сайт только читается.');
$reg->add(new Tool('echo_it', 'Эхо', 'Возвращает переданное',
	['type' => 'object', 'properties' => ['n' => ['type' => 'integer'], 's' => ['type' => 'string']],
	 'required' => ['n']],
	function (array $a) { return ['got' => $a['n']]; }));
$reg->add(new Tool('empty_it', 'Пусто', 'Всегда говорит, что не нашёл', ['type' => 'object'],
	function (array $a) { throw new ToolError('Товар не найден'); }));
$reg->add(new Tool('boom', 'Взрыв', 'Падает', ['type' => 'object'],
	function (array $a) { throw new \RuntimeException('делить на ноль'); }));

$authorize = function (string $t) use ($reg) {
	if ($t !== 'good-token') { throw new AuthError('нет такого'); }
	return $reg;
};
$dispatch = function (array $m, Registry $r) { return Protocol::dispatch($m, $r); };
$ORIGINS  = ['https://shop.example.com'];

$H = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer good-token'];
function call(array $h, $body, string $method = 'POST') {
	global $authorize, $dispatch, $ORIGINS;
	$raw = is_string($body) ? $body : json_encode($body);
	return Transport::respond($method, $h, $raw, $authorize, $dispatch, $ORIGINS);
}
function rpc(array $h, array $body) { $r = call($h, $body); $r['json'] = json_decode($r['body'], true); return $r; }

echo "=== Транспорт ===\n";
is_('GET → 405',            call($H, '', 'GET')['status'], 405);
is_('GET отдаёт Allow',     call($H, '', 'GET')['headers']['Allow'], 'POST');
is_('DELETE → 405',         call($H, '', 'DELETE')['status'], 405);
is_('чужой Content-Type → 415',
	call(['Content-Type' => 'text/plain', 'Authorization' => 'Bearer good-token'], '{}')['status'], 415);
is_('чужой Origin → 403',   call($H + ['Origin' => 'https://evil.example'], [])['status'], 403);
is_('свой Origin проходит',
	rpc($H + ['Origin' => 'https://shop.example.com'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status'], 200);
is_('без Origin проходит',  rpc($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status'], 200);
is_('чужой токен → 401',
	call(['Content-Type' => 'application/json', 'Authorization' => 'Bearer nope'], [])['status'], 401);
is_('без токена → 401',     call(['Content-Type' => 'application/json'], [])['status'], 401);
is_('401 несёт WWW-Authenticate',
	call(['Content-Type' => 'application/json'], [])['headers']['WWW-Authenticate'], 'Bearer');
is_('чужая версия → 400',
	call($H + ['MCP-Protocol-Version' => '1999-01-01'], [])['status'], 400);
is_('знакомая версия проходит',
	rpc($H + ['MCP-Protocol-Version' => '2025-06-18'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status'], 200);
is_('старая версия (2025-03-26) проходит',
	rpc($H + ['MCP-Protocol-Version' => '2025-03-26'], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status'], 200);
is_('битый JSON → 400',     call($H, '{не json')['status'], 400);
is_('битый JSON → код -32700',
	json_decode(call($H, '{не json')['body'], true)['error']['code'], -32700);
is_('пачка (batch) → 400',  call($H, '[{"jsonrpc":"2.0","id":1,"method":"ping"}]')['status'], 400);
is_('тело больше потолка → 413',
	call($H, '{"x":"' . str_repeat('a', 300000) . '"}')['status'], 413);

echo "\n=== Рукопожатие ===\n";
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
	'params' => ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'x', 'version' => '1']]]);
is_('знакомая версия возвращается как есть', $r['json']['result']['protocolVersion'], '2025-06-18');
is_('объявлены инструменты', isset($r['json']['result']['capabilities']['tools']), true);
is_('есть serverInfo.name', $r['json']['result']['serverInfo']['name'], 'bitrix-mcp');
is_('инструкции доехали', $r['json']['result']['instructions'], 'Сайт только читается.');
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
	'params' => ['protocolVersion' => '2030-01-01']]);
is_('чужая версия → наша последняя, а НЕ ошибка', $r['json']['result']['protocolVersion'], '2025-06-18');
is_('чужая версия не даёт error', isset($r['json']['error']), false);

echo "\n=== Уведомления ===\n";
$r = call($H, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
is_('уведомление → 202', $r['status'], 202);
is_('уведомление → пустое тело', $r['body'], '');
$r = call($H, ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => ['requestId' => 1]]);
is_('незнакомое уведомление тоже 202', $r['status'], 202);

echo "\n=== Инструменты ===\n";
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
is_('в списке три инструмента', count($r['json']['result']['tools']), 3);
is_('у инструмента есть title', $r['json']['result']['tools'][0]['title'], 'Эхо');
is_('помечен как читающий', $r['json']['result']['tools'][0]['annotations']['readOnlyHint'], true);
// ⚠️ Проверяем СЫРОЕ тело, а не разобранное: json_decode($body, true) превращает
// пустой объект в пустой массив, и обратная сборка даёт «[]» там, где на проводе
// стоит «{}». Проверять надо то, что уходит в сокет.
is_('пустой properties — объект, а не []',
	strpos($r['body'], '"properties":{}') !== false, true);

$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
	'params' => ['name' => 'echo_it', 'arguments' => ['n' => 7]]]);
is_('удачный вызов → HTTP 200', $r['status'], 200);
is_('структурированный результат', $r['json']['result']['structuredContent'], ['got' => 7]);
is_('он же продублирован текстом', $r['json']['result']['content'][0]['text'], '{"got":7}');
is_('isError false', $r['json']['result']['isError'], false);

$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => ['name' => 'нетакого']]);
is_('неизвестный инструмент → ошибка ПРОТОКОЛА', $r['json']['error']['code'], -32602);
is_('и всё равно HTTP 200', $r['status'], 200);

$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call',
	'params' => ['name' => 'empty_it', 'arguments' => []]]);
is_('«не нашлось» → НЕ ошибка протокола', isset($r['json']['error']), false);
is_('«не нашлось» → isError в результате', $r['json']['result']['isError'], true);
is_('и текст причины виден', $r['json']['result']['content'][0]['text'], 'Товар не найден');

$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call',
	'params' => ['name' => 'boom', 'arguments' => []]]);
is_('падение инструмента не роняет протокол', $r['json']['result']['isError'], true);
is_('текст падения виден',
	strpos($r['json']['result']['content'][0]['text'], 'делить на ноль') !== false, true);

echo "\n=== Аргументы ===\n";
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call',
	'params' => ['name' => 'echo_it', 'arguments' => []]]);
is_('нет обязательного → -32602', $r['json']['error']['code'], -32602);
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call',
	'params' => ['name' => 'echo_it', 'arguments' => ['n' => '7']]]);
is_('строка вместо числа не проглатывается', isset($r['json']['error']), true);
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
	'params' => ['name' => 'echo_it', 'arguments' => ['n' => 1, 'опечатка' => 5]]]);
is_('лишний аргумент — ошибка, а не мусор', isset($r['json']['error']), true);
is_('и назван поимённо',
	strpos($r['json']['error']['message'], 'опечатка') !== false, true);

echo "\n=== JSON-RPC ===\n";
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 10, 'method' => 'нетакого/метода']);
is_('неизвестный метод → -32601', $r['json']['error']['code'], -32601);
$r = rpc($H, ['id' => 11, 'method' => 'ping']);
is_('без jsonrpc → -32600', $r['json']['error']['code'], -32600);
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 12, 'method' => 'ping']);
is_('ping → пустой result (на проводе)', strpos($r['body'], '"result":{}') !== false, true);
is_('id возвращается тот же', $r['json']['id'], 12);
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 'строковый-id', 'method' => 'ping']);
is_('строковый id тоже возвращается', $r['json']['id'], 'строковый-id');

echo "\n=== Сборка ответа ===\n";
// Инструменты читают чужие файлы и чужие базы. Один байт не в той кодировке
// раньше ронял json_encode, и наружу уходил код 200 с ПУСТЫМ телом — ответ,
// который не объясняет ничего.
$reg->add(new Tool('bad_bytes', 'Битые байты', 'Возвращает не-UTF-8', ['type' => 'object'],
	function (array $a) { return ['doc' => "\xC0\xE1\xE2", 'ok' => 'обычный текст']; }));
$r = rpc($H, ['jsonrpc' => '2.0', 'id' => 20, 'method' => 'tools/call',
	'params' => ['name' => 'bad_bytes', 'arguments' => []]]);
is_('битые байты не обнуляют ответ', $r['body'] !== '', true);
is_('и это по-прежнему 200', $r['status'], 200);
is_('годная часть ответа уцелела',
	strpos($r['body'], 'обычный текст') !== false, true);
is_('негодные байты заменены',
	strpos($r['body'], "\u{FFFD}") !== false || strpos($r['body'], 'ufffd') !== false, true);

echo "\n=== Ограничение частоты ===\n";
// Ограничитель обязан срабатывать ДО разбора токена: иначе перебор токенов
// бесплатен для нападающего и дорог для базы.
$seen = [];
$callT = function (array $h, $body, callable $throttle, string $method = 'POST') use (&$seen) {
	global $authorize, $dispatch, $ORIGINS;
	$auth = function (string $t) use ($authorize, &$seen) { $seen[] = 'auth'; return $authorize($t); };
	$raw = is_string($body) ? $body : json_encode($body);
	return Transport::respond($method, $h, $raw, $auth, $dispatch, $ORIGINS, $throttle);
};
$never = function () { return null; };
$always = function () { return 42; };

$seen = [];
$r = $callT($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $always);
is_('превышение → 429', $r['status'], 429);
is_('429 несёт Retry-After', $r['headers']['Retry-After'], '42');
is_('токен при отказе НЕ разбирался', $seen, []);

$seen = [];
$r = $callT($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $never);
is_('в пределах нормы — обычный ответ', $r['status'], 200);
is_('и токен разобран', $seen, ['auth']);

is_('без ограничителя всё как было',
	call($H, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])['status'], 200);
// Отказ по методу и по Origin дешевле счётчика, поэтому стоит раньше него.
is_('GET отвергается до счётчика', $callT($H, '', $always, 'GET')['status'], 405);
is_('чужой Origin — до счётчика',
	$callT($H + ['Origin' => 'https://evil.example'], [], $always)['status'], 403);

echo "\n=== Разбор ответа для журнала ===\n";
// Server::failure — единственная часть стыка с Битриксом, которую можно проверить
// отдельно. Именно здесь был фатал: ping отвечает объектом «{}», а разбирали его
// как массив, и запрос падал целиком.
require $lib . 'Server.php';
is_('ping (result — объект) не роняет разбор',
	\Itb\Mcp\Server::failure(Protocol::ok(1, new \stdClass())), null);
is_('обычный удачный результат', \Itb\Mcp\Server::failure(Protocol::ok(1, ['tools' => []])), null);
is_('уведомление', \Itb\Mcp\Server::failure(null), null);
is_('ошибка протокола', \Itb\Mcp\Server::failure(Protocol::err(1, -32601, 'нет метода')), 'нет метода');
is_('isError с текстом', \Itb\Mcp\Server::failure(
	Protocol::ok(1, ['content' => [['type' => 'text', 'text' => 'не нашёл']], 'isError' => true])), 'не нашёл');
is_('isError без текста', \Itb\Mcp\Server::failure(Protocol::ok(1, ['isError' => true])), 'isError');
is_('isError=false — не ошибка', \Itb\Mcp\Server::failure(
	Protocol::ok(1, ['content' => [], 'isError' => false])), null);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
