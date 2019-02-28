<?php
/**
 * Created by PhpStorm.
 * User: kenanduman
 * Date: 10/13/18
 * Time: 11:02 PM
 */
define('ROOT', __DIR__ . '/.');

require_once('vendor/autoload.php');

use kolranking\ParserWorker;

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();


$worker = new ParserWorker;

// Run the worker
$worker
    ->initParserClient()
    ->perform()
    ->startParsingProcess()
;
