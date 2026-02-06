<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new think\App();
$http = $app->http;

$response = $http->run();
$response->send();
$http->end($response);
