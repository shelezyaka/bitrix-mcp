<?php
namespace Itb\Mcp;

use Bitrix\Catalog\CatalogIblockTable;
use Bitrix\Catalog\GroupLangTable;
use Bitrix\Catalog\GroupTable;
use Bitrix\Catalog\PriceTable;
use Bitrix\Catalog\ProductTable;
use Bitrix\Catalog\StoreProductTable;
use Bitrix\Catalog\StoreTable;

/**
 * Цены, остатки и товарные параметры (модуль catalog). Только чтение.
 *
 * Цены и остатки живут не в инфоблоке, а в своих таблицах, поэтому element_get
 * их не видит.
 */
class Catalog
{
	const OFFERS_MAX = 50;

	/** Значения b_catalog_product.TYPE. Совпадают с ProductTable::TYPE_*. */
	const TYPES = [
		1 => 'простой товар',
		2 => 'комплект',
		3 => 'товар с торговыми предложениями',
		4 => 'торговое предложение',
		5 => 'предложение без товара',
		6 => 'товар без предложений',
		7 => 'услуга',
	];

	const PRODUCT_FIELDS = ['ID', 'TYPE', 'AVAILABLE', 'QUANTITY', 'QUANTITY_RESERVED',
		'QUANTITY_TRACE', 'CAN_BUY_ZERO', 'MEASURE', 'WEIGHT', 'WIDTH', 'LENGTH', 'HEIGHT',
		'VAT_ID', 'VAT_INCLUDED', 'PURCHASING_PRICE', 'PURCHASING_CURRENCY', 'SUBSCRIBE'];

	public static function product(array $a): array
	{
		$id = (int)($a['id'] ?? 0);
		if ($id <= 0) { throw new ToolError('Не указан id элемента'); }

		self::init();

		$el = \Bitrix\Iblock\ElementTable::getRow([
			'select' => ['ID', 'IBLOCK_ID', 'NAME', 'CODE', 'ACTIVE'],
			'filter' => ['=ID' => $id],
		]);
		if (!$el) { throw new ToolError('Элемент ' . $id . ' не найден'); }

		$iblock = (int)$el['IBLOCK_ID'];
		Data::assertIblock($iblock);

		$out = [
			'id'     => $id,
			'iblock' => $iblock,
			'name'   => (string)$el['NAME'],
			'code'   => (string)$el['CODE'],
			'active' => (string)$el['ACTIVE'],
		];

		$p = ProductTable::getRow(['select' => self::PRODUCT_FIELDS, 'filter' => ['=ID' => $id]]);
		if (!$p) {
			$out['note'] = 'Элемент есть, но товаром не является: ни цен, ни остатков у него нет.';
			return $out;
		}

		$type = (int)$p['TYPE'];
		$out['product'] = [
			'type'              => $type,
			'type_name'         => self::TYPES[$type] ?? 'неизвестный тип',
			'available'         => (string)$p['AVAILABLE'],
			'quantity'          => (float)$p['QUANTITY'],
			'quantity_reserved' => (float)$p['QUANTITY_RESERVED'],
			'quantity_trace'    => (string)$p['QUANTITY_TRACE'],
			'can_buy_zero'      => (string)$p['CAN_BUY_ZERO'],
			'measure'           => (int)$p['MEASURE'] ?: null,
			'weight'            => (float)$p['WEIGHT'],
			'dimensions'        => ['width' => (float)$p['WIDTH'], 'length' => (float)$p['LENGTH'],
				'height' => (float)$p['HEIGHT']],
			'vat_id'            => (int)$p['VAT_ID'] ?: null,
			'vat_included'      => (string)$p['VAT_INCLUDED'],
			'subscribe'         => (string)$p['SUBSCRIBE'],
		];

		// Закупочная цена — коммерческая тайна, а не свойство товара. Отдаём, но
		// отдельным ключом, чтобы её было видно в ответе, а не среди прочих полей.
		if ((float)$p['PURCHASING_PRICE'] > 0) {
			$out['purchasing_price'] = [
				'price'    => (float)$p['PURCHASING_PRICE'],
				'currency' => (string)$p['PURCHASING_CURRENCY'],
				'note'     => 'Закупочная цена.',
			];
		}

		$out['prices'] = self::prices([$id])[$id] ?? [];
		$out['stores'] = self::stocks([$id])[$id] ?? [];

		// Пустой список складов легко прочитать как «товара нет» — поэтому
		// говорим прямо, что остаток в этом случае один и он выше.
		if (!$out['stores']) {
			$out['stores_note'] = 'Остатков по складам у этого товара нет; общий остаток —'
				. ' quantity выше. Ведётся ли складской учёт, покажет store_list.';
		}

		if ($type === 3 || $type === 6) {
			$out['offers'] = self::offers($id, $iblock);
		}

		if (!$out['prices'] && !isset($out['offers'])) {
			$out['note'] = 'Цен у товара нет. Если это товар с предложениями, цены стоят на них.';
		}

		return $out;
	}

