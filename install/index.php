<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Установщик модуля MCP-сервера.
 *
 * Обработчиков событий модуль не регистрирует: запросы приходят на собственную
 * точку входа /mcp/index.php, на страницах сайта его код не выполняется.
 * Токен при установке не создаётся — доступ выдаётся вручную.
 */
class itb_mcp extends CModule
{
	public $MODULE_ID = 'itb.mcp';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $PARTNER_NAME;
	public $MODULE_GROUP_RIGHTS = 'Y';

	public function __construct()
	{
		include __DIR__ . '/version.php';
		$this->MODULE_VERSION      = $arModuleVersion['VERSION'] ?? '0.0.0';
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '';
		$this->MODULE_NAME         = Loc::getMessage('ITB_MCP_NAME');
		$this->MODULE_DESCRIPTION  = Loc::getMessage('ITB_MCP_DESC');
		$this->PARTNER_NAME        = 'ITB';
	}

	public function DoInstall()
	{
		global $APPLICATION;
		try {
			$this->CheckVisible();
			$this->CheckFiles();
			// Сперва таблицы, потом регистрация: модуль без своих таблиц выглядит
			// установленным и падает на первом запросе.
			$this->InstallDB();
			$this->InstallFiles();
			\Bitrix\Main\ModuleManager::registerModule($this->MODULE_ID);
		} catch (\Throwable $e) {
			$APPLICATION->ThrowException($e->getMessage());
			return false;
		}
		return true;
	}

	public function DoUninstall()
	{
		\Bitrix\Main\ModuleManager::unRegisterModule($this->MODULE_ID);
		$this->UnInstallFiles();
		$this->UnInstallDB();
		// Настройки стираем вместе с токенами: иначе после повторной установки
		// старый токен снова заработает.
		\Bitrix\Main\Config\Option::delete($this->MODULE_ID);
		return true;
	}

	/**
	 * Видит ли Битрикс папку модуля своим механизмом поиска.
	 * Установщик находит себя через __DIR__ и работает всегда, а includeModule
	 * в бою идёт через getLocal() — если тот не видит local/, модуль установится
	 * и не заработает ни разу.
	 */
	public function CheckVisible()
	{
		$root = rtrim((string)\Bitrix\Main\Application::getDocumentRoot(), '/\\');
		$want = $root . '/local/modules/' . $this->MODULE_ID . '/include.php';

		$found = \Bitrix\Main\Loader::getLocal('modules/' . $this->MODULE_ID . '/include.php');
		if ($found !== false && file_exists($found) && strpos($found, '/local/') !== false) {
			return true;
		}

		throw new \Exception(
			'Битрикс не видит папку модуля. Ожидается файл: ' . $want . "\n"
			. 'Установщик при этом запущен из: ' . __DIR__ . "\n"
			. 'Если файл на диске есть, дело в правах: папка local должна читаться '
			. 'пользователем веб-сервера. Разбор по шагам — страница /mcp/check.php.'
		);
	}

	/** Все ли файлы доехали. Проверка до первой записи в базу. */
	public function CheckFiles()
	{
		$need = require __DIR__ . '/manifest.php';

		$lost = [];
		foreach ($need as $f) {
			if (!file_exists(__DIR__ . '/../' . $f)) { $lost[] = $f; }
		}
		if (!$lost) { return true; }

		throw new \Exception(
			'Модуль доехал не целиком, не хватает ' . count($lost) . ' файлов: ' . "\n"
			. implode("\n", $lost) . "\n"
			. 'Ожидаются в ' . realpath(__DIR__ . '/..') . '. Обновите файлы и повторите.'
		);
	}

	/** Таблицы создаём через ORM; индексы она не делает, добавляем сами. */
	public function InstallDB()
	{
		\Bitrix\Main\Loader::includeModule('main');

		// Прямые пути от __DIR__, а не автозагрузка: она ищет модуль через getLocal()
		// и при неудаче уходит в bitrix/modules, где ничего нет.
		require_once __DIR__ . '/../lib/Orm/TokenTable.php';
		require_once __DIR__ . '/../lib/Orm/LogTable.php';
		require_once __DIR__ . '/../lib/Setup.php';

		$db = \Bitrix\Main\Application::getConnection();

		foreach ([\Itb\Mcp\Orm\TokenTable::class, \Itb\Mcp\Orm\LogTable::class] as $cls) {
			if (!$db->isTableExists($cls::getTableName())) {
				$cls::getEntity()->createDbTable();
			}
		}

		$t = \Itb\Mcp\Orm\TokenTable::getTableName();
		if (!$db->isIndexExists($t, ['TOKEN_HASH'])) {
			$db->createIndex($t, 'ix_itb_mcp_token_hash', ['TOKEN_HASH'], null, $db::INDEX_UNIQUE);
		}
		$l = \Itb\Mcp\Orm\LogTable::getTableName();
		if (!$db->isIndexExists($l, ['CREATED_AT'])) {
			$db->createIndex($l, 'ix_itb_mcp_log_date', ['CREATED_AT']);
		}

		\Itb\Mcp\Setup::ensureSchema();

		return true;
	}

	/** Таблицы сносятся вместе с токенами — иначе «удалил модуль» не значит «отозвал доступ». */
	public function UnInstallDB()
	{
		$db = \Bitrix\Main\Application::getConnection();
		foreach (['itb_mcp_log', 'itb_mcp_token'] as $t) {
			if ($db->isTableExists($t)) { $db->dropTable($t); }
		}
		return true;
	}

	/**
	 * Точка входа и страница диагностики в корне сайта.
	 * Существующие файлы не перезаписываем: они могут приезжать через git.
	 */
	public function InstallFiles()
	{
		$dir = $_SERVER['DOCUMENT_ROOT'] . '/mcp';
		if (!is_dir($dir)) { mkdir($dir, BX_DIR_PERMISSIONS, true); }

		foreach (['index.php', 'check.php'] as $name) {
			if (!file_exists($dir . '/' . $name)) {
				copy(__DIR__ . '/entry/' . $name, $dir . '/' . $name);
			}
		}
		return true;
	}

	public function UnInstallFiles()
	{
		$dir = $_SERVER['DOCUMENT_ROOT'] . '/mcp';
		foreach (['index.php', 'check.php'] as $name) {
			if (file_exists($dir . '/' . $name)) { unlink($dir . '/' . $name); }
		}
		// Пустую папку убираем за собой; если там осталось чужое — не трогаем.
		if (is_dir($dir) && !glob($dir . '/*')) { @rmdir($dir); }
		return true;
	}
}
