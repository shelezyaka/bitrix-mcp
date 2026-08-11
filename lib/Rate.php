<?php
namespace Itb\Mcp;

/**
 * Ограничение частоты обращений по IP.
 *
 * ⚠ Настоящий DDoS этим не остановить: веб-сервер и загрузка ядра Битрикса
 * происходят ДО нашего кода, и заплатить за них придётся в любом случае. Смысл
 * ограничителя в другом — не давать наплыву доходить до базы и до инструментов.
 * Защита от самого потока запросов — задача nginx, Apache или того, что стоит
 * перед сайтом.
 *
 * Проверка стоит РАНЬШЕ разбора токена: иначе перебор токенов сам по себе
 * оставался бы бесплатным для нападающего и дорогим для базы.
 */
class Rate
{
	/** Длина окна, секунд. */
	const WINDOW = 60;

	/**
	 * Проверить и посчитать обращение.
	 *
	 * @return array{allowed:bool, retry:int, first:bool}
	 *         first — первое превышение в этом окне: по нему пишется одна строка
	 *         в журнал, чтобы наплыв был виден, но не залил журнал собой.
	 */
	public static function hit(string $ip): array
	{
		$limit = (int)\Bitrix\Main\Config\Option::get('itb.mcp', 'rate_limit', 120);
		if ($limit <= 0 || $ip === '') {
			return ['allowed' => true, 'retry' => 0, 'first' => false];
		}

		$now    = time();
		$window = $now - ($now % self::WINDOW);

		try {
			$hits = self::count($ip, $window);
		} catch (\Throwable $e) {
			// Сломанный счётчик не должен закрывать доступ: это защита от наплыва,
			// а не средство авторизации.
			error_log('itb.mcp: счётчик частоты недоступен: ' . $e->getMessage());
			return ['allowed' => true, 'retry' => 0, 'first' => false];
		}

		if ($hits <= $limit) {
			return ['allowed' => true, 'retry' => 0, 'first' => false];
		}

		return [
			'allowed' => false,
			'retry'   => max(1, $window + self::WINDOW - $now),
			'first'   => $hits === $limit + 1,
		];
	}

	/**
	 * Увеличить счётчик и вернуть новое значение.
	 *
	 * ⚠ Одним запросом и атомарно: два шага «прочитать, потом записать» под
	 * наплывом считают неверно — именно тогда, когда счётчик и нужен.
	 */
	private static function count(string $ip, int $window): int
	{
		$db = \Bitrix\Main\Application::getConnection();

		if (strtolower((string)$db->getType()) !== 'mysql') {
			// На другой СУБД синтаксис иной; лучше не считать вовсе, чем считать
			// неверно и закрывать доступ живым клиентам.
			return 0;
		}

		$sql = "INSERT INTO itb_mcp_rate (IP, WINDOW_TS, HITS) VALUES ('"
			. $db->getSqlHelper()->forSql($ip, 45) . "', " . $window . ", 1)
			ON DUPLICATE KEY UPDATE
				HITS = IF(WINDOW_TS = " . $window . ", HITS + 1, 1),
				WINDOW_TS = " . $window;
		$db->query($sql);

		$row = $db->query("SELECT HITS FROM itb_mcp_rate WHERE IP = '"
			. $db->getSqlHelper()->forSql($ip, 45) . "'")->fetch();

		return (int)($row['HITS'] ?? 0);
	}

	/** Чистка покинутых адресов — раз в сотню обращений, как у журнала. */
	public static function prune(): void
	{
		if (random_int(1, 100) !== 1) { return; }

		try {
			$db = \Bitrix\Main\Application::getConnection();
			$db->query('DELETE FROM itb_mcp_rate WHERE WINDOW_TS < ' . (time() - 3600));
		} catch (\Throwable $e) {
			// Не критично: таблица маленькая, вычистится в следующий раз.
		}
	}
}
