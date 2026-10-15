<?php
declare(strict_types=1);

// Front controller. Serve ONLY this directory (public/) from the web server.
require __DIR__ . '/../src/autoload.php';

use Vault24\Http\Kernel;
use Vault24\Http\Request;
use Vault24\Support\Config;

ini_set('display_errors', '0'); // never render PHP errors/stack traces to clients
ini_set('log_errors', '1');

$root = dirname(__DIR__);
$cfg = Config::fromEnv($root);
(new Kernel($cfg))->handle(Request::fromGlobals($cfg))->send();
