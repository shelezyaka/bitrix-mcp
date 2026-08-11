<?php
namespace Itb\Mcp;

use Itb\Mcp\Orm\LogTable;

/**
 * Журнал обращений: одна строка на запрос, пишется в самом конце.
 *
 * ⚠️ Контекст копится по ходу обработки (`note`), запись идёт один раз (`flush`).
 * Писать по кусочкам — значит однажды получить обращение, залогированное
 * наполовину: у отказа авторизации нет инструмента, у отказа по Origin нет и
 * токена, и три разных места записи разошлись бы по составу полей.
 *
 * ⚠️ Пишется КАЖДЫЙ запрос, включая отвергнутые. Перебор токенов и чужой Origin
 * видны только в отказах; журнал одних успехов показывает ровно ту картину,
 * которую хотел бы видеть тот, кто ломится.
 */
class Audit
{
	/** Потолок длины аргументов в журнале. */
	const ARGS_MAX = 1000;

	private static $ctx = [];
	private static $t0  = 0.0;
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

	/** Аргументы вызова — в журнал, обрезанными. */
	public static function args(array $args): void
	{
		$s = (string)json_encode($args, JSON_UNESCAPED_UNICODE);
		self::$ctx['ARGS'] = mb_substr($s, 0, self::ARGS_MAX);
	}

	public static function flush(int $status, int $size): void
	{
		// ⚠️ Ровно один раз за запрос: `flush` зовётся из завершающего обработчика,
		// а тот срабатывает и после обычного выхода, и после фатальной ошибки.
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
			// ⚠️ Сломанный журнал не должен ронять ответ: доступ к данным важнее
			// записи о доступе. Но и молчать нельзя — пишем в лог PHP.
			error_log('itb.mcp: журнал не пишется: ' . $e->getMessage());
		}
	}

	/**
	 * Чистка старых записей.
	 *
	 * ⚠️ Раз в сотню запросов, а не каждый: удаление по дате на каждом обращении
	 * это лишний запрос там, где важна скорость ответа. И не агентом — агент ради
	 * одной DELETE выглядит солиднее, чем стоит.
	 */
	private static function prune(): void
	{
		$days = (int)\Bitrix\Main\Config\Option::get('itb.mcp', 'log_days', 30);
		if ($days <= 0 || random_int(1, 100) !== 1) { return; }

		$edge = new \Bitrix\Main\Type\DateTime();
		$edge->add('-' . $days . ' days');

		$rs = LogTable::getList([
			'select' => ['ID'],
			'filter' => ['<CREATED_AT' => $edge],
			'limit'  => 5000,
		]);
		while ($r = $rs->fetch()) { LogTable::delete($r['ID']); }
	}

	public static function tail(int $n = 50): array
	{
		return LogTable::getList(['order' => ['ID' => 'DESC'], 'limit' => $n])->fetchAll();
	}
}
