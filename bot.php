<?php

require_once './vendor/autoload.php';
require_once "CoachBot.php";

$config = [];
$bot = new CoachBot('CoachBot', []);

if (php_sapi_name() == "cli") {
    $bot->start();
} else {
    $bot->webhook();
}
