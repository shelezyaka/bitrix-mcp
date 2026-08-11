<?php
namespace Itb\Mcp;

/**
 * Ядро протокола MCP: JSON-RPC поверх Streamable HTTP.
 * Без зависимостей от Битрикса — проверяется тестом tests/protocol.php.
 */
class Protocol
{
	/** Поддерживаемые версии, первая — наша последняя. */
	const VERSIONS = ['2025-06-18', '2025-03-26'];

	/** Версия при отсутствии заголовка MCP-Protocol-Version (так в спецификации). */
	const FALLBACK_VERSION = '2025-03-26';

	const NAME    = 'bitrix-mcp';
	const TITLE   = 'Bitrix MCP (только чтение)';
	const VERSION = '0.1.0';

	const E_PARSE    = -32700;
	const E_REQUEST  = -32600;
	const E_METHOD   = -32601;
	const E_PARAMS   = -32602;
	const E_INTERNAL = -32603;

	/** Сообщение → ответ. null означает уведомление: транспорт отдаст 202 без тела. */
	public static function dispatch(array $msg, Registry $reg): ?array
	{
		$hasId  = array_key_exists('id', $msg) && $msg['id'] !== null;
		$id     = $hasId ? $msg['id'] : null;
		$method = isset($msg['method']) && is_string($msg['method']) ? $msg['method'] : '';
		$params = isset($msg['params']) && is_array($msg['params']) ? $msg['params'] : [];

		if (($msg['jsonrpc'] ?? '') !== '2.0') {
			return $hasId ? self::err($id, self::E_REQUEST, 'Ожидается jsonrpc 2.0') : null;
		}
		if ($method === '') {
			return $hasId ? self::err($id, self::E_REQUEST, 'Не указан method') : null;
		}
		if (!$hasId) { return null; }

		switch ($method) {
			case 'initialize': return self::ok($id, self::initialize($params, $reg));
			case 'ping':       return self::ok($id, new \stdClass());
			case 'tools/list': return self::ok($id, ['tools' => $reg->schema()]);
			case 'tools/call': return self::call($id, $params, $reg);
		}

		return self::err($id, self::E_METHOD, 'Метод не поддерживается: ' . $method);
	}

	/** Версия согласуется, а не проверяется: чужую не отвергаем, а называем свою. */
	private static function initialize(array $params, Registry $reg): array
	{
		$want = (string)($params['protocolVersion'] ?? '');
		$use  = in_array($want, self::VERSIONS, true) ? $want : self::VERSIONS[0];

		return [
			'protocolVersion' => $use,
			// listChanged=false: соединения между вызовами не держим, уведомить не о чем.
			'capabilities' => ['tools' => ['listChanged' => false]],
			'serverInfo'   => ['name' => self::NAME, 'title' => self::TITLE, 'version' => self::VERSION],
			'instructions' => $reg->instructions(),
		];
	}

	/**
	 * Вызов инструмента.
	 *
	 * Неизвестный инструмент и негодные аргументы — ошибка протокола (сбой).
	 * Инструмент отработал, но данных нет — результат с isError, его видит модель
	 * и может исправиться сама.
	 */
	private static function call($id, array $params, Registry $reg): array
	{
		$name = (string)($params['name'] ?? '');
		$args = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];

		$tool = $reg->find($name);
		if (!$tool) { return self::err($id, self::E_PARAMS, 'Неизвестный инструмент: ' . $name); }

		$bad = Schema::validate($args, $tool->inputSchema);
		if ($bad !== null) { return self::err($id, self::E_PARAMS, 'Аргументы не подходят: ' . $bad); }

		try {
			$res = ($tool->handler)($args);
		} catch (ToolError $e) {
			return self::ok($id, ['content' => [self::text($e->getMessage())], 'isError' => true]);
		} catch (\Throwable $e) {
			// Наружу текст, но не трассировка: в ней пути и куски запросов.
			return self::ok($id, [
				'content' => [self::text('Внутренняя ошибка инструмента: ' . $e->getMessage())],
				'isError' => true,
			]);
		}

		return self::ok($id, self::result($res));
	}

	/** Структурированный результат дублируется текстом — требование спецификации. */
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
