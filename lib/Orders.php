<?php
namespace Itb\Mcp;

/**
 * Чтение заказов (модуль sale). Только чтение.
 *
 * Здесь персональные данные покупателей — имя, телефон, адрес лежат в свойствах
 * заказа. Группа выключена по умолчанию и выдаётся токену отдельно.
 */
class Orders
{
	const LIMIT_MAX = 50;
	const LIMIT_DEF = 20;

	/**
	 * Поля заказа. Набор фиксирован: в b_sale_order под сотню полей, и почти всё
	 * там служебное — блокировки, флаги пересчёта, идентификаторы сотрудников.
	 */
	const FIELDS = ['ID', 'ACCOUNT_NUMBER', 'DATE_INSERT', 'DATE_UPDATE', 'STATUS_ID',
		'PRICE', 'CURRENCY', 'PRICE_DELIVERY', 'SUM_PAID', 'DISCOUNT_VALUE',
		'PAYED', 'DATE_PAYED', 'CANCELED', 'DATE_CANCELED', 'REASON_CANCELED',
		'DEDUCTED', 'MARKED', 'REASON_MARKED', 'USER_ID', 'PERSON_TYPE_ID',
		'PAY_SYSTEM_ID', 'DELIVERY_ID', 'TRACKING_NUMBER', 'USER_DESCRIPTION',
		'COMMENTS', 'XML_ID', 'ID_1C'];

	public static function search(array $a): array
	{
		self::init();

		$filter = [];
		if ((int)($a['id'] ?? 0) > 0)      { $filter['=ID'] = (int)$a['id']; }
		if (($a['account'] ?? '') !== '')  { $filter['=ACCOUNT_NUMBER'] = (string)$a['account']; }
		if (($a['status'] ?? '') !== '')   { $filter['=STATUS_ID'] = (string)$a['status']; }
		if ((int)($a['user'] ?? 0) > 0)    { $filter['=USER_ID'] = (int)$a['user']; }
		foreach (['payed' => 'PAYED', 'canceled' => 'CANCELED'] as $arg => $field) {
			$v = (string)($a[$arg] ?? '');
			if ($v === 'Y' || $v === 'N') { $filter['=' . $field] = $v; }
		}
		if (($a['from'] ?? '') !== '') { $filter['>=DATE_INSERT'] = self::date((string)$a['from'], false); }
		if (($a['to'] ?? '') !== '')   { $filter['<DATE_INSERT']  = self::date((string)$a['to'], true); }

		$limit  = min(max(1, (int)($a['limit'] ?? self::LIMIT_DEF)), self::LIMIT_MAX);
		$offset = max(0, (int)($a['offset'] ?? 0));

		$rs = \Bitrix\Sale\Internals\OrderTable::getList([
			'select'      => self::FIELDS,
			'filter'      => $filter,
			'order'       => ['ID' => 'DESC'],
			'limit'       => $limit,
			'offset'      => $offset,
			'count_total' => true,
		]);
		$total = (int)$rs->getCount();

		$items = [];
		while ($r = $rs->fetch()) { $items[] = self::shape($r); }

		$out = ['total' => $total, 'shown' => count($items), 'offset' => $offset, 'orders' => $items];
		if ($total > $offset + count($items)) {
			$out['more'] = 'Показаны не все: всего ' . $total . '. Следующая порция — offset '
				. ($offset + count($items)) . '.';
		}
		// Состав заказа и данные покупателя в списке не идут: это ещё запрос на
		// каждый заказ, а в списке обычно ищут нужный, а не читают все подряд.
		$out['note'] = 'Товары и данные покупателя — в order_get по конкретному заказу.';

		return $out;
	}

