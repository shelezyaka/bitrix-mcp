<?php
namespace Itb\Mcp;

/**
 * Граница чтения файлов.
 *
 * Разбор пути чистый — файловой системы не касается, поэтому проверяется из
 * консоли (tests/files.php). Это единственное место модуля, где ошибка означает
 * утечку конфигурации, поэтому слоёв три и ни один не заменяет остальные:
 * белый список папок, чёрный список имён, повторная проверка после realpath.
 */
class Path
{
	/** Откуда разрешено читать. */
	const ROOTS = ['local/', 'bitrix/templates/'];

	/** И ещё lib любого модуля: тот же код, что отдаёт api_source, но файлом. */
	const MODULE_LIB = '~^bitrix/modules/[a-zA-Z0-9_.\-]+/lib(/|$)~';

	/** Папки, которые можно перечислить, чтобы дойти до разрешённых. */
	const LISTABLE = ['local', 'bitrix/templates', 'bitrix/modules'];

	/**
	 * Имена, запрещённые в любой папке. Расширение у них разрешённое, и без
	 * этого списка dbconn.php прочитался бы как обычный php.
	 */
	const NAMES_DENY = ['dbconn.php', '.settings.php', '.settings_extra.php', '.env',
		'.htpasswd', '.git-credentials', '.npmrc', '.my.cnf', 'id_rsa', 'id_dsa'];

	/** Сегменты пути, закрытые целиком. */
	const DIRS_DENY = ['.git', '.svn', 'node_modules', 'upload'];

	/**
	 * Читаем только текст и код: белый список расширений отсекает разом логи,
	 * дампы, архивы, ключи и картинки, не требуя перечислять их поимённо.
	 */
	const EXT_ALLOW = ['php', 'phtml', 'js', 'mjs', 'jsx', 'ts', 'tsx', 'vue', 'css', 'scss',
		'less', 'html', 'htm', 'tpl', 'twig', 'json', 'xml', 'xsd', 'yml', 'yaml', 'md', 'txt'];

	/**
	 * Путь от корня сайта в канонический вид; null — путь негоден.
	 * «..» не схлопывается, а отвергается: схлопывание молча превращает чужой
	 * путь в разрешённый, и в журнале остаётся уже безобидная строка.
	 */
	public static function normalize(string $raw): ?string
	{
		if (strpos($raw, "\0") !== false) { return null; }

		$p = preg_replace('~/+~', '/', str_replace('\\', '/', trim($raw)));
		$p = trim((string)$p, '/');
		if ($p === '') { return null; }

		$out = [];
		foreach (explode('/', $p) as $seg) {
			if ($seg === '.' || $seg === '') { continue; }
			if ($seg === '..') { return null; }
			$out[] = $seg;
		}

		return $out ? implode('/', $out) : null;
	}

	/** Причина отказа либо null, если читать можно. Путь — уже нормализованный. */
	public static function why(string $rel, bool $dir = false): ?string
	{
		foreach (explode('/', $rel) as $seg) {
			if (in_array(strtolower($seg), self::DIRS_DENY, true)) {
				return 'Папка «' . $seg . '» закрыта.';
			}
		}

		if (!self::inRoots($rel, $dir)) {
			return 'Путь «' . $rel . '» вне разрешённых папок. Открыты: local/,'
				. ' bitrix/templates/, bitrix/modules/*/lib/.';
		}

		if ($dir) { return null; }

		$name = strtolower(basename($rel));
		if (in_array($name, self::NAMES_DENY, true)) {
			return 'Файл «' . $name . '» закрыт: в таких лежат пароли и ключи.';
		}

		$ext = strtolower((string)pathinfo($rel, PATHINFO_EXTENSION));
		if ($ext === '' || !in_array($ext, self::EXT_ALLOW, true)) {
			return 'Читаются только текст и код: ' . implode(', ', self::EXT_ALLOW) . '.';
		}

		return null;
	}

	private static function inRoots(string $rel, bool $dir): bool
	{
		$probe = $rel . '/';
		foreach (self::ROOTS as $root) {
			if (strpos($probe, $root) === 0) { return true; }
		}
		if (preg_match(self::MODULE_LIB, $rel)) { return true; }

		if (!$dir) { return false; }

		// Папку разрешаем ещё и навигационную: иначе до bitrix/modules/xxx/lib
		// нельзя дойти, не зная заранее имени модуля.
		if (in_array($rel, self::LISTABLE, true)) { return true; }

		return (bool)preg_match('~^bitrix/modules/[a-zA-Z0-9_.\-]+$~', $rel);
	}

	/**
	 * Нормализация, проверка, разрешение симлинков и проверка ещё раз.
	 * Возвращает абсолютный путь.
	 */
	public static function real(string $raw, bool $dir = false): string
	{
		$rel = self::normalize($raw);
		if ($rel === null) { throw new ToolError('Путь «' . $raw . '» не разбирается'); }

		$why = self::why($rel, $dir);
		if ($why !== null) { throw new ToolError($why); }

		$root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
		if ($root === false) { throw new ToolError('Не определён корень сайта'); }
		$root = rtrim(str_replace('\\', '/', $root), '/');

		$real = realpath($root . '/' . $rel);
		if ($real === false) { throw new ToolError('Не найдено: ' . $rel); }
		$real = str_replace('\\', '/', $real);

		if (strpos($real, $root . '/') !== 0) {
			throw new ToolError('Путь ведёт за пределы сайта');
		}

		// Проверка по настоящему пути: симлинк из разрешённой папки мог увести
		// в запрещённую, а первая проверка видела только имя ссылки.
		$why = self::why(substr($real, strlen($root) + 1), $dir);
		if ($why !== null) { throw new ToolError($why); }

		if ($dir && !is_dir($real)) { throw new ToolError('«' . $rel . '» — не папка'); }
		if (!$dir && !is_file($real)) { throw new ToolError('«' . $rel . '» — не файл'); }

		return $real;
	}

	/** Путь от корня сайта: в ответах наружу абсолютных путей не показываем. */
	public static function relative(string $abs): string
	{
		$root = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
		$root = rtrim(str_replace('\\', '/', (string)$root), '/');
		$abs  = str_replace('\\', '/', $abs);

		return strpos($abs, $root . '/') === 0 ? substr($abs, strlen($root) + 1) : $abs;
	}
}
