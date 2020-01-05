<?php
// web/index.php
require_once __DIR__.'/../vendor/autoload.php';

//Oauth2 library
require_once(__DIR__.'/../oauth2/src/OAuth2/Autoloader.php');
OAuth2\Autoloader::register();

//Database library and credentials
include_once('Database.php');

//SMTP class
include_once('SMTP.php');

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;
use Silex\Application;

$db = new Database();

$app = new Silex\Application();

$app['debug'] = true;
$user["id"] = null;
$user["adm"] = false;

$storage = new OAuth2\Storage\Pdo(array('dsn' => $db->dsn(), 'username' => $db->username(), 'password' => $db->password()));

$config_refresh_token = array (
	'always_issue_new_refresh_token' => true
);
$server = new OAuth2\Server($storage);
$server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));
$server->addGrantType(new OAuth2\GrantType\UserCredentials($storage));
$server->addGrantType(new OAuth2\GrantType\RefreshToken($storage, $config_refresh_token));


// verificar autenticacao
$app->before(function(Request $request) use ($app, $db, $storage, $server) {
	global $user;
    $route = $request->get('_route');
	
	//Aqui definimos quais rotas dispensam autenticação:
	$rotaslivres[0] = "POST_auth"; //rota para autenticar, obviamente nao exige que o usuario esteja autenticado
	$rotaslivres[1] = "POST_users"; //rota para criar um novo usuário
	$rotaslivres[2] = "GET_"; //esta é a rota padrão e exibe uma mensagem de erro amigável, dispensa autenticação por isso
	$rotaslivres[3] = "GET_activate_actcode"; //esta rota serve para ativar um usuário recem registrado que recebeu o link por email
	$rotaslivres[4] = "POST_users_enviaLostPasswordLink"; // rota para recuperar link de resetar senha
	$rotaslivres[5] = "GET_lostpassword_actcode"; // rota para resetar senha
	
    if(!in_array($route,$rotaslivres) && $request->getMethod() != 'OPTIONS') {
		$authorization = $request->headers->get("Authorization");
		list($jwt) = sscanf($authorization, 'Bearer %s');
		
        if($jwt) { //foi enviado um token, verificar se ele está disponível
			//Autorização vai aqui:
			$request = OAuth2\Request::createFromGlobals();
			$response = new OAuth2\Response();
			if (!$server->verifyResourceRequest($request,$response)) {
				$resposta = $response->getParameters();
				return new Response(json_encode($resposta), 401);
			} 
			$tData = $server->getAccessTokenData(OAuth2\Request::createFromGlobals());
			
			$user['id'] = $tData['user_id'];
			
			$sql_s_u = "SELECT userActive, userNotActiveReason FROM register_users WHERE userID = '".$user['id']."';";
			$sql_s_c = "SELECT ativo FROM oauth_clients WHERE client_id = '".$tData['client_id']."';";
			
			$rows = $db ->select($sql_s_u);

			if ($rows) {
				if ($rows[0]['userActive']==0) {
					$resposta['error']="usuario_inativo";
					$resposta['error_description']=$rows[0]['userNotActiveReason'];
					return new Response(json_encode($resposta), 403);
				}
			} 
			$rows = null;
			$rows = $db ->select($sql_s_c);
			if ($rows) {
				if ($rows[0]['ativo']==0) {
					$resposta['error']="cliente_bloqueado";
					$resposta['error_description']="cliente foi bloqueado";
					return new Response(json_encode($resposta), 403);
				}
			} 
			
			$user['adm'] = (strpos($tData['scope'], 'adm') !== false);
		}
		else {
            // nao foi possivel extrair token do header Authorization
            return new Response('{"mensagem":"Token não informado"}', 403);
        } 
    }

});

function randomPassword() {
    $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';
    $pass = array(); //remember to declare $pass as an array
    $alphaLength = strlen($alphabet) - 1; //put the length -1 in cache
    for ($i = 0; $i < 8; $i++) {
        $n = rand(0, $alphaLength);
        $pass[] = $alphabet[$n];
    }
    return implode($pass); //turn the array into a string
}

function getSaldoContaID($contaID) {
	global $db;
	$sql_s = "SELECT SUM(`transacao_valor`) AS saldoConta FROM
	(SELECT `register_transacoes`.`transacao_valor` FROM `register_diarios`
	JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
	JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
	LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
	LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
	LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
	LEFT JOIN `register_contas` `contas2` ON `register_transacoes_itens`.`transf_para_conta_id` = `contas2`.`conta_id`
	WHERE `register_transacoes`.`conta_id` = '$contaID'
	UNION ALL
	SELECT -`register_transacoes_itens`.`transacoes_item_valor` AS `transacao_valor` FROM `register_diarios`
	JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
	JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
	LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
	LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
	LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
	LEFT JOIN `register_contas` `contas2` ON `register_transacoes_itens`.`transf_para_conta_id` = `contas2`.`conta_id`
	WHERE `register_transacoes_itens`.`transf_para_conta_id` IS NOT NULL AND `register_transacoes_itens`.`transf_para_conta_id` = '$contaID') AS TT2;";
	$rows = $db->select($sql_s);
	if ($rows) {
		return $rows[0]['saldoConta'];
	} else {
		return 0;
	}
}

function getDiarioID($diariouid) {
	global $db;
	$duid = $db->escape_string($diariouid);
	$sql_diario = "SELECT `id` AS `diario_id`, `user_id` FROM `register_diarios` WHERE `uid` = '$duid'";
	$rows = $db->select($sql_diario);
	if ($rows)
		return $rows[0];
	else
		return 0;
}

/**
 * Atualiza os campos Created IP, CreatedDate, ModifiedIP e ModifiedDate da tabela designada baseado em uma chave primária e seu ID
 *
 * @param string $table
 * @param string $pkColumn
 * @param string $pkID
 * @param int $ip
 * @return boolean 
 */
function atualizaCreated($table, $pkColumn, $pkID, $ip) {
	global $db;
	$sql_u = "UPDATE $table SET CreatedIP='$ip', CreatedDate=CURDATE(), ModifiedIP='$ip', ModifiedDate=CURDATE() WHERE $pkColumn = '$pkID';";
	return $db->query($sql_u);
}

/**
 * Atualiza os campos de ModifiedIP e ModifiedDate da tabela designada baseaso em uma chave primaria e seu ID
 *
 * @param string $table
 * @param string $pkColumn
 * @param string $pkID
 * @param int $ip
 * @return boolean
 */
function atualizaModified($table, $pkColumn, $pkID, $ip) {
	global $db;
	$sql_u = "UPDATE $table SET ModifiedIP='$ip', ModifiedDate=CURDATE() WHERE $pkColumn = '$pkID';";
	return $db->query($sql_u);
}

/**
 * Atualiza a última alteração em um diário
 *
 * @param string $diarioUID Unique ID do diário a ser atualizado
 * @return void
 */
function atualizaLastChildModifiedDate($diarioUID) {
	global $db;
	$sql_u = "UPDATE `register_diarios` SET `LastChildModifiedDate`=CURDATE() WHERE `uid`='$diarioUID';";
	return $db->query($sql_u);
}

/**
 * Retorna o ID de usuário baseado no Access Token
 *
 * @param string $accessToken
 * @return int User ID
 */
function getUserIDbyAcessToken(string $accessToken) {
	global $db;
	$sql_s = "SELECT `user_id` FROM `oauth_access_tokens` WHERE `access_token` = '$accessToken';";
	$rows = $db->select($sql_s);
	if ($rows) {
		return $rows[0]['user_id'];
	} else {
		return -1;
	}
}

/**
 * Atualiza a data e o IP do último login do usuário
 *
 * @param int $userID ID do usuário que está sendo atualizado
 * @param true $userIP IP da requisição que fez a tentativa de login
 * @return boolean Verdadeiro se atualizado, falso se falhou
 */
function atualizaLastLogin($userID, $userIP) {
	global $db;
	$sql_u = "UPDATE `register_users` SET `userLastLoginIP`='$userIP',`userLastLoginDate`=CURDATE() WHERE `userID`='$userID';";
	return $db->query($sql_u);
}

function MoveSubcategoria($move_from, $move_to, $categoria_id) {
	global $db;
	$sql = "SELECT `subcategoria_id`,`subcategoria_ordem` FROM `register_subcategorias` WHERE `categoria_id` = '$categoria_id' ORDER BY `subcategoria_ordem`;";
	$subcategorias = $db->select($sql);
	$countSubcategorias = count($subcategorias);
	if ($move_from < $move_to) {
		if ($move_to >= $countSubcategorias || $move_to < 0) {
			return false;
		} else {
			for ($i=$move_from;$i<=$move_to;$i++) {
				if ($i==$move_from)
					$subcategorias[$i]['subcategoria_ordem']=$move_to;
				else
					$subcategorias[$i]['subcategoria_ordem']-=1;
				$sql_u = "UPDATE register_subcategorias SET subcategoria_ordem='".$subcategorias[$i]['subcategoria_ordem']."'
							WHERE subcategoria_id = '".$subcategorias[$i]['subcategoria_id']."';";
				$reordem = $db->query($sql_u);							
			}
			return true;
		}					
	} elseif ($move_from > $move_to) {
		if ($move_to >= $countSubcategorias || $move_to < 0) {
			return false;
		} else {
			for ($i=$move_to;$i<=$move_from;$i++) {
				if ($i==$move_from)
					$subcategorias[$i]['subcategoria_ordem']=$move_to;
				else
					$subcategorias[$i]['subcategoria_ordem']+=1;
				$sql_u = "UPDATE register_subcategorias SET subcategoria_ordem='".$subcategorias[$i]['subcategoria_ordem']."'
							WHERE subcategoria_id = '".$subcategorias[$i]['subcategoria_id']."';";
				$reordem = $db->query($sql_u);
			}
			return true;
		}
	} else {
		return true;
	}
}

$app->get("/", function (Request $request) {
	$lastScriptModifiedDate = getlastmod();
	return new Response('{"mensagem":"method not allowed","current_version":"'.$lastScriptModifiedDate.'"}',485);
});

//Aqui estamos preparando o 'pré-voo' adicionando uma resposta válida para o method 'options'
$app->options("{anything}", function () {
		$response = new \Symfony\Component\HttpFoundation\JsonResponse("OK", 204);
		return new $response;
})->assert("anything", ".*");

$app->after(function (Request $request, Response $response) {
	$response->headers->set('Access-Control-Allow-Origin', '*');
	$response->headers->set('Access-Control-Allow-Methods', 'GET,POST,PUT');
	$response->headers->set('Access-Control-Allow-Headers', '*');
});

// Autenticacao
$app->post('/auth', function (Request $request) use ($app, $db, $storage, $server) {
	ob_start(); //Start output buffer
	$resposta = $server->handleTokenRequest(OAuth2\Request::createFromGlobals());
	$data = json_decode($request->getContent(), true);
	$authorization = $request->headers->get("Authorization");
	
	if ($authorization!=null) {
		sscanf($authorization, 'Basic %s',$basic);
		$client_credentials = explode(":",base64_decode($basic));
		$client_id = $client_credentials[0];
		if (isset($client_credentials[1]))
			$client_secret = $client_credentials[1];
		else
			$client_secret = "";
	} else {
		$client_id = $data['client_id'];
		$client_secret = $data['client_secret'];
	}
	$grant_type = $data['grant_type'];
	$username = $data['username'];
	$password = $data['password'];
	
	if ($grant_type == "password") {
		$sql_s_u = "SELECT userActive, userNotActiveReason FROM register_users WHERE  userEmail= '".$username."';";
		$rows = $db ->select($sql_s_u);
		
		if ($rows) {
			if ($rows[0]['userActive']==0) {
				$resp['error']="usuario_inativo";
				$resp['error_description']=$rows[0]['userNotActiveReason'];
				return new Response(json_encode($resp), 403);
			}
		}
	} elseif ($grant_type == "client_credentials") {
		$sql_s_c = "SELECT ativo FROM oauth_clients WHERE client_id = '".$client_id."';";			 
		$rows = null;
		$rows = $db ->select($sql_s_c);
		if ($rows) {
			if ($rows[0]['ativo']==0) {
				$resp['error']="cliente_bloqueado";
				$resp['error_description']="cliente foi bloqueado";
				return new Response(json_encode($resp), 403);
			}
		}	
	} 
	$status = $resposta->getStatusCode();
	$respStr = $resposta->getResponseBody();
	
	
	if ($status == 200) {
		//significa que autenticou
		$resp = $resposta->getParameters();
		$userID = getUserIDbyAcessToken($resp['access_token']);
		atualizaLastLogin($userID,$request->getClientIp());
	}
	return new Response($respStr,$status);
});

//Rota para ativar o novo usuário
$app->get('/activate/{actcode}', function (Request $request, $actcode) use ($app, $db) {
	$sql = "SELECT userID FROM register_users WHERE userActivationCode = '".$actcode."'";
	$rows = $db ->select($sql);
	if ($rows) {
		$sql_u = "UPDATE register_users SET userActive='1',userNotActiveReason=NULL,userActivationCode=NULL WHERE userID='".$rows[0]['userID']."'";
		$ativo = $db->query($sql_u);
		if ($ativo)
			Return new Response('{"mensagem":"OK! Usuário ativado!"}', 201);
		else 
			Return new Response('{"mensagem":"Usuário não autorizado ou código inválido"}', 401);
	} else {
		Return new Response('{"mensagem":"Usuário não autorizado ou código inválido"}', 401);
	}
});

//Rota para resetar a senha e ativar o usuário
$app->get('/lostpassword/{actcode}', function (Request $request, $actcode) use ($app, $db) {
	$sql = "SELECT userID, userEmail FROM register_users WHERE userActivationCode = '".$actcode."'";
	$rows = $db ->select($sql);
	if ($rows) {
		$senha = randomPassword();
		$userPassword = password_hash($senha, PASSWORD_DEFAULT);
		$sql_u = "UPDATE register_users SET userActive='1',userNotActiveReason=NULL,userActivationCode=NULL, `userPassword`='$userPassword' WHERE userID='".$rows[0]['userID']."'";
		$ativo = $db->query($sql_u);
		if ($ativo) {
			//Envia um email com um novo código de ativação:
			$destinatario = $rows[0]['userEmail'];
			$assunto = "Sua nova senha para acesso ao Meus Investimentos";
			$template="new_password";
			$variaveis['actCode'] = $senha;
			
			ob_start();
			$mail = new enviarEmail($destinatario,$assunto,$template,$variaveis);
			$envio = $mail->enviar();
			ob_clean();

			return new Response('{"mensagem":"OK! E-mail com sua nova senha foi enviada para o seu e-mail!"}', 201);
		}
		else 
			return new Response('{"mensagem":"Usuário não autorizado ou código inválido"}', 401);
	} else {
		return new Response('{"mensagem":"Usuário não autorizado ou código inválido"}', 401);
	}
});

$app->post('/users/enviaLostPasswordLink', function (Request $request) use ($app, $db) {
	$data = json_decode($request->getContent(), true);
	$userEmail = $db->escape_string($data['userEmail']);
	$actCode = $db->escape_string (sha1(mt_rand(10000,99999).time().$userEmail));
	$sql_u = "UPDATE register_users SET `userActivationCode` = '$actCode' WHERE `userEmail`='$userEmail';";
	$resultado = $db->query($sql_u);

	if ($resultado) {
		//Envia um email com um novo código de ativação:
		$destinatario = $userEmail;
		$assunto = "Você trocou seu e-mail no Meus Investimentos";
		$template="lost_password";
		$variaveis['actCode'] = $request->getSchemeAndHttpHost().'/lostpassword/'.$actCode;
		
		ob_start();
		$mail = new enviarEmail($destinatario,$assunto,$template,$variaveis);
		$envio = $mail->enviar();
		ob_clean();
		return new Response('{"mensagem":"ok"}',200);
	} else {
		return new Response('{"mensagem":"e-mail não encontrado"}', 400);
	}

});

