<?php
namespace Itb\Mcp;

/**
 * Готовые сценарии: клиент показывает их списком, модель получает постановку
 * задачи. Отбор по группам токена. Без Битрикса — см. tests/prompts.php.
 */
class Prompts
{
	/** @return array<string, array> имя => описание сценария */
	public static function all(array $groups): array
	{
		$has = static function (array $need) use ($groups) {
			foreach ($need as $g) { if (!in_array($g, $groups, true)) { return false; } }
			return true;
		};

		$period = [
			['name' => 'from', 'description' => 'Начало периода, ДД.ММ.ГГГГ', 'required' => false],
			['name' => 'to',   'description' => 'Конец периода, ДД.ММ.ГГГГ',  'required' => false],
		];

		$out = [];

		if ($has(['reports'])) {
			$out['sales_summary'] = [
				'title'       => 'Сводка продаж',
				'description' => 'Итоги за период: динамика, средний чек, что продавалось.',
				'arguments'   => $period,
				'text' => "Собери сводку продаж{period}.\n\n"
					. "1. sales_report по дням — динамика, средний чек, доля оплаченных.\n"
					. "2. order_stats за тот же период — итог, отмены, разбивка по статусам"
					. " и покупателям.\n"
					. "3. top_products — что дало выручку.\n\n"
					. "Покажи таблицей: заказы, сумма, средний чек. Назови дни выше и ниже"
					. " обычного и не объясняй причины, которых не видно в данных."
					. " Все числа — только из ответов инструментов.",
			];

			$out['yesterday'] = [
				'title'       => 'Что было вчера',
				'description' => 'Короткая сводка за вчерашний день.',
				'arguments'   => [],
				'text' => "Посмотри вчерашний день: order_stats за вчера, top_products за вчера.\n"
					. "Ответь коротко: сколько заказов и на какую сумму, сколько оплачено,"
					. " сколько отменено, что продавалось. Если отмен больше обычного —"
					. " скажи об этом. Даты бери из site_info: server_time.",
			];

			$out['stock_check'] = [
				'title'       => 'Склад: что лежит и что кончается',
				'description' => 'Неликвиды и позиции, которых скоро не хватит.',
				'arguments'   => [
					['name' => 'days', 'description' => 'За сколько дней смотреть спрос', 'required' => false],
				],
				'text' => "Проверь склад:\n"
					. "1. slow_movers — что лежит без продаж{days} и сколько в этом денег.\n"
					. "2. low_stock — что кончается: остаток против спроса, days_left.\n\n"
					. "Сначала то, что кончается — это упущенные продажи. Потом неликвиды."
					. " По спорным позициям возьми product_get: цены и остатки по складам.",
			];

			$out['search_gaps'] = [
				'title'       => 'Что ищут и не находят',
				'description' => 'Поисковые фразы без результата — спрос без предложения.',
				'arguments'   => [
					['name' => 'days', 'description' => 'За сколько дней', 'required' => false],
				],
				'text' => "Возьми search_phrases с no_results: true{days} — это спрос, на который"
					. " сайт не отвечает.\n"
					. "Сгруппируй похожие фразы по смыслу и покажи, чего людям не хватает."
					. " Проверь через element_search, правда ли такого товара нет: фраза могла"
					. " не найтись из-за формулировки, а не из-за отсутствия товара.",
			];
		}

		if ($has(['orders'])) {
			$out['order_trace'] = [
				'title'       => 'Разобрать заказ',
				'description' => 'Что в заказе, кто покупатель, что с оплатой и статусом.',
				'arguments'   => [
					['name' => 'order', 'description' => 'Номер заказа', 'required' => true],
				],
				'text' => "Разбери заказ {order}: order_get по номеру, затем user_get по"
					. " USER_ID из ответа, а коды статусов сверь по order_statuses.\n"
					. "Скажи: что заказано, на какую сумму, оплачен ли, каким способом идёт"
					. " доставка и что означает текущий статус. Персональные данные покупателя"
					. " приводи только те, без которых ответ теряет смысл.",
			];
		}

		if ($has(['api'])) {
			$out['code_trace'] = [
				'title'       => 'Где это работает',
				'description' => 'Найти, каким кодом сделано поведение сайта.',
				'arguments'   => [
					['name' => 'what', 'description' => 'Что ищем: класс, функция, поведение', 'required' => true],
				],
				'text' => "Найди, где на этом сайте сделано: {what}.\n\n"
					. "Порядок: api_find_class и api_class — есть ли такой класс здесь"
					. " и какие у него методы; api_source — как он написан; api_events"
					. " и api_agents — не навешано ли это обработчиком или агентом;"
					. " file_grep — если инструменты выше ничего не дали.\n"
					. "Отвечай по фактам этой установки, а не по документации Битрикса:"
					. " версии расходятся, и метода из документации здесь может не быть.",
			];
		}

		return $out;
	}

	/** Список для prompts/list. */
	public static function schema(array $groups): array
	{
		$out = [];
		foreach (self::all($groups) as $name => $p) {
			$out[] = [
				'name'        => $name,
				'title'       => $p['title'],
				'description' => $p['description'],
				'arguments'   => $p['arguments'],
			];
		}

		return $out;
	}

	/** Имя недостающего обязательного аргумента, иначе null. */
	public static function missing(string $name, array $args, array $groups): ?string
	{
		$all = self::all($groups);
		if (!isset($all[$name])) { return null; }

		foreach ($all[$name]['arguments'] as $arg) {
			if (empty($arg['required'])) { continue; }
			if (trim((string)($args[$arg['name']] ?? '')) === '') { return (string)$arg['name']; }
		}

		return null;
	}

	/** Готовое сообщение для prompts/get; null — сценария нет или он не выдан. */
	public static function get(string $name, array $args, array $groups): ?array
	{
		$all = self::all($groups);
		if (!isset($all[$name])) { return null; }

		$p = $all[$name];

		return [
			'description' => $p['description'],
			'messages'    => [
				['role' => 'user', 'content' => ['type' => 'text',
					'text' => self::fill($p['text'], $args)]],
			],
		];
	}

	/**
	 * Подстановка в текст сценария. Пустой аргумент исчезает вместе со своим
	 * оборотом: «за период с  по» читается как испорченная постановка.
	 */
	private static function fill(string $text, array $args): string
	{
		$from = trim((string)($args['from'] ?? ''));
		$to   = trim((string)($args['to'] ?? ''));
		$days = (int)($args['days'] ?? 0);

		$period = '';
		if ($from !== '' && $to !== '') { $period = ' за период с ' . $from . ' по ' . $to; }
		elseif ($from !== '')           { $period = ' с ' . $from; }
		elseif ($to !== '')             { $period = ' по ' . $to; }

		$map = [
			'{period}' => $period,
			'{days}'   => $days > 0 ? ' за ' . $days . ' дн.' : '',
			'{order}'  => trim((string)($args['order'] ?? '')),
			'{what}'   => trim((string)($args['what'] ?? '')),
		];

		return strtr($text, $map);
	}
}
