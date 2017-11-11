<?php 

// web/index.php
require_once __DIR__.'/../vendor/autoload.php';

//Oauth2 library
require_once(__DIR__.'/../oauth2/src/OAuth2/Autoloader.php');
OAuth2\Autoloader::register();

//Database library and credentials
include_once('Database.php');

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

	
    if($route != 'POST_auth' && $request->getMethod() != 'OPTIONS') {
		$authorization = $request->headers->get("Authorization");
		list($jwt) = sscanf($authorization, 'Bearer %s');
		
        if($jwt) { //foi enviado um token, verificar se ele está disponível
			//Autorização vai aqui:
			$request = OAuth2\Request::createFromGlobals();
			$response = new OAuth2\Response();
			if (!$server->verifyResourceRequest($request,$response)) {
				return new Response($response."..", 403);
			}
			$tData = $server->getAccessTokenData(OAuth2\Request::createFromGlobals());
			
			$user['id'] = $tData['user_id'];
			$user['adm'] = (strpos($tData['scope'], 'adm') !== false);
		}
		else {
            // nao foi possivel extrair token do header Authorization
            return new Response('Token nao informado', 401);
        } 
    }
});

//Aqui estamos preparando o 'pré-voo' adicionando uma resposta válida para o method 'options'
$app->options("{anything}", function () {
        return new \Symfony\Component\HttpFoundation\JsonResponse(null, 204);
})->assert("anything", ".*");

// Autenticacao
$app->post('/auth', function (Request $request) use ($app, $db, $storage, $server) {
	ob_start(); //Start output buffer
	$server->handleTokenRequest(OAuth2\Request::createFromGlobals())->send();
	$output = ob_get_contents(); //Grab output
	ob_end_clean(); //Discard output buffer
	return($output);
});

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

$app->run();