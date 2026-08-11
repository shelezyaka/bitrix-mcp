<?php
namespace Itb\Mcp;

/**
 * Чтение данных сайта. Только чтение — записи в модуле нет.
 * Фильтр собирается здесь из проверенных кусочков и не принимается извне как есть.
 */
class Data
{
	const LIMIT_MAX = 50;
	const LIMIT_DEF = 20;

	/** Поля карточки. У списка и у карточки наборы разные: тексты в поиск не идут. */
	const FIELDS_FULL = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT',
		'DATE_CREATE', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID', 'DETAIL_PAGE_URL',
		'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'];

	const FIELDS_LIST = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT',
		'DATE_CREATE', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID', 'DETAIL_PAGE_URL',
		'PREVIEW_PICTURE', 'DETAIL_PICTURE'];

	/** Свойства инфоблока: код => имя. */
	public static function props(int $iblockId): array
	{
		static $cache = [];
		if (isset($cache[$iblockId])) { return $cache[$iblockId]; }

		\Bitrix\Main\Loader::includeModule('iblock');
		$out = [];
		$rs = \CIBlockProperty::GetList(['SORT' => 'ASC'], ['IBLOCK_ID' => $iblockId, 'ACTIVE' => 'Y']);
		while ($p = $rs->Fetch()) {
			if ((string)$p['CODE'] === '') { continue; }
			$out[(string)$p['CODE']] = (string)$p['NAME'];
		}

		return $cache[$iblockId] = $out;
	}

	/** Открытые инфоблоки с названиями и составом свойств. Кэш на запрос. */
	public static function catalogue(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		\Bitrix\Main\Loader::includeModule('iblock');

		$out = [];
		foreach (Expose::all() as $id => $rule) {
			$ib = \CIBlock::GetByID($id)->Fetch();
			if (!$ib) {
				$out[] = ['id' => $id, 'name' => null, 'error' => 'инфоблок не найден'];
				continue;
			}
			$out[] = [
				'id'         => $id,
				'name'       => (string)$ib['NAME'],
				'code'       => (string)$ib['CODE'],
				'type'       => (string)$ib['IBLOCK_TYPE_ID'],
				'props_mode' => $rule['props'] === null ? 'все свойства' : 'только выбранные',
				'props'      => Expose::filterProps($id, self::props($id)),
			];
		}

		return $cache = $out;
	}

	public static function search(array $a): array
	{
		$iblock = (int)($a['iblock'] ?? 0);
		self::assertIblock($iblock);

		\Bitrix\Main\Loader::includeModule('iblock');

		$filter = ['IBLOCK_ID' => $iblock];

		$active = (string)($a['active'] ?? 'any');
		if ($active === 'Y' || $active === 'N') { $filter['ACTIVE'] = $active; }

		if (($a['name'] ?? '') !== '') { $filter['%NAME'] = (string)$a['name']; }
		if (($a['code'] ?? '') !== '') { $filter['CODE'] = (string)$a['code']; }
		if (($a['section'] ?? 0) > 0) {
			$filter['SECTION_ID'] = (int)$a['section'];
			$filter['INCLUDE_SUBSECTIONS'] = 'Y';
		}
		if (!empty($a['ids']) && is_array($a['ids'])) {
			$filter['ID'] = array_map('intval', array_slice($a['ids'], 0, self::LIMIT_MAX));
		}

		// Фильтровать можно только по открытому свойству: фильтр отвечает «есть/нет»,
		// и этого хватает, чтобы перебором вытащить закрытое значение.
		$allowed = Expose::filterProps($iblock, self::props($iblock));
		foreach ((array)($a['property'] ?? []) as $code => $val) {
			$code = strtoupper((string)$code);
			if (!isset($allowed[$code]) && !isset($allowed[strtolower($code)])) {
				throw new ToolError('Свойство «' . $code . '» не открыто для чтения. Доступны: '
					. self::codeHint($allowed));
			}
			$filter['PROPERTY_' . $code] = is_array($val) ? array_map('strval', $val) : (string)$val;
		}

		$limit  = self::limit($a);
		$offset = max(0, (int)($a['offset'] ?? 0));

		// Свойства в выдачу не идут, пока не названы: карточка без них 398 байт,
		// со всеми 138 — 17,6 КБ, то есть двадцать строк дали бы 750 КБ.
		$want = self::wantedProps($a, $allowed);

		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'], $filter, false,
			['nPageSize' => $limit, 'iNumPage' => (int)floor($offset / $limit) + 1],
			self::FIELDS_LIST
		);
		$total = (int)$rs->SelectedRowsCount();

		$items = [];
		// Запрошенные поимённо свойства показываем и пустыми: «его нет» — это ответ.
		while ($el = $rs->GetNextElement()) {
			$items[] = self::shape($el, $want ?? [], self::FIELDS_LIST, false);
		}

		$out = [
			'iblock' => $iblock,
			'total'  => $total,
			'shown'  => count($items),
			'offset' => $offset,
			'items'  => $items,
		];

		if ($want === null) {
			$out['note'] = 'Свойства в выдачу поиска не включены — их ' . count($allowed)
				. '. Назовите нужные в props либо возьмите карточку целиком через element_get.';
		}
		if ($total > $offset + count($items)) {
			$out['more'] = 'Показаны не все: всего ' . $total . '. Следующая порция — offset '
				. ($offset + count($items)) . '.';
		}

		return $out;
	}

	/** Какие свойства просили; null — не просили. Неизвестный код — отказ. */
	private static function wantedProps(array $a, array $allowed): ?array
	{
		$want = $a['props'] ?? null;
		if (!is_array($want) || !$want) { return null; }

		$out = [];
		foreach ($want as $code) {
			$code = strtoupper((string)$code);
			if (!isset($allowed[$code])) {
				throw new ToolError('Свойство «' . $code . '» не открыто для чтения. Доступны: '
					. self::codeHint($allowed));
			}
			$out[$code] = $allowed[$code];
		}

		return $out;
	}

	public static function element(array $a): array
	{
		$id = (int)($a['id'] ?? 0);
		if ($id <= 0) { throw new ToolError('Не указан id элемента'); }

		\Bitrix\Main\Loader::includeModule('iblock');

		$rs = \CIBlockElement::GetList(['ID' => 'ASC'], ['ID' => $id], false, false, self::FIELDS_FULL);
		$el = $rs->GetNextElement();
		if (!$el) { throw new ToolError('Элемент ' . $id . ' не найден'); }

		$fields = $el->GetFields();
		$iblock = (int)$fields['IBLOCK_ID'];
		// Проверка после выборки, но до отдачи: инфоблок известен только по элементу.
		self::assertIblock($iblock);

		$allowed = Expose::filterProps($iblock, self::props($iblock));
		$want    = self::wantedProps($a, $allowed);

		$row = self::shape($el, $want ?? $allowed, self::FIELDS_FULL, $want === null);
		if ($want === null) {
			$row['PROPERTIES_NOTE'] = 'Показаны заполненные: ' . count($row['PROPERTIES'] ?? [])
				. ' из ' . count($allowed) . '. Пустые опущены.';
		}

		return $row;
	}

	public static function sections(array $a): array
	{
		$iblock = (int)($a['iblock'] ?? 0);
		self::assertIblock($iblock);

		\Bitrix\Main\Loader::includeModule('iblock');

		$out = [];
		// Третий аргумент — bIncCnt: без него ELEMENT_CNT не приходит вовсе.
		$rs = \CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $iblock], true,
			['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'ACTIVE', 'ELEMENT_CNT']);
		while ($s = $rs->GetNext(true, false)) {
			$out[] = [
				'id'       => (int)$s['ID'],
				'name'     => (string)$s['NAME'],
				'code'     => (string)$s['CODE'],
				'parent'   => (int)$s['IBLOCK_SECTION_ID'] ?: null,
				'depth'    => (int)$s['DEPTH_LEVEL'],
				'active'   => (string)$s['ACTIVE'],
				'elements' => isset($s['ELEMENT_CNT']) ? (int)$s['ELEMENT_CNT'] : null,
			];
		}

		return ['iblock' => $iblock, 'total' => count($out), 'sections' => $out];
	}

	private static function assertIblock(int $id): void
	{
		if (Expose::allows($id)) { return; }

		$open = Expose::ids();
		throw new ToolError($id > 0
			? ('Инфоблок ' . $id . ' не открыт для чтения. Открыты: '
				. (implode(', ', $open) ?: 'ни одного — настройте белый список в админке'))
			: ('Не указан инфоблок. Открыты: ' . (implode(', ', $open) ?: 'ни одного')));
	}

	/** Коды для текста отказа, с обрезкой: полный список бывает в полтора килобайта. */
	private static function codeHint(array $allowed): string
	{
		if (!$allowed) { return 'ни одного'; }

		$codes = array_keys($allowed);
		$head  = array_slice($codes, 0, 20);
		$rest  = count($codes) - count($head);

		return implode(', ', $head)
			. ($rest > 0 ? ' и ещё ' . $rest . ' (полный список — в site_info)' : '');
	}

	public static function limit(array $a): int
	{
		$n = (int)($a['limit'] ?? self::LIMIT_DEF);
		if ($n <= 0) { $n = self::LIMIT_DEF; }
		return min($n, self::LIMIT_MAX);
	}

	private static function shape($el, array $allowed, array $fields, bool $dropEmpty): array
	{
		$f = $el->GetFields();

		$row = [];
		foreach ($fields as $k) {
			if (!array_key_exists($k, $f)) { continue; }
			if ($k === 'PREVIEW_PICTURE' || $k === 'DETAIL_PICTURE') {
				$row[$k] = $f[$k] ? (string)\CFile::GetPath($f[$k]) : null;
				continue;
			}
			$row[$k] = $f[$k];
		}

		if ($allowed) {
			$props = [];
			foreach ($el->GetProperties() as $code => $p) {
				if (!isset($allowed[$code])) { continue; }

				$v = $p['VALUE'];
				if ($dropEmpty && ($v === null || $v === '' || $v === [] || $v === false)) { continue; }

				// Множественное свойство приходит массивом: (string) дало бы «Array».
				$props[$code] = [
					'name'  => (string)$p['NAME'],
					'value' => is_array($v) ? array_values(array_map('strval', $v)) : $v,
				];
			}
			$row['PROPERTIES'] = $props;
		}

		return $row;
	}
}
