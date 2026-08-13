<?php
namespace Itb\Mcp;

/**
 * Сборка набора инструментов под токен.
 * Описания собираются из настройки сайта: id и коды свойств у каждого сайта свои.
 */
class Tools
{
	const PROPS_IN_DESC = 40;

	/** Группы инструментов, из которых складываются права токена. */
	const GROUPS = [
		'catalog' => 'Каталог: поиск элементов, карточка, разделы, цены и остатки',
		'orders'  => 'Заказы: список, сводка, заказ целиком, покупатели — с их данными',
		'api'     => 'Разведка API: классы, сущности, исходники, события, агенты',
		'reports' => 'Отчёты: динамика продаж, топ товаров, брошенные корзины — без данных покупателей',
		'files'   => 'Файлы: чтение и поиск по коду в local, шаблонах и lib модулей',
		'sql'     => 'SQL: произвольный SELECT к базе сайта',
	];

	/** Короткие подписи для таблицы прав в админке. */
	const GROUP_SHORT = [
		'catalog' => 'каталог',
		'orders'  => 'заказы',
		'reports' => 'отчёты',
		'api'     => 'API',
		'files'   => 'файлы',
		'sql'     => 'SQL',
	];

	/**
	 * @param string[] $groups группы, выданные токену; пусто — только site_info
	 */
	public static function build(array $groups = []): Registry
	{
		$reg = new Registry();

		foreach (self::pick(self::all(), $groups) as $tool) { $reg->add($tool); }
		// Сценарии отбираются по тем же группам: предлагать то, чего нельзя
		// вызвать, — обман.
		$reg->setGroups($groups);

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

	/**
	 * Пересечение двух условий: группа включена НА САЙТЕ и выдана ТОКЕНУ.
	 *
	 * Выключенная группа приходит сюда пустым списком, поэтому право токена на
	 * неё ничего не даёт — доступ не «остаётся до перезапуска», его просто нет.
	 * Проверяется на каждом запросе, состав инструментов нигде не кэшируется.
	 *
	 * Чистая функция: см. tests/tokens.php.
	 *
	 * @param array<string, Tool[]> $byGroup
	 * @param string[]              $granted
	 * @return Tool[]
	 */
	public static function pick(array $byGroup, array $granted): array
	{
		$out = [];
		foreach ($byGroup as $group => $tools) {
			// site_info доступен всегда: без него модель не знает, что ей открыто,
			// и начинает подставлять идентификаторы наугад.
			if ($group !== 'site' && !in_array($group, $granted, true)) { continue; }
			foreach ($tools as $tool) { $out[] = $tool; }
		}

		return $out;
	}

	/**
	 * Группы, включённые на этом сайте. Выключенная группа пуста — значит,
	 * выдавать её токену нечего, и в админке она недоступна для отметки.
	 *
	 * @return string[]
	 */
	public static function enabled(): array
	{
		$out = [];
		foreach (self::all() as $group => $tools) {
			if ($group !== 'site' && $tools) { $out[] = $group; }
		}

		return $out;
	}

	/** @return array<string, Tool[]> группа => инструменты */
	private static function all(): array
	{
		$site = [
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

		// Разведка API не зависит от белого списка инфоблоков: она про устройство
		// кода, а не про данные.
		$out = ['site' => $site, 'api' => self::apiTools(), 'orders' => self::orderTools(),
			'reports' => self::reportTools(), 'files' => self::fileTools(),
			'sql' => self::sqlTools(), 'catalog' => []];

		// Инструменты чтения появляются, только если что-то открыто: иначе модель
		// зовёт их и получает отказ на каждый вызов.
		if (!Expose::ids()) { return $out; }

		$where = self::describeIblocks();

		$tools = [];
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

		// Цены и остатки лежат в таблицах модуля catalog, а не в инфоблоке:
		// без него у товара нет ни цены, ни склада, и звать нечего.
		if (\Bitrix\Main\ModuleManager::isModuleInstalled('catalog')) {
			$tools[] = new Tool(
				'product_get',
				'Цены и остатки товара',
				'Товарная часть элемента: тип, доступность, вес и габариты, все типы цен с'
				. ' названиями, остатки по складам. У товара с торговыми предложениями'
				. ' цены стоят на предложениях — они возвращаются тем же вызовом, если'
				. ' инфоблок предложений тоже открыт.' . "\n"
				. 'Свойства товара сюда не входят: они в element_get.',
				[
					'type' => 'object',
					'properties' => ['id' => ['type' => 'integer', 'description' => 'ID элемента']],
					'required' => ['id'],
				],
				[Catalog::class, 'product']
			);

			$tools[] = new Tool(
				'store_list',
				'Склады',
				'Склады магазина: название, адрес, телефон, режим работы. Нужен, чтобы'
				. ' понимать остатки из product_get — там они приходят по идентификаторам.',
				['type' => 'object', 'properties' => new \stdClass()],
				[Catalog::class, 'stores']
			);
		}

		$out['catalog'] = $tools;

		return $out;
	}

	/**
	 * Заказы. Группа выключена по умолчанию: в свойствах заказа лежат имя,
	 * телефон и адрес покупателя.
	 *
	 * @return Tool[]
	 */
	private static function orderTools(): array
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'orders', 'N') !== 'Y') { return []; }

