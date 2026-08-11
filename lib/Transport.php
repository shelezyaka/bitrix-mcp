<?php
namespace Itb\Mcp;

/**
 * Транспорт Streamable HTTP.
 *
 * ⚠️ Как и `Protocol`, ничего не знает ни о Битриксе, ни о суперглобальных
 * массивах: на входе метод, заголовки и тело, на выходе код, заголовки и тело.
 * Поэтому «405 на GET», «202 на уведомление» и «400 на чужую версию» проверяются
 * прогоном из консоли, а не запросами к боевому сайту.
 *
 * Что спецификация РАЗРЕШАЕТ не делать, и мы не делаем:
 *   — SSE. На запрос можно ответить обычным `application/json`;
 *   — поток серверных сообщений по GET. Разрешено ответить `405`;
 *   — сессии. `Mcp-Session-Id` — MAY, мы без состояния.
 *
 * Что спецификация ТРЕБУЕТ и что легко пропустить:
 *   — проверку заголовка `Origin` (защита от DNS rebinding);
 *   — `400` на неизвестный `MCP-Protocol-Version`;
 *   — при отсутствии этого заголовка считать версию `2025-03-26`;
 *   — `202` без тела в ответ на уведомление.
 */
class Transport
{
	/** Потолок тела запроса. Инструменты читают, им незачем принимать мегабайты. */
	const MAX_BODY = 262144;

	/**
	 * @param callable $authorize fn(string $bearer): Registry — бросает AuthError
	 * @param callable $dispatch  fn(array $msg, Registry $reg): ?array
	 * @param string[] $origins   разрешённые Origin; пустой список = браузерам нельзя
	 * @return array{status:int,headers:array,body:string}
	 */
	public static function respond(string $httpMethod, array $headers, string $rawBody,
		callable $authorize, callable $dispatch, array $origins = []): array
	{
		$h = [];
		foreach ($headers as $k => $v) { $h[strtolower((string)$k)] = (string)$v; }
		$httpMethod = strtoupper($httpMethod);

		// ── Метод ───────────────────────────────────────────────────────────
		if ($httpMethod !== 'POST') {
			// GET и DELETE спецификация разрешает отклонять: поток серверных
			// сообщений и завершение сессии нам не нужны. Отвечаем ровно так, как
			// там написано, чтобы клиент не счёл эндпоинт сломанным.
			return self::plain(405, 'Только POST', ['Allow' => 'POST']);
		}

		// ── Origin ──────────────────────────────────────────────────────────
		//
		// ⚠️⚠️ Требование спецификации, и оно не формальность. Без него страница
		// в браузере жертвы может ходить на этот эндпоинт от её имени (DNS
		// rebinding). Отсутствие заголовка — это НЕ браузер (обычный MCP-клиент
		// его не шлёт), такое пропускаем; чужой Origin отвергаем. CORS-заголовков
		// не отдаём вовсе и preflight не отвечаем — тогда браузер и не дойдёт
		// до запроса.
		$origin = $h['origin'] ?? '';
		if ($origin !== '' && !in_array($origin, $origins, true)) {
			return self::plain(403, 'Origin не разрешён');
		}

		if (strlen($rawBody) > self::MAX_BODY) {
			return self::plain(413, 'Тело запроса слишком велико');
		}

		$ct = strtolower($h['content-type'] ?? '');
		if (strpos($ct, 'application/json') === false) {
			return self::plain(415, 'Ожидается Content-Type: application/json');
		}

		// ── Токен ───────────────────────────────────────────────────────────
		$bearer = '';
		if (preg_match('~^\s*Bearer\s+(\S+)\s*$~i', $h['authorization'] ?? '', $m)) {
			$bearer = $m[1];
		}
		try {
			$reg = $authorize($bearer);
		} catch (AuthError $e) {
			return self::plain(401, 'Токен не принят', ['WWW-Authenticate' => 'Bearer']);
		}

		// ── Версия протокола ────────────────────────────────────────────────
		//
		// ⚠️ Заголовка нет — это старый клиент, а не «согласен на что угодно»:
		// спецификация велит считать версию 2025-03-26. Заголовок есть, но версия
		// чужая — ровно 400, здесь выбора нет.
		$ver = $h['mcp-protocol-version'] ?? '';
		if ($ver === '') { $ver = Protocol::FALLBACK_VERSION; }
		if (!in_array($ver, Protocol::VERSIONS, true)) {
			return self::plain(400, 'Версия протокола не поддерживается: ' . $ver);
		}

		// ── Тело ────────────────────────────────────────────────────────────
		$msg = json_decode($rawBody, true);
		if (!is_array($msg)) {
			return self::json(400, Protocol::err(null, Protocol::E_PARSE, 'Тело не разбирается как JSON'));
		}
		// ⚠️ Пачки (batch) в этой версии протокола запрещены: тело обязано быть
		// ОДНИМ сообщением. Список — это не «несколько запросов», а ошибка.
		if (array_keys($msg) === range(0, count($msg) - 1) && $msg !== []) {
			return self::json(400, Protocol::err(null, Protocol::E_REQUEST,
				'Тело должно быть одним сообщением, а не списком'));
		}

		$res = $dispatch($msg, $reg);

		// ⚠️ null — это уведомление: 202 и ПУСТОЕ тело. Не «200 с null»: клиент
		// ждёт отсутствия ответа и на лишний JSON реагирует как на нарушение.
		if ($res === null) { return ['status' => 202, 'headers' => [], 'body' => '']; }

		// ⚠️ Ошибка JSON-RPC едет в теле с кодом 200. Отдать её как HTTP 4xx —
		// значит спрятать причину: клиент покажет «сервер недоступен» вместо текста.
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
