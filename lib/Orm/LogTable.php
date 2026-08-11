<?php
namespace Itb\Mcp\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Журнал обращений. Пишутся аргументы вызова, но не ответ:
 * иначе журнал стал бы второй копией каталога.
 */
class LogTable extends DataManager
{
	public static function getTableName()
	{
		return 'itb_mcp_log';
	}

	public static function getMap()
	{
		return [
			new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Fields\DatetimeField('CREATED_AT'),
			// 0 — запрос без опознанного токена.
			new Fields\IntegerField('TOKEN_ID', ['default_value' => 0]),
			new Fields\StringField('IP', ['size' => 45]),
			new Fields\StringField('RPC_METHOD', ['size' => 64]),
			new Fields\StringField('TOOL', ['size' => 128]),
			new Fields\TextField('ARGS'),
			new Fields\IntegerField('HTTP_STATUS'),
			new Fields\IntegerField('MS'),
			new Fields\IntegerField('SIZE'),
			new Fields\StringField('ERROR', ['size' => 255]),
		];
	}
}
