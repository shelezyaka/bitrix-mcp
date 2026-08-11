<?php
namespace Itb\Mcp;

/**
 * Стык с сайтом: собрать настоящий HTTP-запрос, отдать настоящий ответ.
 *
 * ⚠️ Здесь и только здесь модуль знает про суперглобальные массивы и Битрикс.
 * Логика протокола лежит в `Protocol` и `Transport` и проверяется тестом из
 * консоли (`tests/protocol.php`). Всё, что попадёт сюда, тестом уже не покрыто —
 * значит сюда попадает как можно меньше.
 *
 * ⚠️⚠️ Обработчиков событий модуль НЕ регистрирует. Запрос приходит на
 * собственную точку входа `/mcp/index.php`, поэтому на страницах магазина не
 * выполняется ни строки этого кода. Готовый модуль с гитхаба ловил запросы через
 * `OnProlog`, то есть жил на каждом хите витрины — ради того лишь, чтобы не
 * заводить отдельный файл.
 */
class Server
{
	public static function handle(): void
	{
		Audit::start();

		// ⚠️ Фатальная ошибка не проходит через наш код вовсе: ответ обрывается,
		// и в журнале не остаётся ничего — то есть самый интересный случай виден
		// хуже всех остальных. Завершающий обработчик дописывает строку и тогда.
		register_shutdown_function(static function () {
			$e = error_get_last();
			if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
				Audit::note(['ERROR' => mb_substr('fatal: ' . $e['message'], 0, 255)]);
				Audit::flush(500, 0);
			}
		});

		$r = Transport::respond(
			(string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
			self::headers(),
			(string)file_get_contents('php://input'),
			[Auth::class, 'registryFor'],
			[self::class, 'dispatch'],
			self::origins()
		);

		// ⚠️ Буферы сбрасываем до заголовков: любая случайная строка, выведенная
		// ядром до нас, превращает JSON в мусор, а причину прячет.
		while (ob_get_level() > 0) { ob_end_clean(); }

		http_response_code($r['status']);
		foreach ($r['headers'] as $k => $v) { header($k . ': ' . $v); }
		// Ответы MCP кэшировать нельзя: это данные, а не страница.
		header('Cache-Control: no-store');
		echo $r['body'];

		Audit::flush($r['status'], strlen($r['body']));
	}

	/**
	 * Обёртка над ядром протокола: то же самое плюс запись в журнал.
	 *
	 * ⚠️ Именно обёртка, а не правка `Protocol`: ядро обязано оставаться без
	 * побочных действий, иначе его нельзя гонять тестом.
	 */
	public static function dispatch(array $msg, Registry $reg): ?array
	{
		$method = (string)($msg['method'] ?? '');
		Audit::note(['RPC_METHOD' => mb_substr($method, 0, 64)]);

		if ($method === 'tools/call') {
			$p = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];
			Audit::note(['TOOL' => mb_substr((string)($p['name'] ?? ''), 0, 128)]);
			Audit::args(isset($p['arguments']) && is_array($p['arguments']) ? $p['arguments'] : []);
		}

		$res = Protocol::dispatch($msg, $reg);

		// Ошибку протокола тоже видно в журнале: «инструмент звали, но не тот»
		// снаружи неотличимо от «не звали вовсе».
		if (isset($res['error']['message'])) {
			Audit::note(['ERROR' => mb_substr((string)$res['error']['message'], 0, 255)]);
		} elseif (!empty($res['result']['isError'])) {
			Audit::note(['ERROR' => mb_substr(
				(string)($res['result']['content'][0]['text'] ?? 'isError'), 0, 255)]);
		}

		return $res;
	}

	/** Заголовки запроса в виде «имя => значение». */
	private static function headers(): array
	{
		$out = [];
		foreach ($_SERVER as $k => $v) {
			if (strncmp($k, 'HTTP_', 5) === 0) {
				$out[str_replace('_', '-', substr($k, 5))] = (string)$v;
			}
		}
		// ⚠️ Content-Type и Content-Length приходят БЕЗ префикса HTTP_ — это
		// давняя особенность CGI, и без этих двух строк проверка типа тела
		// отвергала бы любой корректный запрос.
		if (isset($_SERVER['CONTENT_TYPE']))   { $out['CONTENT-TYPE'] = (string)$_SERVER['CONTENT_TYPE']; }
		if (isset($_SERVER['CONTENT_LENGTH'])) { $out['CONTENT-LENGTH'] = (string)$_SERVER['CONTENT_LENGTH']; }
		return $out;
	}

	/**
	 * Разрешённые Origin.
	 *
	 * ⚠️ По умолчанию — пусто, то есть браузерам нельзя вовсе. Обычный MCP-клиент
	 * заголовок Origin не шлёт и проверку проходит; заголовок появляется там, где
	 * запрос делает браузер, — и вот его пускать некуда.
	 */
	private static function origins(): array
	{
		$raw = trim((string)\Bitrix\Main\Config\Option::get('itb.mcp', 'origins', ''));
		if ($raw === '') { return []; }
		return array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw)))));
	}
}
