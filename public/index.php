<?php

use Sunfox\ServerCheck\Bootstrap;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Bootstrap::run($root . '/config', isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null);
