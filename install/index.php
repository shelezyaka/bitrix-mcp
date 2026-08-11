<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Установщик модуля MCP-сервера.
 *
 * ⚠️ Модуль НЕ регистрирует ни одного обработчика событий — и это его главное
 * отличие от готовых решений, которые ловят запросы в `OnProlog`. Ставится ровно
 * две вещи: сам модуль и точка входа `/mcp/index.php`. На страницах магазина не
 * выполняется ничего.
 *
 * ⚠️ Токен при установке НЕ создаётся. Модуль после установки не отвечает никому,
 * пока человек не выпустит токен руками. Сгенерированный «для удобства» токен —
 * это открытая дверь, о которой владелец сайта не знает.
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
		$v = [];
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
			// ⚠️ Порядок: сперва таблицы, потом регистрация. Модуль,
			// зарегистрированный без своих таблиц, поднимается и падает на первом
			// же запросе — а выглядит установленным.
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
		// ⚠️ Настройки (в т.ч. хеш токена) стираем: оставленный хеш означает, что
		// после повторной установки старый токен снова заработает, а владелец
		// сайта считает, что доступ отозван вместе с модулем.
		\Bitrix\Main\Config\Option::delete($this->MODULE_ID);
		return true;
	}

	/**
	 * Таблицы токенов и журнала.
	 *
	 * ⚠️ Создаём через ORM (`createDbTable`), а не своим SQL: типы полей описаны
	 * в одном месте — в классе таблицы, — и не разъедутся между описанием и
	 * установкой. Индекс по хешу добавляем отдельно: его ORM не делает, а поиск
	 * токена идёт именно по нему на каждом запросе.
	 */
	public function InstallDB()
	{
		\Bitrix\Main\Loader::includeModule('main');
		require_once __DIR__ . '/../include.php';

		$db = \Bitrix\Main\Application::getConnection();

		foreach ([\Itb\Mcp\Orm\TokenTable::class, \Itb\Mcp\Orm\LogTable::class] as $cls) {
			if (!$db->isTableExists($cls::getTableName())) {
				$cls::getEntity()->createDbTable();
			}
		}

		$t = \Itb\Mcp\Orm\TokenTable::getTableName();
		if (!$db->isIndexExists($t, ['TOKEN_HASH'])) {
			$db->createIndex($t, 'ix_itb_mcp_token_hash', ['TOKEN_HASH'], null,
				$db::INDEX_UNIQUE);
		}
		$l = \Itb\Mcp\Orm\LogTable::getTableName();
		if (!$db->isIndexExists($l, ['CREATED_AT'])) {
			$db->createIndex($l, 'ix_itb_mcp_log_date', ['CREATED_AT']);
		}

		return true;
	}

	/**
	 * ⚠️ Таблицы при удалении СНОСЯТСЯ вместе с токенами. Оставленный хеш означает,
	 * что после повторной установки старый токен снова заработает, а владелец
	 * сайта уверен, что доступ отозван вместе с модулем.
	 */
	public function UnInstallDB()
	{
		$db = \Bitrix\Main\Application::getConnection();
		foreach (['itb_mcp_log', 'itb_mcp_token'] as $t) {
			if ($db->isTableExists($t)) { $db->dropTable($t); }
		}
		return true;
	}

	/**
	 * Точка входа в корне сайта.
	 *
	 * ⚠️ Существующий файл НЕ перезаписываем: на нашем сайте он приезжает вместе
	 * с кодом через git, и установщик, затирающий его своей копией, однажды
	 * откатит правку, которой в модуле ещё нет.
	 */
	public function InstallFiles()
	{
		$dir  = $_SERVER['DOCUMENT_ROOT'] . '/mcp';
		$file = $dir . '/index.php';
		if (!is_dir($dir)) { mkdir($dir, BX_DIR_PERMISSIONS, true); }
		if (!file_exists($file)) {
			copy(__DIR__ . '/entry/index.php', $file);
		}
		return true;
	}

	public function UnInstallFiles()
	{
		// ⚠️ Точку входа удаляем ОБЯЗАТЕЛЬНО: оставшийся файл после удаления
		// модуля отвечает 503, но сам факт живого адреса вводит в заблуждение.
		$file = $_SERVER['DOCUMENT_ROOT'] . '/mcp/index.php';
		if (file_exists($file)) { unlink($file); }
		return true;
	}
}
