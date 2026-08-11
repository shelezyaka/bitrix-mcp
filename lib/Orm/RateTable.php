<?php
namespace Itb\Mcp\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Счётчик обращений по IP. Одна строка на адрес, окно перезапускается по времени.
 *
 * Адрес — первичный ключ: под наплывом это обращение по ключу, а не поиск.
 */
class RateTable extends DataManager
{
	public static function getTableName()
	{
		return 'itb_mcp_rate';
	}

	public static function getMap()
	{
		return [
			new Fields\StringField('IP', ['primary' => true, 'size' => 45]),
			// Начало текущего окна, метка времени.
			new Fields\IntegerField('WINDOW_TS', ['default_value' => 0]),
			new Fields\IntegerField('HITS', ['default_value' => 0]),
		];
	}
}
