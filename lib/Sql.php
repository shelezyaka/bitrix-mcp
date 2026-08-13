<?php
namespace Itb\Mcp;

/**
 * Произвольный SELECT к базе сайта.
 *
 * Разбор запроса чистый — Битрикса не касается и проверяется из консоли
 * (tests/sql.php). Проверяется и исполняется ОДИН И ТОТ ЖЕ текст: если
 * проверить исходный запрос, а выполнить очищенный (или наоборот), между двумя
 * этими текстами и живёт обход.
 */
class Sql
{
	const ROWS_DEF = 100;
	const ROWS_MAX = 1000;
	const OPT      = 'sql_tables';

	/**
	 * Таблицы, закрытые всегда: белый список их не открывает, потому что в них
	 * лежит не «чувствительная информация», а прямые ключи от сайта.
	 */
	const DENY = [
		'b_user'             => 'хеши паролей и контрольные слова',
		'b_option'           => 'ключи и пароли модулей',
		'b_user_hit_auth'    => 'данные авторизации',
		'b_user_stored_auth' => 'токены «запомнить меня»',
		'b_user_auth_code'   => 'коды подтверждения',
		'b_user_otp'         => 'одноразовые пароли',
		'itb_mcp_token'      => 'хеши токенов самого MCP',
	];

	/** Конструкции, которые выводят SELECT за пределы чтения. */
	const DENY_WORDS = [
		'into outfile'  => 'запись файла на диск',
		'into dumpfile' => 'запись файла на диск',
		'load_file'     => 'чтение файла с диска',
		'sleep'         => 'удержание соединения',
		'benchmark'     => 'нагрузка на сервер',
		'get_lock'      => 'блокировка',
		'release_lock'  => 'блокировка',
		'processlist'   => 'запросы других пользователей базы',
		'mysql.'        => 'служебная база mysql',
	];

	/**
	 * Комментарии долой, хвостовая точка с запятой долой.
	 * Внутри комментария прячется что угодно, вплоть до второй инструкции,
	 * поэтому проверка идёт по тексту без комментариев — и он же исполняется.
	 */
	public static function clean(string $raw): string
	{
		$q = preg_replace('~/\*.*?\*/~s', ' ', $raw);
		$q = preg_replace('~(--[ \t][^\n]*|#[^\n]*)~', ' ', (string)$q);
		$q = trim(preg_replace('~\s+~', ' ', (string)$q));

		return rtrim($q, "; \t\n\r");
	}

	/**
	 * Причина отказа либо null. Запрос — уже очищенный.
	 *
	 * @param string[] $allow белый список таблиц; пустой — «все, кроме DENY»
	 */
	public static function why(string $sql, array $allow = []): ?string
	{
		if ($sql === '') { return 'Пустой запрос'; }

		$low = strtolower($sql);

		if (!preg_match('~^(select|with)\s~', $low)) {
			return 'Разрешён только SELECT: запрос должен начинаться с SELECT или WITH.';
		}

		// После clean() точка с запятой остаться может только внутри запроса,
		// то есть как попытка выполнить второй.
		if (strpos($sql, ';') !== false) {
			return 'В запросе точка с запятой: выполняется ровно одна инструкция.';
		}

		foreach (self::DENY_WORDS as $word => $why) {
			$pattern = substr($word, -1) === '.'
				? '~\b' . preg_quote($word, '~') . '~'
				: '~\b' . preg_quote($word, '~') . '\b~';
			if (preg_match($pattern, $low)) {
				return 'В запросе «' . $word . '» — ' . $why . '. Это не чтение данных.';
			}
		}

		foreach (self::DENY as $table => $why) {
			if (preg_match('~\b' . preg_quote($table, '~') . '\b~', $low)) {
				return 'Таблица ' . $table . ' закрыта: ' . $why . '.';
			}
		}

		if ($allow) {
			$allow = array_map('strtolower', $allow);
			foreach (self::tablesIn($sql) as $table) {
				if (!in_array($table, $allow, true)) {
					return 'Таблица ' . $table . ' не в белом списке. Разрешены: '
						. implode(', ', $allow) . '.';
				}
			}
		}

		return null;
	}

	/** Таблицы после FROM и JOIN. Подзапросы «FROM (SELECT …)» пропускаются. */
	public static function tablesIn(string $sql): array
	{
		preg_match_all('~\b(?:from|join)\s+`?([a-zA-Z0-9_.]+)`?~i', $sql, $m);

		$out = [];
		foreach ($m[1] as $name) {
			$name = strtolower($name);
			if ($name !== '' && !in_array($name, $out, true)) { $out[] = $name; }
		}

		return $out;
	}

	/**
	 * Свой LIMIT в конце запроса, если он есть.
	 * Нужен, чтобы не навесить второй: «LIMIT 10 LIMIT 100» — синтаксическая ошибка.
	 */
	public static function declaredLimit(string $sql): ?int
	{
		if (!preg_match('~\blimit\s+(\d+)(?:\s*,\s*(\d+))?\s*$~i', $sql, $m)) { return null; }

		return isset($m[2]) ? (int)$m[2] : (int)$m[1];
	}

