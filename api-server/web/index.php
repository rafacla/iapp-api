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
		if (isset($client_credentials[1]))
			$client_secret = $client_credentials[1];
		else
			$client_secret = "";
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
		
		return new Response (json_encode($resposta), 201);
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
		return new Response('Acesso negado: usuário não possui privilégios de administrador', 403);
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
			return new Response("Este usuáro não existe.", 404);
		}
	} else {
		return new Response('Você não tem privilégios para isso.', 403);
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
				return new Response("ok",200);
			else //vixe, o cliente até existe, mas está bloqueado, vamos informar ao cliente:
				return new Response("cliente_bloqueado",403);
		} else {
			return new Response("credenciais_invalidas", 401);
		}
	} else {
		return new Response('credenciais_nao_enviadas',401);
	}
});

//rota para criar um novo cliente
$app->post('/cliente', function (Request $request) use ($app, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (!isset($data['client_id']) || !isset($data['client_secret'])) {
		return new Response('falha_ao_criar_cliente: empty body',400);
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
				return new Response('cliente_bloqueado',403);
		else
			return new Response('cliente_existente',409);
	} else {
		if (!isset($description)) {
			return new Response('falha_ao_criar_cliente: no description found',400);
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
			return new Response('falha_ao_inserir_novo_cliente '.$sql_i,400);
		}
	}
});

//rota para listar todos os diários de um usuário
$app->get('/diario', function (Request $request) use ($app, $user, $db) {
	global $user;
	if ($request->headers->get("userid")==null) {
		return new Response("Faltando userid",400);
	} elseif ($user['id']<>$request->headers->get("userid") && $user['adm']!=true) {
		return new Response("Não autorizado",403);
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

//rota para criar um novo diário
$app->post('/diario', function (Request $request) use ($app, $user, $db) {
	global $user;
	$data = json_decode($request->getContent(), true);
	if (isset($data['nome'][2]) && isset($data['description'][2]) && isset($data['userid'])) {
		if ($data['userid']<>$user['id'] && $user['adm']!=true)
			return new Response("Não autorizado",403);		
		$nome 			= $db->escape_string($data['nome']);
		$description	= $db->escape_string($data['description']);
		$userid			= $db->escape_string($data['userid']);
		$uuid 			= md5(uniqid(""));
		$sql_i = "INSERT INTO register_diarios (uid,nome,description,user_id,`default`) VALUES ('$uuid','$nome','$description','$userid','1');";
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
				$sql_i_categoria = "INSERT INTO `register_categorias` (`categoria_nome`,`categoria_description`,`diario_id`,`categoria_ordem`) VALUES
				 ('$categoria_nome','$categoria_desc','$diario_id','$i');";
				
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
						`categoria_id`,`subcategoria_ordem`) VALUES ('$subc_nome','$subc_desc',0,'$cat_id','$j')";
						$res_s = $db->insert($sql_i_subc);
						$j++;
					}
				}
			}
			
			return new Response(json_encode($resposta),201);
		}
		else
			return new Response("Erro ao atualizar banco de dados ",400);
	} else {
		return new Response("Sintaxe de entrada inválida",400);
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
			return new Response("não encontrado para atualizar", 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response("Não autorizado",403);	
		} else {
			$uniqueid 		= $db->escape_string($data['uniqueid']);
			$nome 			= $db->escape_string($data['nome']);
			$description	= $db->escape_string($data['description']);
			$sql_u = "UPDATE register_diarios SET nome='$nome',description='$description' WHERE uid='$uniqueid';";
			$resultado = $db->query($sql_u);
			if ($resultado)
				return new Response("ok",200);
			else
				return new Response("Sintaxe de entrada inválida",400);
		}
	} else {
		return new Response("Sintaxe de entrada inválida",400);
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
			return new Response("não encontrado para deletar", 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response("Não autorizado",403);	
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
				return new Response("deletado",200);
			}
			else
				return new Response("Sintaxe de entrada inválida",400);
		}
	} else {
		return new Response("Sintaxe de entrada inválida",400);
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
			return new Response("não encontrado para selecionar", 404);
		} elseif ($resultado[0]['user_id']<>$user['id'] && $user['adm']!=true) {
			return new Response("Não autorizado",403);	
		} else {
			$sql_u		= "UPDATE `register_diarios` SET `default`=(`uid`='$uniqueid');";
			$resultado = $db->query($sql_u);
			if ($resultado)
				return new Response("selecionado",200);
			else
				return new Response("Sintaxe de entrada inválida",400);
		}
	} else {
		return new Response("Sintaxe de entrada inválida",400);
	}
});

