<?php
namespace Itb\Mcp;

/**
 * Чтение файлов проекта. Только чтение, и только там, где разрешает Path.
 *
 * Нужно для кода, до которого не добирается рефлексия: компоненты, шаблоны,
 * php_interface, обычные функции.
 */
class Files
{
	const MAX_BYTES   = 262144;
	const LINES_DEF   = 300;
	const LINES_MAX   = 2000;
	const LIST_MAX    = 500;
	const GREP_FILES  = 4000;
	const GREP_HITS   = 100;
	const GREP_BYTES  = 1048576;

	public static function read(array $a): array
	{
		$abs = Path::real((string)($a['path'] ?? ''));

		$size = (int)filesize($abs);
		if ($size > self::MAX_BYTES) {
			throw new ToolError('Файл велик: ' . $size . ' Б при пределе ' . self::MAX_BYTES
				. '. Возьмите кусок через from и lines.');
		}

		$raw = (string)file_get_contents($abs);
		if (strpos($raw, "\0") !== false) {
			throw new ToolError('Файл двоичный, читать нечего');
		}

		$all   = preg_split('~\r\n|\r|\n~', $raw) ?: [];
		$count = count($all);

		$from  = max(1, (int)($a['from'] ?? 1));
		$lines = (int)($a['lines'] ?? self::LINES_DEF);
		$lines = min($lines > 0 ? $lines : self::LINES_DEF, self::LINES_MAX);

		$part = array_slice($all, $from - 1, $lines);

		$out = [
			'path'  => Path::relative($abs),
			'lines' => $count,
			'from'  => $from,
			'shown' => count($part),
			'size'  => $size,
			// Кодировку не трогаем: часть сайтов на CP1251, и перекодировка
			// наугад испортила бы то, что и так читается.
			'text'  => implode("\n", $part),
		];
		if ($from + count($part) - 1 < $count) {
			$out['more'] = 'Показаны не все строки: всего ' . $count . '. Следующий кусок — from '
				. ($from + count($part)) . '.';
		}

		return $out;
	}

	public static function listDir(array $a): array
	{
		$abs = Path::real((string)($a['path'] ?? ''), true);

		$items = [];
		$cut   = false;
		foreach ((array)scandir($abs) as $name) {
			if ($name === '.' || $name === '..') { continue; }
			if (count($items) >= self::LIST_MAX) { $cut = true; break; }

			$full = $abs . '/' . $name;
			$dir  = is_dir($full);
			$rel  = Path::relative($full);

			$items[] = [
				'name'     => $name,
				'type'     => $dir ? 'dir' : 'file',
				'size'     => $dir ? null : (int)filesize($full),
				'modified' => date('d.m.Y H:i:s', (int)filemtime($full)),
				// Видно сразу, что из перечисленного получится открыть.
				'readable' => Path::why($rel, $dir) === null,
			];
		}

		usort($items, static function ($x, $y) {
			return $x['type'] === $y['type']
				? strcmp($x['name'], $y['name'])
				: ($x['type'] === 'dir' ? -1 : 1);
		});

		$out = ['path' => Path::relative($abs), 'total' => count($items), 'items' => $items];
		if ($cut) { $out['note'] = 'Показаны первые ' . self::LIST_MAX . ' записей.'; }

		return $out;
	}

	/**
	 * Поиск подстроки по коду.
	 *
	 * Ищем именно подстроку, а не регулярное выражение: шаблон приходит от
	 * модели, и неудачное выражение уложило бы сайт перебором.
	 */
	public static function grep(array $a): array
	{
		$needle = (string)($a['query'] ?? '');
		if (strlen($needle) < 3) { throw new ToolError('Строка поиска короче трёх символов'); }

		$abs = Path::real((string)($a['path'] ?? ''), true);
		$ci  = !empty($a['ignore_case']);

		$hits    = [];
		$scanned = 0;
		$stopped = '';

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ($it as $file) {
			if (count($hits) >= self::GREP_HITS) { $stopped = 'совпадений'; break; }
			if ($scanned >= self::GREP_FILES)    { $stopped = 'файлов';     break; }

			$full = str_replace('\\', '/', (string)$file->getPathname());
			$rel  = Path::relative($full);
			if (Path::why($rel) !== null) { continue; }
			if ($file->getSize() > self::GREP_BYTES) { continue; }

			$scanned++;
			$raw = (string)file_get_contents($full);
			if (($ci ? stripos($raw, $needle) : strpos($raw, $needle)) === false) { continue; }

			foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $i => $line) {
				if (($ci ? stripos($line, $needle) : strpos($line, $needle)) === false) { continue; }
				$hits[] = ['path' => $rel, 'line' => $i + 1,
					'text' => mb_substr(trim($line), 0, 200)];
				if (count($hits) >= self::GREP_HITS) { $stopped = 'совпадений'; break; }
			}
		}

		$out = ['query' => $needle, 'path' => Path::relative($abs),
			'files_scanned' => $scanned, 'total' => count($hits), 'hits' => $hits];
		// Молчаливая обрезка читается как «больше нигде нет» — это неправда.
		if ($stopped !== '') {
			$out['note'] = 'Поиск остановлен по пределу ' . $stopped
				. ': найденное неполно, сузьте path или уточните запрос.';
		}

		return $out;
	}
}
