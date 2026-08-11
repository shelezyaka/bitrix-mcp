<?php
namespace Itb\Mcp;

/**
 * Чтение элементов через ORM D7.
 *
 * Основной путь; когда сущность собрать нельзя (у инфоблока пуст API_CODE),
 * Data откатывается на старый API. Разница по существу — свойства приходят
 * вместе с элементами, а не отдельным запросом на каждый.
 */
class D7
{
	/** Человеческие имена свойств: код => имя. Заполняется на входе в search(). */
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

	/**
	 * Свойства делятся на одиночные и множественные.
	 *
	 * Одиночные (PropertyReference) берутся в основном запросе. Множественные
	 * (PropertyOneToMany) — коллекции: в общем запросе они размножили бы строки
	 * декартовым произведением, поэтому идут отдельными запросами.
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
			if ($kind === 'PropertyReference')      { $out['single'][] = $code; }
			elseif ($kind === 'PropertyOneToMany')  { $out['multi'][] = $code; }
			else                                    { $out['unknown'][] = $code; }
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

		// DETAIL_PAGE_URL полем сущности не является — он собирается из шаблона
		// инфоблока после выборки. В select он даёт «Unknown field definition».
		// Для сборки адреса нужны CODE, XML_ID и раздел, поэтому добираем их сами;
		// в ответ уйдут только те поля, которые просили.
		$want   = array_values(array_diff($spec['fields'], ['DETAIL_PAGE_URL']));
		$select = $want;
		foreach (['ID', 'CODE', 'XML_ID', 'IBLOCK_SECTION_ID'] as $f) {
			if (!in_array($f, $select, true)) { $select[] = $f; }
		}
		foreach ($kinds['single'] as $code) { $select['P_' . $code] = $code . '.VALUE'; }

		$filter = ['=IBLOCK_ID' => $iblock];
		if ($spec['active'] === 'Y' || $spec['active'] === 'N') { $filter['=ACTIVE'] = $spec['active']; }
		if ($spec['name'] !== '') { $filter['%NAME'] = $spec['name']; }
		if ($spec['code'] !== '') { $filter['=CODE'] = $spec['code']; }
		if ($spec['ids'])         { $filter['@ID'] = $spec['ids']; }

		if ($spec['section'] > 0) {
			// Разделы: собираем ветку и фильтруем по основному разделу элемента.
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
			// Сырую строку держим отдельно: для сборки адреса нужны поля, которых
			// в ответе может не быть.
			$raw[$id]   = $r;
			$items[$id] = self::shape($r, $kinds['single'], $spec['dropEmpty'], $want);
		}
		if ($multiFilter && $items) {
			$notes[] = 'Отбор шёл по множественному свойству, повторы строк схлопнуты.';
		}

		if ($kinds['multi'] && $items) {
			self::fillMulti($cls, $iblock, array_keys($items), $kinds['multi'], $items, $spec['dropEmpty']);
		}

		if (in_array('DETAIL_PAGE_URL', $spec['fields'], true)) {
			self::fillUrls($iblock, $items, $raw);
		}

		return ['total' => $total, 'items' => array_values($items), 'notes' => $notes];
	}

	/** Один элемент: тот же путь, но по идентификатору. */
	public static function element(array $spec): ?array
	{
		$spec['ids']     = [(int)$spec['id']];
		$spec['limit']   = 1;
		$spec['offset']  = 0;
		$spec['name']    = '';
		$spec['code']    = '';
		$spec['section'] = 0;
		$spec['active']  = 'any';
		$spec['property'] = [];

		$res = self::search($spec);

		return $res['items'][0] ?? null;
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
	 * Адрес детальной страницы.
	 *
	 * В сущности такого поля нет — это шаблон инфоблока. Подставляет его само
	 * ядро (CIBlock::ReplaceDetailUrl), поэтому свои замены не пишем.
	 */
	private static function fillUrls(int $iblock, array &$items, array $raw): void
	{
		if (!$items) { return; }

		$ib  = \CIBlock::GetArrayByID($iblock);
		$tpl = (string)($ib['DETAIL_PAGE_URL'] ?? '');
		if ($tpl === '') { return; }

		foreach ($items as $id => &$row) {
			$r = $raw[$id] ?? [];
			$code = (string)($r['CODE'] ?? '');
			$row['DETAIL_PAGE_URL'] = \CIBlock::ReplaceDetailUrl($tpl, [
				'ID'                 => $id,
				'CODE'               => $code,
				'~CODE'              => $code,
				'EXTERNAL_ID'        => (string)($r['XML_ID'] ?? ''),
				'IBLOCK_ID'          => $iblock,
				'IBLOCK_CODE'        => (string)($ib['CODE'] ?? ''),
				'IBLOCK_TYPE_ID'     => (string)($ib['IBLOCK_TYPE_ID'] ?? ''),
				'IBLOCK_EXTERNAL_ID' => (string)($ib['XML_ID'] ?? ''),
				'IBLOCK_SECTION_ID'  => (int)($r['IBLOCK_SECTION_ID'] ?? 0),
				'LANG_DIR'           => '',
				'LID'                => '',
			], false, false);
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
	 * Форматы приходится приводить вручную: ORM возвращает даты объектами,
	 * булево — типом bool, картинки — идентификаторами файлов.
	 */
	private static function shape(array $r, array $singleProps, bool $dropEmpty, array $want): array
	{
		$row = [];
		foreach ($r as $k => $v) {
			// Служебные добавки к select (XML_ID, CODE) наружу не отдаём, если их
			// не просили: они нужны были только для сборки адреса.
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