$app->get('/categoria/{diariouid}', function (Request $request, $diariouid) use ($app, $db) {
	global $user;
	
	$diario = getDiarioID($diariouid);
	
	if ($diario) {
		if ($user['adm'] || $user['id']==$diario['user_id']) {
			$diarioID = $diario['diario_id'];
			$sql = sprintf("SELECT `categoria_id`, `categoria_nome`, `categoria_description`, `diario_id` from `register_categorias` WHERE `diario_id` = '%s'",$diarioID);
			$rows = $db ->select($sql);
			if ($rows) {
				$i = 0;
				foreach ($rows as $row) {
					$categorias[$i] = $row;
					$cat_id = $row['categoria_id'];
					$sql_subc = "SELECT `subcategoria_id`,`subcategoria_nome`,`subcategoria_description`,`subcategoria_carry`,`categoria_id` FROM `register_subcategorias` 
					WHERE `categoria_id` = '$cat_id';";
					$subcategorias = $db->select($sql_subc);
					$categorias[$i]['subcategorias'] = $subcategorias;
					$i++;
				}
				return new Response(json_encode($categorias),200);
			} else {
				return new Response("Este diário não existe.", 404);
			}
		} else {
			return new Response('Você não tem privilégios para isso.', 403);
		}
	} else {
		return new Response("Diário não encontrado.", 404);
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
				//estamos descendo
				$sql = "SELECT `categoria_id`,`categoria_ordem` FROM `register_categorias` WHERE `diario_id` = '$diario_id' ORDER BY `categoria_ordem`;";
				$categorias = $db->select($sql);
				$countCategorias = count($categorias);
				if ($move_from < $move_to) {
					if ($move_to >= $countCategorias || $move_to < 0) {
						return new Response("Sintaxe inválida", 400);
					} else {
						for ($i=$move_from;$i<=$move_to;$i++) {
							if ($i==$move_from)
								$categorias[$i]['categoria_ordem']=$move_to;
							else
								$categorias[$i]['categoria_ordem']-=1;
							$sql_u = "UPDATE register_categorias SET categoria_ordem='".$categorias[$i]['categoria_ordem']."'
										WHERE categoria_id = '".$categorias[$i]['categoria_id']."';";
							$reordem = $db->query($sql_u);							
						}
						return new Response("Reordenado",200);
					}					
				} elseif ($move_to > $move_from) {
					if ($move_to >= $countCategorias || $move_to < 0) {
						return new Response("Sintaxe inválida", 400);
					} else {
						for ($i=$move_to;$i<=$move_from;$i++) {
							if ($i==$move_from)
								$categorias[$i]['categoria_ordem']=$move_to;
							else
								$categorias[$i]['categoria_ordem']+=1;
							$sql_u = "UPDATE register_categorias SET categoria_ordem='".$categorias[$i]['categoria_ordem']."'
										WHERE categoria_id = '".$categorias[$i]['categoria_id']."';";
							$reordem = $db->query($sql_u);
						}
						return new Response("Reordenado",200);
					}
				} else {
					return new Response("Reordenado",200);
				}
			} else {
				return new Response("Não autorizado", 403);
			}
		} else {
			return new Response("Não encontrado", 404);
		}
	} else {
		return new Response("Sintaxe de entrada inválida",400);
	}
});

$app->run();