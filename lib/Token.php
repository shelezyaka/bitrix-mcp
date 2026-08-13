<?php
namespace Itb\Mcp;

use Itb\Mcp\Orm\TokenTable;

/**
 * Выпуск, проверка и отзыв токенов.
 * Решающая часть (why, expiresTs, allowed) не трогает базу — см. tests/tokens.php.
 */
class Token
{
	/** Опознаваемый префикс: по нему утёкший токен находится поиском в логах. */
	const PREFIX = 'bxmcp_';

	const BYTES = 32;

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/** @return array{token:string,hash:string,hint:string} */
	public static function generate(): array
	{
		$raw   = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
		$token = self::PREFIX . $raw;

		return ['token' => $token, 'hash' => self::hash($token), 'hint' => substr($raw, -6)];
	}

	/**
	 * Почему токен не годится. null — годится.
	 * $now передаётся параметром, чтобы просрочку можно было прогнать тестом.
	 */
	public static function why(array $row, int $now): ?string
	{
		if (($row['ACTIVE'] ?? 'N') !== 'Y') { return 'токен отозван'; }

		$ts = self::expiresTs($row);
		// Испорченное значение закрывает доступ: «не разобрали — значит бессрочный»
		// превратило бы мусор в поле в вечный токен.
		if ($ts === false) { return 'срок действия не читается'; }
		// Строго «прошло»: в момент истечения токен ещё живой.
		if ($ts !== null && $ts < $now) { return 'срок действия истёк'; }

		return null;
	}

	/**
	 * Метка окончания: null — бессрочно, false — значение испорчено.
	 * @return int|null|false
	 */
	public static function expiresTs(array $row)
	{
		$exp = $row['EXPIRES_AT'] ?? null;
		if ($exp === null || $exp === '') { return null; }

		if (is_object($exp) && method_exists($exp, 'getTimestamp')) { return (int)$exp->getTimestamp(); }
		if (is_int($exp)) { return $exp; }
		if (is_string($exp) && ctype_digit($exp)) { return (int)$exp; }

		if (is_string($exp)) {
			// ДД.ММ.ГГГГ разбираем сами: strtotime трактует точку по-разному.
			if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$~', trim($exp), $m)) {
				[$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
				// checkdate обязателен: mktime не проверяет диапазоны, а пересчитывает,
				// и «31.31.2027» стало бы июлем 2029.
				if (!checkdate($mo, $d, $y)) { return false; }

				$h = (int)($m[4] ?? 0); $i = (int)($m[5] ?? 0); $s = (int)($m[6] ?? 0);
				if ($h > 23 || $i > 59 || $s > 59) { return false; }

				return mktime($h, $i, $s, $mo, $d, $y);
			}
			$t = strtotime($exp);
			if ($t !== false) { return $t; }
		}

		return false;
	}

	/**
	 * Группы инструментов токена. Пусто — НИ ОДНОЙ: остаётся только site_info.
	 *
	 * Права выдаются перечислением, а не умолчанием. Иначе включение новой
	 * группы в настройках сайта молча расширяло бы уже выданные токены — тот,
	 * кому дали каталог, однажды утром получил бы и запросы к базе.
	 *
	 * @return string[]
	 */
	public static function groups(array $row): array
	{
		return self::listOf((string)($row['TOOLS'] ?? '')) ?? [];
	}

	/**
	 * Инфоблоки токена: null — весь белый список сайта.
	 * Список сужает его, но расширить не может — пересечение считает Expose.
	 */
	public static function iblocks(array $row): ?array
	{
		$list = self::listOf((string)($row['IBLOCKS'] ?? ''));
		if ($list === null) { return null; }

		$ids = [];
		foreach ($list as $v) { if ((int)$v > 0) { $ids[] = (int)$v; } }

		return $ids;
	}

	/** Разбор поля-списка: json либо перечисление через запятую и переносы. */
	private static function listOf(string $raw): ?array
	{
		$raw = trim($raw);
		if ($raw === '') { return null; }

		$list = json_decode($raw, true);
		if (!is_array($list)) {
			$list = array_filter(array_map('trim', explode(',', str_replace("\n", ',', $raw))));
		}

		return array_values(array_map('strval', $list));
	}

	/** Введённый срок → метка времени; пусто — бессрочно, мусор — отказ. */
	public static function normalizeExpires(string $raw): ?int
	{
		$raw = trim($raw);
		if ($raw === '') { return null; }

		$ts = self::expiresTs(['EXPIRES_AT' => $raw]);
		if ($ts === false || $ts === null) {
			throw new \InvalidArgumentException(
				'Дата «' . $raw . '» не разбирается. Ожидается ДД.ММ.ГГГГ, '
				. 'либо оставьте поле пустым — тогда токен бессрочный.');
		}

		return $ts;
	}

	// ── База ────────────────────────────────────────────────────────────────

	public static function findByToken(string $token): ?array
	{
		if ($token === '') { return null; }

		$row = TokenTable::getList([
			'filter' => ['=TOKEN_HASH' => self::hash($token)],
			'limit'  => 1,
		])->fetch();
		if (!$row) { return null; }

		return hash_equals((string)$row['TOKEN_HASH'], self::hash($token)) ? $row : null;
	}

	/**
	 * Выпуск. Открытый токен возвращается единственный раз за его жизнь.
	 * @return array{id:int,token:string}
	 */
	public static function issue(string $title, ?string $expires = null, ?array $groups = null,
		int $userId = 0, ?array $iblocks = null): array
	{
		$g  = self::generate();
		$ts = self::normalizeExpires((string)$expires);

		$row = [
			'TITLE'      => $title !== '' ? $title : 'без названия',
			'TOKEN_HASH' => $g['hash'],
			'HINT'       => $g['hint'],
			'USER_ID'    => $userId,
			'TOOLS'      => $groups === null ? '' : json_encode(array_values($groups)),
			'IBLOCKS'    => $iblocks === null ? '' : json_encode(array_values(array_map('intval', $iblocks))),
			'ACTIVE'     => 'Y',
			'CREATED_AT' => new \Bitrix\Main\Type\DateTime(),
			'CREATED_BY' => $userId,
			'USE_COUNT'  => 0,
		];
		// Бессрочный — это отсутствие поля, а не поле со значением null: колонку
		// могли создать NOT NULL, и явный null её роняет.
		if ($ts !== null) {
			$row['EXPIRES_AT'] = \Bitrix\Main\Type\DateTime::createFromTimestamp($ts);
		}

		$r = TokenTable::add($row);

		return ['id' => (int)$r->getId(), 'token' => $g['token']];
	}

	/** Смена прав уже выпущенного токена. null — «без ограничения». */
	public static function setRights(int $id, ?array $groups, ?array $iblocks): void
	{
		TokenTable::update($id, [
			'TOOLS'   => $groups === null ? '' : json_encode(array_values($groups)),
			'IBLOCKS' => $iblocks === null ? '' : json_encode(array_values(array_map('intval', $iblocks))),
		]);
	}

	public static function revoke(int $id): void
	{
		TokenTable::update($id, ['ACTIVE' => 'N']);
	}

	public static function drop(int $id): void
	{
		TokenTable::delete($id);
	}

	public static function touch(array $row): void
	{
		TokenTable::update((int)$row['ID'], [
			'LAST_USED_AT' => new \Bitrix\Main\Type\DateTime(),
			'USE_COUNT'    => (int)($row['USE_COUNT'] ?? 0) + 1,
		]);
	}

	public static function all(): array
	{
		return TokenTable::getList(['order' => ['ID' => 'DESC']])->fetchAll();
	}
}
