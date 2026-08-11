<?php
/**
 * Настройки модуля: токены, журнал, разрешённые Origin.
 *
 * Открывается по «Настройки → Настройки продукта → Настройки модулей → MCP-сервер».
 *
 * ⚠️ Инлайновых скриптов здесь нет вовсе — только формы. На боевом Битриксе
 * проактивная защита вырезает `<script>`, если внутри встретилось значение из
 * запроса; ловить это в админке ничем не легче, чем на витрине.
 *
 * ⚠️⚠️ Действие и объект едут ОДНОЙ парой «имя-значение» (`act=revoke:12`).
 * Сперва было иначе — кнопка `do=revoke` и отдельное скрытое поле `id` в каждой
 * строке таблицы; но форма одна на всю страницу, поэтому браузер отправляет ВСЕ
 * скрытые поля, и «отозвать» у первого токена отзывало бы последний. Ошибка
 * тихая: страница перезагружается, что-то отзывается, и выглядит это правильно.
 *
 * @var CMain $APPLICATION
 */

use Bitrix\Main\Loader;
use Bitrix\Main\Config\Option;

$module_id = 'itb.mcp';
Loader::includeModule($module_id);

$rights   = $APPLICATION->GetGroupRight($module_id);
if ($rights < 'R') { return; }
$canWrite = ($rights >= 'W');

// ⚠️ Схема сверяется при открытии настроек, а не только при установке. Иначе
// правку структуры получают лишь новые сайты, а поставившим модуль раньше
// пришлось бы удалять его и ставить заново — вместе с токенами и журналом.
$repairs = \Itb\Mcp\Setup::ensureSchema();

$freshToken = '';
$msg = '';
$err = '';

// ── Действия ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canWrite && check_bitrix_sessid()) {
	[$act, $arg] = array_pad(explode(':', (string)($_POST['act'] ?? ''), 2), 2, '');

	try {
		if ($act === 'save') {
			Option::set($module_id, 'origins', trim((string)($_POST['origins'] ?? '')));
			Option::set($module_id, 'log_days', max(0, (int)($_POST['log_days'] ?? 30)));
			$msg = 'Настройки сохранены.';
		} elseif ($act === 'issue') {
			$r = \Itb\Mcp\Token::issue(
				trim((string)($_POST['title'] ?? '')),
				trim((string)($_POST['expires'] ?? '')),
				null,
				(int)$GLOBALS['USER']->GetID()
			);
			// ⚠️ Единственный момент за всю жизнь токена, когда его видно. Дальше
			// в базе только sha256 — показать повторно нельзя ни нам, ни владельцу.
			$freshToken = $r['token'];
		} elseif ($act === 'revoke') {
			\Itb\Mcp\Token::revoke((int)$arg);
			$msg = 'Токен ' . (int)$arg . ' отозван. Запросы с ним получают 401.';
		} elseif ($act === 'drop') {
			\Itb\Mcp\Token::drop((int)$arg);
			$msg = 'Токен ' . (int)$arg . ' удалён.';
		}
	} catch (\Throwable $e) {
		$err = $e->getMessage();
	}
}

$origins = (string)Option::get($module_id, 'origins', '');
$logDays = (int)Option::get($module_id, 'log_days', 30);
$tokens  = \Itb\Mcp\Token::all();
$log     = \Itb\Mcp\Audit::tail(50);

