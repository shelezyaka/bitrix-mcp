<?php
namespace Itb\Mcp;

/**
 * Стык с сайтом: собрать HTTP-запрос, отдать ответ.
 * Единственное место, где модуль знает про суперглобальные массивы и Битрикс.
 */
class Server
{
	public static function handle(): void
	{
		Audit::start();

		// Фатальная ошибка не проходит через наш код — строку в журнал дописываем отсюда.
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

		self::dropSession();

		// Буферы сбрасываем до заголовков: случайный вывод ядра испортил бы JSON.
		while (ob_get_level() > 0) { ob_end_clean(); }

		http_response_code($r['status']);
		foreach ($r['headers'] as $k => $v) { header($k . ': ' . $v); }
		header('Cache-Control: no-store');
		echo $r['body'];

		Audit::flush($r['status'], strlen($r['body']));
	}

	/**
	 * Сессия, заведённая ядром, здесь не нужна: авторизация идёт по токену.
	 * Без этого каждый вызов оставлял бы после себя запись сессии.
	 */
	private static function dropSession(): void
	{
		try {
			$s = \Bitrix\Main\Application::getInstance()->getSession();
			if ($s && $s->isStarted()) { $s->destroy(); }
		} catch (\Throwable $e) {
			// Ядро старше 20.5 — SessionInterface там нет, и это не повод падать.
		}
	}

	/** Обёртка над ядром протокола: то же самое плюс запись в журнал. */
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

		if (isset($res['error']['message'])) {
			Audit::note(['ERROR' => mb_substr((string)$res['error']['message'], 0, 255)]);
		} elseif (!empty($res['result']['isError'])) {
			Audit::note(['ERROR' => mb_substr(
				(string)($res['result']['content'][0]['text'] ?? 'isError'), 0, 255)]);
		}

		return $res;
	}

	private static function headers(): array
	{
		$out = [];
		foreach ($_SERVER as $k => $v) {
			if (strncmp($k, 'HTTP_', 5) === 0) {
				$out[str_replace('_', '-', substr($k, 5))] = (string)$v;
			}
		}
		// Content-Type и Content-Length приходят без префикса HTTP_ (особенность CGI).
		if (isset($_SERVER['CONTENT_TYPE']))   { $out['CONTENT-TYPE'] = (string)$_SERVER['CONTENT_TYPE']; }
		if (isset($_SERVER['CONTENT_LENGTH'])) { $out['CONTENT-LENGTH'] = (string)$_SERVER['CONTENT_LENGTH']; }

		if (($out['AUTHORIZATION'] ?? '') === '') { $out['AUTHORIZATION'] = self::authHeader(); }

		return $out;
	}

	/**
	 * Apache кладёт Authorization в $_SERVER не всегда: под CGI он теряется без
	 * CGIPassAuth, при переписывании адресов приезжает как REDIRECT_HTTP_AUTHORIZATION.
	 */
	private static function authHeader(): string
	{
		if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
			return (string)$_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
		}

		foreach (['apache_request_headers', 'getallheaders'] as $fn) {
			if (function_exists($fn)) {
				foreach ((array)$fn() as $k => $v) {
					if (strcasecmp((string)$k, 'Authorization') === 0) { return (string)$v; }
				}
			}
		}

		return '';
	}

	/** Пусто — браузерам нельзя вовсе. Обычный MCP-клиент Origin не шлёт. */
	private static function origins(): array
	{
		$raw = trim((string)\Bitrix\Main\Config\Option::get('itb.mcp', 'origins', ''));
		if ($raw === '') { return []; }
		return array_values(array_filter(array_map('trim', explode("\n", str_replace(',', "\n", $raw)))));
	}
}
