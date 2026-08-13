<?php
namespace Itb\Mcp;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Sale\Internals\BasketTable;
use Bitrix\Sale\Internals\OrderTable;

/**
 * Отчёты по продажам: динамика, товары, брошенные корзины.
 * Наружу уходят только итоги, персональных данных нет — отсюда отдельная группа.
 */
class Sales
{
	const PERIODS_MAX = 400;
	const TOP_DEF     = 20;
	const TOP_MAX     = 100;

	/** Шаг динамики. %% — экранированный процент: выражение идёт через sprintf. */
	const STEPS = [
		'day'   => 'DATE(%s)',
		'week'  => "DATE_FORMAT(%s, '%%x-%%v')",
		'month' => "DATE_FORMAT(%s, '%%Y-%%m')",
	];

	/** Динамика заказов по дням, неделям или месяцам. */
	public static function report(array $a): array
	{
		Orders::init();

		$by = (string)($a['by'] ?? 'day');
		if (!isset(self::STEPS[$by])) { $by = 'day'; }

		$filter = self::period($a, 'DATE_INSERT');
		// Отменённые — не продажи; сколько их было, показывает order_stats.
		if (empty($a['with_canceled'])) { $filter['=CANCELED'] = 'N'; }

		$rows = [];
		$rs = OrderTable::getList([
			'select'  => ['PERIOD', 'CNT', 'SUM_PRICE', 'PAID_CNT', 'PAID_SUM'],
			'filter'  => $filter,
			'runtime' => [
				new ExpressionField('PERIOD', self::STEPS[$by], ['DATE_INSERT']),
				new ExpressionField('CNT', 'COUNT(1)'),
				new ExpressionField('SUM_PRICE', 'SUM(%s)', ['PRICE']),
				new ExpressionField('PAID_CNT', "SUM(CASE WHEN %s = 'Y' THEN 1 ELSE 0 END)", ['PAYED']),
				new ExpressionField('PAID_SUM', "SUM(CASE WHEN %s = 'Y' THEN %s ELSE 0 END)",
					['PAYED', 'PRICE']),
			],
			'group'   => ['PERIOD'],
			'order'   => ['PERIOD' => 'ASC'],
			'limit'   => self::PERIODS_MAX,
		]);

		while ($r = $rs->fetch()) {
			$cnt = (int)$r['CNT'];
			$val = (float)$r['SUM_PRICE'];

			// DATE() возвращается объектом даты и приводится к формату сайта.
			// Ярлыки периодов держим в одном виде: 2026-08-06, 2026-32, 2026-08.
			$period = $r['PERIOD'];
			if ($period instanceof \Bitrix\Main\Type\Date
				|| $period instanceof \Bitrix\Main\Type\DateTime) {
				$period = $period->format('Y-m-d');
			}

			$rows[] = [
				'period'      => (string)$period,
				'orders'      => $cnt,
				'sum'         => round($val, 2),
				'avg_check'   => $cnt > 0 ? round($val / $cnt, 2) : 0,
				'paid_orders' => (int)$r['PAID_CNT'],
				'paid_sum'    => round((float)$r['PAID_SUM'], 2),
			];
		}

		// ⚠ Итог считается отдельным запросом по всему периоду, а не сложением
		// строк: при обрезке списка сумма строк — это итог куска, и выдавать её
		// за общий значит ошибиться в разы, ничем этого не показав.
		$sums = OrderTable::getRow([
			'select'  => ['CNT', 'SUM_PRICE'],
			'filter'  => $filter,
			'runtime' => [
				new ExpressionField('CNT', 'COUNT(1)'),
				new ExpressionField('SUM_PRICE', 'SUM(%s)', ['PRICE']),
			],
		]);
		$orders = (int)($sums['CNT'] ?? 0);
		$sum    = (float)($sums['SUM_PRICE'] ?? 0);

		$out = [
			'step'   => $by,
			'from'   => (string)($a['from'] ?? 'без нижней границы'),
			'to'     => (string)($a['to'] ?? 'без верхней границы'),
			'total'  => ['orders' => $orders, 'sum' => round($sum, 2),
				'avg_check' => $orders > 0 ? round($sum / $orders, 2) : 0],
			'periods' => $rows,
		];
		if (count($rows) >= self::PERIODS_MAX) {
			$out['note'] = 'Периодов больше предела: показаны первые ' . self::PERIODS_MAX
				. ', по ' . end($rows)['period'] . ' — конец диапазона в список не попал.'
				. ' Итог в total посчитан за весь период. Укрупните шаг или сузьте даты.';
		}
		if (empty($a['with_canceled'])) {
			$out['note_canceled'] = 'Отменённые заказы не учтены. Нужны — with_canceled: true.';
		}

		return $out;
	}

