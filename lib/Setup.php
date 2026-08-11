<?php
namespace Itb\Mcp;

/**
 * Приведение схемы базы к текущей версии.
 * Версия хранится числом в настройках; сверяется при установке и при открытии настроек.
 */
class Setup
{
	/** Поднимать при каждом изменении структуры таблиц. */
	const SCHEMA_VERSION = 2;

	const OPT = 'schema_version';

	/** @return string[] что было применено */
	public static function ensureSchema(): array
	{
		$have = (int)\Bitrix\Main\Config\Option::get('itb.mcp', self::OPT, 0);
		if ($have >= self::SCHEMA_VERSION) { return []; }

		$db   = \Bitrix\Main\Application::getConnection();
		$done = [];

		// Версия 2: даты стали NULL-евыми. createDbTable сделал их NOT NULL, и выпуск
		// бессрочного токена падал с «Column 'EXPIRES_AT' cannot be null».
		if ($have < 2) {
			foreach ([['itb_mcp_token', 'EXPIRES_AT'], ['itb_mcp_token', 'LAST_USED_AT']] as [$t, $c]) {
				if (!$db->isTableExists($t)) { continue; }
				try {
					if (strtolower((string)$db->getType()) === 'mysql') {
						$db->query('ALTER TABLE `' . $t . '` MODIFY `' . $c . '` datetime NULL DEFAULT NULL');
						$done[] = $t . '.' . $c . ' → NULL разрешён';
					}
				} catch (\Throwable $e) {
					$done[] = $t . '.' . $c . ' — не удалось: ' . $e->getMessage();
				}
			}
		}

		\Bitrix\Main\Config\Option::set('itb.mcp', self::OPT, self::SCHEMA_VERSION);

		return $done;
	}
}