		// Модуль sale есть не во всех редакциях Битрикса. Без него инструменты не
		// показываем вовсе: иначе модель зовёт их и получает отказ на каждый вызов,
		// а выглядит это как сломанный сервер, а не как отсутствующий модуль.
		// isModuleInstalled, а не includeModule: проверка идёт на каждом запросе,
		// и грузить ради неё весь модуль незачем.
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('sale')) { return []; }

		return [
			new Tool(
				'order_search',
				'Поиск заказов',
				'Список заказов с отбором по номеру, статусу, датам, оплате и отмене.'
				. ' Возвращает поля заказа без состава корзины и без данных покупателя —'
				. ' за ними идите в order_get по конкретному заказу.'
				. ' Названия статусов и их коды покажет order_statuses.',
				[
					'type' => 'object',
					'properties' => [
						'id'       => ['type' => 'integer', 'description' => 'Внутренний ID заказа'],
						'account'  => ['type' => 'string', 'description' => 'Номер заказа, как его видит покупатель'],
						'status'   => ['type' => 'string', 'description' => 'Код статуса, например N или F'],
						'from'     => ['type' => 'string', 'description' => 'Создан не раньше, ДД.ММ.ГГГГ'],
						'to'       => ['type' => 'string', 'description' => 'Создан не позже, ДД.ММ.ГГГГ (включительно)'],
						'payed'    => ['type' => 'string', 'enum' => ['Y', 'N'], 'description' => 'Оплачен'],
						'canceled' => ['type' => 'string', 'enum' => ['Y', 'N'], 'description' => 'Отменён'],
						'user'     => ['type' => 'integer', 'description' => 'ID пользователя-покупателя'],
						'limit'    => ['type' => 'integer', 'minimum' => 1, 'maximum' => Orders::LIMIT_MAX,
							'description' => 'Сколько вернуть, по умолчанию ' . Orders::LIMIT_DEF],
						'offset'   => ['type' => 'integer', 'minimum' => 0, 'description' => 'Сдвиг для листания'],
					],
				],
				[Orders::class, 'search']
			),
			new Tool(
				'order_get',
				'Заказ целиком',
				'Один заказ: поля, состав корзины с ценами и количеством, свойства заказа.'
				. ' ВНИМАНИЕ: в свойствах — персональные данные покупателя (имя, телефон,'
				. ' адрес доставки).',
				[
					'type' => 'object',
					'properties' => [
						'id'      => ['type' => 'integer', 'description' => 'Внутренний ID заказа'],
						'account' => ['type' => 'string', 'description' => 'Либо номер заказа'],
					],
				],
				[Orders::class, 'get']
			),
			new Tool(
				'order_statuses',
				'Статусы заказов',
				'Коды статусов этого сайта и их названия. Нужен, чтобы задать status'
				. ' в order_search: коды у каждого магазина свои.',
				['type' => 'object', 'properties' => new \stdClass()],
				[Orders::class, 'statuses']
			),
			new Tool(
				'order_stats',
				'Сводка по заказам',
				'Итоги за период одним запросом: сколько заказов и на какую сумму, сколько'
				. ' оплачено и отменено, разбивка по статусам и десять покупателей с'
				. ' наибольшим числом заказов.' . "\n"
				. 'Считает база. Не выбирайте сотню заказов через order_search, чтобы'
				. ' сложить их самостоятельно — для этого есть этот инструмент.',
				[
					'type' => 'object',
					'properties' => [
						'from'   => ['type' => 'string', 'description' => 'Создан не раньше, ДД.ММ.ГГГГ'],
						'to'     => ['type' => 'string', 'description' => 'Создан не позже, ДД.ММ.ГГГГ (включительно)'],
						'status' => ['type' => 'string', 'description' => 'Только этот статус'],
					],
				],
				[Orders::class, 'stats']
			),
			new Tool(
				'user_get',
				'Покупатель',
				'Карточка пользователя по ID, логину или почте: имя, контакты, группы,'
				. ' даты регистрации и последнего входа, число заказов и их сумма.'
				. ' Нужен, чтобы понять, кто стоит за USER_ID в заказе: у обменов с'
				. ' маркетплейсами все заказы висят на служебном пользователе.' . "\n"
				. 'ВНИМАНИЕ: это персональные данные.',
				[
					'type' => 'object',
					'properties' => [
						'id'    => ['type' => 'integer', 'description' => 'ID пользователя'],
						'login' => ['type' => 'string', 'description' => 'Либо логин, точное совпадение'],
						'email' => ['type' => 'string', 'description' => 'Либо почта, точное совпадение'],
					],
				],
				[Users::class, 'get']
			),
		];
	}

	/**
	 * Отчёты по продажам. Отдельно от заказов: здесь только итоги, персональных
	 * данных нет, и группу можно выдать тому, кому карточки заказов не нужны.
	 *
	 * @return Tool[]
	 */
	private static function reportTools(): array
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'reports', 'N') !== 'Y') { return []; }
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('sale')) { return []; }

		$dates = [
			'from' => ['type' => 'string', 'description' => 'Начало периода, ДД.ММ.ГГГГ'],
			'to'   => ['type' => 'string', 'description' => 'Конец периода, ДД.ММ.ГГГГ (включительно)'],
		];

		$tools = [
			new Tool(
				'sales_report',
				'Динамика продаж',
				'Заказы и выручка по дням, неделям или месяцам: сумма, средний чек,'
				. ' сколько оплачено. Отменённые не учитываются, пока не попросят.'
				. "\n" . 'Отвечает на «как идут продажи» — это ряд, а не одно число.'
				. ' Итог за период одним числом даёт order_stats.',
				[
					'type' => 'object',
					'properties' => $dates + [
						'by' => ['type' => 'string', 'enum' => ['day', 'week', 'month'],
							'description' => 'Шаг, по умолчанию day'],
						'with_canceled' => ['type' => 'boolean',
							'description' => 'Учитывать отменённые заказы'],
					],
				],
				[Sales::class, 'report']
			),
			new Tool(
				'top_products',
				'Топ товаров',
				'Что продавалось за период: количество, выручка и в скольких заказах.'
				. ' Считается по составу корзин, то есть по фактическим ценам со скидками.',
				[
					'type' => 'object',
					'properties' => $dates + [
						'sort'  => ['type' => 'string', 'enum' => ['revenue', 'quantity'],
							'description' => 'Сортировка, по умолчанию revenue'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Sales::TOP_MAX,
							'description' => 'Сколько позиций, по умолчанию ' . Sales::TOP_DEF],
						'with_canceled' => ['type' => 'boolean',
							'description' => 'Учитывать отменённые заказы'],
					],
				],
				[Sales::class, 'topProducts']
			),
			new Tool(
				'abandoned_carts',
				'Брошенные корзины',
				'Корзины, не ставшие заказами: сколько их, на какую сумму и что в них'
				. ' чаще всего остаётся. Отложенное и недоступное к покупке не в счёт.',
				[
					'type' => 'object',
					'properties' => $dates + [
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Sales::TOP_MAX,
							'description' => 'Сколько позиций в списке, по умолчанию ' . Sales::TOP_DEF],
					],
				],
				[Sales::class, 'abandoned']
			),
		];

		$window = ['days' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 365,
			'description' => 'За сколько дней смотреть'],
			'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Stock::LIMIT_MAX,
				'description' => 'Сколько позиций, по умолчанию ' . Stock::LIMIT_DEF]];

		// Остатки живут в модуле catalog: без него складских отчётов не бывает.
		if (\Bitrix\Main\ModuleManager::isModuleInstalled('catalog')) {
			$tools[] = new Tool(
				'slow_movers',
				'Неликвиды',
				'Товары с остатком, которые не продавались за период: сколько лежит'
				. ' и на какую сумму по базовой цене. Отсортированы по стоимости остатка —'
				. ' сверху то, где заморожено больше денег.',
				['type' => 'object', 'properties' => $window],
				[Stock::class, 'slowMovers']
			);
			$tools[] = new Tool(
				'low_stock',
				'Заканчивается',
				'Товары, которых продано больше, чем осталось на складе: продажи за период,'
				. ' текущий остаток и на сколько дней его хватит при том же спросе.',
				['type' => 'object', 'properties' => $window],
				[Stock::class, 'lowStock']
			);
		}

		// Статистику поиска ведёт модуль search; на редакциях без него таблицы нет.
		if (\Bitrix\Main\ModuleManager::isModuleInstalled('search')) {
			$tools[] = new Tool(
				'search_phrases',
				'Поисковые запросы',
				'Что искали на сайте: фраза, сколько раз и сколько нашлось.'
				. ' С no_results — только фразы, по которым не нашлось ничего:'
				. ' это спрос, на который сайт не отвечает.',
				[
					'type' => 'object',
					'properties' => $window + [
						'no_results' => ['type' => 'boolean',
							'description' => 'Только фразы без результатов'],
					],
				],
				[Stock::class, 'searchPhrases']
			);
		}

		return $tools;
	}

	/**
	 * Чтение файлов проекта. Выключено по умолчанию: наружу уходят исходники,
	 * а не данные. Границу держит Path, а не эти описания.
	 *
	 * @return Tool[]
	 */
	private static function fileTools(): array
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'files', 'N') !== 'Y') { return []; }

		$where = 'Открыты: local/ (свой код, компоненты, шаблоны, php_interface),'
			. ' bitrix/templates/ и bitrix/modules/*/lib/. Остальное — отказ.'
			. ' Файлы с паролями (dbconn.php, .settings.php, .env) закрыты отдельно,'
			. ' читаются только текст и код.';

		return [
			new Tool(
				'file_read',
				'Прочитать файл',
				'Файл по пути от корня сайта, целиком или куском строк. Дополняет'
				. ' api_source: тот показывает только код классов, а компоненты,'
				. ' шаблоны и обычные функции классами не являются.' . "\n" . $where,
				[
					'type' => 'object',
					'properties' => [
						'path'  => ['type' => 'string',
							'description' => 'Путь от корня сайта, например local/php_interface/init.php'],
						'from'  => ['type' => 'integer', 'minimum' => 1,
							'description' => 'С какой строки, по умолчанию с первой'],
						'lines' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Files::LINES_MAX,
							'description' => 'Сколько строк, по умолчанию ' . Files::LINES_DEF],
					],
					'required' => ['path'],
				],
				[Files::class, 'read']
			),
			new Tool(
				'file_list',
				'Состав папки',
				'Файлы и подпапки: имя, размер, дата изменения и признак того, доступен ли'
				. ' файл для чтения. Годится, чтобы осмотреться перед file_read.' . "\n" . $where,
				[
					'type' => 'object',
					'properties' => ['path' => ['type' => 'string',
						'description' => 'Папка от корня сайта, например local/components']],
					'required' => ['path'],
				],
				[Files::class, 'listDir']
			),
			new Tool(
				'file_grep',
				'Поиск по коду',
				'Поиск подстроки в файлах указанной папки и вложенных. Ищется именно'
				. ' подстрока, не регулярное выражение. Отвечает на вопрос «где это'
				. ' вызывается», на который рефлексия ответить не может.' . "\n" . $where,
				[
					'type' => 'object',
					'properties' => [
						'query'       => ['type' => 'string', 'minLength' => 3,
							'description' => 'Что искать, минимум три символа'],
						'path'        => ['type' => 'string',
							'description' => 'Где искать, например local или bitrix/modules/sale/lib'],
						'ignore_case' => ['type' => 'boolean', 'description' => 'Не различать регистр'],
					],
					'required' => ['query', 'path'],
				],
				[Files::class, 'grep']
			),
		];
	}

	/**
	 * Разведка API: что за классы есть в этой установке и как они устроены.
	 * Группа выключена по умолчанию — включается в настройках модуля.
	 *
	 * @return Tool[]
	 */
	private static function apiTools(): array
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'api', 'N') !== 'Y') { return []; }

		$tools = [
			new Tool(
				'api_modules',
				'Модули Битрикса',
				'Установленные модули этой сборки с версиями. Нужен, чтобы не писать код'
				. ' под модуль, которого здесь нет, и знать версию, от которой зависит'
				. ' состав методов.',
				['type' => 'object', 'properties' => new \stdClass()],
				[Api::class, 'modules']
			),
			new Tool(
				'api_class',
				'Устройство класса',
				'Существует ли класс в ЭТОЙ установке, в каком файле лежит, от кого'
				. ' наследуется, какие у него публичные методы с полными сигнатурами и'
				. ' какие константы. Зовите вместо того, чтобы вспоминать имя метода:'
				. ' версии Битрикса различаются, и метод из документации может'
				. ' отсутствовать.',
				[
					'type' => 'object',
					'properties' => ['class' => ['type' => 'string',
						'description' => 'Полное имя класса, например \\Bitrix\\Iblock\\ElementTable']],
					'required' => ['class'],
				],
				[Api::class, 'classInfo']
			),
			new Tool(
				'api_entity',
				'Поля ORM-сущности',
				'Для класса-наследника DataManager: имя таблицы и полный состав полей с'
				. ' типами, обязательностью и ссылками на другие сущности. Нужен, чтобы'
				. ' писать getList с существующими полями.',
				[
					'type' => 'object',
					'properties' => ['class' => ['type' => 'string',
						'description' => 'Класс ORM-сущности, например \\Bitrix\\Catalog\\StoreProductTable']],
					'required' => ['class'],
				],
				[Api::class, 'entity']
			),
			new Tool(
				'api_source',
				'Исходник класса или метода',
				'Исходный код класса целиком или одного его метода. Путь берётся у'
				. ' рефлексии, произвольный файл прочитать нельзя. Отвечает на вопросы,'
				. ' на которые документация не отвечает: что метод делает с пустым'
				. ' значением, какие поля пишет, чем бросается.',
				[
					'type' => 'object',
					'properties' => [
						'class'  => ['type' => 'string', 'description' => 'Полное имя класса'],
						'method' => ['type' => 'string', 'description' => 'Метод; без него — класс целиком'],
					],
					'required' => ['class'],
				],
				[Api::class, 'source']
			),
			new Tool(
				'api_find_class',
				'Поиск классов модуля',
				'Файлы и классы в папке lib выбранного модуля, с отбором по части пути.'
				. ' Нужен, когда известно, что «где-то есть класс про склады», но не'
				. ' известно его имя. Модуль обязателен: обход всего ядра слишком дорог.',
				[
					'type' => 'object',
					'properties' => [
						'module' => ['type' => 'string', 'description' => 'Идентификатор модуля, например catalog'],
						'query'  => ['type' => 'string', 'description' => 'Часть пути или имени файла'],
					],
					'required' => ['module'],
				],
				[Api::class, 'findClass']
			),
			new Tool(
				'api_function',
				'Обычная функция',
				'Где объявлена функция, как вызывается и что о ней написано в докблоке.'
				. ' Без имени — список объявленных функций с отбором по части имени.'
				. ' Классы находит автозагрузка, функции — нет, поэтому api_class о них'
				. ' ничего не знает.',
				[
					'type' => 'object',
					'properties' => [
						'name'  => ['type' => 'string', 'description' => 'Имя функции'],
						'query' => ['type' => 'string', 'description' => 'Либо часть имени — для списка'],
					],
				],
				[Api::class, 'functionInfo']
			),
			new Tool(
				'api_events',
				'Обработчики событий',
				'Что навешано на события этой установки: чьё событие, что вызывается, из'
				. ' какого модуля и файла. Постоянная регистрация видна всегда;'
				. ' обработчики из init.php — только при запросе по конкретной паре'
				. ' module и event, потому что в базе их нет.',
				[
					'type' => 'object',
					'properties' => [
						'module' => ['type' => 'string',
							'description' => 'Модуль, чьё событие, например iblock или sale'],
						'event'  => ['type' => 'string',
							'description' => 'Событие, например OnAfterIBlockElementUpdate'],
					],
				],
				[Site::class, 'events']
			),
			new Tool(
				'api_agents',
				'Агенты',
				'Что запускается по расписанию: модуль, вызов, активность, интервал,'
				. ' прошлый и следующий запуск. Отвечает на вопрос «почему это делается'
				. ' само» и показывает оборвавшиеся агенты.',
				[
					'type' => 'object',
					'properties' => [
						'module' => ['type' => 'string', 'description' => 'Только агенты этого модуля'],
						'active' => ['type' => 'string', 'enum' => ['Y', 'N'],
							'description' => 'Только включённые или только выключенные'],
					],
				],
				[Site::class, 'agents']
			),
		];

		// Highload-блоки есть не во всех редакциях: без модуля инструмент не
		// показываем, иначе модель зовёт его и получает отказ.
		if (\Bitrix\Main\ModuleManager::isModuleInstalled('highloadblock')) {
			$tools[] = new Tool(
				'hl_list',
				'Highload-блоки',
				'Highload-блоки и состав их полей: имя, таблица, коды и типы полей.'
				. ' Сами строки читаются из этой таблицы инструментом sql_select.',
				['type' => 'object', 'properties' => new \stdClass()],
				[Site::class, 'hlBlocks']
			);
		}

		return $tools;
	}

	/**
	 * Произвольный SELECT. Выключено по умолчанию: этой группой читается всё,
	 * до чего дотягивается база, а не то, что перечислено в белых списках.
	 *
	 * @return Tool[]
	 */
	private static function sqlTools(): array
	{
		if (\Bitrix\Main\Config\Option::get('itb.mcp', 'sql', 'N') !== 'Y') { return []; }

		$limits = 'Только SELECT: изменение, удаление, запись файлов и вторая инструкция'
			. ' через точку с запятой отвергаются. Закрыты таблицы с паролями и ключами'
			. ' (b_user, b_option и им подобные) — данные покупателя берите в user_get.';

		return [
			new Tool(
				'sql_tables',
				'Таблицы базы',
				'Таблицы этой базы с оценкой числа строк, а с параметром name — колонки'
				. ' одной таблицы с типами. Нужен, чтобы не писать запрос вслепую.',
				[
					'type' => 'object',
					'properties' => [
						'name'  => ['type' => 'string', 'description' => 'Таблица — тогда вернутся её колонки'],
						'query' => ['type' => 'string', 'description' => 'Часть имени для отбора таблиц'],
					],
				],
				[Sql::class, 'tables']
			),
			new Tool(
				'sql_select',
				'Запрос к базе',
				'Произвольный SELECT к базе сайта: ответ колонками и строками.' . "\n"
				. $limits . "\n"
				. 'Строки highload-блоков читаются отсюда же — их таблицы видны в hl_list.',
				[
					'type' => 'object',
					'properties' => [
						'query' => ['type' => 'string', 'description' => 'Запрос, начинающийся с SELECT или WITH'],
						'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => Sql::ROWS_MAX,
							'description' => 'Сколько строк вернуть, по умолчанию ' . Sql::ROWS_DEF],
					],
					'required' => ['query'],
				],
				[Sql::class, 'select']
			),
		];
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
