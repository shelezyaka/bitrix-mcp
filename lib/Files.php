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
	const GREP_FILES    = 4000;
	const GREP_HITS     = 100;
	/** Иначе один шумный файл съедает весь лимит, и до остальных дело не доходит. */
	const GREP_PER_FILE = 20;
	const GREP_BYTES   = 1048576;
	const GREP_SECONDS = 10;

	public static function read(array $a): array
	{
		$abs = Path::real((string)($a['path'] ?? ''));

		$size  = (int)filesize($abs);
		$from  = max(1, (int)($a['from'] ?? 1));
		$lines = (int)($a['lines'] ?? self::LINES_DEF);
		$lines = min($lines > 0 ? $lines : self::LINES_DEF, self::LINES_MAX);

		// Большой файл читаем построчно: целиком он не влезет ни в память, ни в
		// ответ, но кусок из него взять можно — иначе from и lines бесполезны
		// именно там, где они нужнее всего.
		$whole = $size <= self::MAX_BYTES;

		$part  = [];
		$count = 0;
		$bytes = 0;
		$cut   = false;
		$long  = false;

		$fh = fopen($abs, 'rb');
		if ($fh === false) { throw new ToolError('Файл не открывается на чтение'); }
		while (($line = fgets($fh)) !== false) {
			$count++;
			if ($count >= $from && count($part) < $lines && !$cut) {
				$line = rtrim($line, "\r\n");
				if (strpos($line, "\0") !== false) {
					fclose($fh);
					throw new ToolError('Файл двоичный, читать нечего');
				}
				// Предел по байтам, а не только по строкам: у сжатого js весь
				// файл — одна строка, и счёт строк её не удержит.
				$bytes += strlen($line) + 1;
				if ($bytes > self::MAX_BYTES) {
					$cut = true;
					// Одна строка длиннее предела — отдаём её начало, иначе ответ
					// был бы пуст, а следующий кусок опять начинался бы с неё.
					if (!$part) { $part[] = substr($line, 0, self::MAX_BYTES); $long = true; }
				} else {
					$part[] = $line;
				}
			}
			if (!$whole && ($cut || $count >= $from + $lines)) { break; }
		}
		fclose($fh);

		$out = [
			'path'  => Path::relative($abs),
			'from'  => $from,
			'shown' => count($part),
			'size'  => $size,
			// Кодировку не трогаем: часть сайтов на CP1251, и перекодировка
			// наугад испортила бы то, что и так читается.
			'text'  => implode("\n", $part),
		];

		if ($whole) { $out['lines'] = $count; }

		if ($long) {
			$out['more'] = 'Строка ' . $from . ' длиннее предела в ' . self::MAX_BYTES
				. ' Б — показано её начало. Дальше читайте с from ' . ($from + 1) . '.';
		} elseif (!$part) {
			// Пустой ответ без объяснения читается как «файл пуст». Пометка про
			// следующий кусок здесь была бы враньём: читать дальше нечего.
			$out['more'] = 'Строк с позиции ' . $from . ' нет'
				. ($whole ? ': в файле их ' . $count . '.' : '.');
		} elseif ($cut) {
			$out['more'] = 'Обрыв по объёму: за раз отдаётся не больше ' . self::MAX_BYTES
				. ' Б. Следующий кусок — from ' . ($from + count($part)) . '.';
		} elseif ($whole) {
			if ($from + count($part) - 1 < $count) {
				$out['more'] = 'Показаны не все строки: всего ' . $count . '. Следующий кусок — from '
					. ($from + count($part)) . '.';
			}
		} else {
			$out['more'] = 'Файл больше ' . self::MAX_BYTES . ' Б, читается кусками.'
				. ' Следующий — from ' . ($from + count($part)) . '.';
		}

		return $out;
	}

	public static function listDir(array $a): array
	{
		$abs = Path::real((string)($a['path'] ?? ''), true);

		// scandir возвращает false, а не пустой массив: приведение к массиву
		// дало бы строку [false] и лишнюю запись в выдаче.
		$names = scandir($abs);
		if ($names === false) { throw new ToolError('Папка не читается'); }

		$extra = Path::extra();
		$items = [];
		$cut   = false;
		foreach ($names as $name) {
			if ($name === '.' || $name === '..') { continue; }
			if (count($items) >= self::LIST_MAX) { $cut = true; break; }

			$full = $abs . '/' . $name;
			$dir  = is_dir($full);

			$items[] = [
				'name'     => $name,
				'type'     => $dir ? 'dir' : 'file',
				'size'     => $dir ? null : (int)filesize($full),
				'modified' => date('d.m.Y H:i:s', (int)filemtime($full)),
				// Видно сразу, что из перечисленного получится открыть. Проверка
				// та же, что у чтения, и по тому же разрешённому пути: иначе
				// ссылка наружу значилась бы доступной, а file_read её отверг.
				'readable' => self::resolve($full, $dir, $extra) !== null,
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

		$extra    = Path::extra();
		$hits     = [];
		$scanned  = 0;
		$stopped  = '';
		$deadline = microtime(true) + self::GREP_SECONDS;

		// Симлинки не разворачиваем в обход границы: обход в каталог по ссылке
		// не идёт (RecursiveDirectoryIterator без FOLLOW_SYMLINKS), а ссылку на
		// файл отсекает resolve() ниже.
		// CATCH_GET_CHILD: одна папка без прав чтения иначе роняет весь обход, и
		// поиск возвращает ошибку вместо того, что успел найти.
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS),
			\RecursiveIteratorIterator::LEAVES_ONLY,
			\RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ($it as $file) {
			if (count($hits) >= self::GREP_HITS)  { $stopped = 'совпадений'; break; }
			if ($scanned >= self::GREP_FILES)     { $stopped = 'файлов';     break; }
			if (microtime(true) > $deadline)      { $stopped = 'времени';    break; }

			// Проверяем РАЗРЕШЁННЫЙ путь, а не тот, по которому файл нашли:
			// ссылка local/x.php → bitrix/php_interface/dbconn.php проходит любую
			// проверку по имени, а прочиталось бы содержимое цели.
			$full = self::resolve((string)$file->getPathname(), false, $extra);
			if ($full === null) { continue; }
			$rel = Path::relative($full);
			if ((int)filesize($full) > self::GREP_BYTES) { continue; }

			$scanned++;
			$raw = (string)file_get_contents($full);
			if (($ci ? stripos($raw, $needle) : strpos($raw, $needle)) === false) { continue; }

			$inFile = 0;
			foreach (preg_split('~\r\n|\r|\n~', $raw) ?: [] as $i => $line) {
				if (($ci ? stripos($line, $needle) : strpos($line, $needle)) === false) { continue; }
				// Режем байтами, а не mb_substr: mbstring есть не везде, а его
				// отсутствие роняло бы весь вызов. Обрубок символа на границе
				// заменит JSON_INVALID_UTF8_SUBSTITUTE в Transport.
				$hits[] = ['path' => $rel, 'line' => $i + 1,
					'text' => substr(trim($line), 0, 200)];
				if (++$inFile >= self::GREP_PER_FILE) {
					$hits[] = ['path' => $rel, 'line' => null,
						'text' => '… в этом файле показаны первые ' . self::GREP_PER_FILE . ' совпадений'];
					break;
				}
				if (count($hits) >= self::GREP_HITS) { $stopped = 'совпадений'; break; }
			}
			if (count($hits) >= self::GREP_HITS) { $stopped = 'совпадений'; break; }
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

	/**
	 * Настоящий путь файла, если его разрешено читать; иначе null.
	 *
	 * Обход каталога выдаёт путь, по которому файл найден, а прочитается тот,
	 * куда он ведёт. Проверять надо второй: одной ссылки из разрешённой папки
	 * хватило бы, чтобы вынести dbconn.php.
	 */
	private static function resolve(string $abs, bool $dir, array $extra): ?string
	{
		$real = realpath($abs);
		if ($real === false) { return null; }
		$real = str_replace('\\', '/', $real);

		if ($dir !== is_dir($real)) { return null; }

		$rel = Path::relative($real);
		// relative() вернёт путь как есть, если он вне корня сайта — такой
		// абсолютный путь белым списком не пройдёт.
		return Path::why($rel, $dir, $extra) === null ? $real : null;
	}
}
