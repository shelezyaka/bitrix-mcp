<?php
namespace Itb\Mcp;

/**
 * Сборка реестра инструментов под конкретный токен.
 *
 * ⚠️⚠️ Описания собираются ИЗ НАСТРОЙКИ СAЙТА, а не пишутся текстом. У каждого
 * сайта свои id инфоблоков и свои коды свойств; описание вида «поиск по
 * инфоблоку» оставляет модель наедине с безымянными числами, и она начинает
 * подставлять id по памяти — то есть чужие. Поэтому в описание уходят имена
 * инфоблоков и имена свойств, как они названы в этом каталоге.
 */
class Tools
{
	/** Сколько кодов свойств перечислять в описании. */
	const PROPS_IN_DESC = 40;

	/** @param string[]|null $allow null — всё разрешённое настройкой; список — только эти. */
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
				? ("Открыты инфоблоки: " . implode(', ', $open) . ". Что в них лежит и какие"
					. " свойства доступны — покажет site_info.\n")
				: "Ни один инфоблок пока не открыт: белый список настраивается в админке.\n")
			. "Идентификаторы и коды свойств у каждого сайта свои — берите их из site_info,"
			. " а не по памяти."
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
				'Версия 1С-Битрикс, подключённые модули и — главное — перечень инфоблоков,'
				. ' открытых для чтения, с их названиями и составом свойств. Зовите первым:'
				. ' остальные инструменты работают с идентификаторами, которые видны отсюда.',
				['type' => 'object', 'properties' => new \stdClass()],
				[self::class, 'siteInfo']
			),
		];

		// ⚠️ Инструменты чтения появляются, ТОЛЬКО если что-то открыто. Иначе
		// модель видит их в списке, зовёт и получает отказ на каждый вызов —
		// а выглядит это как сломанный сервер, а не как ненастроенный.
		if (!Expose::ids()) { return $tools; }

		$where = self::describeIblocks();

		$tools[] = new Tool(
			'element_search',
			'Поиск элементов',
			'Поиск элементов инфоблока по названию, символьному коду, разделу, активности'
			. ' и значениям свойств.' . "\n"
			. 'ВАЖНО: свойства в выдачу НЕ включаются, пока не названы в props — иначе'
			. ' ответ раздувается в десятки раз. Нужны отдельные свойства — перечислите'
			. ' их коды; нужна карточка целиком — возьмите её через element_get по id.'
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
						'description' => 'Y — только активные, N — только выключенные, any — всё (по умолчанию)'],
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
			'Один элемент по ID: поля карточки, описание и заполненные свойства со'
			. ' значениями. Пустые свойства опускаются, их число указано отдельно.'
			. ' Инфоблок указывать не нужно — он определяется по элементу, но открыт для'
			. ' чтения он должен быть.',
			[
				'type' => 'object',
				'properties' => [
					'id'    => ['type' => 'integer', 'description' => 'ID элемента'],
					'props' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 30,
						'description' => 'Вернуть только эти свойства, кодами. Без этого — все заполненные'],
				],
				'required' => ['id'],
			],
			[Data::class, 'element']
		);

		$tools[] = new Tool(
			'section_list',
			'Разделы инфоблока',
			'Дерево разделов инфоблока: ID, название, родитель, уровень вложенности и число'
			. ' элементов. Нужен, чтобы задать section в element_search.' . "\n" . $where,
			[
				'type' => 'object',
				'properties' => ['iblock' => ['type' => 'integer', 'description' => 'ID инфоблока из числа открытых']],
				'required' => ['iblock'],
			],
			[Data::class, 'sections']
		);

		return $tools;
	}

	/**
	 * Строка «что открыто» для описаний инструментов.
	 *
	 * ⚠️ Свойства перечисляются КОДАМИ И ИМЕНАМИ: код нужен для фильтра, имя —
	 * чтобы понять, что это. Один код без имени («CML2_ARTICLE») не говорит
	 * ничего, одно имя без кода бесполезно для запроса.
	 */
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
			'bitrix_version' => defined('SM_VERSION') ? SM_VERSION : 'неизвестна',
			'php_version'    => PHP_VERSION,
			'server_time'    => date('c'),
			'modules'        => $mods,
			'exposed_iblocks' => $cat,
			// ⚠️ Пустой список объясняет себя. Иначе модель решит, что на сайте
			// нет инфоблоков, и начнёт сочинять id.
			'note' => $cat
				? 'Читать эти инфоблоки можно инструментами element_search, element_get, section_list.'
				: 'Белый список инфоблоков пуст — откройте нужные в настройках модуля в админке.'
				. ' Пока доступен только site_info.',
		];
	}
}
