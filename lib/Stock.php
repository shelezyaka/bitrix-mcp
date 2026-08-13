<?php
namespace Itb\Mcp;

/**
 * Склад и спрос: что лежит без движения, что кончается, что ищут и не находят.
 * Товары берутся только из открытых инфоблоков.
 */
class Stock
{
	const LIMIT_DEF = 20;
	const LIMIT_MAX = 100;
	const DAYS_DEF  = 90;

	/** Есть остаток, продаж за период нет. */
	public static function slowMovers(array $a): array
	{
		$conn  = self::init();
		$days  = self::days($a, self::DAYS_DEF);
		$limit = self::limit($a);
		$since = self::since($days);
		$ids   = self::iblocks();

		$sql = 'SELECT e.ID, e.NAME, e.IBLOCK_ID, p.QUANTITY, pr.PRICE'
			. ' FROM b_catalog_product p'
			. ' INNER JOIN b_iblock_element e ON e.ID = p.ID'
			. ' LEFT JOIN b_catalog_price pr ON pr.PRODUCT_ID = p.ID AND pr.CATALOG_GROUP_ID = ' . self::basePriceId($conn)
			. ' WHERE p.QUANTITY > 0 AND e.IBLOCK_ID IN (' . implode(',', $ids) . ')'
			. " AND NOT EXISTS (SELECT 1 FROM b_sale_basket b"
			. " INNER JOIN b_sale_order o ON o.ID = b.ORDER_ID"
			. " WHERE b.PRODUCT_ID = p.ID AND o.DATE_INSERT >= '" . $since . "')"
			. ' ORDER BY p.QUANTITY * COALESCE(pr.PRICE, 0) DESC';

		$items = [];
		$frozen = 0.0;
		$rs = $conn->query($sql, $limit);
		while ($r = $rs->fetch()) {
			$value = (float)$r['QUANTITY'] * (float)$r['PRICE'];
			$frozen += $value;
			$items[] = [
				'product_id' => (int)$r['ID'],
				'name'       => (string)$r['NAME'],
				'iblock'     => (int)$r['IBLOCK_ID'],
				'quantity'   => (float)$r['QUANTITY'],
				'price'      => round((float)$r['PRICE'], 2),
				'stock_value' => round($value, 2),
			];
		}

		return [
			'days'  => $days,
			'since' => $since,
			'total' => count($items),
			'items' => $items,
			// Сумма считается по показанным строкам, поэтому и названа так.
			'note'  => 'В этих ' . count($items) . ' позициях лежит ' . round($frozen, 2)
				. ' по базовой цене. Отсортировано по стоимости остатка.',
		];
	}

	/** Продаётся, а остатка мало: на сколько дней хватит при текущем спросе. */
	public static function lowStock(array $a): array
	{
		$conn  = self::init();
		$days  = self::days($a, 30);
		$limit = self::limit($a);
		$since = self::since($days);
		$ids   = self::iblocks();

		$sql = 'SELECT b.PRODUCT_ID, MAX(b.NAME) AS NAME, SUM(b.QUANTITY) AS SOLD,'
			. ' MAX(p.QUANTITY) AS STOCK'
			. ' FROM b_sale_basket b'
			. " INNER JOIN b_sale_order o ON o.ID = b.ORDER_ID AND o.CANCELED = 'N'"
			. " AND o.DATE_INSERT >= '" . $since . "'"
			. ' INNER JOIN b_catalog_product p ON p.ID = b.PRODUCT_ID'
			. ' INNER JOIN b_iblock_element e ON e.ID = p.ID'
			. ' WHERE e.IBLOCK_ID IN (' . implode(',', $ids) . ')'
			. ' GROUP BY b.PRODUCT_ID'
			// Спрос обгоняет остаток.
			. ' HAVING SOLD > 0 AND STOCK < SOLD'
			// По размеру дефицита: при сортировке по доле наверх всплывают нули,
			// проданные по штуке, а ходовой дефицит до списка не доходит.
			. ' ORDER BY SOLD - STOCK DESC';

		$items = [];
		$rs = $conn->query($sql, $limit);
		while ($r = $rs->fetch()) {
			$sold  = (float)$r['SOLD'];
			$stock = (float)$r['STOCK'];
			$items[] = [
				'product_id'   => (int)$r['PRODUCT_ID'],
				'name'         => (string)$r['NAME'],
				'sold'         => $sold,
				'stock'        => $stock,
				'shortage'     => round($sold - $stock, 2),
				'out_of_stock' => $stock <= 0,
				'days_left'    => $sold > 0 ? round($stock / ($sold / $days), 1) : null,
			];
		}

		return [
			'days'  => $days,
			'since' => $since,
			'total' => count($items),
			'items' => $items,
			'note'  => 'Остаток меньше проданного за ' . $days . ' дн. Сверху — наибольший'
				. ' дефицит (shortage). out_of_stock — уже кончилось, спрос при этом есть.'
				. ' days_left — на сколько дней хватит при том же спросе.',
		];
	}

