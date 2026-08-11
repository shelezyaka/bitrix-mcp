<?php
namespace Itb\Mcp\Orm;

use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields;

/**
 * Выпущенные токены доступа.
 *
 * ⚠️⚠️ Самого токена в таблице НЕТ — только его sha256. Показать значение
 * повторно нельзя ни владельцу сайта, ни нам: потерял — выпусти новый, старый
 * отзови. Это не строгость ради строгости: дамп базы уходит в бэкапы, в отчёты
 * об ошибках и к подрядчикам, а токен — это вход в магазин.
 *
 * ⚠️ `HINT` — последние 6 символов, чтобы отличать токены в списке. Шесть
 * символов из 43 не помогают подобрать остальное, но позволяют человеку понять,
 * какой из трёх токенов он сейчас отзывает.
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
			// Чей это доступ. Пока справочно — модуль читает мимо прав пользователя,
			// потому что состав данных определяет белый список, а не роль.
			new Fields\IntegerField('USER_ID'),
			// ⚠️ Пусто = «все инструменты, разрешённые настройкой сайта». Список =
			// только перечисленные. Пустая строка и «ничего не разрешено» — разные
			// вещи, поэтому второе задаётся снятием ACTIVE, а не пустым списком.
			new Fields\TextField('TOOLS'),
			new Fields\BooleanField('ACTIVE', ['values' => ['N', 'Y'], 'default_value' => 'Y']),
			// ⚠️⚠️ Обе даты обязаны быть NULL-евыми, и это не мелочь оформления.
			// Пустой срок означает «бессрочно», пустая дата последнего вызова —
			// «ещё не звали». Колонка NOT NULL делает оба состояния невыразимыми:
			// выпуск бессрочного токена падает с «Column EXPIRES_AT cannot be null».
			new Fields\DatetimeField('EXPIRES_AT', ['nullable' => true]),
			new Fields\DatetimeField('CREATED_AT'),
			new Fields\IntegerField('CREATED_BY'),
			new Fields\DatetimeField('LAST_USED_AT', ['nullable' => true]),
			new Fields\IntegerField('USE_COUNT', ['default_value' => 0]),
		];
	}
}
