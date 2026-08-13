<?php
namespace Itb\Mcp;

/**
 * Произвольный SELECT к базе сайта. Разбор чистый, см. tests/sql.php.
 *
 * ⚠ Проверяется и исполняется один и тот же текст: между «проверили одно,
 * выполнили другое» и живёт обход.
 */
class Sql
{
	const ROWS_DEF  = 100;
	const ROWS_MAX  = 1000;
	const MAX_BYTES = 524288;
	const SECONDS   = 10;
	const OPT       = 'sql_tables';

	/** Закрыты всегда, белым списком не открываются: это ключи от сайта. */
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
		'into outfile'       => 'запись файла на диск',
		'into dumpfile'      => 'запись файла на диск',
		'load_file'          => 'чтение файла с диска',
		'sleep'              => 'удержание соединения',
		'benchmark'          => 'нагрузка на сервер',
		'get_lock'           => 'блокировка',
		'release_lock'       => 'блокировка',
		// SELECT умеет блокировать строки и двигать счётчики — это уже не чтение,
		// и на работающем магазине такой запрос встанет поперёк оформления заказа.
		'for update'         => 'блокировка строк',
		'for share'          => 'блокировка строк',
		'lock in share mode' => 'блокировка строк',
		'nextval'            => 'сдвиг последовательности',
		'setval'             => 'сдвиг последовательности',
		'mysql.'             => 'служебная база mysql',
		// Здесь лежат тексты чужих запросов вместе со значениями: пароль,
		// введённый администратором минуту назад, читается как обычная строка.
		'processlist'        => 'запросы других соединений',
		'innodb_trx'         => 'запросы других соединений',
		'performance_schema.' => 'история запросов сервера',
		'sys.'               => 'служебные представления сервера',
	];

	/** Комментарии и хвостовая точка с запятой долой: в комментарии прячется всё. */
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

	/**
	 * Таблицы после FROM и JOIN, включая перечисление через запятую: «FROM a, b»
	 * — тоже соединение, и по первому имени вторая таблица прошла бы мимо.
	 */
	public static function tablesIn(string $sql): array
	{
		$stop = 'where|on|using|group|order|limit|having|union|set|inner|left|right|'
			. 'cross|outer|straight_join|join|for|into|window|procedure';

		// Скобка обрывает перечисление: за ней либо подзапрос, либо его конец.
		preg_match_all('~\b(?:from|join|straight_join)\s+([^()]*?)(?=[()]|\s+\b(?:' . $stop . ')\b|$)~i',
			$sql, $m);

		$out = [];
		foreach ($m[1] as $clause) {
			foreach (explode(',', $clause) as $part) {
				// В куске «b_iblock i» имя таблицы — первое слово, остальное псевдоним.
				if (!preg_match('~^\s*`?([a-zA-Z0-9_.]+)`?~', $part, $one)) { continue; }
				$name = strtolower($one[1]);
				if ($name !== '' && !in_array($name, $out, true)) { $out[] = $name; }
			}
		}

		return $out;
	}

	/**
	 * Свой LIMIT в конце запроса, если он есть.
	 * Нужен, чтобы не навесить второй: «LIMIT 10 LIMIT 100» — синтаксическая ошибка.
	 */
	public static function declaredLimit(string $sql): ?int
	{
		// Три записи: LIMIT n, LIMIT смещение, n и LIMIT n OFFSET m. Пропустить
		// последнюю значило бы навесить второй LIMIT и получить ошибку синтаксиса.
		if (preg_match('~\blimit\s+(\d+)\s*,\s*(\d+)\s*$~i', $sql, $m)) { return (int)$m[2]; }
		if (preg_match('~\blimit\s+(\d+)(?:\s+offset\s+\d+)?\s*$~i', $sql, $m)) { return (int)$m[1]; }

		return null;
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

		$conn = \Bitrix\Main\Application::getConnection();
		self::deadline($conn);

		$start = microtime(true);
		try {
			// Предел навешивает ядро (getTopSql), а не подстановка в текст запроса.
			$res = $own === null ? $conn->query($sql, $rows) : $conn->query($sql);
		} catch (\Throwable $e) {
			throw new ToolError('Запрос не выполнен: ' . $e->getMessage());
		}

		$out   = [];
		$bytes = 0;
		$cut   = false;
		while ($r = $res->fetch()) {
			$row = self::flatten($r);
			// Предел по объёму, а не только по строкам: тысяча строк с текстом
			// описания — это десятки мегабайт в ответе.
			$bytes += strlen((string)json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
			if ($bytes > self::MAX_BYTES) { $cut = true; break; }
			$out[] = $row;
		}

		$result = [
			'rows'    => count($out),
			'ms'      => (int)round((microtime(true) - $start) * 1000),
			'columns' => $out ? array_keys($out[0]) : [],
			'data'    => $out,
		];

		if ($cut) {
			$result['note'] = 'Обрыв по объёму ответа: показано ' . count($out)
				. ' строк. Уберите лишние колонки или сузьте отбор.';
		} elseif ($own === null && count($out) >= $rows) {
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

	/**
	 * Предел времени на запрос — средствами сервера, а не переписыванием текста.
	 *
	 * Имена настроек у MySQL и MariaDB разные, на старых версиях их нет вовсе,
	 * поэтому пробуем обе и молчим при отказе: без предела запрос всё равно
	 * выполнится, просто дольше.
	 */
	private static function deadline($conn): void
	{
		foreach (['SET SESSION MAX_EXECUTION_TIME=' . (self::SECONDS * 1000),
			'SET SESSION max_statement_time=' . self::SECONDS] as $sql) {
			try { $conn->query($sql); return; } catch (\Throwable $e) {}
		}
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
