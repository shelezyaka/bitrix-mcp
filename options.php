<?php
/**
 * Настройки модуля: токены, белый список инфоблоков, журнал.
 * Настройки → Настройки продукта → Настройки модулей → MCP-сервер.
 *
 * Инлайновых скриптов нет — только формы: проактивная защита Битрикса вырезает
 * <script>, если внутри встретилось значение из запроса.
 *
 * Действие и объект едут одной парой (act=revoke:12). Форма одна на страницу,
 * поэтому отдельное скрытое поле id в каждой строке отправлялось бы целиком,
 * и «отозвать» у первого токена отозвало бы последний.
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

/**
 * Строка «18, 21» → список идентификаторов; пусто → null («без сужения»).
 * Пустой список и «без ограничения» — разные вещи, поэтому null, а не [].
 */
function itbMcpIblockList(string $raw): ?array
{
	$raw = trim($raw);
	if ($raw === '') { return null; }

	$ids = [];
	foreach (preg_split('~[^0-9]+~', $raw) as $v) {
		if ((int)$v > 0) { $ids[] = (int)$v; }
	}

	return $ids;
}

// Схема сверяется и здесь, а не только при установке: иначе правку структуры
// получали бы только новые сайты.
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
			Option::set($module_id, 'rate_limit', max(0, (int)($_POST['rate_limit'] ?? 120)));
			Option::set($module_id, 'api', empty($_POST['api']) ? 'N' : 'Y');
			Option::set($module_id, 'orders', empty($_POST['orders']) ? 'N' : 'Y');
			Option::set($module_id, 'reports', empty($_POST['reports']) ? 'N' : 'Y');
			Option::set($module_id, 'files', empty($_POST['files']) ? 'N' : 'Y');
			// Папки нормализуем тем же разбором, что и проверка пути: в настройке
			// должно лежать ровно то, что будет действовать.
			Option::set($module_id, 'files_dirs',
				implode(', ', \Itb\Mcp\Path::parse((string)($_POST['files_dirs'] ?? ''))));
			Option::set($module_id, 'sql', empty($_POST['sql']) ? 'N' : 'Y');
			// Белый список нормализуем тем же разбором, что и проверка запроса:
			// иначе в настройке может лежать одно, а действовать другое.
			Option::set($module_id, 'sql_tables',
				implode(', ', \Itb\Mcp\Sql::parse((string)($_POST['sql_tables'] ?? ''))));
			Option::set($module_id, 'engine', empty($_POST['engine']) ? 'legacy' : 'orm');
			$msg = 'Настройки сохранены.';
		} elseif ($act === 'issue') {
			$r = \Itb\Mcp\Token::issue(
				trim((string)($_POST['title'] ?? '')),
				trim((string)($_POST['expires'] ?? '')),
				array_values((array)($_POST['groups'] ?? [])),
				(int)$GLOBALS['USER']->GetID(),
				itbMcpIblockList((string)($_POST['tok_iblocks'] ?? ''))
			);
			// Единственный момент за всю жизнь токена, когда его видно: в базе
			// лежит только sha256, показать повторно нельзя ни нам, ни владельцу.
			$freshToken = $r['token'];
		} elseif ($act === 'rights') {
			$id = (int)$arg;
			\Itb\Mcp\Token::setRights(
				$id,
				array_values((array)($_POST['g'][$id] ?? [])),
				itbMcpIblockList((string)($_POST['ib_tok'][$id] ?? ''))
			);
			$msg = 'Права токена ' . $id . ' изменены.';
		} elseif ($act === 'expose') {
			// Список собирается заново из отмеченных галочек: снятая галочка
			// обязана закрывать доступ, а не требовать отдельной ветки кода.
			$map = [];
			foreach ((array)($_POST['ib'] ?? []) as $id) {
				$id = (int)$id;
				if ($id > 0) { $map[$id] = ['props' => (string)($_POST['props'][$id] ?? '')]; }
			}
			\Itb\Mcp\Expose::save($map);
			$msg = $map
				? ('Открыто инфоблоков: ' . count($map) . '. Клиенту нужно перечитать список'
					. ' инструментов — обычно это переподключение.')
				: 'Все инфоблоки закрыты. Доступен только site_info.';
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

// Все инфоблоки сайта, сгруппированные по типу — для вкладки «Данные».
$exposed = \Itb\Mcp\Expose::all();
$iblocks = [];
if (\Bitrix\Main\Loader::includeModule('iblock')) {
	$rs = CIBlock::GetList(['IBLOCK_TYPE' => 'ASC', 'NAME' => 'ASC'], ['CHECK_PERMISSIONS' => 'N']);
	while ($ib = $rs->Fetch()) {
		$iblocks[(string)$ib['IBLOCK_TYPE_ID']][] = $ib;
	}
}

// Отметить можно только включённую группу: у выключенной инструментов нет,
// и право на неё — обещание, которое некому исполнить.
$liveGroups = \Itb\Mcp\Tools::enabled();

$tabs = new CAdminTabControl('itbMcpTabs', [
	['DIV' => 'tokens',   'TAB' => 'Токены',    'TITLE' => 'Доступ к MCP-серверу'],
	['DIV' => 'data',     'TAB' => 'Данные',    'TITLE' => 'Какие инфоблоки разрешено читать'],
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
				<td>Действует до</td><td>Вызовов</td><td>Что можно</td><td>&nbsp;</td>
			</tr>
			<?php if (!$tokens): ?>
				<tr><td colspan="8" style="text-align:center;color:#777">Токенов нет</td></tr>
			<?php endif; ?>
			<?php foreach ($tokens as $t):
				$tid = (int)$t['ID'];
				$why = \Itb\Mcp\Token::why($t, time());
				$gr  = \Itb\Mcp\Token::groups($t);
				$ibl = \Itb\Mcp\Token::iblocks($t); ?>
			<tr>
				<td><?php echo $tid; ?></td>
				<td><?php echo htmlspecialcharsbx((string)$t['TITLE']); ?></td>
				<td><code>…<?php echo htmlspecialcharsbx((string)$t['HINT']); ?></code></td>
				<td><?php echo $why === null
					? '<b style="color:#1a7f37">действует</b>'
					: '<span style="color:#c0392b">' . htmlspecialcharsbx($why) . '</span>'; ?></td>
				<td><?php echo $t['EXPIRES_AT'] ? htmlspecialcharsbx((string)$t['EXPIRES_AT']) : 'бессрочно'; ?></td>
				<td><?php echo (int)$t['USE_COUNT']; ?></td>
				<td style="white-space:nowrap">
					<?php foreach (\Itb\Mcp\Tools::GROUPS as $key => $label):
						$live = in_array($key, $liveGroups, true); ?>
					<label title="<?php echo htmlspecialcharsbx($label
							. ($live ? '' : ' — группа выключена в настройках модуля')); ?>"<?php
						echo $live ? '' : ' style="color:#aaa"'; ?>><input type="checkbox"
						name="g[<?php echo $tid; ?>][]" value="<?php echo htmlspecialcharsbx($key); ?>"<?php
						echo in_array($key, $gr, true) ? ' checked' : '';
						echo $live ? '' : ' disabled'; ?>>
						<?php echo htmlspecialcharsbx(\Itb\Mcp\Tools::GROUP_SHORT[$key] ?? $key); ?></label>
					<?php endforeach; ?>
					<br><input type="text" name="ib_tok[<?php echo $tid; ?>]" size="16"
						value="<?php echo $ibl === null ? '' : htmlspecialcharsbx(implode(', ', $ibl)); ?>"
						placeholder="инфоблоки: все">
				</td>
				<td style="white-space:nowrap"><?php if ($canWrite): ?>
					<button type="submit" name="act" value="rights:<?php echo $tid; ?>">изменить</button>
					<button type="submit" name="act" value="revoke:<?php echo $tid; ?>">отозвать</button>
					<button type="submit" name="act" value="drop:<?php echo $tid; ?>">удалить</button>
				<?php endif; ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
		<p style="color:#777">Права выдаются перечислением: сняты все галки — токену доступен
			только <code>site_info</code>. Поэтому включение новой группы в настройках
			<b>не расширяет</b> уже выданные токены — её нужно отметить здесь и нажать
			«права».</p>
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
			// Полгода по умолчанию: бессрочный токен никто потом не отзывает.
			$defExpires = date('d.m.Y', strtotime('+6 months'));
			if (class_exists('CAdminCalendar')) {
				echo CAdminCalendar::CalendarDate('expires', $defExpires, 12, false);
			} else {
				echo '<input type="text" name="expires" size="12" value="'
					. htmlspecialcharsbx($defExpires) . '">';
			}
		?> <span style="color:#777">очистите поле — токен станет бессрочным</span></td>
	</tr>
	<tr>
		<td>Что разрешить:</td>
		<td><?php foreach (\Itb\Mcp\Tools::GROUPS as $key => $label):
			$live = in_array($key, $liveGroups, true); ?>
			<label style="display:block<?php echo $live ? '' : ';color:#aaa'; ?>"><input
				type="checkbox" name="groups[]"
				value="<?php echo htmlspecialcharsbx($key); ?>"<?php
				echo $live ? ' checked' : ' disabled'; ?>>
				<?php echo htmlspecialcharsbx($label);
				echo $live ? '' : ' <b>— выключена в настройках модуля</b>'; ?></label>
		<?php endforeach; ?>
		<?php if (!$liveGroups): ?>
			<b style="color:#c0392b">Ни одна группа не включена. Откройте инфоблоки на вкладке
				«Данные» либо включите нужные группы на вкладке «Настройки» — иначе токену
				будет доступен только <code>site_info</code>.</b>
		<?php else: ?>
		<span style="color:#777">снимите все — останется только <code>site_info</code></span>
		<?php endif; ?></td>
	</tr>
	<tr>
		<td>Инфоблоки (через запятую, пусто — все открытые):</td>
		<td><input type="text" name="tok_iblocks" size="30" placeholder="например 18, 21">
			<span style="color:#777">сужает белый список сайта, расширить не может</span></td>
	</tr>
	<tr>
		<td>&nbsp;</td>
		<td><button type="submit" name="act" value="issue">Выпустить</button>
			<span style="color:#777">значение покажется один раз</span></td>
	</tr>
	<?php endif; ?>

<?php $tabs->BeginNextTab(); ?>
	<tr><td colspan="2">
		<p>Отмеченные инфоблоки модуль вправе <b>читать</b>. Что не отмечено — для него
			не существует: инструменты откажутся работать с чужим идентификатором и
			перечислят, что доступно.</p>
		<p style="color:#777">Поле «свойства» — необязательное сужение. Пусто — отдаются все
			свойства инфоблока. Через запятую — только перечисленные коды
			(например <code>CML2_ARTICLE, METALL, VSTAVKI</code>).</p>
		<p style="color:#c60">⚠️ Инфоблоки с персональными данными — заказы, обращения,
			подписки — открывать не нужно: их читает не человек, а модель, и её ответы
			уходят за пределы сайта.</p>
		<table class="internal" style="width:100%">
			<tr class="heading">
				<td width="60">Читать</td><td width="60">ID</td><td>Инфоблок</td>
				<td width="120">Код</td><td>Только эти свойства</td>
			</tr>
			<?php if (!$iblocks): ?>
				<tr><td colspan="5" style="text-align:center;color:#777">Инфоблоков не найдено
					(модуль iblock не подключён?)</td></tr>
			<?php endif; ?>
			<?php foreach ($iblocks as $type => $list): ?>
				<tr><td colspan="5" style="background:#f2f4f7"><b>Тип: <?php
					echo htmlspecialcharsbx((string)$type); ?></b></td></tr>
				<?php foreach ($list as $ib):
					$id = (int)$ib['ID'];
					$on = array_key_exists($id, $exposed);
					$pr = $on && $exposed[$id]['props'] !== null
						? implode(', ', $exposed[$id]['props']) : ''; ?>
				<tr<?php echo $on ? ' style="background:#eefaf0"' : ''; ?>>
					<td style="text-align:center"><input type="checkbox" name="ib[]"
						value="<?php echo $id; ?>"<?php echo $on ? ' checked' : ''; ?>></td>
					<td><?php echo $id; ?></td>
					<td><?php echo htmlspecialcharsbx((string)$ib['NAME']); ?></td>
					<td><code><?php echo htmlspecialcharsbx((string)$ib['CODE']); ?></code></td>
					<td><input type="text" name="props[<?php echo $id; ?>]" size="45"
						value="<?php echo htmlspecialcharsbx($pr); ?>"
						placeholder="пусто — все свойства"></td>
				</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
		</table>
	</td></tr>
	<?php if ($canWrite): ?>
	<tr><td>&nbsp;</td><td><button type="submit" name="act" value="expose">Сохранить список</button>
		<span style="color:#777">после изменения клиент должен перечитать инструменты</span></td></tr>
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
	<tr>
		<td>Запросов с одного IP в минуту (0 — без ограничения):</td>
		<td><input type="text" name="rate_limit" size="6" value="<?php
			echo (int)Option::get($module_id, 'rate_limit', 120); ?>"></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Сверх нормы отвечаем <code>429</code> с заголовком <code>Retry-After</code>,
		не доходя ни до токена, ни до базы каталога. В журнал пишется только первое
		превышение в минуту — иначе запись о наплыве обошлась бы дороже самого наплыва.<br>
		⚠️ Настоящий DDoS этим не остановить: веб-сервер и загрузка ядра Битрикса
		происходят <b>до</b> кода модуля. От потока запросов защищает nginx или Apache
		перед сайтом; здесь мы лишь не даём наплыву дёргать базу и инструменты.
	</td></tr>
	<tr class="heading"><td colspan="2">Чтение каталога</td></tr>
	<tr>
		<td>Читать через ORM (D7):</td>
		<td><input type="checkbox" name="engine" value="orm"<?php
			echo Option::get($module_id, 'engine', 'orm') === 'orm' ? ' checked' : ''; ?>>
			свойства приходят вместе с элементами, а не запросом на каждый</td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Включено по умолчанию. Снятая галка возвращает прежний путь
		(<code>CIBlockElement</code>): он делает отдельный запрос за свойствами на
		каждый элемент, зато отбор по разделу учитывает все привязки, а не только
		основной раздел. У инфоблоков без <code>API_CODE</code> модуль откатывается
		на него сам. В ответе видно, каким путём получены данные: поле
		<code>engine</code>.
	</td></tr>
	<tr class="heading"><td colspan="2">Заказы</td></tr>
	<?php $hasSale = \Bitrix\Main\ModuleManager::isModuleInstalled('sale'); ?>
	<tr>
		<td>Разрешить читать заказы:</td>
		<td><input type="checkbox" name="orders" value="Y"<?php
			echo Option::get($module_id, 'orders', 'N') === 'Y' ? ' checked' : '';
			echo $hasSale ? '' : ' disabled'; ?>>
			добавляет <code>order_search</code>, <code>order_get</code>,
			<code>order_statuses</code>, <code>order_stats</code>, <code>user_get</code>
			<?php if (!$hasSale): ?>
			<br><b style="color:#c0392b">Модуль «Интернет-магазин» (sale) на этом сайте
				не установлен — заказов нет, включать нечего.</b>
			<?php endif; ?></td>
	</tr>
	<tr><td colspan="2" style="color:#c60">
		⚠️ В свойствах заказа лежат <b>персональные данные покупателей</b>: имя, телефон,
		адрес доставки. То же самое отдаёт <code>user_get</code> — карточку покупателя
		целиком. Они уйдут в ответ, а значит
		за пределы сайта — модели и туда, где эта переписка хранится. Включайте, только
		если понимаете, зачем это нужно, и помните про 152-ФЗ. По умолчанию выключено.
	</td></tr>
	<tr class="heading"><td colspan="2">Отчёты по продажам</td></tr>
	<tr>
		<td>Разрешить отчёты:</td>
		<td><input type="checkbox" name="reports" value="Y"<?php
			echo Option::get($module_id, 'reports', 'N') === 'Y' ? ' checked' : '';
			echo $hasSale ? '' : ' disabled'; ?>>
			добавляет <code>sales_report</code>, <code>top_products</code>,
			<code>abandoned_carts</code></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Динамика заказов по дням, неделям и месяцам, что продавалось и что осталось
		в брошенных корзинах. <b>Персональных данных здесь нет</b> — только итоги,
		поэтому группу можно выдать тому, кому карточки заказов открывать не нужно.
		Выручка считается по ценам в корзине, то есть со скидками.
	</td></tr>
	<tr class="heading"><td colspan="2">Разведка API</td></tr>
	<tr>
		<td>Разрешить читать устройство кода:</td>
		<td><input type="checkbox" name="api" value="Y"<?php
			echo Option::get($module_id, 'api', 'N') === 'Y' ? ' checked' : ''; ?>>
			добавляет <code>api_modules</code>, <code>api_class</code>,
			<code>api_entity</code>, <code>api_source</code>, <code>api_find_class</code>,
			<code>api_function</code>, <code>api_events</code>, <code>api_agents</code>,
			<code>hl_list</code></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Группа отвечает на вопрос «что за классы есть в этой установке и как они
		устроены»: список модулей с версиями, сигнатуры методов, поля ORM-сущностей,
		исходники классов и методов. Нужна, чтобы писать код под ваш сайт по фактам,
		а не по памяти — версии Битрикса различаются, и метод из документации здесь
		может отсутствовать.<br>
		<b>Данных сайта эта группа не отдаёт</b>: ни товаров, ни заказов, ни настроек.
		Выполнить что-либо через неё тоже нельзя — только чтение через рефлексию.<br>
		⚠️ Исходники — это <b>любой код сайта</b>, включая ваш собственный. Ограничение
		не в списке папок, а в способе: путь всегда даёт рефлексия по существующему
		классу, поэтому произвольный файл не прочитать, а файл без класса
		(<code>.env</code>, конфиги, выгрузки) — не прочитать вовсе. Но ключи,
		записанные прямо в коде класса, станут видны. По умолчанию группа выключена.
	</td></tr>
	<tr class="heading"><td colspan="2">Файлы проекта</td></tr>
	<tr>
		<td>Разрешить чтение файлов:</td>
		<td><input type="checkbox" name="files" value="Y"<?php
			echo Option::get($module_id, 'files', 'N') === 'Y' ? ' checked' : ''; ?>>
			добавляет <code>file_read</code>, <code>file_list</code>, <code>file_grep</code></td>
	</tr>
	<tr>
		<td>Дополнительные папки (через запятую):</td>
		<td><textarea name="files_dirs" rows="2" cols="50"><?php
			echo htmlspecialcharsbx((string)Option::get($module_id, 'files_dirs', '')); ?></textarea>
			<br><span style="color:#777">от корня сайта, например <code>adm</code> —
			если свой код лежит не в <code>/local/</code></span></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Дополняет разведку API там, где она бессильна: компоненты, шаблоны,
		<code>php_interface</code> и обычные функции классами не являются, и рефлексия
		их не видит.<br>
		Читать разрешено из <code>/local/</code>, <code>/bitrix/templates/</code>,
		<code>/bitrix/modules/*/lib/</code> и того, что перечислено выше. Разрешение идёт
		по границе папки: <code>adm</code> не открывает <code>admin</code>. Внутри
		дополнительной папки действуют те же запреты — по имени файла, по расширению и
		по вложенным <code>.git</code>, <code>node_modules</code>, <code>upload</code>.
		Остальное отвергается, включая
		<code>/bitrix/php_interface/</code>, где лежит <code>dbconn.php</code>. Отдельно
		закрыты по имени <code>dbconn.php</code>, <code>.settings.php</code>,
		<code>.env</code>, <code>.htpasswd</code> и ключи, а также папки
		<code>.git</code>, <code>node_modules</code> и <code>upload</code>. Открываются
		только текст и код — логи, дампы, архивы и картинки отсекаются по расширению.
		Путь проверяется до и после разрешения симлинков, «..» не схлопывается,
		а отвергается.<br>
		⚠️ Всё это <b>ваш исходный код</b>, и он уйдёт за пределы сайта. Ключи и пароли,
		записанные прямо в коде, станут видны — списком имён закрыты только обычные
		места их хранения. По умолчанию группа выключена.
	</td></tr>
	<tr class="heading"><td colspan="2">Запросы к базе</td></tr>
	<tr>
		<td>Разрешить произвольный SELECT:</td>
		<td><input type="checkbox" name="sql" value="Y"<?php
			echo Option::get($module_id, 'sql', 'N') === 'Y' ? ' checked' : ''; ?>>
			добавляет <code>sql_tables</code> и <code>sql_select</code></td>
	</tr>
	<tr>
		<td>Разрешённые таблицы (через запятую, пусто — все):</td>
		<td><textarea name="sql_tables" rows="2" cols="50"><?php
			echo htmlspecialcharsbx((string)Option::get($module_id, 'sql_tables', '')); ?></textarea></td>
	</tr>
	<tr><td colspan="2" style="color:#777">
		Самая сильная группа: она читает то, до чего дотягивается база, а не то, что
		перечислено в белых списках инфоблоков. Отменить её действие настройками
		каталога нельзя.<br>
		Что не пройдёт: всё, кроме <code>SELECT</code> и <code>WITH</code>; вторая
		инструкция через точку с запятой; <code>INTO OUTFILE</code>,
		<code>LOAD_FILE</code>, <code>SLEEP</code>, <code>BENCHMARK</code>,
		<code>GET_LOCK</code>; база <code>mysql</code> и список процессов. Число строк
		ограничено, предел навешивает ядро Битрикса, а не подстановка в текст запроса.<br>
		Всегда закрыты <code>b_user</code> (хеши паролей и контрольные слова),
		<code>b_option</code> (ключи модулей, пароли SMTP и эквайринга), таблицы
		авторизации и таблица токенов самого модуля. <b>Белый список их не открывает.</b>
		Данные покупателей читаются инструментом <code>user_get</code> из группы заказов.<br>
		⚠️ Всё остальное содержимое базы этой группе доступно. По умолчанию выключена.
	</td></tr>
	<?php if ($canWrite): ?>
	<tr><td>&nbsp;</td><td><button type="submit" name="act" value="save">Сохранить</button></td></tr>
	<?php endif; ?>

<?php $tabs->BeginNextTab(); ?>
	<tr><td colspan="2">
		<table class="internal" style="width:100%">
			<?php
			// Номер токена ничего не подсказывает — рядом ставим название, под
			// которым его выпускали. Удалённый токен остаётся номером: строка
			// журнала должна пережить его удаление.
			$titles = [];
			foreach ($tokens as $t) { $titles[(int)$t['ID']] = (string)$t['TITLE']; }
			?>
			<tr class="heading">
				<td>Когда</td><td>Токен</td><td>IP</td><td>Метод</td><td>Инструмент</td>
				<td>С чем позвали</td><td>Код</td><td>мс</td><td>Ответ</td><td>Ошибка</td>
			</tr>
			<?php if (!$log): ?>
				<tr><td colspan="10" style="text-align:center;color:#777">Обращений ещё не было</td></tr>
			<?php endif; ?>
			<?php foreach ($log as $l):
				$ltid = (int)$l['TOKEN_ID']; ?>
			<tr>
				<td><?php echo htmlspecialcharsbx((string)$l['CREATED_AT']); ?></td>
				<td><?php
					if (!$ltid) { echo '<span style="color:#c0392b">не опознан</span>'; }
					elseif (isset($titles[$ltid])) {
						echo $ltid . ' · <b>' . htmlspecialcharsbx($titles[$ltid]) . '</b>';
					} else {
						echo $ltid . ' <span style="color:#777">(удалён)</span>';
					}
				?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['IP']); ?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['RPC_METHOD']); ?></td>
				<td><?php echo htmlspecialcharsbx((string)$l['TOOL']); ?></td>
				<?php
				// Аргументы вызова пишутся в журнал давно, но видно их не было.
				// Для группы sql это единственное место, где виден сам запрос.
				$args = (string)$l['ARGS'];
				?>
				<td style="font-size:11px;max-width:340px;overflow:hidden"
					title="<?php echo htmlspecialcharsbx($args); ?>"><?php
					echo htmlspecialcharsbx(mb_substr($args, 0, 120))
						. (mb_strlen($args) > 120 ? '…' : ''); ?></td>
				<td<?php echo (int)$l['HTTP_STATUS'] >= 400 ? ' style="color:#c0392b"' : ''; ?>><?php
					echo (int)$l['HTTP_STATUS']; ?></td>
				<td><?php echo (int)$l['MS']; ?></td>
				<td><?php echo (int)$l['SIZE']; ?></td>
				<td style="font-size:11px"><?php echo htmlspecialcharsbx((string)$l['ERROR']); ?></td>
			</tr>
			<?php endforeach; ?>
		</table>
		<p style="color:#777">Пишется каждый запрос, включая отвергнутые: перебор токенов
			и чужой <code>Origin</code> видны только в отказах. «Не опознан» означает,
			что токен не подошёл — тогда различить обращения можно лишь по IP.
			Если разные машины показывают <b>один и тот же</b> токен, значит он у них
			общий: выпустите каждой свой, иначе отозвать доступ одной, не задев
			остальных, не получится.</p>
	</td></tr>

<?php $tabs->End(); ?>
</form>
