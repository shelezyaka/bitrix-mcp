<?php
namespace Itb\Mcp;

/**
 * Приведение схемы базы к текущей версии.
 *
 * ⚠️ Нужно потому, что таблицы создаются ОДИН раз при установке, а описание
 * полей живёт в коде и меняется с ним. Без сверки сайт, поставивший модуль
 * вчера, работает по вчерашней схеме — и ломается на изменении, которого у него
 * нет. Требовать «удалите и поставьте заново» нельзя: вместе с таблицами уедут
 * выпущенные токены и весь журнал.
 *
 * ⚠️ Версия схемы хранится числом в настройках, а не выводится из состава
 * колонок. Опрос `information_schema` отвечает «как есть сейчас», но не отвечает
 * «что уже применяли»; на второй правке той же колонки этого перестаёт хватать.
 */
class Setup
{
	/** Поднимать при КАЖДОМ изменении структуры таблиц. */
	const SCHEMA_VERSION = 2;

	const OPT = 'schema_version';

	/**
	 * @return string[] что было применено (пусто — всё и так актуально)
	 */
	public static function ensureSchema(): array
	{
		$have = (int)\Bitrix\Main\Config\Option::get('itb.mcp', self::OPT, 0);
		if ($have >= self::SCHEMA_VERSION) { return []; }

		$db   = \Bitrix\Main\Application::getConnection();
		$done = [];

		// Версия 2: даты стали NULL-евыми.
		//
		// ⚠️ `createDbTable()` по описанию поля сделал их NOT NULL, и выпуск
		// бессрочного токена падал с «Column 'EXPIRES_AT' cannot be null».
		// Правка описания сама по себе чинит только НОВЫЕ установки — уже
		// созданной таблицы она не касается.
		if ($have < 2) {
			foreach ([['itb_mcp_token', 'EXPIRES_AT'], ['itb_mcp_token', 'LAST_USED_AT']] as [$t, $c]) {
				if (!$db->isTableExists($t)) { continue; }
				try {
					// ⚠️ Синтаксис MySQL-специфичный, поэтому под проверкой типа:
					// на другой СУБД запрос не выполнится, а модуль обязан хотя бы
					// не падать.
					if (strtolower((string)$db->getType()) === 'mysql') {
						$db->query('ALTER TABLE `' . $t . '` MODIFY `' . $c . '` datetime NULL DEFAULT NULL');
						$done[] = $t . '.' . $c . ' → NULL разрешён';
					}
				} catch (\Throwable $e) {
					// Молчать нельзя, но и ронять страницу настроек из-за схемы —
					// тоже: тогда чинить будет негде.
					$done[] = $t . '.' . $c . ' — не удалось: ' . $e->getMessage();
				}
			}
		}

		\Bitrix\Main\Config\Option::set('itb.mcp', self::OPT, self::SCHEMA_VERSION);

		return $done;
	}
}
