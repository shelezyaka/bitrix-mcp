<?php
namespace Itb\Mcp;

use Itb\Mcp\Orm\LogTable;

/**
 * Журнал обращений: строка на запрос, включая отвергнутые.
 * Контекст копится по ходу, запись идёт один раз в конце.
 */
class Audit
{
	const ARGS_MAX = 1000;

	private static $ctx  = [];
	private static $t0   = 0.0;
	private static $done = false;

	public static function start(): void
	{
		self::$t0   = microtime(true);
		self::$done = false;
		self::$ctx  = [
			'TOKEN_ID'   => 0,
			'IP'         => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
			'RPC_METHOD' => '',
			'TOOL'       => '',
			'ARGS'       => '',
			'ERROR'      => '',
		];
	}

	public static function note(array $kv): void
	{
		foreach ($kv as $k => $v) { self::$ctx[$k] = $v; }
	}

	public static function args(array $args): void
	{
		$s = (string)json_encode($args, JSON_UNESCAPED_UNICODE);
		self::$ctx['ARGS'] = mb_substr($s, 0, self::ARGS_MAX);
	}

	public static function flush(int $status, int $size): void
	{
		// Зовётся и из завершающего обработчика, поэтому защита от второй записи.
		if (self::$done) { return; }
		self::$done = true;

		try {
			LogTable::add(self::$ctx + [
				'CREATED_AT'  => new \Bitrix\Main\Type\DateTime(),
				'HTTP_STATUS' => $status,
				'MS'          => (int)round((microtime(true) - self::$t0) * 1000),
				'SIZE'        => $size,
			]);
			self::prune();
		} catch (\Throwable $e) {
			// Сломанный журнал не должен ронять ответ, но и молчать не должен.
			error_log('itb.mcp: журнал не пишется: ' . $e->getMessage());
		}
	}

	/** Чистка старых записей — раз в сотню запросов, не на каждом. */
	private static function prune(): void
	{
		$days = (int)\Bitrix\Main\Config\Option::get('itb.mcp', 'log_days', 30);
		if ($days <= 0 || random_int(1, 100) !== 1) { return; }

		$edge = new \Bitrix\Main\Type\DateTime();
		$edge->add('-' . $days . ' days');

		$rs = LogTable::getList(['select' => ['ID'], 'filter' => ['<CREATED_AT' => $edge], 'limit' => 5000]);
		while ($r = $rs->fetch()) { LogTable::delete($r['ID']); }
	}

	public static function tail(int $n = 50): array
	{
		return LogTable::getList(['order' => ['ID' => 'DESC'], 'limit' => $n])->fetchAll();
	}
}
