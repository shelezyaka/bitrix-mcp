<?php
namespace Itb\Mcp;

/**
 * Сборка набора инструментов под токен.
 * Описания собираются из настройки сайта: id и коды свойств у каждого сайта свои.
 */
class Tools
{
	const PROPS_IN_DESC = 40;

	/** @param string[]|null $allow null — всё разрешённое настройкой */
	public static function build(?array $allow = null): Registry
	{
		$reg = new Registry();

		foreach (self::all() as $tool) {
			if ($allow !== null && !in_array($tool->name, $allow, true)) { continue; }
			$reg->add($tool);
		}

		$open = Expose::ids();
		$reg->setInstructions(
			"Это сайт на 1С-Битрикс. Сервер работает ТОЛЬКО НА ЧТЕНИЕ: изменить или удалить"
			. " что-либо через него нельзя.\n"
			. "Данные боевые — это работающий магазин, а не тестовый стенд.\n"
			. ($open
				? ("Открыты инфоблоки: " . implode(', ', $open) . ". Состав — в site_info.\n")
				: "Ни один инфоблок пока не открыт: белый список настраивается в админке.\n")
			. "Идентификаторы и коды свойств берите из site_info, а не по памяти."
		);

		return $reg;
	}

	/** @return Tool[] */
	private static function all(): array
	{
		$tools = [
			new Tool(
				'site_info',
				'Сведения о сайте',
				'Версия 1С-Битрикс, подключённые модули и перечень инфоблоков, открытых для'
				. ' чтения, с их названиями и составом свойств. Зовите первым: остальные'
				. ' инструменты работают с идентификаторами, которые видны отсюда.',
				['type' => 'object', 'properties' => new \stdClass()],
				[self::class, 'siteInfo']
			),
		];

		// Инструменты чтения появляются, только если что-то открыто: иначе модель
		// зовёт их и получает отказ на каждый вызов.
		if (!Expose::ids()) { return $tools; }

		$where = self::describeIblocks();

		$tools[] = new Tool(
			'element_search',
			'Поиск элементов',
			'Поиск элементов инфоблока по названию, символьному коду, разделу, активности'
			. ' и значениям свойств.' . "\n"
			. 'Свойства в выдачу НЕ включаются, пока не названы в props — иначе ответ'
			. ' раздувается в десятки раз. Нужна карточка целиком — element_get по id.'
			. "\n" . $where,
			[
				'type' => 'object',
				'properties' => [
					'iblock'   => ['type' => 'integer', 'description' => 'ID инфоблока из числа открытых'],
					'name'     => ['type' => 'string', 'description' => 'Часть названия (поиск по вхождению)'],
					'code'     => ['type' => 'string', 'description' => 'Символьный код элемента, точное совпадение'],
					'ids'      => ['type' => 'array', 'items' => ['type' => 'integer'],
						'maxItems' => Data::LIMIT_MAX, 'description' => 'Конкретные ID элементов'],
					'section'  => ['type' => 'integer', 'description' => 'ID раздела, включая вложенные'],
					'active'   => ['type' => 'string', 'enum' => ['Y', 'N', 'any'],
						'description' => 'Y — активные, N — выключенные, any — всё (по умолчанию)'],
					'property' => ['type' => 'object',
						'description' => 'Фильтр по свойствам: {"КОД": "значение"}. Коды — только из открытых'],
					'props'    => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 30,
						'description' => 'Какие свойства вернуть, кодами. Без этого свойств в ответе нет'],
					'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => Data::LIMIT_MAX,
						'description' => 'Сколько вернуть, по умолчанию ' . Data::LIMIT_DEF],
					'offset'   => ['type' => 'integer', 'minimum' => 0, 'description' => 'Сдвиг для листания'],
				],
				'required' => ['iblock'],
			],
			[Data::class, 'search']
		);

		$tools[] = new Tool(
			'element_get',
			'Элемент целиком',
			'Один элемент по ID: поля карточки, описание и заполненные свойства. Пустые'
			. ' свойства опускаются, их число указано отдельно. Инфоблок определяется по'
			. ' элементу, но открыт для чтения он должен быть.',
			[
				'type' => 'object',
				'properties' => [
					'id'    => ['type' => 'integer', 'description' => 'ID элемента'],
					'props' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 30,
						'description' => 'Вернуть только эти свойства. Без этого — все заполненные'],
				],
				'required' => ['id'],
			],
			[Data::class, 'element']
		);

		$tools[] = new Tool(
			'section_list',
			'Разделы инфоблока',
			'Дерево разделов: ID, название, родитель, уровень вложенности и число элементов.'
			. ' Нужен, чтобы задать section в element_search.' . "\n" . $where,
			[
				'type' => 'object',
				'properties' => ['iblock' => ['type' => 'integer', 'description' => 'ID инфоблока из числа открытых']],
				'required' => ['iblock'],
			],
			[Data::class, 'sections']
		);

		return $tools;
	}

	/** Свойства перечисляются кодами и именами: код нужен для фильтра, имя — для смысла. */
	private static function describeIblocks(): string
	{
		$lines = [];
		foreach (Data::catalogue() as $ib) {
			if (!empty($ib['error'])) {
				$lines[] = '- ' . $ib['id'] . ': ' . $ib['error'];
				continue;
			}
			$props = [];
			foreach ($ib['props'] as $code => $name) {
				$props[] = $code . ' (' . $name . ')';
				if (count($props) >= self::PROPS_IN_DESC) { $props[] = '…'; break; }
			}
			$lines[] = '- ' . $ib['id'] . ' «' . $ib['name'] . '»'
				. ($ib['type'] !== '' ? ', тип ' . $ib['type'] : '')
				. ($props ? '. Свойства: ' . implode(', ', $props) : '. Свойств нет');
		}

		return $lines ? ("Открыты инфоблоки:\n" . implode("\n", $lines)) : '';
	}

	public static function siteInfo(array $args): array
	{
		$mods = [];
		foreach (['iblock', 'catalog', 'sale', 'highloadblock'] as $m) {
			$mods[$m] = \Bitrix\Main\Loader::includeModule($m);
		}

		$cat = Data::catalogue();

		return [
			'bitrix_version'  => defined('SM_VERSION') ? SM_VERSION : 'неизвестна',
			'php_version'     => PHP_VERSION,
			'server_time'     => date('c'),
			'modules'         => $mods,
			'exposed_iblocks' => $cat,
			'note' => $cat
				? 'Читать эти инфоблоки можно инструментами element_search, element_get, section_list.'
				: 'Белый список инфоблоков пуст — откройте нужные в настройках модуля в админке.',
		];
	}
}