//Rota para criar um novo usuário
$app->post('/users', function (Request $request) use ($app, $db) {
	$ip = $request->getClientIp();
	$data = json_decode($request->getContent(), true);
	$userEmail = $db->escape_string($data['userEmail']);
	$userPassword = password_hash($data['userPassword'], PASSWORD_DEFAULT);
	$userFirstName = $db->escape_string ($data['userFirstName']);
	$userLastName = $db->escape_string ($data['userLastName']);
	$userPhoneNumber = $db->escape_string ($data['userPhoneNumber']);
	
	if (strlen($userEmail)==0 || strlen($userPassword)==0 || strlen($userFirstName)==0 || strlen($userLastName)==0) {
		$resposta['status'] = false;
		$resposta['reason'] = "entrada_invalida";
		return new Response (json_encode($resposta), 400);
	}
	
	$actCode = $db->escape_string (sha1(mt_rand(10000,99999).time().$userEmail));
	$reason = ("Usuário não verificou e-mail ainda.");
	$table = "`register_users`";
	$sql_insert = "INSERT INTO $table ".
	"(userEmail, userPassword,userFirstName,userLastName,userPhoneNumber,userActivationCode,userActive,userNotActiveReason,CreatedIP,CreatedDate)".
	"VALUES".
	"('$userEmail','$userPassword','$userFirstName','$userLastName','$userPhoneNumber','$actCode','0','$reason','$ip',CURDATE());";
	
	$sql_select = "SELECT * FROM ".$table." WHERE `userEmail` = '".$userEmail."';";
	
	$result = $db->select($sql_select);
	//Se o usuario ja existe, retorna erro
	if ($result) {
		$resposta['status'] = false;
		$resposta['reason'] = "usuario_existente";
		return new Response (json_encode($resposta), 400);	
	} 
	//caso contrario, continua
	try	{
		$resultado = $db->insert($sql_insert);
	} catch(Exception $e) {
		$resposta['status'] = false;
		$resposta['reason'] = "erro_desconhecido";
		return new Response (json_encode($resposta), 400);	
	}
	
	//se falhou, id = falso, retorna erro desconhecido
	if (!$resultado) {
		$resposta['status'] = false;
		$resposta['reason'] = "erro_desconhecido";
		return new Response (json_encode($resposta), 400);	
	} else {
		$id = $resultado;
		$sql_grupo = "SELECT groupID from register_groups WHERE scope = 'user';";
		$grupo = $db->select($sql_grupo);
		if ($grupo) {
			$sql_insertGrupo = "INSERT INTO register_users_groups (userID,groupID,CreatedIP,CreatedDate) VALUE ".
			"('$id','".$grupo[0]['groupID']."','$ip',CURDATE());";
			$resultado = $db->insert($sql_insertGrupo);
		}
		$resposta['status'] = true;
		$resposta['id'] = $id;
		
		//prepara envio do e-mail:
		$destinatario = $userEmail;
		$assunto = "Bem vindo ao Meus Investimentos";
		$template="new_user";
		$variaveis['actCode'] = $request->getSchemeAndHttpHost().'/activate/'.$actCode;
		$variaveis['firstName'] = $userFirstName;
		
		
		ob_start();
		$mail = new enviarEmail($destinatario,$assunto,$template,$variaveis);
		$envio = $mail->enviar();
		ob_clean();

		atualizaCreated('register_users','userID',$id, $request->getClientIp());
		
		return new Response (json_encode($resposta), 201);
	}
});

