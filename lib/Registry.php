<?php
namespace Itb\Mcp;

/**
 * Реестр инструментов: что вообще существует для этого токена.
 *
 * ⚠️⚠️ Главное правило модуля: **чего нет в реестре — того не существует**.
 * Никакого «передай свой фильтр, мы отдадим в GetList»: так утекают цены,
 * персональные данные и таблица пользователей. Реестр собирается из настройки
 * сайта в админке, и белый список — единственный способ туда попасть.
 *
 * ⚠️ Реестр строится ПОД ТОКЕН, а не глобально. У токена свой набор разрешённых
 * инструментов, поэтому `tools/list` двум разным токенам отвечает по-разному, и
 * инструмент, которого токен не видит, он же и не может позвать. Один список на
 * всех означал бы, что запрет держится только на проверке при вызове — то есть
 * на одной строке кода.
 */
class Registry
{
	/** @var Tool[] */
	private $tools = [];
	/** @var string */
	private $instructions = '';

	public function add(Tool $t): self
	{
		$this->tools[$t->name] = $t;
		return $this;
	}

	public function find(string $name): ?Tool
	{
		return $this->tools[$name] ?? null;
	}

	/** Описания всех инструментов — для `tools/list`. */
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

	/**
	 * Текст, который клиент показывает модели один раз при подключении.
	 *
	 * ⚠️ Сюда идёт то, что относится ко ВСЕМУ серверу и чего не выразить в описании
	 * отдельного инструмента: что сайт только читается, что id инфоблоков у каждого
	 * сайта свои и их надо сперва спросить, что цифры — это боевой магазин.
	 */
	public function instructions(): string
	{
		return $this->instructions;
	}

	public function names(): array
	{
		return array_keys($this->tools);
	}
}
