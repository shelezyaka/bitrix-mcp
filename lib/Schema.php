<?php
namespace Itb\Mcp;

/**
 * Проверка аргументов инструмента по его JSON Schema.
 * Поддерживается только то, что мы сами используем в описаниях.
 */
class Schema
{
	/** null — годится, строка — что не так. */
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
			// Лишний аргумент — обычно опечатка в имени. Проигнорировав его, мы
			// выполнили бы запрос без фильтра, о котором просили.
			if (!isset($props[$name])) { return 'неизвестный аргумент «' . $name . '»'; }

			$bad = self::one($name, $val, $props[$name]);
			if ($bad !== null) { return $bad; }
		}

		return null;
	}

	private static function one(string $name, $val, array $rule): ?string
	{
		switch ((string)($rule['type'] ?? '')) {
			case 'string':
				if (!is_string($val)) { return '«' . $name . '» должен быть строкой'; }
				if (isset($rule['maxLength'])) {
					$len = function_exists('mb_strlen') ? mb_strlen($val) : strlen($val);
					if ($len > (int)$rule['maxLength']) {
						return '«' . $name . '» длиннее ' . (int)$rule['maxLength'] . ' символов';
					}
				}
				break;

			// is_int, а не is_numeric: «10» вместо 10 значит, что схему поняли иначе.
			case 'integer':
				if (!is_int($val)) { return '«' . $name . '» должен быть целым числом'; }
				break;

			case 'number':
				if (!is_int($val) && !is_float($val)) { return '«' . $name . '» должен быть числом'; }
				break;

			case 'boolean':
				if (!is_bool($val)) { return '«' . $name . '» должен быть true или false'; }
				break;

			case 'array':
				if (!is_array($val) || ($val !== [] && array_keys($val) !== range(0, count($val) - 1))) {
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