//Rota para alterar um usuário:
$app->post('/users/put', function (Request $request) use ($app, $db) {
	global $user;
	
	if ($user) {
		//Nunca alteraremos de uma vez: dados pessoais, email ou senha, apenas um por vez, ok?
		$data = json_decode($request->getContent(), true);
		$userID = $user['id'];
		if ($data['userFirstName']) {
			$userFirstName = $db->escape_string($data['userFirstName']);
			$userLastName = $db->escape_string($data['userLastName']);
			$userPhoneNumber = $db->escape_string($data['userPhoneNumber']);
			$sql_u = "UPDATE register_users SET `userFirstName`='$userFirstName',`userLastName`='$userLastName',`userPhoneNumber`='$userPhoneNumber' WHERE userID='$userID';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				atualizaModified('register_users','userID',$userID, $request->getClientIp());
				return new Response('{"mensagem":"ok"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		} elseif ($data['userEmail']) {
			$userEmail = $db->escape_string($data['userEmail']);
			$actCode = $db->escape_string (sha1(mt_rand(10000,99999).time().$userEmail));
			$sql_u = "UPDATE register_users SET `userEmail`='$userEmail',`userActive`='0', `userNotActiveReason`='E-mail atualizado, necessário verificar!', `userActivationCode` = '$actCode' WHERE userID='$userID';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				//Envia um email com um novo código de ativação:
				$destinatario = $userEmail;
				$assunto = "Você trocou seu e-mail no Meus Investimentos";
				$template="change_email";
				$variaveis['actCode'] = $request->getSchemeAndHttpHost().'/activate/'.$actCode;
				
				ob_start();
				$mail = new enviarEmail($destinatario,$assunto,$template,$variaveis);
				$envio = $mail->enviar();
				ob_clean();
				atualizaModified('register_users','userID',$userID, $request->getClientIp());
				return new Response('{"mensagem":"ok"}',200);
			} else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		} elseif ($data['userPassword']) {
			$userPassword = password_hash($data['userPassword'], PASSWORD_DEFAULT);
			$sql_u = "UPDATE register_users SET `userPassword`='$userPassword' WHERE userID='$userID';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				atualizaModified('register_users','userID',$userID, $request->getClientIp());
				return new Response('{"mensagem":"ok"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		} else {
			return new Response('{"mensagem":"Não encontramos os campos necessários para atualizar."}', 400);
		}
		
	} else {
		return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
	}
});

//Rota para listar todos os usuarios do sistema (apenas ADM)
$app->get('/users', function (Request $request) use ($app, $db) {
	global $user;
	if ($user['adm']) {
		$sql = 'SELECT * from `register_users`';
		$rows = $db ->select($sql);
		return $app->json($rows);
	} else {
		return new Response('{"mensagem":"Acesso negado: usuário não possui privilégios de administrador"}', 403);
	}
});

//Rota para pegar detalhes do usuário logado
$app->get('/users/logged', function (Request $request) use ($app, $db) {
	global $user;
	
	if ($user) {
		$sql = sprintf("SELECT * from `register_users` WHERE `userID` = '%s'",$user['id']);
		$rows = $db ->select($sql);
		if ($rows) {
			return $app->json($rows[0]);
		} else {
			return new Response('{"mensagem":"Este usuáro não existe, mas deveria existir - erro do servidor"}', 500);
		}
	} else {
		return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
	}
	
});

//Rota para pegar detalhes de um usuário específico (apenas ADM ou informações do próprio usuário)
$app->get('/users/{id}', function (Request $request, $id) use ($app, $db) {
	global $user;
	
	if ($user['adm'] || $user['id']==$id) {
		$sql = sprintf("SELECT * from `register_users` WHERE `userID` = '%s'",$id);
		$rows = $db ->select($sql);
		if ($rows) {
			return $app->json($rows[0]);
		} else {
			return new Response('{"mensagem":"Este usuáro não existe"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
	}
});

//Rota para recuperar cartões do diariouid:
$app->get('/cartoes', function (Request $request) use ($app, $db) {
	global $user;

	$diario_uid = $db->escape_string($request->headers->get("diariouid"));
	if ($request->headers->get("diariouid")!=null) {
		$sql_s = "SELECT `register_diarios`.`user_id`, `register_diarios`.`id` FROM `register_diarios` WHERE `register_diarios`.`uid` = '".$diario_uid."'";
		$diarios = $db ->select($sql_s);
		if ($diarios) {
			if ($diarios[0]['user_id'] == $user['id']) {//eba, usuario está tentando recuperar o que é seu:
				$sql_s = "SELECT `conta_id`, `conta_nome`, `conta_descricao`, `conta_reconciliado_valor`, 
				`conta_reconciliado_data`, `conta_budget`, `conta_cartao`, `conta_cartao_data_fechamento`, 
				`conta_cartao_data_vencimento`, `diario_id`, `bank_id`, `conta_img` 
				FROM `register_contas` WHERE `conta_cartao` = '1' AND `diario_id`='".$diarios[0]['id']."'";
				$cartoes = $db ->select($sql_s);

				if ($cartoes) {
					return new Response(json_encode($cartoes),200);
				}
				else
					return new Response('{"mensagem":"Não encontrado"}',404);
			}
			else //vixe, tentando obter os cartões de outra pessoa? Nem vamos avisar que foi descoberto:
				return new Response('{"mensagem":"Não encontrado"}',404);
		} else {
			return new Response('{"mensagem":"Não encontrado"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe invalida"}',400);
	}
});

//Rota para pegar as transacoes da fatura
$app->get('/cartoes/fatura/{fatura_data}', function (Request $request, $fatura_data) use ($app, $db) {
	global $user;

	$cartao_id = $db->escape_string($request->headers->get("cartaoid"));
	$fatura_data = $db->escape_string($fatura_data);
	if ($request->headers->get("cartaoid")!=null && $fatura_data!=null) {
		$sql_s = "SELECT `register_diarios`.`user_id`, `register_diarios`.`id` FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `register_contas`.`conta_id` = '".$cartao_id."'";
		$diarios = $db ->select($sql_s);
		if ($diarios) {
			if ($diarios[0]['user_id'] == $user['id']) {//eba, usuario está tentando recuperar o que é seu:
				$sql_s = 
				"SELECT 
					DATE_FORMAT(`register_transacoes`.`transacao_fatura_data`,'%Y-%m-01') AS `fatura_data`, 
					`register_transacoes`.`transacao_id`,
					`register_contas`.`conta_id`,
					`register_transacoes`.`transacao_data`, 
					`register_transacoes`.`transacao_sacado`,
					`register_transacoes`.`transacao_descricao`,
					(-`register_transacoes`.`transacao_valor`) AS `transacao_valor`
				FROM 
					`register_transacoes` 
				JOIN 
					`register_contas` 
						ON `register_contas`.`conta_id` = `register_transacoes`.`conta_id`
				WHERE 
					`register_contas`.`conta_id` = '".$cartao_id."' 
					AND 
					`register_contas`.`conta_cartao` = '1'
					AND";
				if ($fatura_data == 'null') {
					$sql_s .= " `register_transacoes`.`transacao_fatura_data` IS NULL";
				} else {
					$sql_s .= " DATE_FORMAT(`register_transacoes`.`transacao_fatura_data`,'%Y-%m-01') = '".$fatura_data."'";
				}
				
				$fatura_itens = $db->select($sql_s);
				if ($fatura_itens)
					return new Response(json_encode($fatura_itens),200);
				else
					return new Response('{"mensagem":"Não encontrado"}',404);
			}
			else //vixe, tentando obter os cartões de outra pessoa? Nem vamos avisar que foi descoberto:
				return new Response('{"mensagem":"Não encontrado"}',404);
		} else {
			return new Response('{"mensagem":"Não encontrado"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe invalida"}',400);
	}
});

//Rota para pegar a lista de faturas dado um cartao_id
$app->get('/cartoes/fatura', function (Request $request) use ($app, $db) {
	global $user;

	$cartao_id = $db->escape_string($request->headers->get("cartaoid"));
	if ($request->headers->get("cartaoid")!=null) {
		$sql_s = "SELECT `register_diarios`.`user_id`, `register_diarios`.`id`, `register_contas`.`conta_nome`, `register_contas`.`conta_cartao_data_fechamento`, `register_contas`.`conta_cartao_data_vencimento` FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `register_contas`.`conta_id` = '".$cartao_id."'";
		$diarios = $db ->select($sql_s);
		if ($diarios) {
			if ($diarios[0]['user_id'] == $user['id']) {//eba, usuario está tentando recuperar o que é seu:
				$sql_s = 
				"SELECT `table1`.`fatura_data`, `table1`.`fatura_valor`, `table2`.`fatura_valor_pago` FROM
					( SELECT DATE_FORMAT(`register_transacoes`.`transacao_fatura_data`,'%Y-%m-01') AS `fatura_data`, SUM(transacao_valor) AS `fatura_valor` FROM `register_transacoes` JOIN `register_contas` ON `register_contas`.`conta_id` = `register_transacoes`.`conta_id` WHERE `register_contas`.`conta_id` = '".$cartao_id."' AND `register_contas`.`conta_cartao` = '1' GROUP BY (`fatura_data`)
				) `table1`
				LEFT JOIN 
					( SELECT DATE_FORMAT(IFNULL(`register_transacoes`.`transacao_fatura_data`, `register_transacoes`.`transacao_data`),'%Y-%m-01') AS `fatura_data`, -SUM(`register_transacoes_itens`.`transacoes_item_valor`) AS `fatura_valor_pago` FROM `register_transacoes_itens` JOIN `register_transacoes` ON `register_transacoes`.`transacao_id` = `register_transacoes_itens`.`transacao_id` WHERE `register_transacoes_itens`.`transf_para_conta_id` = '".$cartao_id."' AND `register_transacoes_itens`.`transacoes_item_valor` < 0 GROUP BY (`fatura_data`)
				) `table2`
				ON
				`table1`.`fatura_data` = `table2`.`fatura_data` ORDER BY (`table1`.`fatura_data`)";
				$faturas = $db->select($sql_s);
				if ($faturas) {
					$faturasList = [];
					//vamos calcular a quantidade de faturas e zerar antes de distribuir:
					$minDate = min(array_diff(array_column($faturas,'fatura_data'),array(null)));
					$maxDate = max(array_column($faturas,'fatura_data'));
					$maxLen = date_create($maxDate)->format("m")-date_create($minDate)->format("m")+12*(date_create($maxDate)->format("Y")-date_create($minDate)->format("Y"))+1;
					$curDate = $minDate;
					
					for ($i=0;$i<$maxLen;$i++) {
						$curDate = date_add(date_create($minDate), date_interval_create_from_date_string($i." month"));
						$faturasList[$i+1] = array(
							"fatura_index" => ($i+1),
							"fatura_data" => date_format($curDate,"Y-m-01"),
							"fatura_valor" => 0,
							"fatura_valor_pago" => 0,
							"conta_id" => $cartao_id,
							"conta_nome" => $diarios[0]['conta_nome'],
							"conta_fechamento" => $diarios[0]['conta_cartao_data_fechamento'],
							"conta_vencimento" => $diarios[0]['conta_cartao_data_vencimento']
						);
					}
					foreach ($faturas as $value) {
						if ($value["fatura_data"] == null) {
							$faturasList[0] = array(
								"fatura_index" => 0,
								"fatura_data" => null,
								"fatura_valor" => (-1)*$value["fatura_valor"],
								"fatura_valor_pago" => null,
								"conta_id" => $cartao_id,
								"conta_nome" => $diarios[0]['conta_nome'],
								"conta_fechamento" => $diarios[0]['conta_cartao_data_fechamento'],
								"conta_vencimento" => $diarios[0]['conta_cartao_data_vencimento']
							);
						} else {
							$curDate = $value["fatura_data"];
							$curIndex = date_create($curDate)->format("m")-date_create($minDate)->format("m")+12*(date_create($curDate)->format("Y")-date_create($minDate)->format("Y"))+1;

							$faturasList[$curIndex] = array(
								"fatura_index" => $curIndex,
								"fatura_data" => $value["fatura_data"],
								"fatura_valor" => (-1)*$value["fatura_valor"],
								"fatura_valor_pago" => $value["fatura_valor_pago"],
								"conta_id" => $cartao_id,
								"conta_nome" => $diarios[0]['conta_nome'],
								"conta_fechamento" => $diarios[0]['conta_cartao_data_fechamento'],
								"conta_vencimento" => $diarios[0]['conta_cartao_data_vencimento']
							);
						}
					}
					return new Response(json_encode(array_values($faturasList)),200);
				}
				else
					return new Response('{"mensagem":"Não encontrado"}',404);
			}
			else //vixe, tentando obter os cartões de outra pessoa? Nem vamos avisar que foi descoberto:
				return new Response('{"mensagem":"Não encontrado"}',404);
		} else {
			return new Response('{"mensagem":"Não encontrado"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe invalida"}',400);
	}
});

//Rota para verificar se um cliente existe
$app->get('/cliente', function (Request $request) use ($app, $db) {
	$client_id = $db->escape_string($request->headers->get("clientid"));
	$client_secret = $db->escape_string($request->headers->get("clientsecret"));
	if ($request->headers->get("clientid")!=null && $request->headers->get("clientsecret")!=null) {
		$sql_s = "SELECT user_id, ativo FROM oauth_clients WHERE client_id = '".$client_id."' AND client_secret='".$client_secret."';";
		$rows = $db ->select($sql_s);
		if ($rows) {
			if ($rows[0]['ativo'] == 1) //eba existe e tá ativo, vamos retornar um ok!
				return new Response('{"mensagem":"ok"}',200);
			else //vixe, o cliente até existe, mas está bloqueado, vamos informar ao cliente:
				return new Response('{"mensagem":"cliente_bloqueado"}',403);
		} else {
			return new Response('{"mensagem":"credenciais_invalidas"}', 401);
		}
	} else {
		return new Response('{"mensagem":"credenciais_nao_enviadas"}',401);
	}
});

//rota para criar um novo cliente
$app->post('/cliente', function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (!isset($data['client_id']) || !isset($data['client_secret'])) {
		return new Response('{"mensagem":"falha_ao_criar_cliente: empty body"}',400);
	}
	$client_id = $db->escape_string($data['client_id']);
	$client_secret = $db->escape_string($data['client_secret']);
	if (isset($data['description']))
		$description = $db->escape_string($data['description']);
	if ($user['adm'])
		$user_id = $db->escape_string($data['user_id']);
	else
		$user_id = $user['id'];
	$sql_s = "SELECT user_id, ativo FROM oauth_clients WHERE client_id = '".$client_id."';";
	$rows = $db ->select($sql_s);
	if ($rows) {
		$sql_s1 = "SELECT user_id, ativo FROM oauth_clients WHERE client_id = '".$client_id."' AND client_secret='".$client_secret."';";
		$rows1 = $db ->select($sql_s1);
		if ($rows1)
			if ($rows1[0]['ativo']==1) {
				$sql_user = "SELECT userFirstName, userLastName FROM register_users WHERE userID='".$rows[0]['user_id']."';";
				$rows2 = $db ->select($sql_user);
				$resposta['nome'] = $rows2[0]['userFirstName']." ".$rows2[0]['userLastName'];
				$resposta['id'] = $rows[0]['user_id'];
				return new Response(json_encode($resposta),200);
			}
			else
				return new Response('{"mensagem":"cliente_bloqueado"}',403);
		else
			return new Response('{"mensagem":"cliente_existente"}',409);
	} else {
		if (!isset($description)) {
			return new Response('{"mensagem":"falha_ao_criar_cliente: no description found"}',400);
		}
		$sql_i = "INSERT INTO oauth_clients (client_id,client_secret,grant_types,scope,user_id,description,ativo) ".
		"VALUES ('$client_id','$client_secret','client_credentials','user','$user_id','$description',1)";
		$resultado = $db->insert($sql_i);
		if ($resultado) {
			$sql_user = "SELECT userFirstName, userLastName FROM register_users WHERE userID='".$user_id."';";
			$rows2 = $db ->select($sql_user);
			$resposta['nome'] = $rows2[0]['userFirstName']." ".$rows2[0]['userLastName'];
			return new Response(json_encode($resposta),201);
		}
		else {
			return new Response('{"mensagem":"falha_ao_inserir_novo_cliente'.$sql_i.'"}',400);
		}
	}
});

//rota para listar todos os diários de um usuário
$app->get('/diario', function (Request $request) use ($app, $user, $db) {
	global $user;
	if ($request->headers->get("userid")==null) {
		return new Response('{"mensagem":"Faltando userid"}',400);
	} elseif ($user['id']<>$request->headers->get("userid") && $user['adm']!=true) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	} else {
		$user_id = $db->escape_string($request->headers->get("userid"));
		$sql = "SELECT id,uid AS diarioUID, nome as diarioNome, description AS diarioDescription, `default` AS isDefault, `user_id` AS `userid` FROM register_diarios WHERE user_id = '$user_id';";
		$rows = $db ->select($sql);
		if ($rows)
			return new Response(json_encode($rows),200);
		else
			return new Response("[]",200);
	}
});

//rota para recuperar um diario especifico
$app->get('/diario/{diariouid}', function (Request $request, $diariouid) use ($app, $db) {
	global $user;
	$sql_s = "SELECT `user_id`, `default`, id, uid AS diarioUID, nome AS diarioNome, description AS diarioDescription, `default` AS isDefault, user_id AS userid from `register_diarios` WHERE `uid`='$diariouid';";
	$resultado = $db->select($sql_s);
	if ($resultado == false) {
		return new Response('{"mensagem":"Não encontrado"}', 404);
	} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
		return new Response('{"mensagem":"Não autorizado"}',403);	
	} else {
		$resposta['id'] = $resultado[0]['id'];
		$resposta['diarioUID'] = $resultado[0]['diarioUID'];
		$resposta['diarioNome'] = $resultado[0]['diarioNome'];
		$resposta['diarioDescription'] = $resultado[0]['diarioDescription'];
		$resposta['isDefault'] = $resultado[0]['isDefault'];
		$resposta['userid'] = $resultado[0]['userid'];
		
		return new Response(json_encode($resposta),200);
	}
});

//rota para criar um novo diário
$app->post('/diario', function (Request $request) use ($app, $user, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (isset($data['nome'][1]) && isset($data['description'][1]) && isset($data['userid'])) {
		if ($data['userid']<>$user['id'] && $user['adm']!=true)
			return new Response("Não autorizado",403);		
		$nome 			= $db->escape_string($data['nome']);
		$description	= $db->escape_string($data['description']);
		$userid			= $db->escape_string($data['userid']);
		$uuid 			= md5(uniqid(""));
		$ip = $request->getClientIp();
		$sql_i = "INSERT INTO register_diarios (uid,nome,description,user_id,`default`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES ('$uuid','$nome','$description','$userid','1','$ip','$ip',CURDATE(),CURDATE());";
		$resultado = $db->insert($sql_i);
		//Inserimos um novo diário e definimos o mesmo como default, mas já devia ter um default, precisamos setar ele como não default
		//ou seja, todos os demais são false agora.
		$sql_u = "UPDATE register_diarios `default`='0' WHERE id<>'$resultado' AND user_id='$userid';";
		if ($resultado) {
			$res = $db->query($sql_u);
			$resposta['uid'] = $uuid;
			//Inserimos um novo diario, vamos agora criar as categorias e subcategorias padrões:
			//Lemos de um arquivo de configuração as categorias padrão (edite o arquivo abaixo caso queira alterar as categorias padrão):
			$str = file_get_contents('categorias_default.json');
			$json = json_decode($str, true); // decode the JSON into an associative array
			$i=0;
			foreach ($json as $categoria) {
				$diario_id = $resultado;
				$categoria_nome = $categoria['categoria_nome'];
				$categoria_desc = $categoria['categoria_description'];
				$sql_i_categoria = "INSERT INTO `register_categorias` (`categoria_nome`,`categoria_description`,`diario_id`,`categoria_ordem`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES
				 ('$categoria_nome','$categoria_desc','$diario_id','$i','$ip','$ip',CURDATE(),CURDATE());";
				
				$res = $db->insert($sql_i_categoria);
				$i++;
				if ($res) {
					$subcategorias = $categoria["subcategorias"];
					$j=0;
					foreach ($subcategorias as $subcategoria) {
						$cat_id = $res;
						$subc_nome = $subcategoria['subcategoria_nome'];
						$subc_desc = $subcategoria['subcategoria_description'];
						
						$sql_i_subc = "INSERT INTO `register_subcategorias` (`subcategoria_nome`,`subcategoria_description`,`subcategoria_carry`,
						`categoria_id`,`subcategoria_ordem`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES ('$subc_nome','$subc_desc',0,'$cat_id','$j','$ip','$ip',CURDATE(),CURDATE())";
						$res_s = $db->insert($sql_i_subc);
						$j++;
					}
				}
			}
			
			return new Response(json_encode($resposta),201);
		}
		else
			return new Response('{"mensagem":"Erro ao atualizar banco de dados"}',400);
			
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

//rota para atualizar um novo diario
$app->post('/diario/put', function (Request $request) use ($app, $user, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (isset($data['nome'][2]) && isset($data['description'][2]) && isset($data['uniqueid'][2])) {
		$uniqueid 		= $db->escape_string($data['uniqueid']);
		$sql_s = "SELECT user_id from register_diarios WHERE uid='$uniqueid';";
		$resultado = $db->select($sql_s);
		if ($resultado == false) {
			return new Response('{"mensagem":"Não encontrado para atualizar"}', 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response('{"mensagem":"Não autorizado"}',403);	
		} else {
			$uniqueid 		= $db->escape_string($data['uniqueid']);
			$nome 			= $db->escape_string($data['nome']);
			$description	= $db->escape_string($data['description']);
			$ip = $request->getClientIp();
			$sql_u = "UPDATE register_diarios SET nome='$nome',description='$description', `ModifiedIP`='$ip', `ModifiedDate`=CURDATE() WHERE uid='$uniqueid';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				atualizaLastChildModifiedDate($uniqueid);
				return new Response('{"mensagem":"ok"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

//rota para deletar um diario
$app->post('/diario/delete', function (Request $request) use ($app, $user, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (isset($data['uniqueid'][2])) {
		$uniqueid 		= $db->escape_string($data['uniqueid']);
		$sql_s = "SELECT `user_id`, `default` from `register_diarios` WHERE `uid`='$uniqueid';";
		$resultado = $db->select($sql_s);
		$isDefault = $resultado[0]['default'];
		if ($resultado == false) {
			return new Response('{"mensagem":"Não encontrado para deletar"}', 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response('{"mensagem":"Não autorizado"}',403);	
		} else {
			$sql_u = "DELETE FROM register_diarios WHERE uid='$uniqueid';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				//ok, apagamos o diario, mas e se ele fosse o padrão, como faremos agora?
				//vamos definir o primeiro que tiver como default e estamos satisfeitos:
				$sql_default = "";
				if ($isDefault == 1) {
					$sql_default = "UPDATE `register_diarios` SET `default` = 1 WHERE user_id = '".$user['id']."' LIMIT 1";
					$resultado = $db->query($sql_default);
				}
				return new Response('{"mensagem":"Deletado"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

//rota para selecionar e tornar o diario escolhido o padrão
$app->post('/diario/select', function (Request $request) use ($app, $user, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (isset($data['uniqueid'][2])) {
		$uniqueid	= $db->escape_string($data['uniqueid']);
		$sql_s 		= "SELECT user_id from register_diarios WHERE uid='$uniqueid';";
		$resultado 	= $db->select($sql_s);
		if ($resultado == false) {
			return new Response('{"mensagem":"Não encontrado para selecionar"}', 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response('{"mensagem":"Não autorizado"}',403);	
		} else {
			$sql_u		= "UPDATE `register_diarios` SET `default`=(`uid`='$uniqueid');";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				atualizaLastChildModifiedDate($uniqueid);
				return new Response('{"mensagem":"Selecionado"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

$app->get('/categoria/{diariouid}', function (Request $request, $diariouid) use ($app, $db) {
	global $user;
	
	$diario = getDiarioID($diariouid);
	
	if ($diario) {
		if ($user['adm'] || $user['id']==$diario['user_id']) {
			$diarioID = $diario['diario_id'];
			$sql = sprintf("SELECT `categoria_id`, `categoria_nome`, `categoria_description`, `diario_id`, `categoria_ordem` from `register_categorias` WHERE (`diario_id` = '%s' OR `diario_id` IS NULL) ORDER BY `categoria_ordem`",$diarioID);
			$rows = $db ->select($sql);
			if ($rows) {
				$i = 0;
				foreach ($rows as $row) {
					$categorias[$i] = $row;
					$cat_id = $row['categoria_id'];
					$sql_subc = "SELECT `subcategoria_id`,`subcategoria_nome`,`subcategoria_description`,`subcategoria_carry`,`categoria_id`,`subcategoria_ordem` FROM `register_subcategorias` 
					WHERE `categoria_id` = '$cat_id' ORDER BY `subcategoria_ordem`;";
					$subcategorias = $db->select($sql_subc);
					$categorias[$i]['subcategorias'] = $subcategorias;
					$i++;
				}
				return new Response(json_encode($categorias),200);
			} else {
				return new Response('{"mensagem":"Este diário não existe"}', 404);
			}
		} else {
			return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
		}
	} else {
		return new Response('{"mensagem":"Diário não encontrado"}', 404);
	}
});

$app->get('/categoriatabular/{diariouid}', function (Request $request, $diariouid) use ($app, $db) {
	global $user;
	
	$diario = getDiarioID($diariouid);
	
	if ($diario) {
		if ($user['adm'] || $user['id']==$diario['user_id']) {
			$diarioID = $diario['diario_id'];
			$sql = sprintf("SELECT `categoria_id`, `categoria_nome`, `categoria_description`, `categoria_ordem`, `diario_id` from `register_categorias` WHERE (`diario_id` = '%s' OR `diario_id` IS NULL) ORDER BY `categoria_ordem`",$diarioID);
			$rows = $db ->select($sql);
			if ($rows) {
				$i = 0;
				foreach ($rows as $row) {
					$categorias[$i] = $row;
					$categorias[$i]['subcategoria_is'] = 0;
					$cat_id = $row['categoria_id'];
					$sql_subc = "SELECT `subcategoria_id`,`subcategoria_nome`,`subcategoria_description`,`subcategoria_carry`,`categoria_id`,`subcategoria_ordem` FROM `register_subcategorias` 
					WHERE `categoria_id` = '$cat_id' ORDER BY `subcategoria_ordem`;";
					$subcategorias = $db->select($sql_subc);
					$categorias[$i]['categoria_filhos'] = count($subcategorias);
					$temp_cat = $categorias[$i];
					$i++;
					$k=1;
					foreach ($subcategorias as $subrow) {
						$categorias[$i] = $temp_cat;
						$categorias[$i]['subcategoria_is'] = 1;
						$categorias[$i]['subcategoria_id'] = $subrow['subcategoria_id'];
						$categorias[$i]['subcategoria_nome'] = $subrow['subcategoria_nome'];
						$categorias[$i]['subcategoria_description'] = $subrow['subcategoria_description'];
						$categorias[$i]['subcategoria_carry'] = $subrow['subcategoria_carry'];
						$categorias[$i]['subcategoria_ordem'] = $subrow['subcategoria_ordem'];
						if ($k<$temp_cat['categoria_filhos']) {
							$categorias[$i]['subcategoria_ultima'] = false;
						} else {
							$categorias[$i]['subcategoria_ultima'] = true;
						}
						$i++;
						$k++;
					}
				}
				return new Response(json_encode($categorias),200);
			} 
			else {
				return new Response('{"mensagem":"Este diário não existe"}', 404);
			}
		} else {
			return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
		}
	} else {
		return new Response('{"mensagem":"Diário não encontrado"}', 404);
	}
});

$app->post('/categoria/move', function (Request $request) use ($app, $db) {
	global $user;
	
	$data = json_decode($request->getContent(), true);
	if (isset($data['categoria_id']) && isset($data['move_to'])) {
		$categoria_id = $db->escape_string($data['categoria_id']);
		$move_to = $data['move_to'];
	
		$sql = "SELECT `register_users`.`userID`,`register_categorias`.`categoria_ordem`,`register_categorias`.`diario_id` FROM `register_categorias` 
		JOIN `register_diarios` on `register_categorias`.`diario_id` = `register_diarios`.`id` 
		JOIN `register_users` ON `register_diarios`.`user_id` = `register_users`.`userID`
		WHERE `register_categorias`.`categoria_id` = '$categoria_id';";
		
		$rows = $db->select($sql);
		if ($rows) {
			$user_id = $rows[0]['userID'];
			$move_from = $rows[0]['categoria_ordem'];
			$diario_id = $rows[0]['diario_id'];
			
			if ($user['adm'] || $user['id']==$user_id) {
				$sql = "SELECT `categoria_id`,`categoria_ordem` FROM `register_categorias` WHERE `diario_id` = '$diario_id' ORDER BY `categoria_ordem`;";
				$categorias = $db->select($sql);
				$countCategorias = count($categorias);
				if ($move_from < $move_to) {
					if ($move_to >= $countCategorias || $move_to < 0) {
						return new Response('{"mensagem":"Sintaxe inválida"}', 400);
					} else {
						for ($i=$move_from;$i<=$move_to;$i++) {
							if ($i==$move_from)
								$categorias[$i]['categoria_ordem']=$move_to;
							else
								$categorias[$i]['categoria_ordem']=$i-1;
							$sql_u = "UPDATE register_categorias SET categoria_ordem='".$categorias[$i]['categoria_ordem']."'
										WHERE categoria_id = '".$categorias[$i]['categoria_id']."';";
							$reordem = $db->query($sql_u);							
						}
						return new Response('{"mensagem":"Reordenado para baixo"}',200);
					}					
				} elseif ($move_from > $move_to) {
					if ($move_to >= $countCategorias || $move_to < 0) {
						return new Response('{"mensagem":"Sintaxe inválida"}', 400);
					} else {
						for ($i=$move_from;$i>=$move_to;$i--) {
							if ($i==$move_from)
								$categorias[$i]['categoria_ordem']=$move_to;
							else 
								$categorias[$i]['categoria_ordem']=$i+1;
							$sql_u = "UPDATE register_categorias SET categoria_ordem='".$categorias[$i]['categoria_ordem']."'
										WHERE categoria_id = '".$categorias[$i]['categoria_id']."';";
							$reordem = $db->query($sql_u);
						}
						return new Response('{"mensagem":"Reordenado para cima"}',200);
					}
				} else {
					return new Response('{"mensagem":"Reordenado"}',200);
				}
			} else {
				return new Response('{"mensagem":"Não autorizado"}', 403);
			}
		} else {
			return new Response('{"mensagem":"Não encontrado"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

$app->post('/subcategoria/move', function (Request $request) use ($app, $db) {
	global $user;
	
	$data = json_decode($request->getContent(), true);
	if (isset($data['subcategoria_id']) && isset($data['move_to'])) {
		$subcategoria_id = $db->escape_string($data['subcategoria_id']);
		$move_to = $data['move_to'];
	
		$sql = "SELECT `register_users`.`userID`,`register_subcategorias`.`subcategoria_ordem`,`register_categorias`.`categoria_id` FROM `register_subcategorias` 
		JOIN `register_categorias` ON `register_subcategorias`.`categoria_id` = `register_categorias`.`categoria_id`
		JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` 
		JOIN `register_users` ON `register_diarios`.`user_id` = `register_users`.`userID`
		WHERE `register_subcategorias`.`subcategoria_id` = '$subcategoria_id';";
		
		$rows = $db->select($sql);
		if ($rows) {
			$user_id = $rows[0]['userID'];
			$move_from = $rows[0]['subcategoria_ordem'];
			$categoria_id = $rows[0]['categoria_id'];
			
			if ($user['adm'] || $user['id']==$user_id) {
				if (!isset($data['move_to_categoria_id']) || $data['move_to_categoria_id']==$categoria_id) {
					if (MoveSubcategoria($move_from,$move_to,$categoria_id))
						return new Response('{"mensagem":"Reordenado"}',200);
					else
						return new Response('{"mensagem":"Sintaxe inválida"}',400);
				} else {
					//antes de reordenar, precisamos transferir nossa subcategoria:
					//então, na nova categoria, vamos descobrir a ultima posicao
					$nova_categoria_id = $db->escape_string($data['move_to_categoria_id']);
					$sql = "SELECT MAX(`subcategoria_ordem`) AS `max_posicao` FROM `register_subcategorias` WHERE `categoria_id` = '$nova_categoria_id';";
					$query = $db->select($sql);
					if ($query) {
						$max_pos = $query[0]['max_posicao']+1;
						$sql = "UPDATE `register_subcategorias` SET `categoria_id` = '$nova_categoria_id', `subcategoria_ordem` = '$max_pos' 
								WHERE `subcategoria_id` = '$subcategoria_id';";
						$res = $db->query($sql);
						if ($res) {
							//agora que transferimos, vamos reordenar:
							$move = MoveSubcategoria($max_pos,$move_to,$nova_categoria_id);
							// e temos que tapar o buraco que deixamos na ordem ao transferir:
							$sql = "SELECT `subcategoria_id`,`subcategoria_ordem` FROM `register_subcategorias` WHERE `categoria_id` = '$categoria_id' ORDER BY `subcategoria_ordem`;";
							$subcategorias_antigas = $db->select($sql);
							for ($i=0;$i<count($subcategorias_antigas);$i++) {
								$sql_u = "UPDATE `register_subcategorias` SET `subcategoria_ordem` = '$i' WHERE `subcategoria_id` = '".$subcategorias_antigas[$i]['subcategoria_id']."';";
								$db->query($sql_u);
							}
							if ($move)
								return new Response('{"mensagem":"Transferido e Reordenado"}',200);
							else
								return new Response('{"mensagem":"Falhou ao reordernar, mas transferimos"}',400);
						} else {
							return new Response('{"mensagem":"Falha ao transferir subcategoria"}',500);
						}
					} else {
						return new Response('{"mensagem":"Nova categoria não encontrada"}',404);
					}
				}
			} else {
				return new Response('{"mensagem":"Não autorizado"}', 403);
			}
		} else {
			return new Response('{"mensagem":"Não encontrado"}', 404);
		}
	} else {
		return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
});

$app->post('/categoria',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	//vamos criar uma flag para saber se estamos criando ou atualizando uma linha:
	$operacao = "";
	$user_id = 0;
	$diario_id = 0;
	
	//identificar a quem pertence o item:
	if (isset($data['categoria_id'])) {
		//se a categoria foi informada, vamos ignorar o parâmetro diario_uid pois é uma atualização
		$operacao = "atualizar";
		
		$categoria_id = $db->escape_string($data['categoria_id']);
		$sql_s = "SELECT user_id, diario_id FROM `register_categorias` JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` WHERE `categoria_id` = '$categoria_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} elseif (isset($data['diario_uid'])) {
		//se a categoria não foi informada, obrigatoriamente o diario_uid deve ser informada para criar uma nova categoria.
		$operacao = "criar";
		atualizaLastChildModifiedDate($data['diario_uid']);
		
		$diario_uid = $db->escape_string($data['diario_uid']);
		$sql_s = "SELECT `user_id`, `id` AS `diario_id` FROM `register_diarios` WHERE `uid`='$diario_uid'";
		
		$rows = $db->select($sql_s);
		
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} else {
		//se nenhum dos dois parametros foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	$ip = $request->getClientIp();

	//fazer o que tem que ser feito:
	if ($operacao == "criar") {
		//ok, vamos criar, então vamos preparar os campos obrigatórios:
		if (isset($data['categoria_nome']) && isset($data['categoria_description'])) {
			$categoria_nome = $db->escape_string($data['categoria_nome']);
			$categoria_description = $db->escape_string($data['categoria_description']);
			
			//precisamos recuperar no servidor qual a ordem este item pertence:
			$sql_s = "SELECT MAX(`categoria_ordem`)+1 AS `nova_ordem` FROM `register_categorias` WHERE `diario_id` = '$diario_id'";
			$rows = $db->select($sql_s);
			
			$nova_ordem = 0; //caso não haja nenhuma categoria para este diário, a primeira terá ordem 0
			if ($rows) {
				$nova_ordem = $rows[0]['nova_ordem'];
			}
			
			//ok, estamos prontos para criar:
			$sql_i = "INSERT INTO `register_categorias` (`categoria_nome`,`categoria_description`,`categoria_ordem`,`diario_id`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`)
				VALUES ('$categoria_nome','$categoria_description','$nova_ordem','$diario_id','$ip','$ip',CURDATE(),CURDATE());";
					
			$inserido = $db->insert($sql_i);
			
			if ($inserido) {
				$resposta['categoria_id'] = $inserido;
				$resposta['categoria_ordem'] = $nova_ordem;
				$resposta['diario_id'] = $diario_id;
				
				return new Response(json_encode($resposta),201);
			} else {
				return new Response('{"mensagem":"Erro desconhecido"}',500);
			}
		} else {
			return new Response('{"mensagem":"erro de sintaxe: faltam parametros para criar"}', 400);
		}
	} elseif ($operacao == "atualizar") {
		//vamos atualizar, vamos montar a query, jogo rápido:
		$update_nome = "";
		$update_description = "";
		$update_ordem = "";
		$nr_up = 0;
		if (isset($data['categoria_nome'])) {
			$update[$nr_up] = "`categoria_nome` = '".$db->escape_string($data['categoria_nome'])."'";
			$nr_up++;
		}
		if (isset($data['categoria_description'])) {
			$update[$nr_up] = "`categoria_description` = '".$db->escape_string($data['categoria_description'])."'";
			$nr_up++;
		}
		if (isset($data['categoria_ordem'])) {
			$update[$nr_up] = "`categoria_ordem` = '".$db->escape_string($data['categoria_ordem'])."'";
			$nr_up++;
		}
		$update_text = "";
		for ($i=0;$i<$nr_up;$i++) {
			$update_text .= $update[$i];
			if ($i<($nr_up-1))
				$update_text .= ", ";
		}
		if ($nr_up > 0) {
			$sql_u = "UPDATE `register_categorias` SET $update_text, `ModifiedIP`='$ip', `ModifiedDate`=CURDATE() WHERE `categoria_id` = '$categoria_id'";
			$atualizar = $db->query($sql_u);
			
			if ($atualizar) {
				$resposta["msg"]="atualizado";
				return new Response(json_encode($resposta),200);
				
			}
			else
				return new Response('{"mensagem":"Erro desconhecido"}', 500);
		} else {
		return new Response('{"mensagem":"Nada para atualizar"}', 400);
		}
	}
	
	return new Response('{"mensagem":"Erro de sintaxe"}',400);
});

$app->post('/categoria/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	//vamos criar uma flag para saber se estamos criando ou atualizando uma linha:
	$user_id = 0;
	$diario_id = 0;
	
	//identificar a quem pertence o item:
	if (isset($data['categoria_id'])) {
		//se a categoria foi informada, vamos ignorar o parâmetro diario_uid pois é uma atualização
		$categoria_id = $db->escape_string($data['categoria_id']);
		
		$sql_s = "SELECT user_id, diario_id FROM `register_categorias` JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` WHERE `categoria_id` = '$categoria_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} else {
		//se o parametro não foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	//fazer o que tem que ser feito:
	$sql_d = "DELETE FROM `register_categorias` WHERE `categoria_id` = '$categoria_id';";
	
	$query = $db->query($sql_d);
	
	if ($query) {
		return new Response('{"mensagem":"Excluido"}', 200);
	} else {
		return new Response('{"mensagem":"Erro desconhecido"}', 500);
	}
});

$app->post('/subcategoria',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	//vamos criar uma flag para saber se estamos criando ou atualizando uma linha:
	$operacao = "";
	$user_id = 0;
	$diario_id = 0;

	$ip = $request->getClientIp();
	
	//identificar a quem pertence o item:
	if (isset($data['subcategoria_id'])) {
		//se a subcategoria foi informada, vamos ignorar o parâmetro categoria_id pois é uma atualização
		$operacao = "atualizar";
		
		$subcategoria_id = $db->escape_string($data['subcategoria_id']);
		$sql_s = "SELECT user_id, diario_id FROM `register_subcategorias` JOIN `register_categorias` ON `register_subcategorias`.`categoria_id` = `register_categorias`.`categoria_id` JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` WHERE `subcategoria_id` = '$subcategoria_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} elseif (isset($data['categoria_id'])) {
		//se a subcategoria não foi informada, obrigatoriamente o categoria_id deve ser informada para criar uma nova subcategoria.
		$operacao = "criar";
		
		$categoria_id = $db->escape_string($data['categoria_id']);
		$sql_s = "SELECT `user_id`, `id` AS `diario_id` FROM `register_categorias` JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` WHERE `categoria_id` = '$categoria_id'";
		
		$rows = $db->select($sql_s);
		
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} else {
		//se nenhum dos dois parametros foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	//fazer o que tem que ser feito:
	if ($operacao == "criar") {
		//ok, vamos criar, então vamos preparar os campos obrigatórios:
		if (isset($data['subcategoria_nome']) && isset($data['subcategoria_description']) && isset($data['categoria_id']) ) {
			$subcategoria_nome = $db->escape_string($data['subcategoria_nome']);
			$subcategoria_description = $db->escape_string($data['subcategoria_description']);
			$categoria_id = $db->escape_string($data['categoria_id']);
			$subcategoria_carry = $db->escape_string($data['subcategoria_carry']);
			
			//precisamos recuperar no servidor qual a ordem este item pertence:
			$sql_s = "SELECT MAX(`subcategoria_ordem`)+1 AS `nova_ordem` FROM `register_subcategorias` WHERE `categoria_id` = '$categoria_id'";
			$rows = $db->select($sql_s);
			
			$nova_ordem = 0; //caso não haja nenhuma categoria para este diário, a primeira terá ordem 0
			if ($rows) {
				$nova_ordem = $rows[0]['nova_ordem'];
			}
			
			//ok, estamos prontos para criar:
			$sql_i = "INSERT INTO `register_subcategorias` (`subcategoria_nome`,`subcategoria_description`,`subcategoria_ordem`,`categoria_id`,`subcategoria_carry`,`CreatedIP`,`ModifiedIP`,`CreatedDate`, `ModifiedDate`)
				VALUES ('$subcategoria_nome','$subcategoria_description','$nova_ordem','$categoria_id','$subcategoria_carry','$ip','$ip',CURDATE(),CURDATE());";
					
			$inserido = $db->insert($sql_i);
			
			if ($inserido) {
				$resposta['subcategoria_id'] = $inserido;
				$resposta['subcategoria_ordem'] = $nova_ordem;
				$resposta['categoria_id'] = $categoria_id;
				
				return new Response(json_encode($resposta),201);
			} else {
				return new Response('{"mensagem":"Erro desconhecido"}',500);
			}
		} else {
			return new Response('{"mensagem":"Erro de sintaxe: faltam parametros para criar"}', 400);
		}
	} elseif ($operacao == "atualizar") {
		//vamos atualizar, vamos montar a query, jogo rápido:
		$update_nome = "";
		$update_description = "";
		$update_ordem = "";
		$update_carry = "";
		$nr_up = 0;
		if (isset($data['subcategoria_nome'])) {
			$update[$nr_up] = "`subcategoria_nome` = '".$db->escape_string($data['subcategoria_nome'])."'";
			$nr_up++;
		}
		if (isset($data['subcategoria_description'])) {
			$update[$nr_up] = "`subcategoria_description` = '".$db->escape_string($data['subcategoria_description'])."'";
			$nr_up++;
		}
		if (isset($data['subcategoria_ordem'])) {
			$update[$nr_up] = "`subcategoria_ordem` = '".$db->escape_string($data['subcategoria_ordem'])."'";
			$nr_up++;
		}
		if (isset($data['subcategoria_carry'])) {
			$update[$nr_up] = "`subcategoria_carry` = '".$db->escape_string($data['subcategoria_carry'])."'";
			$nr_up++;
		}
		$update_text = "";
		for ($i=0;$i<$nr_up;$i++) {
			$update_text .= $update[$i];
			if ($i<($nr_up-1))
				$update_text .= ", ";
		}
		if ($nr_up > 0) {
			$sql_u = "UPDATE `register_subcategorias` SET $update_text, `ModifiedIP`= '$ip', `ModifiedDate`=CURDATE() WHERE `subcategoria_id` = '$subcategoria_id'";
			$atualizar = $db->query($sql_u);
			
			if ($atualizar) {
				$resposta["msg"] = "Atualizado";
				return new Response(json_encode($resposta),200);
			}
			else
				return new Response('{"mensagem":"Erro desconhecido"}', 500);
		} else {
			return new Response('{"mensagem":"Nada para atualizar"}', 400);
		}
	}
	
	return new Response('{"mensagem":"Erro de sintaxe"}',400);
});

$app->post('/subcategoria/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	$user_id = 0;
	$diario_id = 0;
	
	//identificar a quem pertence o item:
	if (isset($data['subcategoria_id'])) {
		$subcategoria_id = $db->escape_string($data['subcategoria_id']);
		$sql_s = "SELECT user_id, diario_id FROM `register_subcategorias` JOIN `register_categorias` ON `register_subcategorias`.`categoria_id` = `register_categorias`.`categoria_id` JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id` WHERE `subcategoria_id` = '$subcategoria_id'";
		$rows = $db->select($sql_s);
		
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} else {
		//se o parametro nao foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	//fazer o que tem que ser feito:
	$sql_d = "DELETE FROM `register_subcategorias` WHERE `subcategoria_id` = '$subcategoria_id'";
	
		$query = $db->query($sql_d);
	
	if ($query) {
		return new Response('{"mensagem":"Excluido"}', 200);
	} else {
		return new Response('{"mensagem":"Erro desconhecido"}', 500);
	}
});

//rota para recuperar todas as contas dado um diario_uid
$app->get('/contas/{diariouid}', function (Request $request, $diariouid) use ($app, $db) {
	global $user;
	
	$diario = getDiarioID($diariouid);
	
	if ($diario) {
		atualizaLastChildModifiedDate($diariouid);
		if ($user['adm'] || $user['id']==$diario['user_id']) {
			$diarioID = $diario['diario_id'];
			$sql = sprintf("SELECT `conta_id`, `conta_nome`, `conta_descricao`, `diario_id`, `conta_reconciliado_valor`, `conta_reconciliado_data`, 
				`conta_budget`, `bank_id`, `conta_img`, `conta_cartao`, `conta_cartao_data_fechamento`, `conta_cartao_data_vencimento` FROM 
				`register_contas` WHERE `diario_id` = '%s' ORDER BY `conta_nome`",$diarioID);
			$rows = $db ->select($sql);
			if ($rows) {
				$resultado = [];
				foreach ($rows as $row) {
					$row['conta_saldo'] = getSaldoContaID($row['conta_id']);
					if ($row['conta_saldo'] == null)
						$row['conta_saldo'] = 0;
					array_push($resultado,$row);
				}
				return new Response(json_encode($resultado),200);
			} else {
				return new Response('{"mensagem":"Nenhuma conta encontrada para este diário"}', 404);
			}
		} else {
			return new Response('{"mensagem":"Você não tem privilégios para isso"}', 403);
		}
	} else {
		return new Response('{"mensagem":"Diário não encontrado"}', 404);
	}
});

//rota para criar ou alterar uma conta
$app->post('/conta',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	//vamos criar uma flag para saber se estamos criando ou atualizando uma linha:
	$operacao = "";
	$user_id = 0;
	$diario_id = 0;
	
	//identificar a quem pertence o item:
	if (isset($data['conta_id'])) {
		//se a categoria foi informada, vamos ignorar o parâmetro diario_uid pois é uma atualização
		$operacao = "atualizar";
		
		$conta_id = $db->escape_string($data['conta_id']);
		$sql_s = "SELECT user_id, conta_id FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `conta_id` = '$conta_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$conta_id = $rows[0]['conta_id'];
		}
	} elseif (isset($data['diario_uid'])) {
		//se a categoria não foi informada, obrigatoriamente o diario_uid deve ser informada para criar uma nova conta.
		$operacao = "criar";
		
		$diario_uid = $db->escape_string($data['diario_uid']);
		$sql_s = "SELECT `user_id`, `id` AS `diario_id` FROM `register_diarios` WHERE `uid`='$diario_uid'";
		
		$rows = $db->select($sql_s);
		
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$diario_id = $rows[0]['diario_id'];
		}
	} else {
		//se nenhum dos dois parametros foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida: faltando diarioUID ou id da conta"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	//fazer o que tem que ser feito:
	if ($operacao == "criar") {
		//ok, vamos criar, então vamos preparar os campos obrigatórios:
		if (isset($data['conta_nome']) && isset($data['conta_descricao'])) {
			$conta_nome = $db->escape_string($data['conta_nome']);
			$conta_descricao = $db->escape_string($data['conta_descricao']);
			if (isset($data['conta_budget'])) {
				$conta_budget = $db->escape_string($data['conta_budget']);
			} else {
				$conta_budget = 1;
			}
			if (isset($data['conta_reconciliado_valor'])) {
				$conta_reconciliado_valor = $db->escape_string($data['conta_reconciliado_valor']);
			} else {
				$conta_reconciliado_valor = 0;
			}
			if (isset($data['conta_reconciliado_data'])) {
				$conta_reconciliado_data = $db->escape_string($data['conta_reconciliado_data']);
			} else {
				$conta_reconciliado_data = date("Y-m-d");
			}
			if (isset($data['conta_cartao'])) {
				$conta_cartao = $db->escape_string($data['conta_cartao']);
			} else {
				$conta_cartao = 0;
			}
			if (isset($data['conta_cartao_data_vencimento'])) {
				$conta_cartao_data_vencimento = $db->escape_string($data['conta_cartao_data_vencimento']);
			} else {
				$conta_cartao_data_vencimento = "";
			}
			if (isset($data['conta_cartao_data_fechamento'])) {
				$conta_cartao_data_fechamento = $db->escape_string($data['conta_cartao_data_fechamento']);
			} else {
				$conta_cartao_data_fechamento = "";
			}			
			
			$ip = $request->getClientIp();

			//ok, estamos prontos para criar:
			$sql_i = "INSERT INTO `register_contas` 
				(`conta_nome`,`conta_descricao`,`conta_budget`,`diario_id`,`conta_reconciliado_valor`,`conta_reconciliado_data`,`conta_cartao`,`conta_cartao_data_fechamento`,`conta_cartao_data_vencimento`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`)
				VALUES ('$conta_nome','$conta_descricao','$conta_budget','$diario_id','$conta_reconciliado_valor','$conta_reconciliado_data','$conta_cartao','$conta_cartao_data_fechamento','$conta_cartao_data_vencimento','$ip','$ip',CURDATE(),CURDATE());";
					
			$inserido = $db->insert($sql_i);
			
			if ($inserido) {
				$resposta['conta_id'] = $inserido;
				$resposta['conta_nome'] = $conta_nome;
				$resposta['diario_id'] = $diario_id;

				$transacao_numero = 'Saldo Inicial';
				$transacao_sacado = $transacao_numero;
				
				$sql_i_t = "INSERT INTO `register_transacoes` 
				(`transacao_numero`,`transacao_data`,`transacao_sacado`,`transacao_valor`,`transacao_conciliada`,`conta_id`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`)
				VALUES
				('$transacao_numero','$conta_reconciliado_data','$transacao_sacado','$conta_reconciliado_valor','1',$inserido,'$ip','$ip',CURDATE(),CURDATE());";
				
				$transacao_inicial = $db->insert($sql_i_t);

				return new Response(json_encode($resposta),201);
			} else 
			{
				return new Response('{"mensagem":"Erro desconhecido"}',500);
			}
		} else {
			return new Response('{"mensagem":"erro de sintaxe: faltam parametros para criar"}', 400);
		}
	} elseif ($operacao == "atualizar") 
	{
		//vamos atualizar, vamos montar a query, jogo rápido:
		$update_nome = "";
		$update_description = "";
		$nr_up = 0;
		if (isset($data['conta_nome'])) {
			$update[$nr_up] = "`conta_nome` = '".$db->escape_string($data['conta_nome'])."'";
			$nr_up++;
		}
		if (isset($data['conta_descricao'])) {
			$update[$nr_up] = "`conta_descricao` = '".$db->escape_string($data['conta_descricao'])."'";
			$nr_up++;
		}
		if (isset($data['conta_cartao_data_fechamento'])) {
			$update[$nr_up] = "`conta_cartao_data_fechamento` = '".$db->escape_string($data['conta_cartao_data_fechamento'])."'";
			$nr_up++;
		}
		if (isset($data['conta_cartao_data_vencimento'])) {
			$update[$nr_up] = "`conta_cartao_data_vencimento` = '".$db->escape_string($data['conta_cartao_data_vencimento'])."'";
			$nr_up++;
		}
		$update_text = "";
		for ($i=0;$i<$nr_up;$i++) {
			$update_text .= $update[$i];
			if ($i<($nr_up-1))
				$update_text .= ", ";
		}
		if ($nr_up > 0) {
			$sql_u = "UPDATE `register_contas` SET $update_text, `ModifiedIP`='$ip',`ModifiedDate`=CURDATE() WHERE `conta_id` = '$conta_id'";
			$atualizar = $db->query($sql_u);
			
			if ($atualizar) {
				$resposta["msg"]="atualizado";
				return new Response(json_encode($resposta),200);				
			}
			else
				return new Response('{"mensagem":"Erro desconhecido"}', 500);
		} else {
		return new Response('{"mensagem":"Nada para atualizar"}', 400);
		}
	}
	
	return new Response('{"mensagem":"Erro de sintaxe"}',400);
});

//rota para deletar uma conta
$app->post('/conta/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	
	//vamos criar uma flag para saber se estamos criando ou atualizando uma linha:
	$operacao = "";
	$user_id = 0;
	$diario_id = 0;
	
	//identificar a quem pertence o item:
	if (isset($data['conta_id'])) {
		$operacao = "deletar";
		
		$conta_id = $db->escape_string($data['conta_id']);
		$sql_s = "SELECT user_id, conta_id FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `conta_id` = '$conta_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$conta_id = $rows[0]['conta_id'];
		}
	} else {
		//se nenhum dos dois parametros foi informado, so sorry, erro de sintaxe:
		return new Response('{"mensagem":"Sintaxe inválida: faltando id da conta"}',400);
	}
	
	//verificar se usuário tem permissão:
	if (!$user['adm'] && $user['id']<>$user_id) {
		return new Response('{"mensagem":"Não autorizado"}',403);
	}
	
	if ($operacao == "deletar") 
	{
		$sql_u = "DELETE FROM register_contas WHERE conta_id='$conta_id';";
			$resultado = $db->query($sql_u);
			if ($resultado) {
				return new Response('{"mensagem":"Deletado"}',200);
			}
			else
				return new Response('{"mensagem":"Sintaxe de entrada inválida"}',400);
	}
	
	return new Response('{"mensagem":"Erro de sintaxe"}',400);
});

//rota para criar um parcelamento
$app->post('/parcelamento',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;
	$ip = $request->getClientIp();

	if (isset($data['parcelamento_comentarios'])) {
		$parc_comentarios = $db->escape_string($data['parcelamento_comentarios']);
	} else {
		$parc_comentarios = null;
	}

	if (isset($data['parcelamento_data']) && isset($data['parcelamento_valor_sjuros']) && isset($data['conta_id'])) {
		$conta_id = $db->escape_string($data['conta_id']);
		$sql_s = "SELECT user_id, conta_id FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `conta_id` = '$conta_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			$user_id = $rows[0]['user_id'];
			$conta_id = $rows[0]['conta_id'];

			//verificar se usuário tem permissão:
			if (!$user['adm'] && $user['id']<>$user_id) {
				return new Response('{"mensagem":"Não autorizado"}',403);
			} else {
				//Tudo certo, vamos inserir:
				$parc_data = $db->escape_string($data['parcelamento_data']);
				$parc_valor_sjuros = $db->escape_string($data['parcelamento_valor_sjuros']);
				$sql = "INSERT INTO `register_parcelamentos` (`parcelamento_data`,`parcelamento_valor_sjuros`,`parcelamento_comentarios`,`conta_id`,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`)
				 VALUES ('$parc_data','$parc_valor_sjuros','$parc_comentarios','$conta_id','$ip','$ip',CURDATE(),CURDATE());";

				$inserido = $db->insert($sql);
							
				if ($inserido) {
					$resposta['parcelamento_id'] = $inserido;
					return new Response(json_encode($resposta),201);
				} else 
				{
					return new Response('{"mensagem":"Erro desconhecido"}',500);
				}
			}
		} else {
			return new Response('{"mensagem":"Entradas inválidas"}',403);
		}
	} else {
		return new Response('{"mensagem":"Faltando entradas"}',403);
	}
});

//rota para editar um parcelamento
$app->post('/parcelamento/put',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;
	$ip = $request->getClientIp();

	$sql = "";
	if (isset($data['parcelamento_comentarios'])) {
		$sql = montaUpdateSQL('`parcelamento_comentarios`',$db->escape_string($data['parcelamento_comentarios']),$sql);
	}
	if (isset($data['conta_id'])) {
		$sql = montaUpdateSQL('`conta_id`',$db->escape_string($data['conta_id']),$sql);
	}
	if (isset($data['parcelamento_data'])) {
		$sql = montaUpdateSQL('`parcelamento_data`',$db->escape_string($data['parcelamento_data']),$sql);
	}
	if (isset($data['`parcelamento_valor_sjuros`'])) {
		$sql = montaUpdateSQL('parcelamento_valor_sjuros',$db->escape_string($data['parcelamento_valor_sjuros']),$sql);
	}

	if (strlen($sql)>0 && isset($data['parcelamento_id'])) {
		$parcelamento_id = $db->escape_string($data['parcelamento_id']);
		$sql_s = "SELECT user_id, `register_parcelamentos`.conta_id FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` JOIN `register_parcelamentos` ON `register_parcelamentos`.`conta_id` = `register_contas`.`conta_id` WHERE `parcelamento_id` = '$parcelamento_id'";
		$rows = $db->select($sql_s);
		if ($rows) {
			if (isset($data['conta_id'])) {
				$contaIDnova = $db->escape_string($data['conta_id']);
				$sql_s = "SELECT user_id FROM `register_contas` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `conta_id` = '$contaIDnova'";
				$rows1 = $db->select($sql_s);
				if (!$rows1)
					return new Response('{"mensagem":"Conta inválida"}',403);
				if ($rows1[0]['user_id']!=$rows[0]['user_id']) {
					return new Response('{"mensagem":"Não autorizado"}',403);
				}
			}
			$user_id = $rows[0]['user_id'];
			$conta_id = $rows[0]['conta_id'];

			//verificar se usuário tem permissão:
			if (!$user['adm'] && $user['id']<>$user_id) {
				return new Response('{"mensagem":"Não autorizado"}',403);
			} else {
				//Tudo certo, vamos atualizar:
				$sql = "UPDATE `register_parcelamentos` SET $sql,`ModifiedIP`='$ip',`ModifiedDate`=CURDATE() WHERE `parcelamento_id`='$parcelamento_id';";

				$inserido = $db->query($sql);
							
				if ($inserido) {
					return new Response('{"mensagem":"Atualizado"}',200);
				} else 
				{
					return new Response('{"mensagem":"Erro desconhecido"}',500);
				}
			}
		} else {
			return new Response('{"mensagem":"Entradas inválidas"}',403);
		}
	} else {
		return new Response('{"mensagem":"Faltando entradas"}',403);
	}
});

//rota para deletar um parcelamento
$app->post('/parcelamento/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;

	if (!isset($data['parcelamento_id']))
		return new Response('{"mensagem":"Faltando entradas!"}',403);
		
		$parcelamento_id = $db->escape_string($data['parcelamento_id']);
		$sql_s = "SELECT `user_id` FROM `register_parcelamentos` JOIN `register_contas` ON `register_parcelamentos`.`conta_id` = `register_contas`.`conta_id` JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` WHERE `register_parcelamentos`.`parcelamento_id` = '$parcelamento_id';";

		$rows = $db->select($sql_s);

		if ($rows) {
			if (!$user['adm'] && $user['id'] != $rows[0]['user_id']) {
				return new Response('{"mensagem":"Não autorizado"}',403);	
			} else {
				$sql_d = "DELETE FROM `register_parcelamentos` WHERE `parcelamento_id` = '$parcelamento_id'";
				$res = $db->query($sql_d);
				if ($res) {
					return new Response('{"mensagem":"Excluído"}',200);
				} else {
					return new Response('{"mensagem":"Erro desconhecido"}',500);
				}
			}
		} else {
			return new Response('{"mensagem":"Entrada inválida"}',403);
		}
});

//rota para recuperar parcelamento
$app->get('/parcelamento',function (Request $request) use ($app, $db) {
	global $user;
	
	$diario_uid = $db->escape_string($request->headers->get("diariouid"));
	$filtros_header 	= $request->headers->get("filtros");
	$filtros = json_decode($filtros_header);

	if ($filtros_header == null) {
		$sql_s = "SELECT `register_parcelamentos`.`parcelamento_id`, `parcelamento_data`,`parcelamento_valor_sjuros`,`parcelamento_comentarios`,
		`register_contas`.`conta_id`,`conta_nome`,`conta_budget`,`conta_cartao` 
		FROM `register_parcelamentos` 
		JOIN `register_contas` ON `register_parcelamentos`.`conta_id` 
		JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` 
		WHERE `register_diarios`.`uid` = '$diario_uid';";
		$rows = $db->select($sql_s);

		if ($rows) {
			return new Response (json_encode($rows),200);
		} else {
			return new Response('{"mensagem":"Nenhum resultado"}', 404);
		}
	} if ($filtros === null) {
		return new Response('{"mensagem":"Sintaxe inválida dos filtros"}', 403);
	} else {
		$where = "";
		if (isset($filtros->parcelamento_id)) {
			$where = montaWhereSQL('`register_parcelamentos`.`parcelamento_id`',$db->escape_string($filtros->parcelamento_id),$where);
		}
		if (isset($filtros->parcelamento_comentarios)) {
			$where = montaWhereSQL('`register_parcelamentos`.`parcelamento_comentarios`',$db->escape_string($filtros->parcelamento_comentarios),$where);
		}
		if (isset($filtros->parcelamento_data)) {
			$where = montaWhereSQL('`register_parcelamentos`.`parcelamento_data`',$db->escape_string($filtros->parcelamento_data),$where);
		}
		if (isset($filtros->conta_id)) {
			$where = montaWhereSQL('`register_parcelamentos`.`conta_id`',$db->escape_string($filtros->conta_id),$where);
		}
		

		$sql_s = "SELECT `register_parcelamentos`.`parcelamento_id`, `parcelamento_data`,`parcelamento_valor_sjuros`,`parcelamento_comentarios`,
		`register_contas`.`conta_id`,`conta_nome`,`conta_budget`,`conta_cartao` 
		FROM `register_parcelamentos` 
		JOIN `register_contas` ON `register_parcelamentos`.`conta_id` 
		JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id` 
		WHERE `register_diarios`.`uid` = '$diario_uid' AND $where;";
		$rows = $db->select($sql_s);

		if ($rows) {
			return new Response (json_encode($rows),200);
		} else {
			return new Response('{"mensagem":"Nenhum resultado"}', 404);
		}
	}
});

//rota para criar uma transação
$app->post('/transacao',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;
	$ip = $request->getClientIp();
	$atualizacao = false;

	if (isset($data['transacao_id'])) {
		$atualizacao = true;
	} elseif(isset($data['conta_id'])) {
		$atualizacao = false;
	} else {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	}

	//agora, vamos verificar se a atualização ou criação pode ser realizada pelo usuário:
	if ($atualizacao) {
		$sql_verificacao = "SELECT `user_id` FROM `register_diarios` 
							JOIN `register_contas` ON `register_diarios`.`id` = `register_contas`.`diario_id` 
							JOIN `register_transacoes` ON `register_contas`.`conta_id` = `register_transacoes`.`conta_id` 
							WHERE `register_transacoes`.`transacao_id` = '".$data['transacao_id']."';";
	} else
		$sql_verificacao = "SELECT `user_id` FROM `register_diarios`
							JOIN `register_contas` ON `register_diarios`.`id` = `register_contas`.`diario_id` 
							WHERE `register_contas`.`conta_id` = '".$data['conta_id']."';";
	$rows = $db->select($sql_verificacao);
	if (!$rows) {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	} elseif ($rows[0]['user_id']!=$user['id'] && !$user['adm']) {
		return new Response('{"mensagem":"Não autorizado!"}', 403);
	}

	$tabela_db = "register_transacoes";
	$colunasObrigatorias = "";
	$colunasModificar = "";
	$colunasWhere = "";
	//agora, vamos definir quais colunas são obrigatórias e quais serão inseridas/atualizadas:
	if ($atualizacao) {
		$colunasObrigatorias 	= "transacao_id";
		$colunasModificar		= "conta_id,transacao_numero,transacao_data,transacao_sacado,transacao_descricao,transacao_valor,transacao_conciliada,transacao_conciliada,transacao_merged_to_id,transacao_fatura_data,parcelamento_id";
		$colunasWhere			= "transacao_id";
	} else {
		$colunasObrigatorias	= "transacao_data,transacao_sacado,transacao_valor,conta_id";
		$colunasModificar		= "transacao_numero,transacao_data,transacao_sacado,transacao_descricao,transacao_valor,transacao_conciliada,transacao_conciliada,transacao_merged_to_id,conta_id,transacao_fatura_data,parcelamento_id";
	}

	//a partir de agora, não se modifica o template:
	//primeiro, vamos verificar se as colunas obrigatórias foram fornecidas pelo usuario:
	$colunasObrigatorias = explode(",",$colunasObrigatorias);
	foreach ($colunasObrigatorias as $coluna) {
		if (!isset($data[$coluna])) {
			//não existe uma coluna obrigatória, encerramos por aqui:
			return new Response('{"mensagem":"Faltando entradas obrigatórias"}', 400);
		}
	}

	//agora, vamos montar a query:
	$colunasModificar = explode(",",$colunasModificar);
	if ($atualizacao) {
		$sqlSet = "";
		$sqlWhere = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSet))
					$sqlSet .= ",";
				$sqlSet .= montaUpdateSQL($colunaModificar,$db->escape_string($data[$colunaModificar]));
			}
		}
		$colunasWhere = explode(",",$colunasWhere);
		foreach ($colunasWhere as $colunaWhere) {
			if (strlen($sqlWhere))
				$sqlWhere .= ",";
			$sqlWhere .= montaWhereSQL($colunaWhere,$db->escape_string($data[$colunaWhere]));
		}
		//blz, montamos o rolê, vamos agora executar a query:
		$sql = "UPDATE $tabela_db SET $sqlSet,`ModifiedIP`='$ip',`ModifiedDate`=CURDATE() WHERE $sqlWhere;";
		$resultado = $db->query($sql);
		if ($resultado) {
			return new Response('{"mensagem":"Atualizado!"}', 200);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	} else {
		$sqlCols = "";
		$sqlSets = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSets)) {
					$sqlSets .= ",";
					$sqlCols .= ",";
				}
				$sqlSets .= "'".$db->escape_string($data[$colunaModificar])."'";
				$sqlCols .= "`".$colunaModificar."`";
			}
		}
		$sql = "INSERT INTO `$tabela_db` ($sqlCols,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES ($sqlSets,'$ip','$ip',CURDATE(),CURDATE());";
		$primary_key = $db->insert($sql);
		if ($primary_key) {
			return new Response('{"id":'.$primary_key.'}', 201);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	}	
});

//rota para deletar uma transação
$app->post('/transacao/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;

	$tabela_db 	= "register_transacoes";
	$pk_db 		= "transacao_id";
	$pk_val 	= $data['transacao_id'];

	if (!is_array($pk_val))
		return new Response('{"mensagem":"Sintaxe inválida: array esperada."}', 400);

	$foreignColumns[0] = new ForeignRelationship('register_transacoes',"transacao_id","conta_id");
	$foreignColumns[1] = new ForeignRelationship('register_contas',"conta_id","diario_id");
	$foreignColumns[2] = new ForeignRelationship('register_diarios',"id",null);

	$falha = false;
	foreach ($pk_val as $value) {
		$user_id = retrieveUserIdFromTables($foreignColumns,'transacao_id',$value);
		if (!$user['adm'] && $user['id'] != $user_id) {
			$falha = true;
		} else {
			$sql_d = "DELETE FROM `$tabela_db` WHERE `$pk_db` = '$value'";
			$res = $db->query($sql_d);
			if (!$res) {
				$falha = true;
			}
		}		
	}
	
	if ($falha) {
		return new Response('{"mensagem":"Houve falhas no processo, alguns itens podem não ter sido excluídos"}',403);	
	} else {
		return new Response('{"mensagem":"Itens excluídos com sucesso"}', 200);
	}
});

//rota para aprovar uma transação
$app->post('/transacao/aprova',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;

	$tabela_db 	= "register_transacoes";
	$pk_db 		= "transacao_id";
	$pk_val 	= $data['transacoes'];
	

	if (!is_array($pk_val))
		return new Response('{"mensagem":"Sintaxe inválida: array esperada."}', 400);

	$foreignColumns[0] = new ForeignRelationship('register_transacoes',"transacao_id","conta_id");
	$foreignColumns[1] = new ForeignRelationship('register_contas',"conta_id","diario_id");
	$foreignColumns[2] = new ForeignRelationship('register_diarios',"id",null);

	$falha = false;
	foreach ($pk_val as $value) {
		$transacao_id = $db->escape_string($value['id']);
		if ($value['aprova'])
			$transacao_concilia = 1;
		else 
			$transacao_concilia = 0;
		$user_id = retrieveUserIdFromTables($foreignColumns,'transacao_id',$transacao_id);
		if (!$user['adm'] && $user['id'] != $user_id) {
			$falha = true;
		} else {
			$sql_u = "UPDATE $tabela_db SET `transacao_conciliada`='$transacao_concilia' WHERE `$pk_db`='$transacao_id';";
			$res = $db->query($sql_u);
			if (!$res) {
				$falha = true;
			}
		}		
	}
	
	if ($falha) {
		return new Response('{"mensagem":"Houve falhas no processo, alguns itens podem não ter sido atualizados"}',403);	
	} else {
		return new Response('{"mensagem":"Itens atualizados com sucesso"}', 200);
	}
});

//rota para conciliar uma transação
$app->post('/transacao/concilia',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;

	$tabela_db 	= "register_transacoes";
	$pk_db 		= "transacao_id";
	$pk_val 	= $data['transacoes'];
	

	if (!is_array($pk_val))
		return new Response('{"mensagem":"Sintaxe inválida: array esperada."}', 400);

	$foreignColumns[0] = new ForeignRelationship('register_transacoes',"transacao_id","conta_id");
	$foreignColumns[1] = new ForeignRelationship('register_contas',"conta_id","diario_id");
	$foreignColumns[2] = new ForeignRelationship('register_diarios',"id",null);

	$falha = false;
	foreach ($pk_val as $value) {
		$transacao_id = $db->escape_string($value['id']);
		if ($value['concilia'])
			$transacao_concilia = 1;
		else 
			$transacao_concilia = 0;
		$user_id = retrieveUserIdFromTables($foreignColumns,'transacao_id',$transacao_id);
		if (!$user['adm'] && $user['id'] != $user_id) {
			$falha = true;
		} else {
			$sql_u = "UPDATE $tabela_db SET `transacao_conciliada`='$transacao_concilia' WHERE `$pk_db`='$transacao_id';";
			$res = $db->query($sql_u);
			if (!$res) {
				$falha = true;
			}
		}		
	}
	
	if ($falha) {
		return new Response('{"mensagem":"Houve falhas no processo, alguns itens podem não ter sido atualizados"}',403);	
	} else {
		return new Response('{"mensagem":"Itens atualizados com sucesso"}', 200);
	}
});

//rota para recuperar transacoes incluindo subtransacoes
$app->get('/subtransacao',function (Request $request) use ($app, $db) {
	global $user;
	
	$diario_uid 		= $db->escape_string($request->headers->get("diariouid"));
	$filtros_header 	= $request->headers->get("filtros");
	$filtros 			= json_decode($filtros_header);

	$foreignColumns[0] = new ForeignRelationship('register_diarios',"id",null);

	if (!$request->headers->get("diariouid")) {
		return new Response('{"mensagem":"Você deve informar um UniqueID de Diário"}', 400);
	}

	$user_id = retrieveUserIdFromTables($foreignColumns,'uid',$diario_uid);
	if (!$user['adm'] && $user['id'] != $user_id) {
		return new Response('{"mensagem":"Sem autorização."}', 403);
	}
	if ($filtros_header == null) {
		$sql_s = "(SELECT `register_transacoes`.`transacao_id`,`register_transacoes`.`transacao_data`,
				`register_transacoes`.`transacao_sacado`,`register_transacoes`.`transacao_descricao`, `register_transacoes`.`transacao_valor`,
				`register_transacoes`.`transacao_conciliada`,`register_transacoes`.`transacao_aprovada`,`register_transacoes`.`transacao_merged_to_id`,
				`register_transacoes`.`conta_id`,`register_transacoes`.`transacao_numero`,`register_contas`.`conta_nome`,`register_contas`.`conta_cartao`,`register_diarios`.`uid` AS `diario_uid`,
				`register_transacoes_itens`.`transacoes_item_id`,`register_transacoes_itens`.`transacoes_item_descricao`, `register_transacoes_itens`.`transacoes_item_valor`,
				`register_transacoes_itens`.`transf_para_conta_id`,`contas2`.`conta_nome` AS `transf_para_conta_nome`, IF(`register_transacoes_itens`.`transf_para_conta_id` IS NOT NULL, 'Origem','Nao-Transferencia') AS `transf_para_tipo`,
				`register_categorias`.`categoria_id`,`register_categorias`.`categoria_nome`,
				`register_subcategorias`.`subcategoria_id`,`register_subcategorias`.`subcategoria_nome`,
				`register_transacoes`.`transacao_fatura_data` FROM `register_diarios`
				JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
				JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
				LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
				LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
				LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
				LEFT JOIN `register_contas` `contas2` ON `register_transacoes_itens`.`transf_para_conta_id` = `contas2`.`conta_id`
				WHERE `register_diarios`.`uid` = '$diario_uid')
				UNION
				(SELECT `register_transacoes`.`transacao_id`,`register_transacoes`.`transacao_data`,
				`register_transacoes`.`transacao_sacado`,`register_transacoes`.`transacao_descricao`, -`register_transacoes_itens`.`transacoes_item_valor` AS `transacao_valor`, `register_transacoes`.`transacao_conciliada`,`register_transacoes`.`transacao_aprovada`,`register_transacoes`.`transacao_merged_to_id`,
				`register_transacoes_itens`.`transf_para_conta_id` AS `conta_id`,`register_transacoes`.`transacao_numero`,`contas2`.`conta_nome` AS `conta_nome`,`contas2`.`conta_cartao` AS `conta_cartao`,`register_diarios`.`uid` AS `diario_uid`,
				`register_transacoes_itens`.`transacoes_item_id`,`register_transacoes_itens`.`transacoes_item_descricao`, -`register_transacoes_itens`.`transacoes_item_valor` AS `transacoes_item_valor`,
				`register_transacoes`.`conta_id` AS `transf_para_conta_id`,`register_contas`.`conta_nome` AS `transf_para_conta_nome`, 'Replica' AS `transf_para_tipo`,
				`register_categorias`.`categoria_id`,`register_categorias`.`categoria_nome`,
				`register_subcategorias`.`subcategoria_id`,`register_subcategorias`.`subcategoria_nome`,
				`register_transacoes`.`transacao_fatura_data` FROM `register_diarios`
				JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
				JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
				LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
				LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
				LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
				LEFT JOIN `register_contas` `contas2` ON `register_transacoes_itens`.`transf_para_conta_id` = `contas2`.`conta_id`
				WHERE `register_transacoes_itens`.`transf_para_conta_id` IS NOT NULL AND `register_diarios`.`uid` = '$diario_uid')
				ORDER BY `transacao_data` ASC;";
		$rows = $db->select($sql_s);

		if ($rows) {
			return new Response (json_encode($rows),200);
		} else {
			return new Response('{"mensagem":"Nenhum resultado"}', 404);
		}
	} if ($filtros === null) {
		return new Response('{"mensagem":"Sintaxe inválida dos filtros"}', 403);
	} else {
		$where = "";
		$colunas = array(
			"transacao_id" => "register_transacoes",
			"transacao_sacado" => "register_transacoes"
		);
		foreach ($filtros as $filtro) {
			if (isset($colunas[$filtro->column_name])) {
				$coluna = $colunas[$filtro->column_name];
				$valoresPermitidosComparacao = array("=","<>",">",">=","<","<=","like","in","between");
				if (!in_array($filtro->kindOfCompare,$valoresPermitidosComparacao))
					$filtro->kindOfCompare = "="; //caso o usuario tenha tentado ser espertinho, resetamos o valor. SQL Injection
				$filtro->column_name = $db->escape_string($filtro->column_name);
				$where = montaWhereSQL("`$coluna`.`$filtro->column_name`",$db->escape_string($filtro->value),$where,$filtro->kindOfCompare);
			}
		}
		if (strlen($where) > 0)
			$where = "AND " . $where;
			$sql_s = "(SELECT `register_transacoes`.`transacao_id`,`register_transacoes`.`transacao_data`,
						`register_transacoes`.`transacao_sacado`,`register_transacoes`.`transacao_descricao`, `register_transacoes`.`transacao_valor`,
						`register_transacoes`.`transacao_conciliada`,`register_transacoes`.`transacao_aprovada`,`register_transacoes`.`transacao_merged_to_id`,
						`register_transacoes`.`conta_id`,`register_contas`.`conta_nome`,`register_diarios`.`uid` AS `diario_uid`,
						`register_transacoes_itens`.`transacoes_item_id`,`register_transacoes_itens`.`transacoes_item_descricao`, `register_transacoes_itens`.`transacoes_item_valor`,
						`register_transacoes_itens`.`transf_para_conta_id`,`register_categorias`.`categoria_id`,`register_categorias`.`categoria_nome`,
						`register_subcategorias`.`subcategoria_id`,`register_subcategorias`.`subcategoria_nome` FROM `register_diarios`
						JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
						JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
						LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
						LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
						LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
						WHERE `register_diarios`.`uid` = '$diario_uid' $where)
					UNION
					(SELECT `register_transacoes`.`transacao_id`,`register_transacoes`.`transacao_data`,
							`register_transacoes`.`transacao_sacado`,`register_transacoes`.`transacao_descricao`, -`register_transacoes_itens`.`transacoes_item_valor` AS `transacao_valor`, `register_transacoes`.`transacao_conciliada`,`register_transacoes`.`transacao_aprovada`,`register_transacoes`.`transacao_merged_to_id`,
							`register_transacoes_itens`.`transf_para_conta_id` AS `conta_id`,`contas2`.`conta_nome` AS `conta_nome`,`register_diarios`.`uid` AS `diario_uid`,
							`register_transacoes_itens`.`transacoes_item_id`,`register_transacoes_itens`.`transacoes_item_descricao`, -`register_transacoes_itens`.`transacoes_item_valor` AS `transacoes_item_valor`,
							`register_transacoes`.`conta_id` AS `transf_para_conta_id`,`register_contas`.`conta_nome` AS `transf_para_conta_nome`,`register_categorias`.`categoria_id`,`register_categorias`.`categoria_nome`,
							`register_subcategorias`.`subcategoria_id`,`register_subcategorias`.`subcategoria_nome` FROM `register_diarios`
							JOIN `register_contas` ON `register_contas`.`diario_id` = `register_diarios`.`id`
							JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
							LEFT JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
							LEFT JOIN `register_subcategorias` ON `register_subcategorias`.`subcategoria_id` = `register_transacoes_itens`.`subcategoria_id`
							LEFT JOIN `register_categorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
							LEFT JOIN `register_contas` `contas2` ON `register_transacoes_itens`.`transf_para_conta_id` = `contas2`.`conta_id`
							WHERE `register_transacoes_itens`.`transf_para_conta_id` IS NOT NULL AND `register_diarios`.`uid` = '$diario_uid' $where);";
		$rows = $db->select($sql_s);

		if ($rows) {
			return new Response (json_encode($rows),200);
		} else {
			return new Response('{"mensagem":"Nenhum resultado"}', 404);
		}
	}
});

//rota para criar ou editar uma subtransação
$app->post('/subtransacao',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;
	$ip = $request->getClientIp();
	$atualizacao = false;

	if (isset($data['transacoes_item_id'])) {
		$atualizacao = true;
	} elseif(isset($data['transacao_id'])) {
		$atualizacao = false;
	} else {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	}

	//agora, vamos verificar se a atualização ou criação pode ser realizada pelo usuário:
	if ($atualizacao) {
		$sql_verificacao = "SELECT `user_id` FROM `register_diarios` 
							JOIN `register_contas` ON `register_diarios`.`id` = `register_contas`.`diario_id` 
							JOIN `register_transacoes` ON `register_contas`.`conta_id` = `register_transacoes`.`conta_id` 
							JOIN `register_transacoes_itens` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
							WHERE `register_transacoes_itens`.`transacoes_item_id` = '".$data['transacoes_item_id']."';";
	} else
		$sql_verificacao = "SELECT `user_id` FROM `register_diarios`
							JOIN `register_contas` ON `register_diarios`.`id` = `register_contas`.`diario_id`
							JOIN `register_transacoes` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id` 
							WHERE `register_transacoes`.`transacao_id` = '".$data['transacao_id']."';";
	$rows = $db->select($sql_verificacao);
	if (!$rows) {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	} elseif ($rows[0]['user_id']!=$user['id'] && !$user['adm']) {
		return new Response('{"mensagem":"Não autorizado!"}', 403);
	}

	$tabela_db = "register_transacoes_itens";
	$colunasObrigatorias = "";
	$colunasModificar = "";
	$colunasWhere = "";
	//agora, vamos definir quais colunas são obrigatórias e quais serão inseridas/atualizadas:
	if ($atualizacao) {
		$colunasObrigatorias 	= "transacoes_item_id";
		$colunasModificar		= "transacoes_item_valor,transacoes_item_descricao,subcategoria_id,transf_para_conta_id";
		$colunasWhere			= "transacoes_item_id";
	} else {
		$colunasObrigatorias	= "transacoes_item_valor,transacao_id";
		$colunasModificar		= "transacoes_item_valor,transacoes_item_descricao,transacao_id,subcategoria_id,transf_para_conta_id";
	}

	//a partir de agora, não se modifica o template:
	//primeiro, vamos verificar se as colunas obrigatórias foram fornecidas pelo usuario:
	$colunasObrigatorias = explode(",",$colunasObrigatorias);
	foreach ($colunasObrigatorias as $coluna) {
		if (!isset($data[$coluna])) {
			//não existe uma coluna obrigatória, encerramos por aqui:
			return new Response('{"mensagem":"Faltando entradas obrigatórias"}', 400);
		}
	}

	//agora, vamos montar a query:
	$colunasModificar = explode(",",$colunasModificar);
	if ($atualizacao) {
		$sqlSet = "";
		$sqlWhere = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSet))
					$sqlSet .= ",";
				$sqlSet .= montaUpdateSQL($colunaModificar,$db->escape_string($data[$colunaModificar]));
			}
		}
		$colunasWhere = explode(",",$colunasWhere);
		foreach ($colunasWhere as $colunaWhere) {
			if (strlen($sqlWhere))
				$sqlWhere .= ",";
			$sqlWhere .= montaWhereSQL($colunaWhere,$db->escape_string($data[$colunaWhere]));
		}
		//blz, montamos o rolê, vamos agora executar a query:
		$sql = "UPDATE $tabela_db SET $sqlSet,`ModifiedIP`='$ip',`ModifiedDate`=CURDATE() WHERE $sqlWhere;";
		$resultado = $db->query($sql);
		if ($resultado) {
			return new Response('{"mensagem":"Atualizado!"}', 200);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	} else {
		$sqlCols = "";
		$sqlSets = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSets)) {
					$sqlSets .= ",";
					$sqlCols .= ",";
				}
				$sqlSets .= "'".$db->escape_string($data[$colunaModificar])."'";
				$sqlCols .= "`".$colunaModificar."`";
			}
		}
		$sql = "INSERT INTO `$tabela_db` ($sqlCols,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES ($sqlSets,'$ip','$ip',CURDATE(),CURDATE());";
		$primary_key = $db->insert($sql);
		if ($primary_key) {
			return new Response('{"id":'.$primary_key.'}', 201);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	}	
});

//rota para deletar uma transação
$app->post('/subtransacao/delete',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;

	$tabela_db 	= "register_transacoes_itens";
	$pk_db 		= "transacoes_item_id";
	$pk_val 	= $data['transacoes_item_id'];

	if (!is_array($pk_val))
		return new Response('{"mensagem":"Sintaxe inválida: array esperada."}', 400);

	$foreignColumns[0] = new ForeignRelationship('register_transacoes_itens',"transacoes_item_id","transacao_id");
	$foreignColumns[1] = new ForeignRelationship('register_transacoes',"transacao_id","conta_id");
	$foreignColumns[2] = new ForeignRelationship('register_contas',"conta_id","diario_id");
	$foreignColumns[3] = new ForeignRelationship('register_diarios',"id",null);

	$falha = false;
	foreach ($pk_val as $value) {
		$user_id = retrieveUserIdFromTables($foreignColumns,$pk_db,$value);
		if (!$user['adm'] && $user['id'] != $user_id) {
			$falha = true;
		} else {
			$sql_d = "DELETE FROM `$tabela_db` WHERE `$pk_db` = '$value'";
			$res = $db->query($sql_d);
			if (!$res) {
				$falha = true;
			}
		}		
	}
	
	if ($falha) {
		return new Response('{"mensagem":"Houve falhas no processo, alguns itens podem não ter sido excluídos"}',403);	
	} else {
		return new Response('{"mensagem":"Itens excluídos com sucesso"}', 200);
	}
});

//rota para criar um orçamento
$app->post('/orcamento',function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	$user_id = 0;
	$ip = $request->getClientIp();
	$atualizacao = false;

	$sql_verificacao = "SELECT `orcamento_id` FROM `register_orcamentos` WHERE `subcategoria_id`='".$data['subcategoria_id']."' && `orcamento_date` = '".$data['orcamento_date']."';";
	$rows = $db->select($sql_verificacao);
	if ($rows) {
		$data['orcamento_id'] = $rows[0]['orcamento_id'];
	}

	if (isset($data['orcamento_id'])) {
		$atualizacao = true;
	} elseif(isset($data['diario_uid'])) {
		$atualizacao = false;
	} else {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	}

	//agora, vamos verificar se a atualização ou criação pode ser realizada pelo usuário:
	if ($atualizacao) {
		$sql_verificacao = "SELECT `user_id`, `id` FROM `register_diarios` 
							JOIN `register_orcamentos` ON `register_diarios`.`id` = `register_orcamentos`.`diario_id` 
							WHERE `register_orcamentos`.`orcamento_id` = '".$data['orcamento_id']."';";
	} else
		$sql_verificacao = "SELECT `user_id`, `id` FROM `register_diarios`
							WHERE `register_diarios`.`uid` = '".$data['diario_uid']."';";
	$rows = $db->select($sql_verificacao);
	if (!$rows) {
		return new Response('{"mensagem":"Sintaxe inválida"}', 400);
	} elseif ($rows[0]['user_id']!=$user['id'] && !$user['adm']) {
		return new Response('{"mensagem":"Não autorizado!"}', 403);
	} else {
		$data['diario_id'] = $rows[0]['id'];
	}

	$tabela_db = "register_orcamentos";
	$colunasObrigatorias = "";
	$colunasModificar = "";
	$colunasWhere = "";
	//agora, vamos definir quais colunas são obrigatórias e quais serão inseridas/atualizadas:
	if ($atualizacao) {
		$colunasObrigatorias 	= "orcamento_id";
		$colunasModificar		= "orcamento_date,orcamento_valor,subcategoria_id";
		$colunasWhere			= "orcamento_id";
	} else {
		$colunasObrigatorias	= "orcamento_date,orcamento_valor,diario_id,subcategoria_id";
		$colunasModificar		= "orcamento_date,orcamento_valor,diario_id,subcategoria_id";
	}

	//a partir de agora, não se modifica o template:
	//primeiro, vamos verificar se as colunas obrigatórias foram fornecidas pelo usuario:
	$colunasObrigatorias = explode(",",$colunasObrigatorias);
	foreach ($colunasObrigatorias as $coluna) {
		if (!isset($data[$coluna])) {
			//não existe uma coluna obrigatória, encerramos por aqui:
			return new Response('{"mensagem":"Faltando entradas obrigatórias"}', 400);
		}
	}

	//agora, vamos montar a query:
	$colunasModificar = explode(",",$colunasModificar);
	if ($atualizacao) {
		$sqlSet = "";
		$sqlWhere = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSet))
					$sqlSet .= ",";
				$sqlSet .= montaUpdateSQL($colunaModificar,$db->escape_string($data[$colunaModificar]));
			}
		}
		$colunasWhere = explode(",",$colunasWhere);
		foreach ($colunasWhere as $colunaWhere) {
			if (strlen($sqlWhere))
				$sqlWhere .= ",";
			$sqlWhere .= montaWhereSQL($colunaWhere,$db->escape_string($data[$colunaWhere]));
		}
		//blz, montamos o rolê, vamos agora executar a query:
		$sql = "UPDATE $tabela_db SET $sqlSet,`ModifiedIP`='$ip',`ModifiedDate`=CURDATE() WHERE $sqlWhere;";
		$resultado = $db->query($sql);
		if ($resultado) {
			return new Response('{"mensagem":"Atualizado!"}', 200);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	} else {
		$sqlCols = "";
		$sqlSets = "";
		foreach ($colunasModificar as $colunaModificar) {
			if (isset($data[$colunaModificar])) {
				if (strlen($sqlSets)) {
					$sqlSets .= ",";
					$sqlCols .= ",";
				}
				$sqlSets .= "'".$db->escape_string($data[$colunaModificar])."'";
				$sqlCols .= "`".$colunaModificar."`";
			}
		}
		$sql = "INSERT INTO `$tabela_db` ($sqlCols,`CreatedIP`,`ModifiedIP`,`CreatedDate`,`ModifiedDate`) VALUES ($sqlSets,'$ip','$ip',CURDATE(),CURDATE());";
		$primary_key = $db->insert($sql);
		if ($primary_key) {
			return new Response('{"id":'.$primary_key.'}', 201);
		} else {
			return new Response('{"mensagem":"Falha."}', 400);
		}
	}	
});

//rota para recuperar os orçamentos dado um mês e ano e diarioid
$app->get('/orcamento',function (Request $request) use ($app, $db) {
	global $user;
	
	$diario_uid 	= $db->escape_string($request->headers->get("diariouid"));
	$anoAtual	 	= $db->escape_string($request->headers->get("ano"));
	$mesAtual 		= $db->escape_string($request->headers->get("mes"));
	$mesAtual_m_2	= $mesAtual-2;
	$anoAtual_m_2 	= $anoAtual;
	if ($mesAtual_m_2 <= 0) {
		$mesAtual_m_2 = 12 + $mesAtual_m_2;
		$anoAtual_m_2 -= 1;
	}
	$foreignColumns[0] = new ForeignRelationship('register_diarios',"id",null);

	if (!$request->headers->get("diariouid") || !$request->headers->get("mes") || !$request->headers->get("ano")) {
		return new Response('{"mensagem":"Você deve informar um UniqueID de Diário e/ou mes e/ou ano"}', 400);
	}

	$user_id = retrieveUserIdFromTables($foreignColumns,'uid',$diario_uid);
	if (!$user['adm'] && $user['id'] != $user_id) {
		return new Response('{"mensagem":"Sem autorização."}', 403);
	}

	//beleza, feitas as verificações de praxe, vamos começar o show:
	$sql_categorias = 
	"SELECT `register_categorias`.`categoria_id`, `register_categorias`.`categoria_nome`, `register_categorias`.`categoria_description`,
			`register_categorias`.`categoria_ordem`,`register_categorias`.`diario_id`, `register_diarios`.`uid` AS `diario_uid`,
			`register_subcategorias`.`subcategoria_id`, `register_subcategorias`.`subcategoria_nome`,`register_subcategorias`.`subcategoria_description`,
			`register_subcategorias`.`subcategoria_carry`,`register_subcategorias`.`subcategoria_ordem`
	 FROM `register_categorias` 
	 INNER JOIN `register_subcategorias` ON `register_categorias`.`categoria_id` = `register_subcategorias`.`categoria_id`
     INNER JOIN `register_diarios` ON `register_categorias`.`diario_id` = `register_diarios`.`id`
	 WHERE `register_diarios`.`uid` = '$diario_uid'
	 ORDER BY `register_categorias`.`categoria_ordem`, `register_subcategorias`.`subcategoria_ordem`;";

	$categorias = $db->select($sql_categorias);
	if (!$categorias) {
		return new Response('{"mensagem":"Nenhum resultado."}', 404);
	}

	$sql_orcamentos = 
	"SELECT 
		`register_orcamentos`.`orcamento_id`, MONTH(`register_orcamentos`.`orcamento_date`) AS `orcamento_mes`, YEAR(`register_orcamentos`.`orcamento_date`) AS `orcamento_ano`, 
		`register_orcamentos`.`orcamento_valor`, 
		`register_orcamentos`.`subcategoria_id`, `register_orcamentos`.`diario_id`, `register_diarios`.`uid` AS `diario_uid`
	FROM `register_orcamentos`
	INNER JOIN `register_diarios` ON `register_orcamentos`.`diario_id` = `register_diarios`.`id`
	WHERE `register_diarios`.`uid` = '$diario_uid' AND
		  `register_orcamentos`.`orcamento_date` <= '$anoAtual-$mesAtual-01';";
	
	$orcamentos = $db->select($sql_orcamentos);
	
	$sql_transacoes =
	"SELECT 
		`register_subcategorias`.`subcategoria_id`, 
		MONTH(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)) AS `transacoes_mes`, 
		YEAR(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)) AS `transacoes_ano`, 
		SUM(`register_transacoes_itens`.`transacoes_item_valor`) AS `transacoes_valor`
	FROM `register_transacoes_itens`
	INNER JOIN `register_transacoes` ON `register_transacoes_itens`.`transacao_id` = `register_transacoes`.`transacao_id`
	INNER JOIN `register_subcategorias` ON `register_transacoes_itens`.`subcategoria_id` = `register_subcategorias`.`subcategoria_id`
	INNER JOIN `register_categorias` ON `register_subcategorias`.`categoria_id` = `register_categorias`.`categoria_id`
	INNER JOIN `register_contas` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
	INNER JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id`
	WHERE `register_diarios`.`uid` = '$diario_uid'
		  AND (IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`) <= LAST_DAY('$anoAtual-$mesAtual-01'))
	GROUP BY `register_subcategorias`.`subcategoria_id`, MONTH(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)), YEAR(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`))
	ORDER BY YEAR(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)), MONTH(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`));";

	$transacoes = $db->select($sql_transacoes);

	$receitas = 0;
	$receita_acum_m_1 = 0;
	$receita_acum_m_2 = 0;
	$transacoes_m = 0;
	$transacoes_m_1 = 0;
	foreach ($transacoes as $transacao) {
		//subcategoria_id:
			//1 = Fundos para este mês M
			//2 = Fundos para o próximo mês M+1
		$mes = 0;
		$ano = 0;
		if ($transacao["subcategoria_id"] == 1) {
			//Este mês
			$mes = $transacao["transacoes_mes"]*1;
			$ano = $transacao["transacoes_ano"]*1;
		} elseif ($transacao["subcategoria_id"] == 2) {
			//Próximo mês
			if (($transacao["transacoes_mes"]*1)==12) {
				$mes = 1;
				$ano = $transacao["transacoes_ano"]*1+1;
			} else {
				$mes = $transacao["transacoes_mes"]*1+1;
				$ano = $transacao["transacoes_ano"]*1;
			}
		}
		if ($mes <> 0 && $ano <> 0) {
			if ($mes + $ano*100 <= $mesAtual+$anoAtual*100) {
				$receitas += $transacao["transacoes_valor"];
			} 
			if ($mes + $ano*100 < $mesAtual+$anoAtual*100) {
				$receita_acum_m_1 += $transacao["transacoes_valor"];
			}
			if ($mes + $ano*100 <= $mesAtual_m_2+$anoAtual_m_2*100) {
				$receita_acum_m_2 += $transacao["transacoes_valor"];
			}
		}
	}

	

	$lista_orcamentos = [];
	$categoria_antiga = 0;
	$carry_neg = 0;
	$orcado_acum_m_1 = 0; 
	$orcado_acum_m_2 = 0;
	$orcado_acum = 0;
	$sobregasto_acum_m_1 = 0;
	$sobregasto_acum_m = 0;
	foreach ($categorias as $categoria) {
		$objeto =	[];
		if ($categoria_antiga <> $categoria["categoria_id"]) {
			//se é a primeira vez que esta categoria aparece, vamos criar a linha de cabeçalho
			$objeto_header = array(
				"categoria_id" 				=> $categoria["categoria_id"],
				"categoria_nome" 			=> $categoria["categoria_nome"],
				"categoria_description" 	=> $categoria["categoria_description"],
				"categoria_ordem" 			=> $categoria["categoria_ordem"],
				"subcategoria_is"			=> false,
			);
			array_push($lista_orcamentos,$objeto_header);
			$categoria_antiga = $categoria["categoria_id"];
		}
		//Aqui calcularemos o valor orçado do mês
		$acumulado_w_carry = 0;
		$acumulado_wo_carry = [];
		$orcamento_valor = 0;
		foreach ($orcamentos as $orcamento) {
			if ($orcamento["subcategoria_id"] == $categoria["subcategoria_id"] 
			&& (int)$orcamento["orcamento_mes"] == (int)$mesAtual && (int)$orcamento["orcamento_ano"] == (int)$anoAtual) {
				$orcamento_valor += $orcamento["orcamento_valor"];
			}
			if ($orcamento["subcategoria_id"] == $categoria["subcategoria_id"] 
			&& (int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100 < (int)$mesAtual + 100*(int)$anoAtual) { 
				$orcado_acum_m_1 += $orcamento["orcamento_valor"];
			}
			if ($orcamento["subcategoria_id"] == $categoria["subcategoria_id"] 
			&& (int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100 < (int)$mesAtual_m_2 + 100*(int)$anoAtual_m_2) { 
				$orcado_acum_m_2 += $orcamento["orcamento_valor"];
			}
			if ($orcamento["subcategoria_id"] == $categoria["subcategoria_id"] 
			&& (int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100 <= (int)$mesAtual + 100*(int)$anoAtual) { 
				$orcado_acum += $orcamento["orcamento_valor"];
			}
			if ($orcamento["subcategoria_id"] == $categoria["subcategoria_id"])  {
				if ($categoria['subcategoria_carry']=="1") {
					$acumulado_w_carry += $orcamento["orcamento_valor"];
				} else {
					if (!isset($acumulado_wo_carry[(int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100])) {
						$acumulado_wo_carry[(int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100] = 0;
					}
					$acumulado_wo_carry[(int)$orcamento["orcamento_mes"]+$orcamento["orcamento_ano"]*100] += $orcamento["orcamento_valor"];
				}
			}
		}

		$transacoes_valor = 0;
		//Aqui calculamos o valor acumulado das transações classificadas:
		//Mas também, vamos calcular o valor gasto em transações de M (mês atual) e M-1 (mês anterior)
		//Não entram nessa conta transações sem categoria ou em transferências
		foreach ($transacoes as $transacao) {
			if ($transacao["subcategoria_id"] == $categoria["subcategoria_id"]
			&& (int)$transacao["transacoes_mes"] == (int)$mesAtual && (int)$transacao["transacoes_ano"] == (int)$anoAtual) {
				$transacoes_valor += $transacao["transacoes_valor"];
			}
			if ($transacao["subcategoria_id"] == $categoria["subcategoria_id"]) {
				if ($categoria['subcategoria_carry']=="1") {
					$acumulado_w_carry += $transacao["transacoes_valor"];
				} else {
					if ((int)$transacao["transacoes_mes"]+100*(int)$transacao["transacoes_ano"]<$mesAtual*1+$anoAtual*100) {
						//Se o mes e ano da transacao é menor que o mes e ano atual, então é acumulado m-1
						$transacoes_m_1 += $transacao["transacoes_valor"];
					}
					//caso contrário é m:
					$transacoes_m += $transacao["transacoes_valor"];
					if (!isset($acumulado_wo_carry[(int)$transacao["transacoes_mes"]+$transacao["transacoes_ano"]*100])) {
						$acumulado_wo_carry[(int)$transacao["transacoes_mes"]+$transacao["transacoes_ano"]*100] = 0;
					}
					$acumulado_wo_carry[(int)$transacao["transacoes_mes"]+$transacao["transacoes_ano"]*100] += $transacao["transacoes_valor"];
				}
			}
		}
		$acumulado_wo_carry_val = 0;
		ksort($acumulado_wo_carry);
		$sobregasto_acum_m_categoria = 0;
		$sobregasto_acum_m_1_categoria = 0;
		foreach($acumulado_wo_carry as $data => $acumulado_wo_carry_mes) {
			if ($data < $mesAtual+$anoAtual*100) {
				if ($acumulado_wo_carry_mes >= 0) {
					//aqui, se o valor na categoria é positivo, isto é, as entradas superam a saída, somamos, e seguimos a vida
					$acumulado_wo_carry_val += $acumulado_wo_carry_mes;
				} else {
					//caso contrário, isto é, saídas na categoria superiores a entrada, vamos calcular
					//antes calculamos qual mês é o anterior
					if ($mesAtual*1 == 1) {
						$anoMesAnterior = 12+($anoAtual-1)*100;
					} else {
						$anoMesAnterior = ($mesAtual-1)+($anoAtual)*100;
					}
					if ($data <= $anoMesAnterior) {
						if (($acumulado_wo_carry_val + $acumulado_wo_carry_mes) > 0) {
							//neste caso, o valor acumulado na categoria é maior do que a saída, desta forma, não temos sobregasto,
							//mas sim um desconto nesta 'poupança':
							$acumulado_wo_carry_val = $acumulado_wo_carry_val + $acumulado_wo_carry_mes;
						} else {
							//eita, vamos zerar o saldo da categoria, e vai faltar (sobregasto):
							if ($data == $anoMesAnterior) {
								$carry_neg += $acumulado_wo_carry_val + $acumulado_wo_carry_mes;
							}
							$acumulado_wo_carry_val = 0;
						}
					}
				}
			} else {
				$acumulado_wo_carry_val += $acumulado_wo_carry_mes;
			}
			//Aqui vamos somar os valores de sobregasto (valor gasto em uma categoria acima do orçado) acumuladamente para todas as categorias:
			if ($data <= $mesAtual+$anoAtual*100) {
				$sobregasto_acum_m_categoria += $acumulado_wo_carry_mes;
				if ($data < $mesAtual+$anoAtual*100) {
					//e aqui apenas até o mes anterior (<)
					$sobregasto_acum_m_1_categoria += $acumulado_wo_carry_mes;
				}
			}
		}
		if ($sobregasto_acum_m_categoria < 0)
			$sobregasto_acum_m += $sobregasto_acum_m_categoria;
		if ($sobregasto_acum_m_1_categoria < 0)
			$sobregasto_acum_m_1 += $sobregasto_acum_m_1_categoria;
		$disponivel = 0;
		if ($categoria["subcategoria_carry"]=="1") {
			$disponivel = $acumulado_w_carry;
		} else {
			$disponivel = $acumulado_wo_carry_val;
		}

		$objeto = array(
			"categoria_id" 				=> $categoria["categoria_id"],
			"categoria_nome" 			=> $categoria["categoria_nome"],
			"categoria_description" 	=> $categoria["categoria_description"],
			"categoria_ordem" 			=> $categoria["categoria_ordem"],
			"subcategoria_is"			=> true,
			"subcategoria_id" 			=> $categoria["subcategoria_id"],
			"subcategoria_nome" 		=> $categoria["subcategoria_nome"],
			"subcategoria_description" 	=> $categoria["subcategoria_description"],
			"subcategoria_carry" 		=> $categoria["subcategoria_carry"],
			"subcategoria_ordem" 		=> $categoria["subcategoria_ordem"],
			"orcamento_valor"			=> $orcamento_valor,
			"transacoes_valor"			=> $transacoes_valor,
			"disponivel_valor"			=> $disponivel,
		);
		array_push($lista_orcamentos,$objeto);
	}

	//agora vamos somar todas as transacoes nao classificadas que nao sejam transferencias, idealmente seria ZERO, mas o usuario pode
	//nao ter classificado elas ainda:

	$sql_transacoes_sem_classificacoes =
	"SELECT 
		IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`) AS `transacao_data`,
		MONTH(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)) AS `transacao_mes`,
		YEAR(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)) AS `transacao_ano`,
		`transacao_valor`
	FROM 
		`register_transacoes` 
	INNER JOIN `register_contas` ON `register_transacoes`.`conta_id` = `register_contas`.`conta_id`
	INNER JOIN `register_diarios` ON `register_contas`.`diario_id` = `register_diarios`.`id`
	LEFT JOIN `register_transacoes_itens` ON `register_transacoes`.`transacao_id` = `register_transacoes_itens`.`transacao_id`
	WHERE 
		`register_transacoes`.`transacao_merged_to_id` IS NULL
		AND
		`register_transacoes_itens`.`transacoes_item_id` IS NULL
		AND
		`register_diarios`.`uid` = '$diario_uid'
		AND
			(MONTH(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`))*1
			 +100*YEAR(IFNULL(`register_transacoes`.`transacao_fatura_data`,`register_transacoes`.`transacao_data`)) <= $mesAtual*1+100*$anoAtual);";
	
	$transacoes_s_c = $db->select($sql_transacoes_sem_classificacoes);
	
	$transacoes_sem_classificacao_m = 0;
	$transacoes_sem_classificacao_m_1 = 0;
	foreach ($transacoes_s_c as $transacao) {
		if ((int)$transacao["transacao_mes"]+100*(int)$transacao["transacao_ano"]<$mesAtual*1+$anoAtual*100) {
			//Se o mes e ano da transacao é menor que o mes e ano atual, então é acumulado m-1
			$transacoes_sem_classificacao_m_1 ++;
		}
		$transacoes_sem_classificacao_m ++;
	}
	$sobreorcado_acum_m_1 = 0;
	if ($orcado_acum_m_1 > $receita_acum_m_1) {
		$sobreorcado_acum_m_1 = $orcado_acum_m_1 - $receita_acum_m_1;
	}
	$resposta = array("lista_orcamentos" 			=> $lista_orcamentos, 
					"receita_acum" 					=> $receitas, 
					"receita_mes" 					=> ($receitas-$receita_acum_m_1), 
					"orcado_acum" 					=> $orcado_acum,
					"orcado_mes"					=> ($orcado_acum-$orcado_acum_m_1),
					"sobregasto_mes_m_1"	 		=> $carry_neg*(-1), 
					"sobregasto_acum_m_1"	 		=> $sobregasto_acum_m_1*(-1), 
					"sobregasto_acum"				=> $sobregasto_acum_m*(-1),
					"sobreorcado_acum_m_1"			=> $sobreorcado_acum_m_1,
					"gastos_classificados_mes" 		=> $transacoes_m*(-1)-$transacoes_m_1*(-1),
					"gastos_classificado_acum" 		=> $transacoes_m*(-1),
					"transacoes_sem_classificacao" 	=> $transacoes_sem_classificacao_m);
	return new Response(json_encode($resposta),200);

});