$https    = (string)($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off';
$endpoint = ($https ? 'https://' : 'http://') . (string)($_SERVER['HTTP_HOST'] ?? '') . '/mcp/';

$live = array_filter($tokens, static function ($t) {
	return \Itb\Mcp\Token::why($t, time()) === null;
});

$tabs = new CAdminTabControl('itbMcpTabs', [
	['DIV' => 'tokens',   'TAB' => 'Токены',    'TITLE' => 'Доступ к MCP-серверу'],
	['DIV' => 'settings', 'TAB' => 'Настройки', 'TITLE' => 'Origin и срок хранения журнала'],
	['DIV' => 'log',      'TAB' => 'Журнал',    'TITLE' => 'Последние обращения'],
]);
?>
<?php if ($repairs): ?>
<div class="adm-info-message-wrap"><div class="adm-info-message">
	Схема базы обновлена: <?php echo htmlspecialcharsbx(implode('; ', $repairs)); ?>.
</div></div>
<?php endif; ?>
<?php if ($err !== ''): ?>
<div class="adm-info-message-wrap adm-info-message-red"><div class="adm-info-message"><?php
	echo htmlspecialcharsbx($err); ?></div></div>
<?php endif; ?>
<?php if ($msg !== ''): ?>
<div class="adm-info-message-wrap"><div class="adm-info-message"><?php
	echo htmlspecialcharsbx($msg); ?></div></div>
<?php endif; ?>

<?php if ($freshToken !== ''): ?>
<div class="adm-info-message-wrap adm-info-message-green"><div class="adm-info-message">
	<b>Токен выпущен. Скопируйте сейчас — второй раз он не покажется.</b><br><br>
	<code style="font-size:14px;user-select:all"><?php echo htmlspecialcharsbx($freshToken); ?></code>
	<br><br>Подключение клиента одной командой:<br>
	<code style="user-select:all">claude mcp add --transport http bitrix <?php
		echo htmlspecialcharsbx($endpoint); ?> --header "Authorization: Bearer <?php
		echo htmlspecialcharsbx($freshToken); ?>"</code>
</div></div>
<?php endif; ?>

<?php if (!$live): ?>
<div class="adm-info-message-wrap adm-info-message-gray"><div class="adm-info-message">
	Действующих токенов нет — сервер сейчас <b>не отвечает никому</b>. Так и задумано:
	модуль после установки закрыт, пока доступ не выдан руками.
</div></div>
<?php endif; ?>

<form method="post" action="<?php echo htmlspecialcharsbx($APPLICATION->GetCurPage()); ?>?mid=<?php
	echo htmlspecialcharsbx($module_id); ?>&amp;lang=<?php echo LANGUAGE_ID; ?>">
<?php echo bitrix_sessid_post(); ?>
<?php $tabs->Begin(); ?>

<?php $tabs->BeginNextTab(); ?>
	<tr><td colspan="2">
		<p>Адрес сервера: <code style="user-select:all"><?php echo htmlspecialcharsbx($endpoint); ?></code></p>
		<p style="color:#777">Токен передаётся заголовком <code>Authorization: Bearer …</code>.
			В адресе его не принимаем: адреса оседают в логах веб-сервера, в истории
			браузера и в реферере, заголовки — нет.</p>
		<table class="internal" style="width:100%">
			<tr class="heading">
				<td>ID</td><td>Название</td><td>Хвост</td><td>Состояние</td>
				<td>Действует до</td><td>Последний вызов</td><td>Вызовов</td><td>&nbsp;</td>
			</tr>
			<?php if (!$tokens): ?>
				<tr><td colspan="8" style="text-align:center;color:#777">Токенов нет</td></tr>
			<?php endif; ?>
			<?php foreach ($tokens as $t):
				$why = \Itb\Mcp\Token::why($t, time()); ?>
			<tr>
				<td><?php echo (int)$t['ID']; ?></td>
				<td><?php echo htmlspecialcharsbx((string)$t['TITLE']); ?></td>
				<td><code>…<?php echo htmlspecialcharsbx((string)$t['HINT']); ?></code></td>
				<td><?php echo $why === null
					? '<b style="color:#1a7f37">действует</b>'
					: '<span style="color:#c0392b">' . htmlspecialcharsbx($why) . '</span>'; ?></td>
				<td><?php echo $t['EXPIRES_AT'] ? htmlspecialcharsbx((string)$t['EXPIRES_AT']) : 'бессрочно'; ?></td>
				<td><?php echo $t['LAST_USED_AT'] ? htmlspecialcharsbx((string)$t['LAST_USED_AT']) : '—'; ?></td>
				<td><?php echo (int)$t['USE_COUNT']; ?></td>
				<td><?php if ($canWrite): ?>
					<button type="submit" name="act" value="revoke:<?php echo (int)$t['ID']; ?>">отозвать</button>
					<button type="submit" name="act" value="drop:<?php echo (int)$t['ID']; ?>">удалить</button>
				<?php endif; ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
	</td></tr>
	<?php if ($canWrite): ?>
	<tr class="heading"><td colspan="2">Выпустить токен</td></tr>
	<tr>
		<td width="40%">Название (для себя):</td>
		<td><input type="text" name="title" size="40" value="рабочая машина"></td>
	</tr>
	<tr>
		<td>Действует до:</td>
		<td><?php
			// ⚠️ Полгода по умолчанию, а не «бессрочно». Бессрочный токен никто
			// потом не отзывает — он просто остаётся жить, и через год уже никто
			// не помнит, чья это машина и нужен ли он ещё. Срок заставляет
			// вспомнить об этом хотя бы дважды в год; очистить поле по-прежнему
			// можно, и тогда токен бессрочный.
			$defExpires = date('d.m.Y', strtotime('+6 months'));
			// Календарь Битрикса, если он доступен: своё поле ввода даты в админке
			// выглядит чужеродно и ведёт себя иначе, чем все соседние.
			if (class_exists('CAdminCalendar')) {
				echo CAdminCalendar::CalendarDate('expires', $defExpires, 12, false);
			} else {
				echo '<input type="text" name="expires" size="12" value="'
					. htmlspecialcharsbx($defExpires) . '">';
			}
		?> <span style="color:#777">очистите поле — токен станет бессрочным</span></td>
	</tr>
	<tr>
		<td>&nbsp;</td>
		<td><button type="submit" name="act" value="issue">Выпустить</button>
			<span style="color:#777">значение покажется один раз</span></td>
	</tr>
	<?php endif; ?>

<?php $tabs->BeginNextTab(); ?>
	<tr>
		<td width="40%">Разрешённые Origin (по одному в строке):</td>
		<td><textarea name="origins" rows="3" cols="50"><?php
			echo htmlspecialcharsbx($origins); ?></textarea></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Пусто — <b>браузерам нельзя вовсе</b>, и это верное значение по умолчанию.
		Обычный MCP-клиент заголовок <code>Origin</code> не шлёт и проверку проходит;
		заголовок появляется там, где запрос делает браузер. Проверка требуется
		спецификацией: без неё чужая страница может ходить сюда от имени того, кто
		её открыл.
	</td></tr>
	<tr>
		<td>Хранить журнал, дней (0 — вечно):</td>
		<td><input type="text" name="log_days" size="6" value="<?php echo $logDays; ?>"></td>
	</tr>
	<?php if ($canWrite): ?>
	<tr><td>&nbsp;</td><td><button type="submit" name="act" value="save">Сохранить</button></td></tr>
	<?php endif; ?>

<?php $tabs->BeginNextTab(); ?>
	<tr><td colspan="2">
		<table class="internal" style="width:100%">
			<tr class="heading">
				<td>Когда</td><td>Токен</td><td>IP</td><td>Метод</td><td>Инструмент</td>
				<td>Код</td><td>мс</td><td>Ответ</td><td>Ошибка</td>
			</tr>
			<?php if (!$log): ?>
				<tr><td colspan="9" style="text-align:center;color:#777">Обращений ещё не было</td></tr>
			<?php endif; ?>
			<?php foreach ($log as $l): ?>
			<tr>
				<td><?php echo htmlspecialcharsbx((string)$l['CREATED_AT']); ?></td>
				<td><?php echo (int)$l['TOKEN_ID'] ?: '—'; ?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['IP']); ?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['RPC_METHOD']); ?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['TOOL']); ?></td>
				<td<?php echo (int)$l['HTTP_STATUS'] >= 400 ? ' style="color:#c0392b"' : ''; ?>><?php
					echo (int)$l['HTTP_STATUS']; ?></td>
				<td><?php echo (int)$l['MS']; ?></td>
				<td><?php echo (int)$l['SIZE']; ?></td>
				<td style="font-size:11px"><?php echo htmlspecialcharsbx((string)$l['ERROR']); ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
		<p style="color:#777">Пишется каждый запрос, включая отвергнутые: перебор токенов
			и чужой <code>Origin</code> видны только в отказах.</p>
	</td></tr>

<?php $tabs->End(); ?>
</form>
