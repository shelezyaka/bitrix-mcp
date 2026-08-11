<?php
namespace Itb\Mcp\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Журнал обращений: кто, когда, каким инструментом и что получил.
 *
 * ⚠️⚠️ Пишется КАЖДЫЙ запрос, включая отвергнутые. Иначе «модуль не отвечает»
 * и «модуль отвечает не тому» выглядят одинаково — а отвергнутые запросы и есть
 * то место, где видно перебор токенов и чужой Origin.
 *
 * ⚠️ Строка на запрос, а не на действие: одна вставка, известный размер таблицы,
 * никакой возможности «залогировать половину».
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
			// 0 — запрос без опознанного токена (отказ авторизации, чужой Origin).
			new Fields\IntegerField('TOKEN_ID', ['default_value' => 0]),
			new Fields\StringField('IP', ['size' => 45]),
			new Fields\StringField('RPC_METHOD', ['size' => 64]),
			new Fields\StringField('TOOL', ['size' => 128]),
			// ⚠️ Аргументы пишем, ответ — НЕТ. По аргументам видно, что спрашивали;
			// ответ это выгрузка каталога, и журнал стал бы второй копией базы.
			new Fields\TextField('ARGS'),
			new Fields\IntegerField('HTTP_STATUS'),
			new Fields\IntegerField('MS'),
			new Fields\IntegerField('SIZE'),
			new Fields\StringField('ERROR', ['size' => 255]),
		];
	}
}
