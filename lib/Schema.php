<?php
namespace Itb\Mcp;

/**
 * Проверка аргументов инструмента по его JSON Schema.
 *
 * ⚠️ Это НЕ полная реализация JSON Schema и не должна ею стать. Здесь проверяется
 * ровно то, что мы сами объявляем в описаниях инструментов: тип, обязательность,
 * перечисление, границы чисел и длины строк, лишние поля. Полный валидатор — это
 * зависимость и своя поверхность ошибок ради возможностей, которыми мы не
 * пользуемся.
 *
 * ⚠️ Молчаливое приведение типов запрещено. Строка «10» вместо числа 10 — это
 * сигнал, что модель поняла схему иначе, чем мы её написали; проглотив его, мы
 * узнаем об этом на данных, а не на входе.
 */
class Schema
{
	/** null — годится; строка — человеческое объяснение, что не так. */
	public static function validate(array $args, array $schema): ?string
	{
		$props = isset($schema['properties']) && is_array($schema['properties'])
			? $schema['properties'] : [];

		foreach ((array)($schema['required'] ?? []) as $name) {
			if (!array_key_exists($name, $args)) {
				return 'не хватает обязательного «' . $name . '»';
			}
		}

		foreach ($args as $name => $val) {
			if (!isset($props[$name])) {
				// ⚠️ Лишний аргумент — это ошибка, а не мусор, который можно отбросить.
				// Чаще всего он означает опечатку в имени: молча проигнорировав его,
				// мы выполним запрос БЕЗ фильтра, о котором просили.
				return 'неизвестный аргумент «' . $name . '»';
			}
			$bad = self::one($name, $val, $props[$name]);
			if ($bad !== null) { return $bad; }
		}

		return null;
	}

	private static function one(string $name, $val, array $rule): ?string
	{
		$type = (string)($rule['type'] ?? '');

		switch ($type) {
			case 'string':
				if (!is_string($val)) { return '«' . $name . '» должен быть строкой'; }
				if (isset($rule['maxLength']) && mb_strlen($val) > (int)$rule['maxLength']) {
					return '«' . $name . '» длиннее ' . (int)$rule['maxLength'] . ' символов';
				}
				break;

			case 'integer':
				// ⚠️ is_int, а не is_numeric: «10» это строка, и приехала она не просто так.
				if (!is_int($val)) { return '«' . $name . '» должен быть целым числом'; }
				break;

			case 'number':
				if (!is_int($val) && !is_float($val)) { return '«' . $name . '» должен быть числом'; }
				break;

			case 'boolean':
				if (!is_bool($val)) { return '«' . $name . '» должен быть true или false'; }
				break;

			case 'array':
				if (!is_array($val) || (!empty($val) && array_keys($val) !== range(0, count($val) - 1))) {
					return '«' . $name . '» должен быть списком';
				}
				if (isset($rule['maxItems']) && count($val) > (int)$rule['maxItems']) {
					return 'в «' . $name . '» больше ' . (int)$rule['maxItems'] . ' элементов';
				}
				if (isset($rule['items']) && is_array($rule['items'])) {
					foreach ($val as $i => $v) {
						$bad = self::one($name . '[' . $i . ']', $v, $rule['items']);
						if ($bad !== null) { return $bad; }
					}
				}
				break;

			case 'object':
				if (!is_array($val)) { return '«' . $name . '» должен быть объектом'; }
				break;
		}

		if (isset($rule['enum']) && is_array($rule['enum']) && !in_array($val, $rule['enum'], true)) {
			return '«' . $name . '» должен быть одним из: ' . implode(', ', array_map('strval', $rule['enum']));
		}
		if (isset($rule['minimum']) && is_numeric($val) && $val < $rule['minimum']) {
			return '«' . $name . '» меньше ' . $rule['minimum'];
		}
		if (isset($rule['maximum']) && is_numeric($val) && $val > $rule['maximum']) {
			return '«' . $name . '» больше ' . $rule['maximum'];
		}

		return null;
	}
}
