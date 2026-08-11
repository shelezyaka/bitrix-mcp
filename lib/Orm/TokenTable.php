<?php
namespace Itb\Mcp\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Выпущенные токены. Самого токена в таблице нет — только его sha256.
 * HINT — последние 6 символов, чтобы различать токены в списке.
 */
class TokenTable extends DataManager
{
	public static function getTableName()
	{
		return 'itb_mcp_token';
	}

	public static function getMap()
	{
		return [
			new Fields\IntegerField('ID', ['primary' => true, 'autocomplete' => true]),
			new Fields\StringField('TITLE', ['size' => 255]),
			new Fields\StringField('TOKEN_HASH', ['size' => 64, 'required' => true]),
			new Fields\StringField('HINT', ['size' => 16]),
			new Fields\IntegerField('USER_ID'),
			// Пусто — все инструменты, разрешённые настройкой сайта.
			new Fields\TextField('TOOLS'),
			new Fields\BooleanField('ACTIVE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
			// NULL здесь означает «бессрочно» и «ещё не звали» — состояния нужные.
			new Fields\DatetimeField('EXPIRES_AT', ['nullable' => true]),
			new Fields\DatetimeField('CREATED_AT'),
			new Fields\IntegerField('CREATED_BY'),
			new Fields\DatetimeField('LAST_USED_AT', ['nullable' => true]),
			new Fields\IntegerField('USE_COUNT', ['default_value' => 0]),
		];
	}
}
