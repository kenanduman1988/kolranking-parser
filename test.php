<?php
/**
 * Created by PhpStorm.
 * User: kenanduman
 * Date: 10/13/18
 * Time: 11:02 PM
 */
define('ROOT', __DIR__ . '/.');

require_once('vendor/autoload.php');

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

$parserClient = new \kolranking\ParserClient();
$parserClient->setClient();
var_dump($parserClient->captchaByPass(file_get_contents(ROOT . '/tmp/captcha.html')));
//$parserClient = new \kolranking\ParserClient();
//
//$parserClient->setClient();
//$response = $parserClient->getRequest('https://ipapi.co/ip');
//
//var_dump($response->getBody()->getContents());