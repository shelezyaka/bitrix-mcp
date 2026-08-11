<?php
namespace Itb\Mcp;

/**
 * Один инструмент MCP.
 *
 * ⚠️ `description` — не украшение и не документация для человека. Это ЕДИНСТВЕННОЕ,
 * по чему модель решает, звать инструмент или нет, и с какими аргументами. У
 * универсального модуля описание собирается из настройки сайта: «Поиск по инфоблоку
 * 18 „Товары“ (свойства: артикул, металл, вставки)». Без имён инфоблока и свойств
 * модель видит безымянные числа и не понимает, на что смотрит.
 */
class Tool
{
	/** @var string Имя для вызова: латиница, цифры, подчёркивание. */
	public $name;
	/** @var string Человеческое название для интерфейса клиента. */
	public $title;
	/** @var string Что делает и когда звать — читает модель. */
	public $description;
	/** @var array JSON Schema аргументов. */
	public $inputSchema;
	/** @var callable fn(array $args): array|string */
	public $handler;

	public function __construct(string $name, string $title, string $description,
		array $inputSchema, callable $handler)
	{
		$this->name        = $name;
		$this->title       = $title;
		$this->description = $description;
		$this->inputSchema = $inputSchema;
		$this->handler     = $handler;
	}

	/** Описание в том виде, в каком его ждёт `tools/list`. */
	public function schema(): array
	{
		$s = $this->inputSchema;
		// ⚠️ Пустой `properties` обязан быть ОБЪЕКТОМ, а не массивом: json_encode
		// превратит пустой php-массив в `[]`, и клиент со строгой проверкой схемы
		// откажется от инструмента целиком.
		if (empty($s['properties'])) { $s['properties'] = new \stdClass(); }
		if (!isset($s['type'])) { $s['type'] = 'object'; }

		return [
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'inputSchema' => $s,
			// ⚠️ Говорим прямо, что инструмент не портит данные и безопасен для
			// повтора. Клиенты по этим пометкам решают, спрашивать ли человека.
			// Врать здесь нельзя — модуль потому и сделан только читающим.
			'annotations' => [
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			],
		];
	}
}
