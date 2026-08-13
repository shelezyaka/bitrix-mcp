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
	 * Обработчики событий.
	 *
	 * Источников два, и они разные. В b_module_to_module лежат обработчики,
	 * зарегистрированные насовсем (RegisterModuleDependences) — их видно всегда.
	 * Навешенные в init.php через AddEventHandler в таблицу не попадают: они
	 * живут только в памяти текущего запроса, и найти их можно, лишь спросив
	 * про конкретное событие.
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

		// Полный ответ возможен только по конкретному событию: findEventHandlers
		// отдаёт и то, что навешано в init.php на этот запрос.
		if ($module !== '' && $event !== '') {
			$live = [];
			foreach (\Bitrix\Main\EventManager::getInstance()->findEventHandlers($module, $event) as $x) {
				$live[] = [
					'handler' => self::callable($x),
					'file'    => (string)($x['TO_PATH'] ?? ''),
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

	/** Highload-блоки и состав их полей. Строки читаются через sql_select. */
	public static function hlBlocks(array $a): array
	{
		if (!\Bitrix\Main\Loader::includeModule('highloadblock')) {
			throw new ToolError('Модуль highloadblock на этом сайте не подключён');
		}

		$blocks = [];
		$rs = \Bitrix\Highloadblock\HighloadBlockTable::getList([
			'select' => ['ID', 'NAME', 'TABLE_NAME'],
			'order'  => ['ID' => 'ASC'],
		]);
		while ($b = $rs->fetch()) {
			$blocks[(int)$b['ID']] = [
				'id'     => (int)$b['ID'],
				'name'   => (string)$b['NAME'],
				'table'  => (string)$b['TABLE_NAME'],
				'fields' => [],
			];
		}
		if (!$blocks) { return ['total' => 0, 'blocks' => []]; }

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
			'note' => 'Строки highload-блока читаются из его таблицы инструментом sql_select.'];
	}

	/** Класс::метод, файл или просто функция — как записан обработчик. */
	private static function callable(array $r): string
	{
		$class  = (string)($r['TO_CLASS'] ?? '');
		$method = (string)($r['TO_METHOD'] ?? '');

		if ($class !== '' && $method !== '') { return $class . '::' . $method; }

		return $method !== '' ? $method : $class;
	}
}
