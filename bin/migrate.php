<?php

declare(strict_types=1);

use HeardLive\Support\Config;
use HeardLive\Support\Db;
use HeardLive\Support\Migrator;

$root = dirname(__DIR__);
require $root . '/src/autoload.php';

$config = Config::load($root);
$applied = (new Migrator(Db::connect($config->dbPath), $root . '/migrations'))->migrate();

echo $applied === []
    ? "Database is up to date.\n"
    : 'Applied: ' . implode(', ', $applied) . "\n";
