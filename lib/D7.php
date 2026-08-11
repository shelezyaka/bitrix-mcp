<?php
namespace Itb\Mcp;

/**
 * Чтение элементов через ORM D7.
 *
 * Свойства приходят вместе с элементами, а не запросом на каждый.
 * Когда сущность собрать нельзя (у инфоблока пуст API_CODE), Data откатывается
 * на старый API.
 */
class D7
{
	/**
	 * Сколько свойств брать за один запрос.
	 *
	 * Каждое одиночное свойство — это JOIN, а MySQL не соединяет больше 61
	 * таблицы: карточка со 138 свойствами падала с «Too many tables». Запас до
	 * предела оставлен на служебные соединения самой сущности.
	 */
	const JOIN_CHUNK = 30;

	/** Человеческие имена свойств: код => имя. */
	private static $names = [];

	/**
	 * Класс сущности инфоблока или null.
	 *
	 * getEntityDataClass() при пустом API_CODE шлёт E_USER_WARNING и возвращает
	 * null — предупреждение попало бы в тело ответа и испортило JSON. Поэтому
	 * сперва спрашиваем имя: этот метод молчит.
	 */
	public static function entityClass(int $iblock): ?string
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'engine', 'legacy') !== 'orm') { return null; }

		static $cache = [];
		if (array_key_exists($iblock, $cache)) { return $cache[$iblock]; }

		$cls = null;
		try {
			\Bitrix\Main\Loader::includeModule('iblock');
			$ib   = \Bitrix\Iblock\Iblock::wakeUp($iblock);
			$name = $ib->getEntityDataClassName();
			if ((string)$name !== '') {
				$try = $ib->getEntityDataClass();
				if (is_string($try) && class_exists($try)) { $cls = $try; }
			}
		} catch (\Throwable $e) {
			$cls = null;
		}

		return $cache[$iblock] = $cls;
	}

	/** Описание свойств инфоблока: код => [ID, TYPE, MULTIPLE]. */
	private static function propMeta(int $iblock): array
	{
		static $cache = [];
		if (isset($cache[$iblock])) { return $cache[$iblock]; }

		$out = [];
		$rs = \Bitrix\Iblock\PropertyTable::getList([
			'select' => ['ID', 'CODE', 'PROPERTY_TYPE', 'MULTIPLE'],
			'filter' => ['=IBLOCK_ID' => $iblock, '=ACTIVE' => 'Y'],
		]);
		while ($r = $rs->fetch()) {
			if ((string)$r['CODE'] === '') { continue; }
			$out[(string)$r['CODE']] = [
				'id'       => (int)$r['ID'],
				'type'     => (string)$r['PROPERTY_TYPE'],
				'multiple' => $r['MULTIPLE'] === 'Y',
			];
		}

		return $cache[$iblock] = $out;
	}

	/**
	 * Свойства делятся на одиночные и множественные.
	 *
	 * Одиночные (PropertyReference) берутся вместе с элементом. Множественные
	 * (PropertyOneToMany) — коллекции: в общем запросе они размножили бы строки
	 * декартовым произведением, поэтому идут отдельно.
	 *
	 * @return array{single: string[], multi: string[], unknown: string[]}
	 */
	public static function splitProps(string $cls, array $codes): array
	{
		$fields = $cls::getEntity()->getFields();

		$out = ['single' => [], 'multi' => [], 'unknown' => []];
		foreach ($codes as $code) {
			if (!isset($fields[$code])) { $out['unknown'][] = $code; continue; }
			$kind = (new \ReflectionClass($fields[$code]))->getShortName();
			if ($kind === 'PropertyReference')     { $out['single'][] = $code; }
			elseif ($kind === 'PropertyOneToMany') { $out['multi'][] = $code; }
			else                                   { $out['unknown'][] = $code; }
		}

		return $out;
	}

	/**
	 * Поиск. $spec — уже проверенное описание запроса из Data.
	 *
	 * @return array{total:int, items:array, notes:string[]}
	 */
	public static function search(array $spec): array
	{
		$iblock = (int)$spec['iblock'];
		$cls    = self::entityClass($iblock);
		if ($cls === null) { throw new ToolError('Сущность инфоблока недоступна'); }

		self::$names = (array)($spec['names'] ?? []);

		$kinds = self::splitProps($cls, $spec['props']);
		$notes = [];
		if ($kinds['unknown']) {
			$notes[] = 'Не нашлись в сущности и пропущены: ' . implode(', ', $kinds['unknown']);
		}

		// DETAIL_PAGE_URL полем сущности не является — собирается после выборки.
		// CODE, XML_ID и раздел нужны для адреса; наружу уйдут только запрошенные.
		$want   = array_values(array_diff($spec['fields'], ['DETAIL_PAGE_URL']));
		$base   = $want;
		foreach (['ID', 'CODE', 'XML_ID', 'IBLOCK_SECTION_ID'] as $f) {
			if (!in_array($f, $base, true)) { $base[] = $f; }
		}

		$filter = ['=IBLOCK_ID' => $iblock];
		if ($spec['active'] === 'Y' || $spec['active'] === 'N') { $filter['=ACTIVE'] = $spec['active']; }
		if ($spec['name'] !== '') { $filter['%NAME'] = $spec['name']; }
		if ($spec['code'] !== '') { $filter['=CODE'] = $spec['code']; }
		if ($spec['ids'])         { $filter['@ID'] = $spec['ids']; }

		if ($spec['section'] > 0) {
			// Старый API смотрел ещё и таблицу связей, поэтому элемент, привязанный
			// к разделу вторым, сюда не попадёт — об этом сказано в ответе.
			$ids = self::branch($iblock, $spec['section']);
			$filter['@IBLOCK_SECTION_ID'] = $ids ?: [-1];
			$notes[] = 'Отбор по разделу идёт по основному разделу элемента.';
		}

		$multiFilter = false;
		foreach ($spec['property'] as $code => $val) {
			$filter['=' . $code . '.VALUE'] = $val;
			if (in_array($code, $kinds['multi'], true)) { $multiFilter = true; }
		}

		// Свойства режем на пачки: см. JOIN_CHUNK.
		$chunks = array_chunk($kinds['single'], self::JOIN_CHUNK);
		$first  = array_shift($chunks) ?: [];

		$select = $base;
		foreach ($first as $code) { $select['P_' . $code] = $code . '.VALUE'; }

		$rows = $cls::getList([
			'select'      => $select,
			'filter'      => $filter,
			'order'       => ['ID' => 'ASC'],
			'limit'       => $spec['limit'],
			'offset'      => $spec['offset'],
			'count_total' => true,
		]);
		$total = (int)$rows->getCount();

		$items = [];
		$raw   = [];
		while ($r = $rows->fetch()) {
			// Фильтр по множественному свойству join-ит коллекцию и множит строки.
			$id = (int)$r['ID'];
			if (isset($items[$id])) { continue; }
			$raw[$id]   = $r;
			$items[$id] = self::shape($r, $first, $spec['dropEmpty'], $want);
		}
		if ($multiFilter && $items) {
			$notes[] = 'Отбор шёл по множественному свойству, повторы строк схлопнуты.';
		}

		if ($items) {
			$ids = array_keys($items);
			foreach ($chunks as $chunk) { self::fillChunk($cls, $iblock, $ids, $chunk, $items, $spec['dropEmpty']); }
			if ($kinds['multi']) {
				self::fillMulti($cls, $iblock, $ids, $kinds['multi'], $items, $spec['dropEmpty']);
			}
			self::resolveEnums($iblock, $items);
			if (in_array('DETAIL_PAGE_URL', $spec['fields'], true)) {
				self::fillUrls($iblock, $items, $raw);
			}
		}

		return ['total' => $total, 'items' => array_values($items), 'notes' => $notes];
	}

	/** Один элемент: тот же путь, но по идентификатору. */
	public static function element(array $spec): ?array
	{
		$spec['ids']      = [(int)$spec['id']];
		$spec['limit']    = 1;
		$spec['offset']   = 0;
		$spec['name']     = '';
		$spec['code']     = '';
		$spec['section']  = 0;
		$spec['active']   = 'any';
		$spec['property'] = [];

		$res = self::search($spec);

		return $res['items'][0] ?? null;
	}

	/** Доборная пачка одиночных свойств для уже найденных элементов. */
	private static function fillChunk(string $cls, int $iblock, array $ids, array $codes,
		array &$items, bool $dropEmpty): void
	{
		$select = ['ID'];
		foreach ($codes as $code) { $select['P_' . $code] = $code . '.VALUE'; }

		$rs = $cls::getList(['select' => $select, 'filter' => ['=IBLOCK_ID' => $iblock, '@ID' => $ids]]);
		while ($r = $rs->fetch()) {
			$id = (int)$r['ID'];
			if (!isset($items[$id])) { continue; }
			foreach ($codes as $code) {
				$v = $r['P_' . $code] ?? null;
				if ($dropEmpty && ($v === null || $v === '')) { continue; }
				$items[$id]['PROPERTIES'][$code] = ['name' => self::$names[$code] ?? $code, 'value' => $v];
			}
		}
	}

	/** Значения множественных свойств — по запросу на свойство. */
	private static function fillMulti(string $cls, int $iblock, array $ids, array $codes,
		array &$items, bool $dropEmpty): void
	{
		foreach ($codes as $code) {
			$rs = $cls::getList([
				'select' => ['ID', 'V' => $code . '.VALUE'],
				'filter' => ['=IBLOCK_ID' => $iblock, '@ID' => $ids],
			]);
			$acc = [];
			while ($r = $rs->fetch()) {
				$v = $r['V'];
				if ($v === null || $v === '') { continue; }
				$acc[(int)$r['ID']][] = (string)$v;
			}
			foreach ($items as $id => &$row) {
				$vals = $acc[$id] ?? [];
				if ($dropEmpty && !$vals) { continue; }
				$row['PROPERTIES'][$code] = [
					'name'  => self::$names[$code] ?? $code,
					'value' => array_values($vals),
				];
			}
			unset($row);
		}
	}

	/**
	 * Списочные свойства.
	 *
	 * ORM отдаёт по ним идентификатор записи справочника, а не текст: METALL
	 * приходил как «516» вместо «серебро». Собираем все идентификаторы разом и
	 * переводим одним запросом — по запросу на значение это был бы тот же N+1,
	 * от которого уходили.
	 */
	private static function resolveEnums(int $iblock, array &$items): void
	{
		$meta = self::propMeta($iblock);

		$need = [];
		foreach ($items as $row) {
			foreach ((array)($row['PROPERTIES'] ?? []) as $code => $p) {
				if (($meta[$code]['type'] ?? '') !== 'L') { continue; }
				foreach ((array)$p['value'] as $v) {
					if ((string)$v !== '') { $need[(int)$v] = true; }
				}
			}
		}
		if (!$need) { return; }

		$map = [];
		$rs = \Bitrix\Iblock\PropertyEnumerationTable::getList([
			'select' => ['ID', 'VALUE'],
			'filter' => ['@ID' => array_keys($need)],
		]);
		while ($r = $rs->fetch()) { $map[(int)$r['ID']] = (string)$r['VALUE']; }

		foreach ($items as &$row) {
			foreach ((array)($row['PROPERTIES'] ?? []) as $code => &$p) {
				if (($meta[$code]['type'] ?? '') !== 'L') { continue; }
				if (is_array($p['value'])) {
					foreach ($p['value'] as &$v) { $v = $map[(int)$v] ?? $v; }
					unset($v);
				} elseif ((string)$p['value'] !== '') {
					// Неизвестный идентификатор оставляем как есть: подменять его
					// пустотой значит потерять то единственное, что мы знаем.
					$p['value'] = $map[(int)$p['value']] ?? $p['value'];
				}
			}
			unset($p);
		}
		unset($row);
	}

	/**
	 * Адрес детальной страницы.
	 *
	 * Это шаблон инфоблока, подставляет его ядро. Шаблону нужны SITE_DIR и путь
	 * разделов: без них адрес собирался в «catalog/» вместо «/catalog/sergi/548/».
	 */
	private static function fillUrls(int $iblock, array &$items, array $raw): void
	{
		$ib  = \CIBlock::GetArrayByID($iblock);
		$tpl = (string)($ib['DETAIL_PAGE_URL'] ?? '');
		if ($tpl === '') { return; }

		$paths = [];
		foreach ($items as $id => &$row) {
			$r    = $raw[$id] ?? [];
			$code = (string)($r['CODE'] ?? '');
			$sec  = (int)($r['IBLOCK_SECTION_ID'] ?? 0);

			if ($sec > 0 && !isset($paths[$sec])) {
				$paths[$sec] = (string)\CIBlockSection::getSectionCodePath($sec);
			}

			$row['DETAIL_PAGE_URL'] = \CIBlock::ReplaceDetailUrl($tpl, [
				'ID'                 => $id,
				'CODE'               => $code,
				'~CODE'              => $code,
				'EXTERNAL_ID'        => (string)($r['XML_ID'] ?? ''),
				'IBLOCK_ID'          => $iblock,
				'IBLOCK_CODE'        => (string)($ib['CODE'] ?? ''),
				'IBLOCK_TYPE_ID'     => (string)($ib['IBLOCK_TYPE_ID'] ?? ''),
				'IBLOCK_EXTERNAL_ID' => (string)($ib['XML_ID'] ?? ''),
				'IBLOCK_SECTION_ID'  => $sec,
				'SECTION_CODE_PATH'  => $paths[$sec] ?? '',
				'SITE_DIR'           => defined('SITE_DIR') ? SITE_DIR : '/',
				'LANG_DIR'           => defined('SITE_DIR') ? SITE_DIR : '/',
				'LID'                => defined('SITE_ID') ? SITE_ID : '',
			], true, false);
		}
		unset($row);
	}

	/** Идентификаторы раздела и всех вложенных. */
	private static function branch(int $iblock, int $section): array
	{
		$s = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblock, 'ID' => $section], false,
			['ID', 'LEFT_MARGIN', 'RIGHT_MARGIN'])->Fetch();
		if (!$s) { return []; }

		$ids = [];
		$rs = \CIBlockSection::GetList([], ['IBLOCK_ID' => $iblock,
			'>=LEFT_MARGIN' => (int)$s['LEFT_MARGIN'], '<=RIGHT_MARGIN' => (int)$s['RIGHT_MARGIN']],
			false, ['ID']);
		while ($r = $rs->Fetch()) { $ids[] = (int)$r['ID']; }

		return $ids;
	}

	/**
	 * Строка ORM → та же форма, что отдаёт старый путь.
	 *
	 * ORM возвращает даты объектами, булево типом bool, картинки
	 * идентификаторами файлов — приводим к прежнему виду.
	 */
	private static function shape(array $r, array $singleProps, bool $dropEmpty, array $want): array
	{
		$row = [];
		foreach ($r as $k => $v) {
			// Служебные добавки к select наружу не отдаём, если их не просили.
			if (strncmp($k, 'P_', 2) === 0 || !in_array($k, $want, true)) { continue; }

			if ($v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date) {
				$row[$k] = $v->format('d.m.Y H:i:s');
			} elseif ($k === 'ACTIVE') {
				$row[$k] = ($v === true || $v === 'Y') ? 'Y' : 'N';
			} elseif ($k === 'PREVIEW_PICTURE' || $k === 'DETAIL_PICTURE') {
				$row[$k] = $v ? (string)\CFile::GetPath($v) : null;
			} else {
				$row[$k] = is_scalar($v) || $v === null ? $v : (string)$v;
			}
		}

		$props = [];
		foreach ($singleProps as $code) {
			$v = $r['P_' . $code] ?? null;
			if ($dropEmpty && ($v === null || $v === '')) { continue; }
			$props[$code] = ['name' => self::$names[$code] ?? $code, 'value' => $v];
		}
		$row['PROPERTIES'] = $props;

		return $row;
	}
}