	/** Что искали на сайте. */
	public static function searchPhrases(array $a): array
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('search')) {
			throw new ToolError('Модуль search на этом сайте не установлен');
		}
		$conn = self::conn();

		$days  = self::days($a, 30);
		$limit = self::limit($a);
		$since = self::since($days);
		$empty = !empty($a['no_results']);

		$sql = 'SELECT PHRASE, COUNT(*) AS HITS, MAX(RESULT_COUNT) AS BEST'
			. " FROM b_search_phrase WHERE TIMESTAMP_X >= '" . $since . "'"
			. " AND PHRASE <> ''"
			. ' GROUP BY PHRASE'
			. ($empty ? ' HAVING BEST = 0' : '')
			. ' ORDER BY HITS DESC';

		$items = [];
		$rs = $conn->query($sql, $limit);
		while ($r = $rs->fetch()) {
			$items[] = [
				'phrase'  => (string)$r['PHRASE'],
				'hits'    => (int)$r['HITS'],
				'results' => (int)$r['BEST'],
			];
		}

		return [
			'days'       => $days,
			'since'      => $since,
			'no_results' => $empty,
			'total'      => count($items),
			'items'      => $items,
			'note'       => $empty
				? 'Фразы, по которым поиск ничего не нашёл ни разу: спрос есть, товара нет.'
				: 'results — лучшее число найденного по этой фразе за период.',
		];
	}

	/** Базовый тип цены: по нему считается стоимость остатка. */
	private static function basePriceId($conn): int
	{
		$row = $conn->query("SELECT ID FROM b_catalog_group WHERE BASE = 'Y' ORDER BY ID LIMIT 1")->fetch();

		return (int)($row['ID'] ?? 1);
	}

	private static function iblocks(): array
	{
		$ids = Expose::ids();
		if (!$ids) {
			throw new ToolError('Ни один инфоблок не открыт — товары брать неоткуда.'
				. ' Белый список настраивается в админке.');
		}

		return array_map('intval', $ids);
	}

	/** Склад и продажи: нужны обе части магазина. @return \Bitrix\Main\DB\Connection */
	private static function init()
	{
		if (!\Bitrix\Main\Loader::includeModule('catalog')) {
			throw new ToolError('Модуль catalog на этом сайте не подключён');
		}
		Orders::init();

		return self::conn();
	}

	/** @return \Bitrix\Main\DB\Connection */
	private static function conn()
	{
		$conn = \Bitrix\Main\Application::getConnection();
		// Отчёты идут по боевым таблицам магазина: предел времени обязателен.
		Sql::deadline($conn);

		return $conn;
	}

	private static function days(array $a, int $default): int
	{
		$n = (int)($a['days'] ?? $default);

		return min(max($n > 0 ? $n : $default, 1), 365);
	}

	private static function limit(array $a): int
	{
		$n = (int)($a['limit'] ?? self::LIMIT_DEF);

		return min($n > 0 ? $n : self::LIMIT_DEF, self::LIMIT_MAX);
	}

	/** Дата в формате базы; собирается здесь, а не приходит извне. */
	private static function since(int $days): string
	{
		$d = new \Bitrix\Main\Type\DateTime();
		$d->add('-' . $days . ' days');

		return $d->format('Y-m-d H:i:s');
	}
}
