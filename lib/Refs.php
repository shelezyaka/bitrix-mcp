<?php
namespace Itb\Mcp;

/**
 * Справочники магазина: доставки, платёжные системы, типы плательщиков, скидки.
 * Карты «id → название» читаются один раз на запрос — в заказе их нужно много.
 */
class Refs
{
	const DISCOUNTS_MAX = 100;

	/** @return array<int, string> */
	public static function deliveries(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = \Bitrix\Sale\Delivery\Services\Table::getList([
			'select' => ['ID', 'NAME'],
		]);
		while ($r = $rs->fetch()) { $out[(int)$r['ID']] = (string)$r['NAME']; }

		return $cache = $out;
	}

	/** @return array<int, string> */
	public static function paySystems(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = \Bitrix\Sale\Internals\PaySystemActionTable::getList([
			'select' => ['ID', 'NAME', 'PSA_NAME'],
		]);
		while ($r = $rs->fetch()) {
			// PSA_NAME — название для конкретного типа плательщика, оно короче
			// и понятнее полного NAME, где часто дописаны условия акции.
			$name = trim((string)$r['PSA_NAME']) !== '' ? $r['PSA_NAME'] : $r['NAME'];
			$out[(int)$r['ID']] = (string)$name;
		}

		return $cache = $out;
	}

	/** @return array<int, string> */
	public static function personTypes(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = \Bitrix\Sale\Internals\PersonTypeTable::getList(['select' => ['ID', 'NAME']]);
		while ($r = $rs->fetch()) { $out[(int)$r['ID']] = (string)$r['NAME']; }

		return $cache = $out;
	}

	/** Все три справочника разом: заказ ссылается на них одними идентификаторами. */
	public static function directories(array $a): array
	{
		Orders::init();

		$shape = static function (array $map) {
			$out = [];
			foreach ($map as $id => $name) { $out[] = ['id' => $id, 'name' => $name]; }
			return $out;
		};

		return [
			'deliveries'   => $shape(self::deliveries()),
			'pay_systems'  => $shape(self::paySystems()),
			'person_types' => $shape(self::personTypes()),
			'note' => 'Это расшифровка полей DELIVERY_ID, PAY_SYSTEM_ID и PERSON_TYPE_ID'
				. ' в заказах. Коды статусов — в order_statuses.',
		];
	}

	/** Правила скидок. */
	public static function discounts(array $a): array
	{
		Orders::init();

		$filter = [];
		$active = (string)($a['active'] ?? 'Y');
		if ($active === 'Y' || $active === 'N') { $filter['=ACTIVE'] = $active; }

		$items = [];
		$rs = \Bitrix\Sale\Internals\DiscountTable::getList([
			// SHORT_DESCRIPTION собирается из служебной структуры и наружу приходит
			// не текстом, поэтому его здесь нет.
			'select' => ['ID', 'NAME', 'ACTIVE', 'ACTIVE_FROM', 'ACTIVE_TO', 'SORT', 'PRIORITY',
				'USE_COUPONS', 'DISCOUNT_VALUE', 'DISCOUNT_TYPE', 'CURRENCY'],
			'filter' => $filter,
			'order'  => ['PRIORITY' => 'DESC', 'SORT' => 'ASC'],
			'limit'  => self::DISCOUNTS_MAX,
		]);
		while ($r = $rs->fetch()) {
			$items[] = [
				'id'          => (int)$r['ID'],
				'name'        => (string)$r['NAME'],
				'active'      => (string)$r['ACTIVE'],
				'from'        => self::date($r['ACTIVE_FROM']),
				'to'          => self::date($r['ACTIVE_TO']),
				'priority'    => (int)$r['PRIORITY'],
				'use_coupons' => (string)$r['USE_COUPONS'],
				'value'       => $r['DISCOUNT_VALUE'] === null ? null : (float)$r['DISCOUNT_VALUE'],
				'type'        => (string)$r['DISCOUNT_TYPE'],
				'currency'    => (string)$r['CURRENCY'],
			];
		}

		return [
			'total' => count($items),
			'items' => $items,
			// Условия и действия правила хранятся сериализованными структурами:
			// читать их как текст бессмысленно, поэтому их здесь нет.
			'note'  => 'Условия применения правил не отдаются — они хранятся в служебном'
				. ' виде. Какой купон сработал в заказе, видно в order_get.',
		];
	}

	/** Купоны, применённые к заказу. */
	public static function coupons(int $orderId): array
	{
		$out = [];
		$conn = \Bitrix\Main\Application::getConnection();
		$rs = $conn->query('SELECT COUPON, TYPE, ORDER_DISCOUNT_ID FROM b_sale_order_coupons'
			. ' WHERE ORDER_ID = ' . $orderId, 20);
		while ($r = $rs->fetch()) {
			$out[] = ['coupon' => (string)$r['COUPON'], 'type' => (int)$r['TYPE'],
				'discount_id' => (int)$r['ORDER_DISCOUNT_ID']];
		}

		return $out;
	}

	private static function date($v): ?string
	{
		if ($v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date) {
			return $v->format('d.m.Y H:i:s');
		}

		return $v === null || $v === '' ? null : (string)$v;
	}
}
