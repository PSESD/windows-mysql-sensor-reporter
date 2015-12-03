<?php
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'Provider.php');
require_once(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php');

$headers = getallheaders();
if (empty($headers['X-Api-Key']) || !in_array($headers['X-Api-Key'], $config['keys'])) {
	http_response_code(403);
	echo "403 Forbidden";
	exit(0);
}

$provider = new psesd\serverReporter\Provider($config);
$provider->provide();