	public static function select(array $a): array
	{
		$sql = self::clean((string)($a['query'] ?? ''));

		$why = self::why($sql, self::allowed());
		if ($why !== null) { throw new ToolError($why); }

		$rows = (int)($a['limit'] ?? self::ROWS_DEF);
		$rows = min($rows > 0 ? $rows : self::ROWS_DEF, self::ROWS_MAX);

		$own = self::declaredLimit($sql);
		if ($own !== null && $own > self::ROWS_MAX) {
			throw new ToolError('LIMIT в запросе больше предела ' . self::ROWS_MAX . '.');
		}

		$conn  = \Bitrix\Main\Application::getConnection();
		$start = microtime(true);
		try {
			// Предел навешивает ядро (getTopSql), а не подстановка в текст запроса.
			$res = $own === null ? $conn->query($sql, $rows) : $conn->query($sql);
		} catch (\Throwable $e) {
			throw new ToolError('Запрос не выполнен: ' . $e->getMessage());
		}

		$out = [];
		while ($r = $res->fetch()) { $out[] = self::flatten($r); }

		$result = [
			'rows'    => count($out),
			'ms'      => (int)round((microtime(true) - $start) * 1000),
			'columns' => $out ? array_keys($out[0]) : [],
			'data'    => $out,
		];
		if ($own === null && count($out) >= $rows) {
			$result['note'] = 'Показаны первые ' . $rows . ' строк — предел выборки, а не конец данных.';
		}

		return $result;
	}

	/** Таблицы базы, а с параметром name — колонки одной таблицы. */
	public static function tables(array $a): array
	{
		$conn = \Bitrix\Main\Application::getConnection();
		$name = trim((string)($a['name'] ?? ''));

		if ($name !== '' && !preg_match('~^[a-zA-Z0-9_]+$~', $name)) {
			throw new ToolError('Имя таблицы: буквы, цифры и подчёркивание');
		}
		if ($name !== '' && isset(self::DENY[strtolower($name)])) {
			throw new ToolError('Таблица ' . $name . ' закрыта: ' . self::DENY[strtolower($name)] . '.');
		}

		$allow = self::allowed();
		if ($name !== '' && $allow && !in_array(strtolower($name), array_map('strtolower', $allow), true)) {
			throw new ToolError('Таблица ' . $name . ' не в белом списке.');
		}

		if ($name !== '') {
			$cols = [];
			$res = $conn->query('SHOW FULL COLUMNS FROM `' . $name . '`');
			while ($c = $res->fetch()) {
				$cols[] = ['name' => $c['Field'], 'type' => $c['Type'], 'null' => $c['Null'],
					'key' => $c['Key'], 'default' => $c['Default'], 'comment' => $c['Comment']];
			}
			return ['table' => $name, 'total' => count($cols), 'columns' => $cols];
		}

		$like = strtolower(trim((string)($a['query'] ?? '')));

		$out = [];
		// TABLE_ROWS у InnoDB — оценка оптимизатора, а не точный счёт.
		$res = $conn->query('SELECT TABLE_NAME, TABLE_ROWS, ENGINE FROM information_schema.TABLES'
			. ' WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
		while ($t = $res->fetch()) {
			$table = (string)($t['TABLE_NAME'] ?? $t['table_name'] ?? '');
			if ($table === '' || isset(self::DENY[strtolower($table)])) { continue; }
			if ($like !== '' && strpos(strtolower($table), $like) === false) { continue; }
			if ($allow && !in_array(strtolower($table), array_map('strtolower', $allow), true)) { continue; }

			$out[] = ['table' => $table,
				'rows_estimate' => (int)($t['TABLE_ROWS'] ?? $t['table_rows'] ?? 0),
				'engine' => (string)($t['ENGINE'] ?? $t['engine'] ?? '')];
		}

		return ['total' => count($out), 'tables' => $out,
			'note' => 'rows_estimate — оценка, а не точный счёт. Колонки таблицы — тот же'
				. ' инструмент с параметром name.'];
	}

	/** Белый список из настроек; пустой — ограничения нет. */
	public static function allowed(): array
	{
		return self::parse((string)\Bitrix\Main\Config\Option::get('itb.mcp', self::OPT, ''));
	}

	/** @return string[] */
	public static function parse(string $raw): array
	{
		$out = [];
		foreach (preg_split('~[,\s]+~', trim($raw)) ?: [] as $t) {
			$t = strtolower(trim($t));
			if ($t !== '' && preg_match('~^[a-z0-9_]+$~', $t)) { $out[] = $t; }
		}

		return array_values(array_unique($out));
	}

	private static function flatten(array $row): array
	{
		$out = [];
		foreach ($row as $k => $v) {
			if ($v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date) {
				$out[$k] = $v->format('d.m.Y H:i:s');
			} elseif (is_object($v)) {
				$out[$k] = (string)$v;
			} else {
				$out[$k] = $v;
			}
		}

		return $out;
	}
}
