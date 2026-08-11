#!/usr/bin/env php
<?php
/**
 * Версия модуля.
 *
 *   php bin/version.php            показать текущую
 *   php bin/version.php --patch    0.2.0 → 0.2.1   (правки, обычный коммит)
 *   php bin/version.php --minor    0.2.1 → 0.3.0   (новая группа инструментов)
 *   php bin/version.php --major    0.3.0 → 1.0.0   (несовместимое изменение)
 *   php bin/version.php --set 1.2.3
 *
 * Файл install/version.php читает Битрикс, поэтому переписывается он целиком и
 * в том же виде, а не правится регулярным выражением по месту.
 */

$file = __DIR__ . '/../install/version.php';
if (!is_file($file)) {
	fwrite(STDERR, "Не найден $file\n");
	exit(1);
}

$arModuleVersion = [];
include $file;
$cur = (string)($arModuleVersion['VERSION'] ?? '0.0.0');

$argvv = array_slice($argv, 1);
$mode  = $argvv[0] ?? '';

if ($mode === '') {
	echo $cur, "\n";
	exit(0);
}

$parts = array_map('intval', array_pad(explode('.', $cur), 3, 0));

switch ($mode) {
	case '--patch': $parts[2]++; break;
	case '--minor': $parts[1]++; $parts[2] = 0; break;
	case '--major': $parts[0]++; $parts[1] = 0; $parts[2] = 0; break;
	case '--set':
		$v = (string)($argvv[1] ?? '');
		if (!preg_match('~^\d+\.\d+\.\d+$~', $v)) {
			fwrite(STDERR, "Ожидается версия вида 1.2.3\n");
			exit(1);
		}
		$parts = array_map('intval', explode('.', $v));
		break;
	default:
		fwrite(STDERR, "Неизвестный аргумент: $mode\n");
		exit(1);
}

$new = implode('.', $parts);

$out = "<?php\n"
	. "\$arModuleVersion = [\n"
	. "\t'VERSION'      => '" . $new . "',\n"
	. "\t'VERSION_DATE' => '" . date('Y-m-d H:i:s') . "',\n"
	. "];\n";

file_put_contents($file, $out);

echo $new, "\n";
