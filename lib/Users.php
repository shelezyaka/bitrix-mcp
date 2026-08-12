<?php
namespace Itb\Mcp;

use Bitrix\Main\GroupTable;
use Bitrix\Main\UserGroupTable;
use Bitrix\Main\UserTable;

/**
 * Карточка покупателя. Только чтение.
 *
 * Живёт в группе orders: заказ без покупателя не читают, а разделять права
 * между ними значило бы выдавать одни и те же персональные данные дважды.
 */
class Users
{
	/**
	 * Поля карточки. Набор закрытый: в b_user под семьдесят полей, и там же
	 * лежат хеш пароля, контрольное слово и коды подтверждения — их не должно
	 * быть даже в select, чтобы они не попали в ответ по недосмотру.
	 */
	const FIELDS = ['ID', 'LOGIN', 'ACTIVE', 'BLOCKED', 'NAME', 'LAST_NAME', 'SECOND_NAME',
		'EMAIL', 'PERSONAL_PHONE', 'PERSONAL_MOBILE', 'PERSONAL_CITY', 'PERSONAL_COUNTRY',
		'DATE_REGISTER', 'LAST_LOGIN', 'LAST_ACTIVITY_DATE', 'TIMESTAMP_X',
		'EXTERNAL_AUTH_ID', 'XML_ID', 'LID'];

	public static function get(array $a): array
	{
		$id    = (int)($a['id'] ?? 0);
		$login = trim((string)($a['login'] ?? ''));
		$email = trim((string)($a['email'] ?? ''));

		if ($id <= 0 && $login === '' && $email === '') {
			throw new ToolError('Укажите id, login или email');
		}

		if ($id > 0)            { $filter = ['=ID' => $id]; }
		elseif ($login !== '')  { $filter = ['=LOGIN' => $login]; }
		else                    { $filter = ['=EMAIL' => $email]; }

		$row = UserTable::getRow(['select' => self::FIELDS, 'filter' => $filter]);
		if (!$row) { throw new ToolError('Пользователь не найден'); }

		$out = [];
		foreach ($row as $k => $v) {
			$out[$k] = $v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date
				? $v->format('d.m.Y H:i:s')
				: $v;
		}

		$uid = (int)$row['ID'];
		$out['GROUPS'] = self::groups($uid);
		$out['ORDERS'] = self::orders($uid);

		// Робот обмена с маркетплейсом выглядит как обычный покупатель, и по
		// заказам их не различить: подсказка про EXTERNAL_AUTH_ID экономит
		// целое расследование.
		if ((string)$row['EXTERNAL_AUTH_ID'] !== '') {
			$out['note'] = 'Пользователь заведён внешней системой авторизации «'
				. $row['EXTERNAL_AUTH_ID'] . '», а не регистрацией на сайте.';
		}

		return $out;
	}

	/** @return array[] группы пользователя с названиями */
	private static function groups(int $userId): array
	{
		$ids = [];
		$rs = UserGroupTable::getList([
			'select' => ['GROUP_ID'],
			'filter' => ['=USER_ID' => $userId],
		]);
		while ($r = $rs->fetch()) { $ids[] = (int)$r['GROUP_ID']; }
		if (!$ids) { return []; }

		$out = [];
		$rs = GroupTable::getList([
			'select' => ['ID', 'NAME', 'STRING_ID'],
			'filter' => ['@ID' => $ids],
			'order'  => ['C_SORT' => 'ASC'],
		]);
		while ($g = $rs->fetch()) {
			$out[] = ['id' => (int)$g['ID'], 'name' => (string)$g['NAME'],
				'code' => (string)$g['STRING_ID']];
		}

		return $out;
	}

	/** Сколько заказов и на какую сумму. Модуля sale может не быть вовсе. */
	private static function orders(int $userId): ?array
	{
		if (!\Bitrix\Main\ModuleManager::isModuleInstalled('sale')
			|| !\Bitrix\Main\Loader::includeModule('sale')) {
			return null;
		}

		$row = \Bitrix\Sale\Internals\OrderTable::getRow([
			'select' => ['CNT', 'SUM_PRICE', 'LAST_DATE'],
			'filter' => ['=USER_ID' => $userId],
			'runtime' => [
				new \Bitrix\Main\ORM\Fields\ExpressionField('CNT', 'COUNT(1)'),
				new \Bitrix\Main\ORM\Fields\ExpressionField('SUM_PRICE', 'SUM(%s)', ['PRICE']),
				new \Bitrix\Main\ORM\Fields\ExpressionField('LAST_DATE', 'MAX(%s)', ['DATE_INSERT']),
			],
		]);

		return [
			'count' => (int)($row['CNT'] ?? 0),
			'sum'   => round((float)($row['SUM_PRICE'] ?? 0), 2),
			'last'  => $row['LAST_DATE'] ?? null,
		];
	}
}
