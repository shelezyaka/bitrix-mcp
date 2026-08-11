<?php
namespace Itb\Mcp;

/**
 * Инструмент отработал, но данных нет: товар не найден, инфоблок закрыт.
 * Уходит модели как результат с isError, а не как сбой сервера.
 */
class ToolError extends \Exception
{
}
