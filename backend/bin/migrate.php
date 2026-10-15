<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/autoload.php';

use Vault24\Support\Config;
use Vault24\Support\Database;

$cfg = Config::fromEnv(dirname(__DIR__));
@mkdir(dirname((string) $cfg->get('db_path')), 0750, true);
(new Database((string) $cfg->get('db_path')))->migrate(); // idempotent, never drops anything
echo "Migrated: " . $cfg->get('db_path') . PHP_EOL;
