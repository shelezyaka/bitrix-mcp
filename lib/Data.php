<?php
namespace Itb\Mcp;

/**
 * Чтение данных сайта. Единственное место, где модуль обращается к каталогу.
 *
 * ⚠️⚠️ Ни один метод не принимает фильтр от вызывающего «как есть». Собирается
 * он здесь, из проверенных кусочков, и каждый код свойства сверяется с белым
 * списком (`Expose`). Передать фильтр насквозь в `GetList` — самый простой
 * способ отдать наружу цены, персональные данные и таблицу пользователей: в
 * Битриксе фильтр умеет и подзапросы, и поля связанных сущностей.
 *
 * ⚠️ Только чтение. Записи нет ни одной — не «отключена настройкой», а
 * отсутствует в коде.
 */
class Data
{
	/** Потолок выдачи. Больше модели всё равно не нужно за один вызов. */
	const LIMIT_MAX = 50;
	const LIMIT_DEF = 20;

	/**
	 * Поля элемента.
	 *
	 * ⚠️ Список ФИКСИРОВАННЫЙ и не настраивается. Это описание карточки, одинаковое
	 * на любом сайте; всё, что специфично, лежит в свойствах и проходит через
	 * белый список. Позволь мы выбирать поля — пришлось бы отвечать на вопрос,
	 * какие из них безопасны, на каждом сайте заново.
	 *
	 * ⚠️⚠️ У списка и у карточки наборы РАЗНЫЕ. Тексты описания в выдачу поиска не
	 * идут: на этом каталоге они короткие, но на контентном сайте одно описание
	 * весит килобайты, и двадцать строк поиска превращаются в простыню. Кому нужен
	 * текст — тот зовёт element_get по конкретному id.
	 */
	const FIELDS_FULL = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT',
		'DATE_CREATE', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID', 'DETAIL_PAGE_URL',
		'PREVIEW_TEXT', 'DETAIL_TEXT', 'PREVIEW_PICTURE', 'DETAIL_PICTURE'];

	const FIELDS_LIST = ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE', 'SORT',
		'DATE_CREATE', 'TIMESTAMP_X', 'IBLOCK_SECTION_ID', 'DETAIL_PAGE_URL',
		'PREVIEW_PICTURE', 'DETAIL_PICTURE'];

	/** Свойства инфоблока: код => человеческое имя. Кэш на запрос. */
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

	/**
	 * Инфоблоки из белого списка с их названиями и составом свойств.
	 *
	 * ⚠️ Кэш на запрос обязателен: описания инструментов строятся из этого же
	 * перечня, а реестр собирается на КАЖДОМ обращении к серверу — иначе один
	 * вызов инструмента тянул бы за собой обход всех открытых инфоблоков со
	 * списком их свойств.
	 */
	public static function catalogue(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		\Bitrix\Main\Loader::includeModule('iblock');

		$out = [];
		foreach (Expose::all() as $id => $rule) {
			$ib = \CIBlock::GetByID($id)->Fetch();
			if (!$ib) {
				// ⚠️ Инфоблок могли удалить, а строка в настройках осталась.
				// Показываем это прямо, а не пропускаем: иначе человек уверен,
				// что данные открыты, а их нет.
				$out[] = ['id' => $id, 'name' => null, 'error' => 'инфоблок не найден'];
				continue;
			}
			$props = Expose::filterProps($id, self::props($id));
			$out[] = [
				'id'         => $id,
				'name'       => (string)$ib['NAME'],
				'code'       => (string)$ib['CODE'],
				'type'       => (string)$ib['IBLOCK_TYPE_ID'],
				'props_mode' => $rule['props'] === null ? 'все свойства' : 'только выбранные',
				'props'      => $props,
			];
		}

		return $cache = $out;
	}

	/**
	 * Поиск элементов.
	 *
	 * ⚠️ Инфоблок проверяется ПЕРВЫМ и отказ объясняет, что вообще доступно:
	 * модель ошиблась id — она же должна суметь исправиться, а «доступ запрещён»
	 * без списка не подсказывает ничего.
	 */
	public static function search(array $a): array
	{
		$iblock = (int)($a['iblock'] ?? 0);
		self::assertIblock($iblock);

		\Bitrix\Main\Loader::includeModule('iblock');

		$filter = ['IBLOCK_ID' => $iblock];

		$active = (string)($a['active'] ?? 'any');
		if ($active === 'Y' || $active === 'N') { $filter['ACTIVE'] = $active; }

		if (($a['name'] ?? '') !== '')    { $filter['%NAME'] = (string)$a['name']; }
		if (($a['code'] ?? '') !== '')    { $filter['CODE'] = (string)$a['code']; }
		if (($a['section'] ?? 0) > 0) {
			$filter['SECTION_ID'] = (int)$a['section'];
			$filter['INCLUDE_SUBSECTIONS'] = 'Y';
		}
		if (!empty($a['ids']) && is_array($a['ids'])) {
			$filter['ID'] = array_map('intval', array_slice($a['ids'], 0, self::LIMIT_MAX));
		}

		// ⚠️ Фильтр по свойству — только по коду ИЗ БЕЛОГО СПИСКА. Иначе через
		// него читается любое свойство инфоблока, включая те, что человек намеренно
		// не открывал: фильтр отвечает «есть/нет» и этого достаточно, чтобы
		// перебором вытащить значение.
		$allowed = Expose::filterProps($iblock, self::props($iblock));
		foreach ((array)($a['property'] ?? []) as $code => $val) {
			$code = strtoupper((string)$code);
			if (!isset($allowed[$code]) && !isset($allowed[strtolower($code)])) {
				throw new ToolError('Свойство «' . $code . '» не открыто для чтения. Доступны: '
					. (implode(', ', array_keys($allowed)) ?: 'ни одного'));
			}
			$filter['PROPERTY_' . $code] = is_array($val) ? array_map('strval', $val) : (string)$val;
		}

		$limit  = self::limit($a);
		$offset = max(0, (int)($a['offset'] ?? 0));

		// ⚠️⚠️ В выдачу поиска свойства НЕ идут, пока их не попросили поимённо.
		// Замерено на живом каталоге: карточка без свойств — 398 байт, со всеми
		// 138 — 17,6 КБ. Двадцать строк превращались в 750 КБ, то есть один поиск
		// съедал весь контекст модели и вытеснял оттуда сам вопрос. Нужные
		// свойства называются в `props`, остальное — element_get по конкретному id.
		$want = self::wantedProps($a, $allowed);

		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'], $filter, false,
			['nPageSize' => $limit, 'iNumPage' => (int)floor($offset / $limit) + 1],
			self::FIELDS_LIST
		);
		$total = (int)$rs->SelectedRowsCount();

		$items = [];
		while ($el = $rs->GetNextElement()) {
			// ⚠️ Когда свойства запрошены поимённо, пустые значения ОСТАЮТСЯ:
			// спросили про артикул — надо видеть, что его нет, а не гадать,
			// потерялся он или не заполнен.
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
				. ', и ответ вышел бы в десятки раз больше. Назовите нужные в props'
				. ' (например ["CML2_ARTICLE"]) либо возьмите карточку целиком через element_get.';
		}
		if ($total > $offset + count($items)) {
			$out['more'] = 'Показаны не все: всего ' . $total . '. Следующая порция — offset '
				. ($offset + count($items)) . '.';
		}

		return $out;
	}

	/**
	 * Какие свойства просили: null — не просили ни одного.
	 *
	 * ⚠️ Неизвестный код — ОТКАЗ со списком доступных, а не молчаливый пропуск.
	 * Пропустив опечатку, мы вернём карточку без запрошенного свойства, и это
	 * неотличимо от «свойство пустое».
	 */
	private static function wantedProps(array $a, array $allowed): ?array
	{
		$want = $a['props'] ?? null;
		if (!is_array($want) || !$want) { return null; }

		$out = [];
		foreach ($want as $code) {
			$code = strtoupper((string)$code);
			if (!isset($allowed[$code])) {
				throw new ToolError('Свойство «' . $code . '» не открыто для чтения. Доступны: '
					. (implode(', ', array_keys($allowed)) ?: 'ни одного'));
			}
			$out[$code] = $allowed[$code];
		}

		return $out;
	}

	/** Один элемент со всеми разрешёнными свойствами. */
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
		// ⚠️ Проверка ПОСЛЕ выборки, но ДО отдачи: по id заранее не известно, в
		// каком инфоблоке лежит элемент, а отдать его из закрытого — то же самое,
		// что открыть инфоблок целиком (id перебирается).
		self::assertIblock($iblock);

		$allowed = Expose::filterProps($iblock, self::props($iblock));
		$want    = self::wantedProps($a, $allowed);

		// ⚠️ Пустые свойства из карточки выбрасываются: на живом товаре пусты 80 из
		// 138, и они занимают треть ответа, не сообщая ничего. Сколько выброшено —
		// написано рядом, иначе «свойств 58» читалось бы как «в инфоблоке их 58».
		$row = self::shape($el, $want ?? $allowed, self::FIELDS_FULL, $want === null);
		if ($want === null) {
			$shown = count($row['PROPERTIES'] ?? []);
			$row['PROPERTIES_NOTE'] = 'Показаны заполненные: ' . $shown . ' из ' . count($allowed)
				. '. Пустые опущены.';
		}

		return $row;
	}

	/** Разделы инфоблока деревом. */
	public static function sections(array $a): array
	{
		$iblock = (int)($a['iblock'] ?? 0);
		self::assertIblock($iblock);

		\Bitrix\Main\Loader::includeModule('iblock');

		$out = [];
		$rs = \CIBlockSection::GetList(['LEFT_MARGIN' => 'ASC'], ['IBLOCK_ID' => $iblock], false,
			['ID', 'NAME', 'CODE', 'IBLOCK_SECTION_ID', 'DEPTH_LEVEL', 'ACTIVE', 'ELEMENT_CNT']);
		while ($s = $rs->GetNext(true, false)) {
			$out[] = [
				'id'     => (int)$s['ID'],
				'name'   => (string)$s['NAME'],
				'code'   => (string)$s['CODE'],
				'parent' => (int)$s['IBLOCK_SECTION_ID'] ?: null,
				'depth'  => (int)$s['DEPTH_LEVEL'],
				'active' => (string)$s['ACTIVE'],
				'elements' => isset($s['ELEMENT_CNT']) ? (int)$s['ELEMENT_CNT'] : null,
			];
		}

		return ['iblock' => $iblock, 'total' => count($out), 'sections' => $out];
	}

	// ── Общее ───────────────────────────────────────────────────────────────

	private static function assertIblock(int $id): void
	{
		if (Expose::allows($id)) { return; }

		$open = Expose::ids();
		throw new ToolError($id > 0
			? ('Инфоблок ' . $id . ' не открыт для чтения. Открыты: '
				. (implode(', ', $open) ?: 'ни одного — настройте белый список в админке'))
			: ('Не указан инфоблок. Открыты: ' . (implode(', ', $open) ?: 'ни одного')));
	}

	public static function limit(array $a): int
	{
		$n = (int)($a['limit'] ?? self::LIMIT_DEF);
		if ($n <= 0) { $n = self::LIMIT_DEF; }
		return min($n, self::LIMIT_MAX);
	}

	/**
	 * Элемент → плоская структура: поля, картинки путями, разрешённые свойства.
	 *
	 * @param array $allowed   какие свойства отдавать (код => имя)
	 * @param array $fields    набор полей карточки
	 * @param bool  $dropEmpty выбрасывать ли пустые свойства
	 */
	private static function shape($el, array $allowed, array $fields, bool $dropEmpty): array
	{
		$f = $el->GetFields();

		$row = [];
		foreach ($fields as $k) {
			if (!array_key_exists($k, $f)) { continue; }
			if ($k === 'PREVIEW_PICTURE' || $k === 'DETAIL_PICTURE') {
				// ⚠️ Отдаём ПУТЬ, а не id файла: число само по себе не значит
				// ничего, а по пути картинку видно.
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

				// ⚠️ Множественное свойство приходит массивом, и `(string)` по нему
				// даёт буквальное «Array». Те же грабли уже были в разделах Ozon
				// и Маркета — здесь они бы просто выглядели как испорченные данные.
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
