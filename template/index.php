<?php
require_once 'vendor/autoload.php';

include_once('model/Core/files/start.php');
$frontController = new FrontController();
$frontController->run();
