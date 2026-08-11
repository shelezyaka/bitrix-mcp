<?php
namespace Itb\Mcp;

/**
 * Транспорт Streamable HTTP. Без зависимостей от Битрикса.
 *
 * Спецификация разрешает не делать SSE, поток по GET и сессии.
 * Требует: проверку Origin, 400 на чужой MCP-Protocol-Version, 202 на уведомление.
 */
class Transport
{
	const MAX_BODY = 262144;

	/**
	 * @param callable      $authorize fn(string $bearer): Registry, бросает AuthError
	 * @param callable      $dispatch  fn(array $msg, Registry $reg): ?array
	 * @param string[]      $origins   разрешённые Origin; пусто — браузерам нельзя
	 * @param callable|null $throttle  fn(): ?int — сколько секунд ждать, либо null
	 * @return array{status:int,headers:array,body:string}
	 */
	public static function respond(string $httpMethod, array $headers, string $rawBody,
		callable $authorize, callable $dispatch, array $origins = [],
		?callable $throttle = null): array
	{
		$h = [];
		foreach ($headers as $k => $v) { $h[strtolower((string)$k)] = (string)$v; }

		if (strtoupper($httpMethod) !== 'POST') {
			return self::plain(405, 'Только POST', ['Allow' => 'POST']);
		}

		// Отсутствие Origin — не браузер, пропускаем. Чужой Origin отвергаем.
		// CORS-заголовков не отдаём вовсе, поэтому браузер до запроса не дойдёт.
		$origin = $h['origin'] ?? '';
		if ($origin !== '' && !in_array($origin, $origins, true)) {
			return self::plain(403, 'Origin не разрешён');
		}

		if (strlen($rawBody) > self::MAX_BODY) {
			return self::plain(413, 'Тело запроса слишком велико');
		}

		// Ограничитель стоит РАНЬШЕ разбора токена: иначе перебор токенов был бы
		// бесплатен для нападающего и дорог для базы.
		if ($throttle !== null) {
			$wait = $throttle();
			if ($wait !== null) {
				return self::plain(429, 'Слишком часто, подождите ' . (int)$wait . ' с',
					['Retry-After' => (string)(int)$wait]);
			}
		}

		if (strpos(strtolower($h['content-type'] ?? ''), 'application/json') === false) {
			return self::plain(415, 'Ожидается Content-Type: application/json');
		}

		$bearer = '';
		if (preg_match('~^\s*Bearer\s+(\S+)\s*$~i', $h['authorization'] ?? '', $m)) {
			$bearer = $m[1];
		}
		try {
			$reg = $authorize($bearer);
		} catch (AuthError $e) {
			return self::plain(401, 'Токен не принят', ['WWW-Authenticate' => 'Bearer']);
		}

		$ver = $h['mcp-protocol-version'] ?? '';
		if ($ver === '') { $ver = Protocol::FALLBACK_VERSION; }
		if (!in_array($ver, Protocol::VERSIONS, true)) {
			return self::plain(400, 'Версия протокола не поддерживается: ' . $ver);
		}

		$msg = json_decode($rawBody, true);
		if (!is_array($msg)) {
			return self::json(400, Protocol::err(null, Protocol::E_PARSE, 'Тело не разбирается как JSON'));
		}
		// Пачки (batch) в этой версии протокола запрещены: тело — одно сообщение.
		if ($msg !== [] && array_keys($msg) === range(0, count($msg) - 1)) {
			return self::json(400, Protocol::err(null, Protocol::E_REQUEST,
				'Тело должно быть одним сообщением, а не списком'));
		}

		$res = $dispatch($msg, $reg);

		if ($res === null) { return ['status' => 202, 'headers' => [], 'body' => '']; }

		// Ошибка JSON-RPC едет в теле с кодом 200: отданная как HTTP 4xx, она
		// показалась бы клиенту недоступностью сервера, а не причиной.
		return self::json(200, $res);
	}

	private static function json(int $status, array $payload): array
	{
		return [
			'status'  => $status,
			'headers' => ['Content-Type' => 'application/json; charset=utf-8'],
			'body'    => (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		];
	}

	private static function plain(int $status, string $text, array $extra = []): array
	{
		return [
			'status'  => $status,
			'headers' => ['Content-Type' => 'text/plain; charset=utf-8'] + $extra,
			'body'    => $text,
		];
	}
}
