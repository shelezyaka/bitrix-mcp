<?php
namespace Itb\Mcp;

/**
 * Что на сайте настроено и работает: обработчики событий, агенты,
 * highload-блоки. Устройство установки, а не её данные.
 */
class Site
{
	const AGENTS_MAX = 200;

	/**
	 * Обработчики событий из двух источников: постоянная регистрация лежит в
	 * b_module_to_module, а навешенное в init.php — только в памяти запроса,
	 * и находится лишь запросом про конкретное событие.
	 */
	public static function events(array $a): array
	{
		$module = trim((string)($a['module'] ?? ''));
		$event  = trim((string)($a['event'] ?? ''));

		$conn = \Bitrix\Main\Application::getConnection();
		$h    = $conn->getSqlHelper();

		$where = [];
		if ($module !== '') { $where[] = "FROM_MODULE_ID = '" . $h->forSql($module) . "'"; }
		if ($event !== '')  { $where[] = "MESSAGE_ID = '" . $h->forSql($event) . "'"; }

		$rows = [];
		$res = $conn->query(
			'SELECT FROM_MODULE_ID, MESSAGE_ID, TO_MODULE_ID, TO_CLASS, TO_METHOD, TO_PATH, SORT'
			. ' FROM b_module_to_module'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '')
			. ' ORDER BY FROM_MODULE_ID, MESSAGE_ID, SORT',
			500
		);
		while ($r = $res->fetch()) {
			$rows[] = [
				'module'  => (string)$r['FROM_MODULE_ID'],
				'event'   => (string)$r['MESSAGE_ID'],
				'handler' => self::callable($r),
				'file'    => (string)$r['TO_PATH'],
				'by'      => (string)$r['TO_MODULE_ID'],
				'sort'    => (int)$r['SORT'],
			];
		}

		$out = ['total' => count($rows), 'registered' => $rows];

		// Полный список даёт только findEventHandlers и только по паре module+event.
		if ($module !== '' && $event !== '') {
			$live = [];
			foreach (\Bitrix\Main\EventManager::getInstance()->findEventHandlers($module, $event) as $x) {
				$live[] = [
					'handler' => self::callable($x),
					'file'    => self::where($x),
					'by'      => (string)($x['TO_MODULE_ID'] ?? ''),
					'sort'    => (int)($x['SORT'] ?? 0),
					'source'  => empty($x['TO_MODULE_ID']) ? 'код (init.php)' : 'регистрация',
				];
			}
			$out['live'] = $live;
			$out['note'] = 'live — то, что реально сработает на «' . $event . '», включая'
				. ' навешенное в init.php.';
		} else {
			$out['note'] = 'Здесь только постоянная регистрация. Обработчики из init.php'
				. ' в таблице не лежат: спросите по конкретной паре module + event либо'
				. ' поищите AddEventHandler инструментом file_grep.';
		}

