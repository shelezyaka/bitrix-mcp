<?php
namespace Itb\Mcp;

/**
 * Набор инструментов, доступных конкретному токену.
 * Чего здесь нет — того для клиента не существует.
 */
class Registry
{
	/** @var Tool[] */
	private $tools = [];
	private $instructions = '';
	/** @var string[] группы, выданные токену — по ним отбираются сценарии */
	private $groups = [];

	public function add(Tool $t): self
	{
		$this->tools[$t->name] = $t;
		return $this;
	}

	public function find(string $name): ?Tool
	{
		return $this->tools[$name] ?? null;
	}

	public function schema(): array
	{
		$out = [];
		foreach ($this->tools as $t) { $out[] = $t->schema(); }
		return $out;
	}

	public function setInstructions(string $s): self
	{
		$this->instructions = $s;
		return $this;
	}

	/** Текст, который клиент показывает модели один раз при подключении. */
	public function instructions(): string
	{
		return $this->instructions;
	}

	public function names(): array
	{
		return array_keys($this->tools);
	}

	public function setGroups(array $groups): self
	{
		$this->groups = $groups;
		return $this;
	}

	/** @return string[] */
	public function groups(): array
	{
		return $this->groups;
	}
}
