<?php
namespace Itb\Mcp;

/**
 * Заявки из веб-форм (модуль form).
 *
 * В ответах формы лежит то, что писал человек: имя, телефон, почта. Поэтому
 * группа своя — заказы тому, кто читает заявки, не нужны, и наоборот.
 */
class Forms
{
	const LIMIT_DEF = 20;
	const LIMIT_MAX = 100;

	/** Какие формы есть и сколько по ним обращений. */
	public static function forms(array $a): array
	{
		$conn = self::init();

		$items = [];
		$rs = $conn->query('SELECT f.ID, f.NAME, f.SID, COUNT(r.ID) AS CNT, MAX(r.DATE_CREATE) AS LAST'
			. ' FROM b_form f LEFT JOIN b_form_result r ON r.FORM_ID = f.ID'
			. ' GROUP BY f.ID ORDER BY CNT DESC', 100);
		while ($r = $rs->fetch()) {
			$items[] = [
				'id'      => (int)$r['ID'],
				'name'    => (string)$r['NAME'],
				'code'    => (string)$r['SID'],
				'results' => (int)$r['CNT'],
				'last'    => self::date($r['LAST']),
			];
		}

		return ['total' => count($items), 'forms' => $items,
			'note' => 'Обращения по форме — form_results с её id.'];
	}

	/** Обращения за период вместе с ответами. */
	public static function results(array $a): array
	{
		$conn = self::init();

		$limit = (int)($a['limit'] ?? self::LIMIT_DEF);
		$limit = min($limit > 0 ? $limit : self::LIMIT_DEF, self::LIMIT_MAX);

		$where = [];
		if ((int)($a['form'] ?? 0) > 0) { $where[] = 'r.FORM_ID = ' . (int)$a['form']; }
		if (($a['from'] ?? '') !== '') {
			$where[] = "r.DATE_CREATE >= '" . Orders::date((string)$a['from'], false)->format('Y-m-d H:i:s') . "'";
		}
		if (($a['to'] ?? '') !== '') {
			$where[] = "r.DATE_CREATE < '" . Orders::date((string)$a['to'], true)->format('Y-m-d H:i:s') . "'";
		}

		$rows = [];
		$rs = $conn->query('SELECT r.ID, r.FORM_ID, r.DATE_CREATE, r.USER_ID, f.NAME AS FORM_NAME'
			. ' FROM b_form_result r INNER JOIN b_form f ON f.ID = r.FORM_ID'
			. ($where ? ' WHERE ' . implode(' AND ', $where) : '')
			. ' ORDER BY r.ID DESC', $limit);
		while ($r = $rs->fetch()) {
			$rows[(int)$r['ID']] = [
				'id'      => (int)$r['ID'],
				'form'    => (string)$r['FORM_NAME'],
				'form_id' => (int)$r['FORM_ID'],
				'created' => self::date($r['DATE_CREATE']),
				'user_id' => (int)$r['USER_ID'] ?: null,
				'answers' => [],
			];
		}
		if (!$rows) { return ['total' => 0, 'results' => []]; }

		// Ответы одним запросом на всю страницу, а не по обращению.
		$rs = $conn->query('SELECT a.RESULT_ID, fl.TITLE, a.ANSWER_TEXT, a.USER_TEXT'
			. ' FROM b_form_result_answer a'
			. ' INNER JOIN b_form_field fl ON fl.ID = a.FIELD_ID'
			. ' WHERE a.RESULT_ID IN (' . implode(',', array_keys($rows)) . ')'
			. ' ORDER BY a.RESULT_ID, fl.C_SORT');
		while ($r = $rs->fetch()) {
			$id = (int)$r['RESULT_ID'];
			if (!isset($rows[$id])) { continue; }
			$value = trim((string)$r['USER_TEXT']) !== '' ? $r['USER_TEXT'] : $r['ANSWER_TEXT'];
			$rows[$id]['answers'][] = ['field' => (string)$r['TITLE'], 'value' => (string)$value];
		}

		return [
			'total'   => count($rows),
			'results' => array_values($rows),
			'note'    => 'В ответах — персональные данные заявителя.',
		];
	}

	/** @return \Bitrix\Main\DB\Connection */
	private static function init()
	{
		if (!\Bitrix\Main\Loader::includeModule('form')) {
			throw new ToolError('Модуль form на этом сайте не подключён');
		}

		$conn = \Bitrix\Main\Application::getConnection();
		// На сайте с сотнями тысяч обращений сводка по формам — полный проход.
		Sql::deadline($conn);

		return $conn;
	}

	private static function date($v): ?string
	{
		if ($v instanceof \Bitrix\Main\Type\DateTime || $v instanceof \Bitrix\Main\Type\Date) {
			return $v->format('d.m.Y H:i:s');
		}

		return $v === null || $v === '' ? null : (string)$v;
	}
}
