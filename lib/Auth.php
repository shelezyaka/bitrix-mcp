<?php
namespace Itb\Mcp;

/**
 * Токен → реестр инструментов, доступных ИМЕННО ЭТОМУ токену.
 *
 * ⚠️⚠️ Возвращается не «да/нет», а реестр. Токен, которому инструмент не
 * разрешён, попросту не видит его в `tools/list` и не может позвать: запрет
 * держится на составе реестра, а не на одной проверке где-то в глубине вызова.
 *
 * ⚠️ Токен хранится ХЕШЕМ (см. `Token`). Утечка дампа базы не должна давать
 * доступ к сайту.
 */
class Auth
{
	public static function registryFor(string $token): Registry
	{
		$row = Token::findByToken($token);
		if ($row === null) {
			// ⚠️ В журнал — «не найден», наружу — «токен не принят». Разница между
			// «нет такого» и «просрочен» помогает нам ровно так же, как тому, кто
			// подбирает: по ней видно, угадан ли токен.
			Audit::note(['ERROR' => 'токен не найден']);
			throw new AuthError('токен не принят');
		}

		$why = Token::why($row, time());
		if ($why !== null) {
			Audit::note(['TOKEN_ID' => (int)$row['ID'], 'ERROR' => $why]);
			throw new AuthError('токен не принят');
		}

		Audit::note(['TOKEN_ID' => (int)$row['ID']]);
		Token::touch($row);

		return Tools::build(Token::allowed($row));
	}
}
