<?php
namespace Itb\Mcp;

/**
 * Белый список: какие инфоблоки и свойства модуль вправе отдавать.
 *
 * ⚠️⚠️ Это единственный источник ответа на вопрос «можно ли». Ни один инструмент
 * не принимает id инфоблока «на веру»: не отмечен здесь — не существует. Без
 * такого списка универсальный модуль превращается в «отдай что попросят», а
 * просить будет модель, которой ошиблись в подсказке.
 *
 * ⚠️ Единица выбора — ИНФОБЛОК, а не свойство. Человек отмечает галочкой то, что
 * понимает целиком («Товары», «Торговые предложения»); сужение до конкретных
 * свойств есть, но необязательно. Обратный порядок (перечислить сотню кодов,
 * иначе ничего не работает) настраивать никто не станет, и модуль останется
 * выключенным — то есть безопасным ровно до первого «да отдай уже всё».
 */
class Expose
{
	const OPT = 'iblocks';

	/**
	 * Разбор сохранённой настройки. Чистая функция — гоняется тестом.
	 *
	 * @return array<int, array{props: string[]|null}> id инфоблока => правила
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
			if (is_array($list) && $list) {
				// ⚠️ Коды приводим к верхнему регистру: в Битриксе они такие, а
				// человек в поле пишет как придётся. Иначе «cml2_article» тихо
				// не совпадёт ни с чем, и свойство просто не появится в ответе —
				// без единого сообщения о том, что виновата раскладка.
				//
				// ⚠️ Именно `strtoupper`, а не `mb_strtoupper`: код свойства в
				// Битриксе — латиница, цифры и подчёркивание, многобайтовая
				// функция тут ничего не решает, зато тянет зависимость от
				// mbstring в код, который должен гоняться тестом где угодно.
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

	/**
	 * Разрешённые свойства инфоблока: null — все.
	 *
	 * ⚠️ null и пустой массив снова означают РАЗНОЕ (как у белого списка
	 * инструментов у токена): null — «сужения нет», пустой — «не разрешено ни
	 * одно». Второе получается, если человек ввёл в поле мусор, и молча
	 * превращать это во «всё» нельзя.
	 */
	public static function props(int $iblockId): ?array
	{
		$all = self::all();
		return $all[$iblockId]['props'] ?? null;
	}

	/**
	 * Отбор разрешённых свойств из того, что реально есть в инфоблоке.
	 *
	 * ⚠️ Пересечение, а не доверие списку из настроек: код могли переименовать
	 * или удалить, и тогда в описании инструмента стояло бы свойство, которого
	 * нет. Модель попробует им отфильтровать и получит пустой ответ вместо
	 * объяснения.
	 */
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
