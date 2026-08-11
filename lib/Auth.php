<?php
namespace Itb\Mcp;

/**
 * Токен → набор инструментов, доступных этому токену.
 * Возвращается реестр, а не «да/нет»: запрет держится на составе реестра.
 */
class Auth
{
	public static function registryFor(string $token): Registry
	{
		$row = Token::findByToken($token);
		if ($row === null) {
			// В журнал причина, наружу — общий отказ.
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
