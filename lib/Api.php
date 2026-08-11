<?php
namespace Itb\Mcp;

/**
 * Разведка API этой установки Битрикса: что здесь есть и как устроено.
 *
 * Только чтение и только через рефлексию — вызвать метод отсюда нельзя.
 * Смысл группы: писать код под конкретный сайт по фактам, а не по памяти.
 */
class Api
{
	/** Куда разрешено заглядывать. Всё остальное — не наше дело. */
	const ROOTS = ['/bitrix/modules/', '/local/modules/', '/bitrix/php_interface/', '/local/php_interface/'];

	/** Потолок отдаваемого куска исходника, строк. */
	const SOURCE_MAX = 400;

	/** Установленные модули с версиями. */
	public static function modules(array $a): array
	{
		$out = [];
		foreach (\Bitrix\Main\ModuleManager::getInstalledModules() as $id => $m) {
			$out[] = [
				'id'      => (string)$id,
				'name'    => (string)($m['NAME'] ?? ''),
				'version' => (string)(\Bitrix\Main\ModuleManager::getVersion($id) ?: ''),
			];
		}
		usort($out, static function ($x, $y) { return strcmp($x['id'], $y['id']); });

		return ['total' => count($out), 'modules' => $out];
	}

	/** Класс: где лежит, от кого наследуется, какие методы и константы. */
	public static function classInfo(array $a): array
	{
		$name = self::className($a);
		$r    = self::reflect($name);

		$methods = [];
		foreach ($r->getMethods() as $m) {
			if (!$m->isPublic()) { continue; }
			$methods[] = [
				'name'      => $m->getName(),
				'static'    => $m->isStatic(),
				'signature' => self::signature($m),
				'declared'  => $m->getDeclaringClass()->getName(),
				'doc'       => self::firstDocLine($m->getDocComment()),
			];
		}
		usort($methods, static function ($x, $y) { return strcmp($x['name'], $y['name']); });

		$consts = [];
		foreach ($r->getConstants() as $k => $v) {
			$consts[$k] = is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE);
		}

