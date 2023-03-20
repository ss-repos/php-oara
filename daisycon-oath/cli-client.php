<?php

require_once __DIR__ . '/pkce.php';
require_once __DIR__ . '/functions.php';

$host = 'https://login.daisycon.com';
$authorizeUri = "{$host}/oauth/authorize";
$accessTokenUri = "{$host}/oauth/access-token";
$options = getopt(
	'c:s:o:r:',
	[
		'clientId:',
		'clientSecret:',
		'outputFile:',
		'redirectUri:',
		'help',
	]
);

if (true === isset($options['help']))
{
	echo 'Usage: ', PHP_EOL,
		'-c | --clientId        (required) provide your client ID here', PHP_EOL,
		'-c | --clientSecret    (optional) provide your client secret here', PHP_EOL,
		'-c | --outputFile      (optional) provide a file to write the JSON tokens to', PHP_EOL,
		'-c | --redirectUri     (required) provide a custom redirect URI', PHP_EOL,
		'--help                 show this help', PHP_EOL;
	exit;
}

$clientId = $options['c'] ?? $options['clientId'] ?? null;
$clientSecret = $options['s'] ?? $options['clientSecret'] ?? null;
$outputFile = $options['o'] ?? $options['outputFile'] ?? null;
$redirectUri = $options['r'] ?? $options['redirectUri'] ?? "${host}/oauth/cli";

if (true === empty($clientId))
{
	echo 'ERROR: Client ID is required', PHP_EOL;
	die;
}

$pkce = new Pkce();
$codeVerifier = $pkce->getCodeVerifier();

$params = http_build_query([
	'client_id'      => $clientId,
	'response_type'  => 'code',
	'redirect_uri'   => $redirectUri,
	'code_challenge' => $pkce->getCodeChallenge(),
]);

echo 'Please open the following URL in your browser, then copy paste the responded "code" back here', PHP_EOL, PHP_EOL, $authorizeUri, '?', $params, PHP_EOL, PHP_EOL;

$code = askForResponse();

$response = httpPost(
	$accessTokenUri,
	[
		'grant_type'    => 'authorization_code',
		'redirect_uri'  => $redirectUri,
		'client_id'     => $clientId,
		'client_secret' => $clientSecret,
		'code'          => $code,
		'code_verifier' => $codeVerifier,
	]
);

if (true === empty($outputFile))
{
	echo 'Here are your access tokens, save them somewhere safe', PHP_EOL,
	 '{';

	$first = true;
	foreach($response as $key => $value)
	{
		echo ($first ? '' : ',' . PHP_EOL),
			"\t" , '"', $key, '": ', json_encode($value);
		$first = false;
	}
	echo PHP_EOL, '}', PHP_EOL, PHP_EOL;
	exit;
}

file_put_contents($outputFile, json_encode($response));
echo "Tokens written to output file: {$outputFile}\n\n";
exit;

function askForResponse(int $attempt = 0): string
{
	if ($attempt > 3)
	{
		echo 'ERROR: Response code not received', PHP_EOL;
		die;
	}
	echo 'Please enter the response code:', PHP_EOL;
	$fin = fopen('php://stdin', 'r');
	$code = trim(fgets($fin));
	fclose($fin);

	if (empty($code))
	{
		++$attempt;
		return askForResponse($attempt);
	}
	return $code;
}