	/** Заказ целиком: поля, состав корзины и свойства (в них покупатель). */
	public static function get(array $a): array
	{
		self::init();

		$id      = (int)($a['id'] ?? 0);
		$account = trim((string)($a['account'] ?? ''));
		if ($id <= 0 && $account === '') { throw new ToolError('Укажите id или account'); }

		$rs = \Bitrix\Sale\Internals\OrderTable::getList([
			'select' => self::FIELDS,
			'filter' => $id > 0 ? ['=ID' => $id] : ['=ACCOUNT_NUMBER' => $account],
			'limit'  => 1,
		]);
		$row = $rs->fetch();
		if (!$row) { throw new ToolError('Заказ не найден'); }

		$order = self::shape($row);
		$oid   = (int)$row['ID'];

		$basket = [];
		$rs = \Bitrix\Sale\Internals\BasketTable::getList([
			'select' => ['ID', 'PRODUCT_ID', 'NAME', 'QUANTITY', 'PRICE', 'BASE_PRICE',
				'DISCOUNT_PRICE', 'CURRENCY', 'WEIGHT', 'PRODUCT_XML_ID', 'DETAIL_PAGE_URL'],
			'filter' => ['=ORDER_ID' => $oid],
			'order'  => ['ID' => 'ASC'],
		]);
		while ($b = $rs->fetch()) { $basket[] = self::shape($b); }
		$order['BASKET'] = $basket;

		// Имя, телефон и адрес покупателя лежат здесь, а не в полях заказа.
		$props = [];
		$rs = \Bitrix\Sale\Internals\OrderPropsValueTable::getList([
			'select' => ['CODE', 'NAME', 'VALUE'],
			'filter' => ['=ORDER_ID' => $oid],
			'order'  => ['ID' => 'ASC'],
		]);
		while ($p = $rs->fetch()) {
			$code = (string)$p['CODE'] !== '' ? (string)$p['CODE'] : 'PROP_' . count($props);
			$props[$code] = ['name' => (string)$p['NAME'], 'value' => $p['VALUE']];
		}
		$order['PROPERTIES'] = $props;

		return $order;
	}