		return $out;
	}

	/** Агенты: что запускается по расписанию, когда запускалось и когда будет. */
	public static function agents(array $a): array
	{
		$filter = [];
		if (($a['module'] ?? '') !== '') { $filter['MODULE_ID'] = (string)$a['module']; }
		$active = (string)($a['active'] ?? '');
		if ($active === 'Y' || $active === 'N') { $filter['ACTIVE'] = $active; }

		$rows = [];
		$rs = \CAgent::GetList(['MODULE_ID' => 'ASC', 'NEXT_EXEC' => 'ASC'], $filter);
		while ($r = $rs->Fetch()) {
			if (count($rows) >= self::AGENTS_MAX) { break; }
			$rows[] = [
				'id'        => (int)$r['ID'],
				'module'    => (string)$r['MODULE_ID'],
				'call'      => (string)$r['NAME'],
				'active'    => (string)$r['ACTIVE'],
				'periodic'  => (string)$r['IS_PERIOD'],
				'interval'  => (int)$r['AGENT_INTERVAL'],
				'last_exec' => (string)$r['LAST_EXEC'],
				'next_exec' => (string)$r['NEXT_EXEC'],
				'running'   => (string)$r['RUNNING'],
				'retries'   => (int)$r['RETRY_COUNT'],
				'user_id'   => (int)$r['USER_ID'] ?: null,
			];
		}

		$out = ['total' => count($rows), 'agents' => $rows];
		if (count($rows) >= self::AGENTS_MAX) {
			$out['note'] = 'Показаны первые ' . self::AGENTS_MAX . ' — отберите по module.';
		}
		// Агент со статусом RUNNING = Y, который висит так давно, — это упавший
		// агент: сам он больше не запустится.
		$out['hint'] = 'running=Y означает «выполняется сейчас». Если так висит давно —'
			. ' агент оборвался и повторно не стартует.';

		return $out;
	}

	const HL_OPT = 'hl_deny';

	/** Закрытые highload-блоки: идентификаторы из настройки. @return int[] */
	public static function hlDenied(): array
	{
		$raw = (string)\Bitrix\Main\Config\Option::get('itb.mcp', self::HL_OPT, '');

		$out = [];
		foreach (preg_split('~[^0-9]+~', $raw) ?: [] as $v) {
			if ((int)$v > 0 && !in_array((int)$v, $out, true)) { $out[] = (int)$v; }
		}

		return $out;
	}

	/** Все highload-блоки: id => имя и таблица. Пусто, если модуля нет. */
	public static function hlAll(): array
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('highloadblock')
			|| !\Bitrix\Main\Loader::includeModule('highloadblock')) {
			return [];
		}

		$out = [];
		$rs = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'order'  => ['ID' => 'ASC'],
		]);
		while ($b = $rs->fetch()) {
			$out[(int)$b['ID']] = ['id' => (int)$b['ID'], 'name' => (string)$b['NAME'],
				'table' => (string)$b['TABLE_NAME']];
		}

		return $out;
	}

	/**
	 * Таблицы закрытых блоков — для запрета в sql_select.
	 * Иначе закрыть блок значило бы спрятать его название, оставив данные.
	 *
	 * @return array<string, string> таблица => имя блока
	 */
	public static function hlClosedTables(): array
	{
		$deny = self::hlDenied();
		if (!$deny) { return []; }

		$out = [];
		foreach (self::hlAll() as $id => $b) {
			if (in_array($id, $deny, true) && $b['table'] !== '') { $out[$b['table']] = $b['name']; }
		}

		return $out;
	}

	/** Highload-блоки и состав их полей. Строки читаются через sql_select. */
	public static function hlBlocks(array $a): array
	{
		if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
			throw new ToolError('Модуль highloadblock на этом сайте не подключён');
		}

		$deny   = self::hlDenied();
		$closed = 0;

		$blocks = [];
		foreach (self::hlAll() as $id => $b) {
			if (in_array($id, $deny, true)) { $closed++; continue; }
			$blocks[$id] = $b + ['fields' => []];
		}
		if (!$blocks) {
			return ['total' => 0, 'blocks' => [],
				'note' => $closed ? 'Все блоки закрыты настройкой модуля.' : 'Блоков нет.'];
		}

		$entities = [];
		foreach (array_keys($blocks) as $id) { $entities[] = 'HLBLOCK_' . $id; }

		$rs = \Bitrix\Main\UserFieldTable::getList([
			'select' => ['ENTITY_ID', 'FIELD_NAME', 'USER_TYPE_ID', 'MULTIPLE', 'MANDATORY'],
			'filter' => ['@ENTITY_ID' => $entities],
			'order'  => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($f = $rs->fetch()) {
			$id = (int)substr((string)$f['ENTITY_ID'], strlen('HLBLOCK_'));
			if (!isset($blocks[$id])) { continue; }
			$blocks[$id]['fields'][] = [
				'code'      => (string)$f['FIELD_NAME'],
				'type'      => (string)$f['USER_TYPE_ID'],
				'multiple'  => (string)$f['MULTIPLE'],
				'mandatory' => (string)$f['MANDATORY'],
			];
		}

		return ['total' => count($blocks), 'blocks' => array_values($blocks),
			'note' => 'Строки highload-блока читаются из его таблицы инструментом sql_select.'
				. ($closed ? ' Ещё ' . $closed . ' закрыто настройкой модуля — ни структуры,'
					. ' ни строк по ним не будет.' : '')];
	}

	/**
	 * Имя обработчика. У навешенного кодом нет ни TO_CLASS, ни TO_METHOD —
	 * только CALLBACK, и без его разбора такой обработчик выглядит пустым.
	 */
	private static function callable(array $r): string
	{
		$name = trim((string)($r['TO_NAME'] ?? ''));
		if ($name !== '') { return $name; }

		$class  = (string)($r['TO_CLASS'] ?? '');
		$method = (string)($r['TO_METHOD'] ?? '');
		if ($class !== '' || $method !== '') {
			return $class !== '' && $method !== '' ? $class . '::' . $method : $class . $method;
		}

		$cb = $r['CALLBACK'] ?? null;
		if (is_string($cb)) { return $cb; }
		if ($cb instanceof \Closure) { return 'замыкание'; }
		if (is_array($cb) && count($cb) === 2) {
			return (is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0]) . '::' . (string)$cb[1];
		}

		return 'не определён';
	}

	/** Файл обработчика: у навешенного кодом его знает только сам callback. */
	private static function where(array $r): string
	{
		$path = trim((string)($r['TO_PATH'] ?? ''));
		if ($path === '') { $path = trim((string)($r['FULL_PATH'] ?? '')); }
		if ($path !== '') { return self::relative($path); }

		$cb = $r['CALLBACK'] ?? null;
		try {
			if ($cb instanceof \Closure || (is_string($cb) && function_exists($cb))) {
				$f = new \ReflectionFunction($cb);
			} elseif (is_array($cb) && count($cb) === 2) {
				$f = new \ReflectionMethod(is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0],
					(string)$cb[1]);
			} else {
				return '';
			}

			return self::relative((string)$f->getFileName()) . ':' . $f->getStartLine();
		} catch (\Throwable $e) {
			return '';
		}
	}

	private static function relative(string $file): string
	{
		$root = str_replace('\\', '/', (string)\Bitrix\Main\Application::getDocumentRoot());
		$file = str_replace('\\', '/', $file);

		return $root !== '' && strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
	}
}
