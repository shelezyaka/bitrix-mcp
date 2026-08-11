<?php
namespace Itb\Mcp;

use Itb\Mcp\Orm\TokenTable;

/**
 * Выпуск, проверка и отзыв токенов.
 *
 * ⚠️ Решающая часть — `why()` — намеренно ЧИСТАЯ: строка таблицы и время на
 * входе, причина отказа на выходе. Просрочку, отзыв и белый список можно
 * прогнать из консоли, не поднимая ни базу, ни Битрикс. Ошибка здесь означает
 * открытый доступ, и проверять её вручную «а давайте подождём до завтра, когда
 * токен протухнет» — не проверка.
 */
class Token
{
	/**
	 * Опознаваемый префикс.
	 *
	 * ⚠️ Нужен не для красоты: по нему секрет находится поиском в логах, чатах и
	 * репозиториях — и своими силами, и сервисами, которые ищут утечки. Токен без
	 * префикса выглядит как случайная строка и лежит незамеченным.
	 */
	const PREFIX = 'bxmcp_';

	/** 32 байта случайности. Перебирать нечего, поэтому хеш быстрый (sha256). */
	const BYTES = 32;

	public static function hash(string $token): string
	{
		return hash('sha256', $token);
	}

	/** @return array{token:string,hash:string,hint:string} */
	public static function generate(): array
	{
		// ⚠️ random_bytes, а не rand/uniqid: те предсказуемы, и токен, выведенный
		// из времени, подбирается по времени выпуска.
		$raw   = rtrim(strtr(base64_encode(random_bytes(self::BYTES)), '+/', '-_'), '=');
		$token = self::PREFIX . $raw;

		return ['token' => $token, 'hash' => self::hash($token), 'hint' => substr($raw, -6)];
	}

	/**
	 * Почему токен не годится. null — годится.
	 *
	 * @param array $row строка TokenTable
	 * @param int   $now метка времени (передаётся, чтобы прогонять просрочку тестом)
	 */
	public static function why(array $row, int $now): ?string
	{
		if (($row['ACTIVE'] ?? 'N') !== 'Y') { return 'токен отозван'; }

		$ts = self::expiresTs($row);
		if ($ts === false) {
			// ⚠️⚠️ Срок стоит, но прочитать его не удалось. Единственный
			// допустимый исход — считать токен просроченным. Обратное («не
			// разобрали — значит бессрочный») превращает испорченное значение
			// в вечный доступ, и заметить это будет негде.
			return 'срок действия не читается';
		}
		// ⚠️ Строго «прошло»: ровно в момент истечения токен ещё живой. Иначе срок
		// «до 12:00» кончался бы в 11:59:59 у одних часов и в 12:00:00 у других.
		if ($ts !== null && $ts < $now) { return 'срок действия истёк'; }

		return null;
	}

	/**
	 * Метка времени окончания: null — бессрочно, false — значение испорчено.
	 *
	 * ⚠️ Три исхода, а не два. «Бессрочно» и «не разобрали» обязаны различаться:
	 * свести их к одному null — значит однажды пустить просроченный токен.
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
			// ⚠️ Формат «ДД.ММ.ГГГГ» разбираем сами: strtotime понимает точку как
			// разделитель по-разному в зависимости от локали сборки PHP, и
			// «01.02.2027» может оказаться февралём, а может январём.
			if (preg_match('~^(\d{2})\.(\d{2})\.(\d{4})(?:\s+(\d{2}):(\d{2})(?::(\d{2}))?)?$~', trim($exp), $m)) {
				return mktime((int)($m[4] ?? 0), (int)($m[5] ?? 0), (int)($m[6] ?? 0),
					(int)$m[2], (int)$m[1], (int)$m[3]);
			}
			$t = strtotime($exp);
			if ($t !== false) { return $t; }
		}

		return false;
	}

	/**
	 * Белый список инструментов токена: null — всё разрешённое настройкой сайта.
	 *
	 * ⚠️ Пустая строка и пустой список означают РАЗНОЕ, и это не придирка. Пустая
	 * строка — «ограничений нет» (так заводится обычный токен). Пустой список,
	 * если его когда-нибудь запишут, — «не разрешено ничего», и молча превратить
	 * его в «разрешено всё» значит открыть доступ на ровном месте.
	 */
	public static function allowed(array $row): ?array
	{
		$raw = trim((string)($row['TOOLS'] ?? ''));
		if ($raw === '') { return null; }

		$list = json_decode($raw, true);
		if (!is_array($list)) {
			// Строка вида «a, b, c» — так удобнее вводить руками в админке.
			$list = array_filter(array_map('trim', explode(',', str_replace("\n", ',', $raw))));
		}

		return array_values(array_map('strval', $list));
	}

	// ── Работа с базой ──────────────────────────────────────────────────────

	public static function findByToken(string $token): ?array
	{
		if ($token === '') { return null; }

		$row = TokenTable::getList([
			'filter' => ['=TOKEN_HASH' => self::hash($token)],
			'limit'  => 1,
		])->fetch();
		if (!$row) { return null; }

		// ⚠️ Выборка идёт уже по хешу, так что сверять как будто нечего. Сверяем
		// всё равно и через hash_equals: если однажды выборка станет мягче
		// (регистр, LIKE, коллация), проверка останется строгой и постоянной по
		// времени.
		return hash_equals((string)$row['TOKEN_HASH'], self::hash($token)) ? $row : null;
	}

	/**
	 * Выпуск. Возвращает ОТКРЫТЫЙ токен — единственный раз за его жизнь.
	 *
	 * @return array{id:int,token:string}
	 */
	public static function issue(string $title, ?string $expires = null, ?array $tools = null,
		int $userId = 0): array
	{
		$g = self::generate();

		$r = TokenTable::add([
			'TITLE'      => $title !== '' ? $title : 'без названия',
			'TOKEN_HASH' => $g['hash'],
			'HINT'       => $g['hint'],
			'USER_ID'    => $userId,
			'TOOLS'      => $tools === null ? '' : json_encode(array_values($tools)),
			'ACTIVE'     => 'Y',
			'EXPIRES_AT' => ($expires !== null && $expires !== '')
				? new \Bitrix\Main\Type\DateTime($expires, 'd.m.Y') : null,
			'CREATED_AT' => new \Bitrix\Main\Type\DateTime(),
			'CREATED_BY' => $userId,
			'USE_COUNT'  => 0,
		]);

		return ['id' => (int)$r->getId(), 'token' => $g['token']];
	}

	public static function revoke(int $id): void
	{
		TokenTable::update($id, ['ACTIVE' => 'N']);
	}

	public static function drop(int $id): void
	{
		TokenTable::delete($id);
	}

	/** Отметка об использовании — по ней видно мёртвые токены, которые пора убрать. */
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