	/** Список статусов заказа с человеческими названиями. */
	public static function statuses(array $a): array
	{
		self::init();

		$out = [];
		$rs = \Bitrix\Sale\Internals\StatusLangTable::getList([
			'select' => ['STATUS_ID', 'NAME', 'DESCRIPTION'],
			'filter' => ['=LID' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'],
			'order'  => ['STATUS_ID' => 'ASC'],
		]);
		while ($r = $rs->fetch()) {
			$out[] = ['id' => (string)$r['STATUS_ID'], 'name' => (string)$r['NAME'],
				'description' => (string)$r['DESCRIPTION']];
		}

		return ['total' => count($out), 'statuses' => $out];
	}

	/**
	 * Сводка за период: считает база, а не модель.
	 *
	 * Сотня заказов в ответе — это сотня строк, которые кто-то должен сложить.
	 * GROUP BY делает это одним запросом и без арифметических ошибок.
	 */
	public static function stats(array $a): array
	{
		self::init();

		$filter = [];
		if (($a['from'] ?? '') !== '') { $filter['>=DATE_INSERT'] = self::date((string)$a['from'], false); }
		if (($a['to'] ?? '') !== '')   { $filter['<DATE_INSERT']  = self::date((string)$a['to'], true); }
		if (($a['status'] ?? '') !== '') { $filter['=STATUS_ID'] = (string)$a['status']; }

		// ⚠ Имя вычисляемого поля не должно совпадать с полем таблицы. Запрос
		// работает на КЛОНЕ сущности, а клон перезаписывает поле молча, без
		// предупреждения (Entity::appendField, ветка isClone). Поле SUM_PAID,
		// названное так же, подменило бы собой колонку, из которой само и
		// считается. Отсюда PAID_TOTAL.
		$sum = static function (string $name, string $expr, array $from = []) {
			return new \Bitrix\Main\ORM\Fields\ExpressionField($name, $expr, $from ?: null);
		};

		$total = \Bitrix\Sale\Internals\OrderTable::getRow([
			'select'  => ['CNT', 'SUM_PRICE', 'PAID_TOTAL', 'CNT_PAID', 'CNT_CANCELED', 'SUM_CANCELED'],
			'filter'  => $filter,
			'runtime' => [
				$sum('CNT', 'COUNT(1)'),
				$sum('SUM_PRICE', 'SUM(%s)', ['PRICE']),
				$sum('PAID_TOTAL', 'SUM(%s)', ['SUM_PAID']),
				$sum('CNT_PAID', "SUM(CASE WHEN %s = 'Y' THEN 1 ELSE 0 END)", ['PAYED']),
				$sum('CNT_CANCELED', "SUM(CASE WHEN %s = 'Y' THEN 1 ELSE 0 END)", ['CANCELED']),
				$sum('SUM_CANCELED', "SUM(CASE WHEN %s = 'Y' THEN %s ELSE 0 END)", ['CANCELED', 'PRICE']),
			],
		]);

		$count    = (int)($total['CNT'] ?? 0);
		$price    = round((float)($total['SUM_PRICE'] ?? 0), 2);
		$canceled = round((float)($total['SUM_CANCELED'] ?? 0), 2);

		$names = [];
		foreach (self::statuses([])['statuses'] as $s) { $names[$s['id']] = $s['name']; }

		$byStatus = [];
		$rs = \Bitrix\Sale\Internals\OrderTable::getList([
			'select'  => ['STATUS_ID', 'CNT', 'SUM_PRICE'],
			'filter'  => $filter,
			'runtime' => [$sum('CNT', 'COUNT(1)'), $sum('SUM_PRICE', 'SUM(%s)', ['PRICE'])],
			'order'   => ['CNT' => 'DESC'],
		]);
		while ($r = $rs->fetch()) {
			$sid = (string)$r['STATUS_ID'];
			$byStatus[] = ['id' => $sid, 'name' => $names[$sid] ?? $sid,
				'orders' => (int)$r['CNT'], 'sum' => round((float)$r['SUM_PRICE'], 2)];
		}

		$byUser = [];
		$rs = \Bitrix\Sale\Internals\OrderTable::getList([
			'select'  => ['USER_ID', 'CNT', 'SUM_PRICE'],
			'filter'  => $filter,
			'runtime' => [$sum('CNT', 'COUNT(1)'), $sum('SUM_PRICE', 'SUM(%s)', ['PRICE'])],
			'order'   => ['CNT' => 'DESC'],
			'limit'   => 10,
		]);
		while ($r = $rs->fetch()) {
			$byUser[] = ['user_id' => (int)$r['USER_ID'], 'orders' => (int)$r['CNT'],
				'sum' => round((float)$r['SUM_PRICE'], 2)];
		}

		return [
			'from'  => (string)($a['from'] ?? 'без нижней границы'),
			'to'    => (string)($a['to'] ?? 'без верхней границы'),
			'total' => [
				'orders'           => $count,
				'sum'              => $price,
				'paid_sum'         => round((float)($total['PAID_TOTAL'] ?? 0), 2),
				'paid_orders'      => (int)($total['CNT_PAID'] ?? 0),
				'canceled_orders'  => (int)($total['CNT_CANCELED'] ?? 0),
				'canceled_sum'     => $canceled,
				'sum_without_canceled' => round($price - $canceled, 2),
			],
			'by_status' => $byStatus,
			'by_user'   => $byUser,
			'note' => 'by_user — десять покупателей с наибольшим числом заказов. Кто это,'
				. ' покажет user_get: у обменов с маркетплейсами заказы висят на роботе.',
		];
	}

	private static function init(): void
	{
		if (!\Bitrix\Main\Loader::includeModule('sale')) {
			throw new ToolError('Модуль sale на этом сайте не подключён');
		}
	}

	/**
	 * Дата из «ДД.ММ.ГГГГ». Правило то же, что у срока действия токена:
	 * checkdate обязателен, mktime сам диапазоны не проверяет.
	 *
	 * $next — сдвинуть на сутки вперёд: «по такое-то число» человек понимает
	 * включительно, а сравнение идёт строгим «меньше».
	 */
	private static function date(string $raw, bool $next): \Bitrix\Main\Type\DateTime
	{
		$raw = trim($raw);
		if (!preg_match('~^(\d{2})\.(\d{2})\.(\d{4})$~', $raw, $m)
			|| !checkdate((int)$m[2], (int)$m[1], (int)$m[3])) {
			throw new ToolError('Дата «' . $raw . '» не разбирается. Ожидается ДД.ММ.ГГГГ.');
		}

		$ts = mktime(0, 0, 0, (int)$m[2], (int)$m[1] + ($next ? 1 : 0), (int)$m[3]);

		return \Bitrix\Main\Type\DateTime::createFromTimestamp($ts);
	}

	/** Даты — строками, булево — Y/N: тот же вид, что у остальных инструментов. */
	private static function shape(array $r): array
	{
		$out = [];
		foreach ($r as $k => $v) {
			if ($v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date) {
				$out[$k] = $v->format('d.m.Y H:i:s');
			} elseif (is_bool($v)) {
				$out[$k] = $v ? 'Y' : 'N';
			} else {
				$out[$k] = $v;
			}
		}
		return $out;
	}
}
