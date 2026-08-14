<?php
namespace Itb\Mcp;

/**
 * Сайты установки. На мультисайте заказы, корзины и поисковые фразы принадлежат
 * разным сайтам, и сводить их в одно число молча нельзя.
 */
class Sites
{
	/** @return array<string, array> код сайта => данные */
	public static function all(): array
	{
		static $cache = null;
		if ($cache !== null) { return $cache; }

		$out = [];
		$rs = \Bitrix\Main\SiteTable::getList([
			'select' => ['LID', 'NAME', 'ACTIVE', 'DIR', 'SERVER_NAME', 'DEF'],
			'order'  => ['SORT' => 'ASC'],
		]);
		while ($r = $rs->fetch()) {
			$out[(string)$r['LID']] = [
				'id'      => (string)$r['LID'],
				'name'    => (string)$r['NAME'],
				'active'  => (string)$r['ACTIVE'],
				'dir'     => (string)$r['DIR'],
				'host'    => (string)$r['SERVER_NAME'],
				'default' => (string)$r['DEF'] === 'Y',
			];
		}

		return $cache = $out;
	}

	public static function many(): bool
	{
		return count(self::all()) > 1;
	}

	/**
	 * Проверенный код сайта либо null, если отбора нет.
	 * Значение сверяется со списком, а не экранируется: в запрос уходит только
	 * то, что уже есть в базе.
	 */
	public static function check($raw): ?string
	{
		$id = trim((string)$raw);
		if ($id === '') { return null; }

		$all = self::all();
		if (!isset($all[$id])) {
			throw new ToolError('Сайт «' . $id . '» не найден. Есть: '
				. implode(', ', array_keys($all)) . '.');
		}

		return $id;
	}

	/** Пометка о том, что данные сводные — только когда сайтов правда несколько. */
	public static function note(?string $site): ?string
	{
		if ($site !== null || !self::many()) { return null; }

		return 'Данные по всем сайтам установки (' . implode(', ', array_keys(self::all()))
			. '). Нужен один — параметр site.';
	}
}
