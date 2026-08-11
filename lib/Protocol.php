<?php
namespace Itb\Mcp;

/**
 * Ядро протокола MCP — JSON-RPC поверх Streamable HTTP.
 *
 * ⚠️⚠️ Здесь НЕТ ни одной строки про Битрикс, и это главное свойство файла.
 * Протокол проверяется прогоном из консоли: сообщение на входе — сообщение на
 * выходе. Иначе «клиент не подключается» ловит человек, сидящий перед клиентом,
 * который на любую нашу ошибку показывает одну строку «не удалось соединиться».
 * Данные подставляет реестр (`Registry`), он же единственная точка, где начинается
 * сайт.
 *
 * Спецификация: modelcontextprotocol.io/specification/2025-06-18
 */
class Protocol
{
	/**
	 * Версии, которые мы умеем. Первая — наша последняя.
	 *
	 * ⚠️ Список нужен целиком, а не одна константа: при несовпадении версий
	 * отвечать ошибкой НЕЛЬЗЯ. По спецификации сервер обязан назвать ту версию,
	 * которую поддерживает сам, и дальше решает клиент.
	 */
	const VERSIONS = ['2025-06-18', '2025-03-26'];

	/**
	 * Версия по умолчанию, когда заголовка `MCP-Protocol-Version` нет вовсе.
	 *
	 * ⚠️ Именно `2025-03-26`, а не наша последняя: так сказано в спецификации.
	 * Заголовок появился позже, и его отсутствие означает старого клиента, а не
	 * «клиент согласен на что угодно».
	 */
	const FALLBACK_VERSION = '2025-03-26';

	const NAME    = 'bitrix-mcp';
	const TITLE   = 'Bitrix MCP (только чтение)';
	const VERSION = '0.1.0';

	// Коды JSON-RPC 2.0.
	const E_PARSE      = -32700;
	const E_REQUEST    = -32600;
	const E_METHOD     = -32601;
	const E_PARAMS     = -32602;
	const E_INTERNAL   = -32603;

	/**
	 * Разобранное сообщение → ответ, либо null для уведомлений.
	 *
	 * ⚠️ null здесь означает «ответа быть не должно», а не «ошибка». Транспорт
	 * обязан превратить его в `202 Accepted` с пустым телом — так требует
	 * спецификация, и клиент на этом строит свой конечный автомат.
	 */
	public static function dispatch(array $msg, Registry $reg): ?array
	{
		// Уведомление и ответ клиента отличаются от запроса ровно отсутствием id.
		$hasId  = array_key_exists('id', $msg) && $msg['id'] !== null;
		$id     = $hasId ? $msg['id'] : null;
		$method = isset($msg['method']) && is_string($msg['method']) ? $msg['method'] : '';
		$params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];

		if (($msg['jsonrpc'] ?? '') !== '2.0') {
			return $hasId ? self::err($id, self::E_REQUEST, 'Ожидается jsonrpc 2.0') : null;
		}
		// Ответ клиента (result/error без method) нам слать некому — молча принимаем.
		if ($method === '') {
			return $hasId ? self::err($id, self::E_REQUEST, 'Не указан method') : null;
		}
		if (!$hasId) {
			// Уведомления: подтверждать нечем, но и ошибкой отвечать нельзя.
			return null;
		}

		switch ($method) {
			case 'initialize':      return self::ok($id, self::initialize($params, $reg));
			case 'ping':            return self::ok($id, new \stdClass());
			case 'tools/list':      return self::ok($id, ['tools' => $reg->schema()]);
			case 'tools/call':      return self::call($id, $params, $reg);
		}

		return self::err($id, self::E_METHOD, 'Метод не поддерживается: ' . $method);
	}

	/**
	 * Рукопожатие.
	 *
	 * ⚠️ Версия согласуется, а не проверяется: просит клиент знакомую — отвечаем
	 * ею же, незнакомую — называем свою последнюю. Ошибка здесь означала бы «мы
	 * несовместимы», хотя клиент, возможно, умеет обе.
	 */
	private static function initialize(array $params, Registry $reg): array
	{
		$want = (string)($params['protocolVersion'] ?? '');
		$use  = in_array($want, self::VERSIONS, true) ? $want : self::VERSIONS[0];

		return [
			'protocolVersion' => $use,
			// listChanged=false — реестр меняется правкой настроек в админке, и
			// уведомлять об этом нам нечем: соединения между вызовами мы не держим.
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo'   => ['name' => self::NAME, 'title' => self::TITLE, 'version' => self::VERSION],
			'instructions' => $reg->instructions(),
		];
	}

	/**
	 * Вызов инструмента.
	 *
	 * ⚠️⚠️ Два РАЗНЫХ вида ошибок, и путать их нельзя. Неизвестный инструмент и
	 * негодные аргументы — ошибка ПРОТОКОЛА (`error`), клиент показывает её как
	 * сбой. Инструмент отработал, но сказать нечего («товар не найден») — это
	 * результат с `isError`, и он уходит МОДЕЛИ, которая может решить, что делать
	 * дальше. Свернув второе в первое, мы лишаем её возможности исправиться.
	 */
	private static function call($id, array $params, Registry $reg): array
	{
		$name = (string)($params['name'] ?? '');
		$args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

		$tool = $reg->find($name);
		if (!$tool) {
			return self::err($id, self::E_PARAMS, 'Неизвестный инструмент: ' . $name);
		}

		// «Validate all tool inputs» из раздела безопасности спецификации. Проверяем
		// у себя: отказ на стороне инструмента объясняет хуже, а иногда и дороже.
		$bad = Schema::validate($args, $tool->inputSchema);
		if ($bad !== null) {
			return self::err($id, self::E_PARAMS, 'Аргументы не подходят: ' . $bad);
		}

		try {
			$res = ($tool->handler)($args);
		} catch (ToolError $e) {
			return self::ok($id, ['content' => [self::text($e->getMessage())], 'isError' => true]);
		} catch (\Throwable $e) {
			// ⚠️ Наружу уходит класс исключения и текст, но НЕ трассировка: в ней
			// пути на диске и куски запросов. Подробности — в журнал вызовов.
			return self::ok($id, [
				'content' => [self::text('Внутренняя ошибка инструмента: ' . $e->getMessage())],
				'isError' => true,
			]);
		}

		return self::ok($id, self::result($res));
	}

	/**
	 * Приведение того, что вернул инструмент, к форме ответа MCP.
	 *
	 * ⚠️ Структурированный результат дублируется текстом — так требует
	 * спецификация ради клиентов, которые `structuredContent` ещё не понимают.
	 * Без дубля такой клиент получает пустое сообщение вместо данных.
	 */
	private static function result($res): array
	{
		if (is_string($res)) { return ['content' => [self::text($res)], 'isError' => false]; }

		$json = json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		return [
			'content'           => [self::text((string)$json)],
			'structuredContent' => $res,
			'isError'           => false,
		];
	}

	private static function text(string $s): array
	{
		return ['type' => 'text', 'text' => $s];
	}

	public static function ok($id, $result): array
	{
		return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
	}

	public static function err($id, int $code, string $message, ?array $data = null): array
	{
		$e = ['code' => $code, 'message' => $message];
		if ($data !== null) { $e['data'] = $data; }
		return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $e];
	}
}
