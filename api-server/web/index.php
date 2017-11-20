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
$server = new OAuth2\Server($storage);
$server->addGrantType(new OAuth2\GrantType\ClientCredentials($storage));
$server->addGrantType(new OAuth2\GrantType\UserCredentials($storage));


// verificar autenticacao
$app->before(function(Request $request, Application $app) use ($app, $db, $storage, $server) {
	global $user;
    $route = $request->get('_route');
	
	//Aqui definimos quais rotas dispensam autenticação:
	$rotaslivres[0] = "POST_auth"; //rota para autenticar, obviamente nao exige que o usuario esteja autenticado
	$rotaslivres[1] = "POST_users"; //rota para criar um novo usuário
	$rotaslivres[2] = "GET_"; //esta é a rota padrão e exibe uma mensagem de erro amigável, dispensa autenticação por isso
	$rotaslivres[3] = "GET_activate_actcode"; //esta rota serve para ativar um usuário recem registrado que recebeu o link por email
	
    if(!in_array($route,$rotaslivres) && $request->getMethod() != 'OPTIONS') {
		$authorization = $request->headers->get("Authorization");
		list($jwt) = sscanf($authorization, 'Bearer %s');
		
        if($jwt) { //foi enviado um token, verificar se ele está disponível
			//Autorização vai aqui:
			$request = OAuth2\Request::createFromGlobals();
			$response = new OAuth2\Response();
			if (!$server->verifyResourceRequest($request,$response)) {
				return new Response($response."...", 403);
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
            return new Response('Token não informado', 403);
        } 
    }

});

$app->get("/", function (Request $request) {
	return new Response("method not allowed",485);
});

//Aqui estamos preparando o 'pré-voo' adicionando uma resposta válida para o method 'options'
$app->options("{anything}", function () {
        return new \Symfony\Component\HttpFoundation\JsonResponse(null, 204);
})->assert("anything", ".*");

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
		$client_secret = $client_credentials[1];
	} else {
		$client_id = $request->get("client_id");
		$client_secret = $request->get("client_secret");
	}
	$grant_type = $request->get("grant_type");
	$username = $request->get("username");
	$password = $request->get("password");
	
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
			Return new Response('OK! Usuário ativado!', 201);
		else 
			Return new Response('Usuário não autorizado ou código inválido', 401);
	} else {
		Return new Response('Usuário não autorizado ou código inválido', 401);
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
	
	//se falou, id = falso, retorna erro desconhecido
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
		
		return new Response (json_encode($resposta), 201);
	}
});

//Rota para listar todos os usuarios do sistema (apenas ADM)
$app->get('/users', function (Request $request) use ($app, $db, $user) {
	global $user;
	if ($user['adm']) {
		$sql = 'SELECT * from `register_users`';
		$rows = $db ->select($sql);
		return $app->json($rows);
	} else {
		return new Response('Acesso negado: usuário não possui privilégios de administrador', 403);
	}
});

//Rota para pegar detalhes de um usuário específico (apenas ADM ou informações do próprio usuário)
$app->get('/users/{id}', function (Request $request, $id) use ($app, $db, $user) {
	global $user;
	
	if ($user['adm'] || $user['id']==$id) {
		$sql = sprintf("SELECT * from `register_users` WHERE `userID` = '%s'",$id);
		$rows = $db ->select($sql);
		if ($rows) {
			return $app->json($rows[0]);
		} else {
			return new Response("Este usuáro não existe.", 404);
		}
	} else {
		return new Response('Você não tem privilégios para isso.', 403);
	}
});

//Rota para verificar se um cliente existe
$app->get('/cliente', function (Request $request) use ($app, $db, $user) {
	$client_id = $db->escape_string($request->headers->get("client_id"));
	$client_secret = $db->escape_string($request->headers->get("client_secret"));
	if ($request->headers->get("client_id")!=null && $request->headers->get("client_secret")!=null) {
		$sql_s = "SELECT user_id, ativo FROM oauth_clients WHERE client_id = '".$client_id."' AND client_secret='".$client_secret."';";
		$rows = $db ->select($sql_s);
		if ($rows) {
			if ($rows[0]['ativo'] == 1) //eba existe e tá ativo, vamos retornar um ok!
				return new Response("ok",200);
			else //vixe, o cliente até existe, mas está bloqueado, vamos informar ao cliente:
				return new Response("cliente_bloqueado",403);
		} else {
			return new Response("credenciais_invalidas", 401);
		}
	} else {
		return new Response('credenciais_nao_enviadas'.$client_id.$client_secret,401);
	}
});

$app->run();