/**
 * Retorna um par para incluir no SET
 *
 * @param string $column
 * @param string $value
 * @param string $sql
 * @return string part SET `$column` = $value
 */
function montaUpdateSQL(string $column, string $value, string $sql = "") {
	if (strlen($sql) > 0) {
		$sql = $sql . ",$column = '$value'";
	} else {
		$sql = "$column = '$value'";
	}
	return $sql;
}

/**
 * Retorna um par para incluir no WHERE
 *
 * @param string $column
 * @param string $value
 * @param string $sql
 * @return string WHERE Clause
 */
function montaWhereSQL(string $column, string $value, string $sql = "", string $tipoWhere = "=") {
	if (strlen($sql) > 0) {
		$sql = $sql . "AND $column $tipoWhere '$value'";
	} else {
		$sql = "$column $tipoWhere '$value'";
	}
	return $sql;
}

//Esta classe existe para estruturarmos as foreign keys para obter o ID do usuário mais facilmente (código replicável)
class ForeignRelationship {
	public $table;
	public $pk;
	public $fk;
	/**
	 * Cria um novo objeto chamado ForeignRelationship
	 *
	 * @param string $table nome da tabela
	 * @param string $pk nome da coluna Primary Key
	 * @param string $fk nome da coluna Foreign key
	 */
	public function __construct($table, $pk, $fk) {
		$this->table = $table;
		$this->fk = $fk;
		$this->pk = $pk;
	}
}
/**
 * Retorna o ID do usuário dada uma sequência de tabelas e foreign keys (Array of ForeignRelationship) e uma Coluna para a clausula 
 * Where e seu valor, deve ser montado de trás para frente até a tabela `register_diarios` (inclusa) onde o user_id é recuperado.
 *
 * @param array $foreignColumns
 * @param string $whereColumn
 * @param string $whereClause
 * @return int user_id - id do usuário.
 */
function retrieveUserIdFromTables(array $foreignColumns, string $whereColumn, string $whereClause) {
	global $db;

	$sql = "SELECT `register_diarios`.`user_id` FROM ";
	$lastFK = "";
	$lastTable = "";
	foreach ($foreignColumns as $foreignColumn) {
		if (strlen($lastFK) > 0) {
			$sql .= " JOIN `" . $foreignColumn->table . "` ON `" . $foreignColumn->table . "`.`" . $foreignColumn->pk . "` = `". $lastTable . "`.`" . $lastFK . "`";
		} else {
			$sql .= "`" . $foreignColumn->table . "`";
		}
		$lastFK = $foreignColumn->fk;
		$lastTable = $foreignColumn->table;
	}
	$sql .= " WHERE `$whereColumn`='$whereClause';";

	$rows = $db->select($sql);
	if ($rows) {
		return $rows[0]['user_id'];
	} else {
		return false;
	}
}



$app->run();