	/** Что продаётся: позиции корзин заказов за период. */
	public static function topProducts(array $a): array
	{
		Orders::init();

		$limit = (int)($a['limit'] ?? self::TOP_DEF);
		$limit = min($limit > 0 ? $limit : self::TOP_DEF, self::TOP_MAX);
		$sort  = (string)($a['sort'] ?? 'revenue') === 'quantity' ? 'QTY' : 'REVENUE';

		// По дате заказа, а не строки корзины: собрать могли вчера, оформить сегодня.
		$filter = self::period($a, 'ORDER.DATE_INSERT');
		$filter['!=ORDER_ID'] = null;
		if (empty($a['with_canceled'])) { $filter['=ORDER.CANCELED'] = 'N'; }

		$rows = [];
		$rs = BasketTable::getList([
			'select'  => ['PRODUCT_ID', 'NAME_ANY', 'URL_ANY', 'QTY', 'REVENUE', 'ORDERS'],
			'filter'  => $filter,
			'runtime' => [
				// MAX: название и ссылка могли меняться, группировка идёт по ID.
				new ExpressionField('NAME_ANY', 'MAX(%s)', ['NAME']),
				new ExpressionField('URL_ANY', 'MAX(%s)', ['DETAIL_PAGE_URL']),
				new ExpressionField('QTY', 'SUM(%s)', ['QUANTITY']),
				new ExpressionField('REVENUE', 'SUM(%s * %s)', ['PRICE', 'QUANTITY']),
				new ExpressionField('ORDERS', 'COUNT(DISTINCT %s)', ['ORDER_ID']),
			],
			'group'   => ['PRODUCT_ID'],
			'order'   => [$sort => 'DESC'],
			'limit'   => $limit,
		]);
		while ($r = $rs->fetch()) {
			$rows[] = [
				'product_id' => (int)$r['PRODUCT_ID'],
				'name'       => (string)$r['NAME_ANY'],
				'url'        => (string)$r['URL_ANY'],
				'quantity'   => (float)$r['QTY'],
				'revenue'    => round((float)$r['REVENUE'], 2),
				'orders'     => (int)$r['ORDERS'],
			];
		}

		return [
			'from'  => (string)($a['from'] ?? 'без нижней границы'),
			'to'    => (string)($a['to'] ?? 'без верхней границы'),
			'sort'  => $sort === 'QTY' ? 'quantity' : 'revenue',
			'total' => count($rows),
			'items' => $rows,
			'note'  => 'Выручка считается по цене в корзине, то есть с учётом скидок.'
				. ' Цены и остатки товара покажет product_get.',
		];
	}

	/** Корзины, не ставшие заказами. */
	public static function abandoned(array $a): array
	{
		Orders::init();

		$limit = (int)($a['limit'] ?? self::TOP_DEF);
		$limit = min($limit > 0 ? $limit : self::TOP_DEF, self::TOP_MAX);

		// DELAY — список желаний, CAN_BUY = N — недоступный к покупке остаток.
		$filter = self::period($a, 'DATE_INSERT') + [
			'=ORDER_ID' => null,
			'=DELAY'    => 'N',
			'=CAN_BUY'  => 'Y',
		];

		$total = BasketTable::getRow([
			'select'  => ['LINES', 'CARTS', 'SUM_PRICE'],
			'filter'  => $filter,
			'runtime' => [
				new ExpressionField('LINES', 'COUNT(1)'),
				new ExpressionField('CARTS', 'COUNT(DISTINCT %s)', ['FUSER_ID']),
				new ExpressionField('SUM_PRICE', 'SUM(%s * %s)', ['PRICE', 'QUANTITY']),
			],
		]);

		$rows = [];
		$rs = BasketTable::getList([
			'select'  => ['PRODUCT_ID', 'NAME_ANY', 'QTY', 'SUM_PRICE', 'CARTS'],
			'filter'  => $filter,
			'runtime' => [
				new ExpressionField('NAME_ANY', 'MAX(%s)', ['NAME']),
				new ExpressionField('QTY', 'SUM(%s)', ['QUANTITY']),
				new ExpressionField('SUM_PRICE', 'SUM(%s * %s)', ['PRICE', 'QUANTITY']),
				new ExpressionField('CARTS', 'COUNT(DISTINCT %s)', ['FUSER_ID']),
			],
			'group'   => ['PRODUCT_ID'],
			'order'   => ['CARTS' => 'DESC'],
			'limit'   => $limit,
		]);
		while ($r = $rs->fetch()) {
			$rows[] = [
				'product_id' => (int)$r['PRODUCT_ID'],
				'name'       => (string)$r['NAME_ANY'],
				'quantity'   => (float)$r['QTY'],
				'sum'        => round((float)$r['SUM_PRICE'], 2),
				'carts'      => (int)$r['CARTS'],
			];
		}

		return [
			'from'  => (string)($a['from'] ?? 'без нижней границы'),
			'to'    => (string)($a['to'] ?? 'без верхней границы'),
			'total' => [
				'carts' => (int)($total['CARTS'] ?? 0),
				'lines' => (int)($total['LINES'] ?? 0),
				'sum'   => round((float)($total['SUM_PRICE'] ?? 0), 2),
			],
			'items' => $rows,
			'note'  => 'Корзина считается брошенной, если она не привязана к заказу.'
				. ' Отложенное и недоступное к покупке не в счёт. Живые корзины'
				. ' покупателей, которые ещё оформляются, тоже попадают сюда.',
		];
	}

	/** Границы периода одним правилом для всех отчётов. */
	private static function period(array $a, string $field): array
	{
		$filter = [];
		if (($a['from'] ?? '') !== '') { $filter['>=' . $field] = Orders::date((string)$a['from'], false); }
		if (($a['to'] ?? '') !== '')   { $filter['<' . $field]  = Orders::date((string)$a['to'], true); }

		return $filter;
	}
}
