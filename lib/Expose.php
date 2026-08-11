<?php
namespace Itb\Mcp;

/**
 * Белый список: какие инфоблоки и свойства модуль вправе отдавать.
 * Единица выбора — инфоблок; сужение до свойств необязательно.
 */
class Expose
{
	const OPT = 'iblocks';

	/**
	 * Разбор сохранённой настройки — чистая функция, см. tests/expose.php.
	 * @return array<int, array{props: string[]|null}>
	 */
	public static function parse(string $json): array
	{
		$raw = json_decode($json, true);
		if (!is_array($raw)) { return []; }

		$out = [];
		foreach ($raw as $id => $rule) {
			$id = (int)$id;
			if ($id <= 0) { continue; }

			$props = null;
			$list  = is_array($rule) ? ($rule['props'] ?? '') : '';
			if (is_string($list)) {
				$list = array_filter(array_map('trim', preg_split('~[,\s]+~u', $list) ?: []));
			}
			// Коды в Битриксе в верхнем регистре, человек в поле пишет как придётся.
			if (is_array($list) && $list) {
				$props = array_values(array_unique(array_map('strtoupper', $list)));
			}

			$out[$id] = ['props' => $props];
		}

		ksort($out);
		return $out;
	}

	/** @return array<int, array{props: string[]|null}> */
	public static function all(): array
	{
		return self::parse((string)\Bitrix\Main\Config\Option::get('itb.mcp', self::OPT, ''));
	}

	public static function save(array $map): void
	{
		\Bitrix\Main\Config\Option::set('itb.mcp', self::OPT,
			json_encode($map, JSON_UNESCAPED_UNICODE));
	}

	public static function ids(): array
	{
		return array_keys(self::all());
	}

	public static function allows(int $iblockId): bool
	{
		return array_key_exists($iblockId, self::all());
	}

	/** Разрешённые свойства: null — все, пустой список — ни одного. */
	public static function props(int $iblockId): ?array
	{
		$all = self::all();
		return $all[$iblockId]['props'] ?? null;
	}

	/** Пересечение настройки с тем, что реально есть в инфоблоке. */
	public static function filterProps(int $iblockId, array $existing): array
	{
		$allow = self::props($iblockId);
		if ($allow === null) { return $existing; }

		$out = [];
		foreach ($existing as $code => $name) {
			if (in_array(strtoupper((string)$code), $allow, true)) { $out[$code] = $name; }
		}
		return $out;
	}
}
