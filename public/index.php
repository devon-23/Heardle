<?php

declare(strict_types=1);

use HeardLive\App;
use HeardLive\Http\Request;
use HeardLive\Support\Config;

// Let the built-in dev server serve real files (CSS, images) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if (is_file($file)) {
        return false;
    }
}

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

session_start([
    'name' => 'heardlive',
    'cookie_httponly' => true,
    'cookie_samesite' => 'Lax',
    'use_strict_mode' => true,
]);

(new App(Config::load($root)))->handle(Request::fromGlobals())->send();
