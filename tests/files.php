<?php
// Граница чтения файлов.
//
// Здесь ошибка стоит дороже всего: за границей лежат dbconn.php и .settings.php
// с паролем к базе. Проверяются те же методы, что стоят в коде, а не их копия;
// файловая система не нужна — разбор пути от неё не зависит.
require __DIR__ . '/../lib/ToolError.php';
require __DIR__ . '/../lib/Path.php';
use Itb\Mcp\Path;

$ok = 0; $bad = 0;
function is_(string $what, $got, $want) {
	global $ok, $bad;
	if ($got === $want) { $ok++; return; }
	$bad++;
	printf("  - %s: %s вместо %s\n", $what, var_export($got, true), var_export($want, true));
}

/** Читать можно — why() молчит. */
function may(string $rel, bool $dir = false, array $extra = []) {
	global $ok, $bad;
	$why = Path::why($rel, $dir, $extra);
	if ($why === null) { $ok++; return; }
	$bad++;
	printf("  - «%s» должен читаться, а отказ: %s\n", $rel, $why);
}

/** Читать нельзя — why() обязан назвать причину. */
function mayNot(string $rel, bool $dir = false, array $extra = []) {
	global $ok, $bad;
	$why = Path::why($rel, $dir, $extra);
	if ($why !== null) { $ok++; return; }
	$bad++;
	printf("  - «%s» ОТКРЫТ, а не должен\n", $rel);
}

echo "=== разбор пути ===\n";
is_('обычный путь', Path::normalize('local/php_interface/init.php'), 'local/php_interface/init.php');
is_('ведущий слеш', Path::normalize('/local/x.php'), 'local/x.php');
is_('обратные слеши', Path::normalize('local\\php_interface\\x.php'), 'local/php_interface/x.php');
is_('двойные слеши', Path::normalize('local//x.php'), 'local/x.php');
is_('точка-сегмент', Path::normalize('local/./x.php'), 'local/x.php');
is_('пробелы по краям', Path::normalize('  local/x.php  '), 'local/x.php');

// «..» именно отвергается, а не схлопывается: схлопнутый путь выглядит в
// журнале безобидно, и попытку выхода за границу потом не увидеть.
is_('выход вверх', Path::normalize('local/../bitrix/php_interface/dbconn.php'), null);
is_('выход из корня', Path::normalize('../../etc/passwd'), null);
is_('«..» в середине', Path::normalize('bitrix/modules/iblock/lib/../../../php_interface/dbconn.php'), null);
is_('нулевой байт', Path::normalize("local/x.php\0.jpg"), null);
is_('пусто', Path::normalize(''), null);
is_('только слеш', Path::normalize('/'), null);
is_('только точки', Path::normalize('./.'), null);

echo "=== что читать можно ===\n";
may('local/php_interface/init.php');
may('local/modules/itb.mcp/lib/Path.php');
may('local/templates/main/header.php');
may('local/components/my/list/component.php');
may('bitrix/templates/.default/header.php');
may('bitrix/modules/iblock/lib/elementtable.php');
may('bitrix/modules/itb.mcp/lib/Data.php');
may('local/templates/main/style.css');
may('local/js/app.js');
may('local/composer.json');

echo "=== что читать нельзя ===\n";
// Пароль к базе. Папка не в белом списке, имя в чёрном — падает дважды.
mayNot('bitrix/php_interface/dbconn.php');
mayNot('bitrix/.settings.php');
mayNot('local/php_interface/dbconn.php');
mayNot('local/.settings.php');
mayNot('local/.env');
mayNot('local/.htpasswd');
mayNot('local/config/.my.cnf');
// Ядро вне lib: там установщики, админские скрипты и служебные файлы.
mayNot('bitrix/modules/main/include.php');
mayNot('bitrix/modules/main/classes/general/user.php');
mayNot('bitrix/admin/index.php');
mayNot('index.php');
mayNot('upload/order/scan.php');
mayNot('local/upload/x.php');
mayNot('local/.git/config');
mayNot('local/node_modules/pkg/index.js');
// Белый список расширений: логи, дампы, архивы и ключи не перечисляются
// поимённо, их отсекает само расширение.
mayNot('local/logs/error.log');
mayNot('local/dump.sql');
mayNot('local/backup.tar.gz');
mayNot('local/cert/server.pem');
mayNot('local/Makefile');
mayNot('local/photo.jpg');
// Ловушка на префикс: «localfoo» не должен пройти как «local».
mayNot('localfoo/secret.php');
mayNot('bitrix/templatesX/x.php');
mayNot('bitrix/modules/iblock/libx/x.php');

echo "=== папки ===\n";
may('local', true);
may('local/modules', true);
may('bitrix/templates', true);
may('bitrix/modules', true);
may('bitrix/modules/iblock', true);
may('bitrix/modules/iblock/lib', true);
mayNot('bitrix', true);
mayNot('bitrix/php_interface', true);
mayNot('bitrix/modules/iblock/install', true);
mayNot('upload', true);
mayNot('', true);

echo "=== дополнительные папки из настроек ===\n";
$adm = ['adm'];
may('adm/lib/WarehouseMode.php', false, $adm);
may('adm/ozon/warehouse-mode/warehouse-mode.js', false, $adm);
may('adm', true, $adm);
may('adm/ozon', true, $adm);
// Без настройки та же папка закрыта.
mayNot('adm/lib/WarehouseMode.php');
mayNot('adm', true);
// Разрешение идёт по границе папки: «adm» не должен открывать «admin».
mayNot('admin/index.php', false, $adm);
mayNot('adm_backup/x.php', false, $adm);
mayNot('bitrix/admin/x.php', false, $adm);
// Внутри дополнительной папки действуют те же запреты.
mayNot('adm/dbconn.php', false, $adm);
mayNot('adm/.env', false, $adm);
mayNot('adm/logs/error.log', false, $adm);
mayNot('adm/.git/config', false, $adm);
// Несколько папок сразу.
may('shop/inc/x.php', false, ['adm', 'shop/inc']);
mayNot('shop/other/x.php', false, ['adm', 'shop/inc']);

echo "=== разбор настройки с папками ===\n";
is_('через запятую', Path::parse('adm, shop/inc'), ['adm', 'shop/inc']);
is_('через перенос строки', Path::parse("adm\nshop/inc"), ['adm', 'shop/inc']);
is_('слеши по краям', Path::parse('/adm/'), ['adm']);
is_('обратные слеши', Path::parse('adm\\ozon'), ['adm/ozon']);
is_('повтор схлопывается', Path::parse('adm, adm'), ['adm']);
is_('пусто', Path::parse(''), []);
// «..» в белом списке — не опечатка, а дыра: такая запись отбрасывается.
is_('выход вверх отбрасывается', Path::parse('adm/../bitrix'), []);
is_('закрытая папка отбрасывается', Path::parse('upload, adm'), ['adm']);
is_('корень сайта не открыть', Path::parse('/'), []);

echo "\n" . ($bad ? "ПРОВАЛОВ: $bad, удачных: $ok\n" : "Все $ok проверок прошли.\n");
exit($bad ? 1 : 0);
