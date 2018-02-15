<?php

require_once './vendor/autoload.php';
require_once "CoachBot.php";

$config = [];
$bot = new CoachBot('CoachBot', []);
$bot->webhook();