	/**
	 * Складской учёт включён? null — версия ядра ответить не умеет.
	 *
	 * Многоскладовость есть не во всех редакциях и выключается настройкой.
	 * Когда её нет, остаток у товара один — b_catalog_product.QUANTITY,
	 * и пустой список складов означает «не ведётся», а не «нет товара».
	 */
	public static function inventory(): ?bool
	{
		$class = '\Bitrix\Catalog\Config\State';
		if (!class_exists($class) || !method_exists($class, 'isUsedInventoryManagement')) {
			return null;
		}

		try {
			return (bool)$class::isUsedInventoryManagement();
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** Склады: без них остатки — набор идентификаторов без смысла. */
	public static function stores(array $a): array
	{
		self::init();

		$out = [];
		$rs = StoreTable::getList([
			'select' => ['ID', 'TITLE', 'ADDRESS', 'ACTIVE', 'PHONE', 'SCHEDULE', 'SITE_ID', 'SORT'],
			'order'  => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($r = $rs->fetch()) {
			$out[] = [
				'id'       => (int)$r['ID'],
				'title'    => (string)$r['TITLE'],
				'address'  => (string)$r['ADDRESS'],
				'active'   => (string)$r['ACTIVE'],
				'phone'    => (string)$r['PHONE'],
				'schedule' => (string)$r['SCHEDULE'],
				'site'     => (string)$r['SITE_ID'],
			];
		}

		$inventory = self::inventory();

		$res = ['total' => count($out), 'inventory_management' => $inventory, 'stores' => $out];
		// Выключенный учёт не означает пустых остатков: склады бывают заведены,
		// а количества по ним пишет обмен с учётной системой.
		if (!$out) {
			$res['note'] = 'Складов нет: остаток товара один — quantity в product_get.';
		} elseif ($inventory === false) {
			$res['note'] = 'Складской документооборот Битрикса выключен, но склады заведены —'
				. ' остатки по ним может писать обмен. Общий остаток товара — quantity'
				. ' в product_get.';
		}

		return $res;
	}

	/**
	 * Торговые предложения товара с ценами и остатками.
	 * Инфоблок предложений — отдельный, и открыт он должен быть отдельно.
	 */
	private static function offers(int $productId, int $iblockId): array
	{
		$link = CatalogIblockTable::getRow([
			'select' => ['IBLOCK_ID', 'SKU_PROPERTY_ID'],
			'filter' => ['=PRODUCT_IBLOCK_ID' => $iblockId],
		]);
		if (!$link) { return ['error' => 'инфоблок торговых предложений не настроен']; }

		$offersIblock = (int)$link['IBLOCK_ID'];
		if (!Expose::allows($offersIblock)) {
			return ['iblock' => $offersIblock,
				'error' => 'инфоблок предложений не открыт для чтения — откройте его в настройках модуля'];
		}

		\Bitrix\Main\Loader::includeModule('iblock');

		$rows = [];
		$rs = \CIBlockElement::GetList(
			['ID' => 'ASC'],
			['IBLOCK_ID' => $offersIblock, 'PROPERTY_' . (int)$link['SKU_PROPERTY_ID'] => $productId],
			false,
			['nTopCount' => self::OFFERS_MAX],
			['ID', 'NAME', 'ACTIVE']
		);
		while ($o = $rs->Fetch()) {
			$rows[(int)$o['ID']] = ['id' => (int)$o['ID'], 'name' => (string)$o['NAME'],
				'active' => (string)$o['ACTIVE']];
		}
		if (!$rows) { return ['iblock' => $offersIblock, 'total' => 0, 'items' => []]; }

		$ids    = array_keys($rows);
		$prices = self::prices($ids);
		$stocks = self::stocks($ids);

		$qty = [];
		$rs = ProductTable::getList([
			'select' => ['ID', 'QUANTITY', 'AVAILABLE'],
			'filter' => ['@ID' => $ids],
		]);
		while ($q = $rs->fetch()) { $qty[(int)$q['ID']] = $q; }

		foreach ($rows as $oid => &$row) {
			$row['quantity']  = isset($qty[$oid]) ? (float)$qty[$oid]['QUANTITY'] : null;
			$row['available'] = isset($qty[$oid]) ? (string)$qty[$oid]['AVAILABLE'] : null;
			$row['prices']    = $prices[$oid] ?? [];
			$row['stores']    = $stocks[$oid] ?? [];
		}
		unset($row);

		$out = ['iblock' => $offersIblock, 'total' => count($rows), 'items' => array_values($rows)];
		if (count($rows) >= self::OFFERS_MAX) {
			$out['note'] = 'Показаны первые ' . self::OFFERS_MAX . ' предложений.';
		}

		return $out;
	}

	/** @return array<int, array[]> id товара => список цен */
	private static function prices(array $ids): array
	{
		$types = self::priceTypes();

		$out = [];
		$rs = PriceTable::getList([
			'select' => ['PRODUCT_ID', 'CATALOG_GROUP_ID', 'PRICE', 'CURRENCY',
				'QUANTITY_FROM', 'QUANTITY_TO'],
			'filter' => ['@PRODUCT_ID' => $ids],
			'order'  => ['PRODUCT_ID' => 'ASC', 'CATALOG_GROUP_ID' => 'ASC'],
		]);
		while ($r = $rs->fetch()) {
			$gid = (int)$r['CATALOG_GROUP_ID'];
			$row = [
				'type_id'  => $gid,
				'type'     => $types[$gid]['title'] ?? ('тип ' . $gid),
				'base'     => !empty($types[$gid]['base']),
				'price'    => (float)$r['PRICE'],
				'currency' => (string)$r['CURRENCY'],
			];
			// Диапазон количества заполнен только при ценах «от N штук».
			if ($r['QUANTITY_FROM'] !== null || $r['QUANTITY_TO'] !== null) {
				$row['quantity_from'] = $r['QUANTITY_FROM'] === null ? null : (int)$r['QUANTITY_FROM'];
				$row['quantity_to']   = $r['QUANTITY_TO'] === null ? null : (int)$r['QUANTITY_TO'];
			}
			$out[(int)$r['PRODUCT_ID']][] = $row;
		}

		return $out;
	}

	/** @return array<int, array[]> id товара => остатки по складам */
	private static function stocks(array $ids): array
	{
		$names = self::storeNames();

		$out = [];
		$rs = StoreProductTable::getList([
			'select' => ['PRODUCT_ID', 'STORE_ID', 'AMOUNT', 'QUANTITY_RESERVED'],
			'filter' => ['@PRODUCT_ID' => $ids],
			'order'  => ['PRODUCT_ID' => 'ASC', 'STORE_ID' => 'ASC'],
		]);
		while ($r = $rs->fetch()) {
			$sid = (int)$r['STORE_ID'];
			$out[(int)$r['PRODUCT_ID']][] = [
				'store_id' => $sid,
				'store'    => $names[$sid] ?? ('склад ' . $sid),
				'amount'   => (float)$r['AMOUNT'],
				'reserved' => (float)$r['QUANTITY_RESERVED'],
			];
		}

		return $out;
	}

	/** Типы цен: код в b_catalog_group, человеческое название — в b_catalog_group_lang. */
	private static function priceTypes(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = GroupTable::getList(['select' => ['ID', 'NAME', 'BASE'], 'order' => ['SORT' => 'ASC']]);
		while ($g = $rs->fetch()) {
			$out[(int)$g['ID']] = ['title' => (string)$g['NAME'], 'base' => (string)$g['BASE'] === 'Y'];
		}

		$rs = GroupLangTable::getList([
			'select' => ['CATALOG_GROUP_ID', 'NAME'],
			'filter' => ['=LANG' => defined('LANGUAGE_ID') ? LANGUAGE_ID : 'ru'],
		]);
		while ($l = $rs->fetch()) {
			$gid = (int)$l['CATALOG_GROUP_ID'];
			if (isset($out[$gid]) && (string)$l['NAME'] !== '') { $out[$gid]['title'] = (string)$l['NAME']; }
		}

		return $cache = $out;
	}

	private static function storeNames(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = StoreTable::getList(['select' => ['ID', 'TITLE']]);
		while ($s = $rs->fetch()) { $out[(int)$s['ID']] = (string)$s['TITLE']; }

		return $cache = $out;
	}

	private static function init(): void
	{
		if (!\Bitrix\Main\Loader::includeModule('catalog')) {
			throw new ToolError('Модуль catalog на этом сайте не подключён');
		}
		\Bitrix\Main\Loader::includeModule('iblock');
	}
}