		return [
			'class'      => $r->getName(),
			'file'       => self::relative((string)$r->getFileName()),
			'abstract'   => $r->isAbstract(),
			'parents'    => array_values(class_parents($r->getName()) ?: []),
			'interfaces' => array_values(class_implements($r->getName()) ?: []),
			'constants'  => $consts,
			'methods'    => $methods,
			'doc'        => self::firstDocLine($r->getDocComment()),
		];
	}

	/** Поля ORM-сущности: имя таблицы и состав полей с типами. */
	public static function entity(array $a): array
	{
		$name = self::className($a);
		if (!is_subclass_of($name, '\Bitrix\Main\ORM\Data\DataManager')) {
			throw new ToolError('Класс ' . $name . ' не является ORM-сущностью'
				. ' (не наследует Bitrix\\Main\\ORM\\Data\\DataManager).');
		}

		$fields = [];
		foreach ($name::getEntity()->getFields() as $f) {
			$row = ['name' => $f->getName(), 'type' => (new \ReflectionClass($f))->getShortName()];
			if (method_exists($f, 'isPrimary'))  { $row['primary'] = $f->isPrimary(); }
			if (method_exists($f, 'isRequired')) { $row['required'] = $f->isRequired(); }
			if (method_exists($f, 'isNullable')) { $row['nullable'] = $f->isNullable(); }
			// У ссылочных полей важно, куда они ведут.
			if (method_exists($f, 'getRefEntityName')) {
				try { $row['refers_to'] = $f->getRefEntityName(); } catch (\Throwable $e) {}
			}
			$fields[] = $row;
		}

		return [
			'class'  => $name,
			'table'  => $name::getTableName(),
			'total'  => count($fields),
			'fields' => $fields,
		];
	}

	/**
	 * Исходник класса или метода.
	 *
	 * Путь берётся у рефлексии, а не из аргументов: подставить сюда произвольный
	 * файл нельзя. Отдаём только то, что лежит в папках модулей и php_interface.
	 */
	public static function source(array $a): array
	{
		$name   = self::className($a);
		$method = trim((string)($a['method'] ?? ''));
		$r      = self::reflect($name);

		if ($method !== '') {
			if (!$r->hasMethod($method)) {
				throw new ToolError('У класса ' . $r->getName() . ' нет метода ' . $method);
			}
			$m = $r->getMethod($method);
			$file = (string)$m->getFileName();
			$from = (int)$m->getStartLine();
			$to   = (int)$m->getEndLine();
		} else {
			$file = (string)$r->getFileName();
			$from = (int)$r->getStartLine();
			$to   = (int)$r->getEndLine();
		}

		if ($file === '' || !self::allowedPath($file)) {
			throw new ToolError('Исходник недоступен: файл вне папок модулей.');
		}

		$lines = @file($file);
		if (!$lines) { throw new ToolError('Файл не читается: ' . self::relative($file)); }

		$cut  = false;
		$len  = $to - $from + 1;
		if ($len > self::SOURCE_MAX) { $to = $from + self::SOURCE_MAX - 1; $cut = true; }
		$slice = array_slice($lines, $from - 1, $to - $from + 1);

		return [
			'class'  => $r->getName(),
			'method' => $method !== '' ? $method : null,
			'file'   => self::relative($file),
			'lines'  => $from . '-' . $to,
			'cut'    => $cut ? 'показаны первые ' . self::SOURCE_MAX . ' строк из ' . $len : null,
			'source' => rtrim(implode('', $slice)),
		];
	}

	/**
	 * Поиск классов по части имени в папке lib выбранного модуля.
	 *
	 * Обход ограничен одним модулем: скан всего ядра — это десятки тысяч файлов
	 * на каждый вызов.
	 */
	public static function findClass(array $a): array
	{
		$module = trim((string)($a['module'] ?? ''));
		$query  = strtolower(trim((string)($a['query'] ?? '')));
		if ($module === '') { throw new ToolError('Укажите module — искать по всему ядру слишком дорого.'); }

		$root = null;
		foreach (['/local/modules/', '/bitrix/modules/'] as $base) {
			$p = \Bitrix\Main\Application::getDocumentRoot() . $base . $module . '/lib';
			if (is_dir($p)) { $root = $p; break; }
		}
		if ($root === null) { throw new ToolError('У модуля ' . $module . ' нет папки lib.'); }

		$found = [];
		$it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,
			\FilesystemIterator::SKIP_DOTS));
		foreach ($it as $f) {
			if (count($found) >= 200) { break; }
			if (!$f->isFile() || strtolower($f->getExtension()) !== 'php') { continue; }
			$rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
			if ($query !== '' && strpos(strtolower($rel), $query) === false) { continue; }
			$found[] = [
				'file'  => $rel,
				// Имя класса собирается по конвенции Битрикса: lib/foo/bar.php →
				// Bitrix\<module>\Foo\Bar. Проверяем существование, а не обещаем.
				'class' => self::guessClass($module, $rel),
			];
		}

		return ['module' => $module, 'root' => self::relative($root),
			'total' => count($found), 'files' => $found];
	}

	// ── Внутреннее ──────────────────────────────────────────────────────────

	private static function className(array $a): string
	{
		$name = trim((string)($a['class'] ?? ''));
		if ($name === '') { throw new ToolError('Не указан класс'); }
		return ltrim($name, '\\');
	}

	private static function reflect(string $name): \ReflectionClass
	{
		if (!class_exists($name) && !interface_exists($name) && !trait_exists($name)) {
			throw new ToolError('Класс ' . $name . ' не найден в этой установке.'
				. ' Проверьте, подключён ли его модуль.');
		}
		return new \ReflectionClass($name);
	}

	private static function signature(\ReflectionMethod $m): string
	{
		$args = [];
		foreach ($m->getParameters() as $p) {
			$t = $p->hasType() ? (string)$p->getType() . ' ' : '';
			$d = '';
			if ($p->isDefaultValueAvailable()) {
				try {
					$v = $p->getDefaultValue();
					$d = ' = ' . (is_array($v) ? '[]' : var_export($v, true));
				} catch (\Throwable $e) {}
			}
			$args[] = $t . '$' . $p->getName() . $d;
		}
		$ret = $m->hasReturnType() ? ': ' . (string)$m->getReturnType() : '';

		return $m->getName() . '(' . implode(', ', $args) . ')' . $ret;
	}

	private static function firstDocLine($doc): ?string
	{
		if (!is_string($doc) || $doc === '') { return null; }
		foreach (preg_split('~\R~', $doc) as $line) {
			$line = trim($line, " \t*/");
			if ($line !== '' && strpos($line, '@') !== 0) { return $line; }
		}
		return null;
	}

	private static function allowedPath(string $file): bool
	{
		$file = str_replace('\\', '/', $file);
		foreach (self::ROOTS as $r) {
			if (strpos($file, $r) !== false) { return true; }
		}
		return false;
	}

	private static function relative(string $file): string
	{
		$root = str_replace('\\', '/', (string)\Bitrix\Main\Application::getDocumentRoot());
		$file = str_replace('\\', '/', $file);
		return $root !== '' && strpos($file, $root) === 0 ? substr($file, strlen($root)) : $file;
	}

	private static function guessClass(string $module, string $rel): ?string
	{
		$parts = explode('/', substr($rel, 0, -4));
		$ns    = 'Bitrix\\' . str_replace('.', '\\', $module) . '\\'
			. implode('\\', array_map('ucfirst', $parts));

		return class_exists($ns) ? $ns : null;
	}
}
