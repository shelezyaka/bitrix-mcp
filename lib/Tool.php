<?php
namespace Itb\Mcp;

/**
 * Один инструмент MCP.
 * description читает модель — по нему она решает, звать инструмент или нет.
 */
class Tool
{
	public $name;
	public $title;
	public $description;
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

	public function schema(): array
	{
		$s = $this->inputSchema;
		// Пустой properties должен стать «{}», а не «[]»: иначе клиент со строгой
		// проверкой схемы откажется от инструмента.
		if (empty($s['properties'])) { $s['properties'] = new \stdClass(); }
		if (!isset($s['type'])) { $s['type'] = 'object'; }

		return [
			'name'        => $this->name,
			'title'       => $this->title,
			'description' => $this->description,
			'inputSchema' => $s,
			'annotations' => [
				'readOnlyHint'    => true,
				'destructiveHint' => false,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			],
		];
	}
}
