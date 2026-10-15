<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';
require __DIR__ . '/Harness.php';
foreach (glob(__DIR__ . '/Feature/*Test.php') as $f) {
    require $f;
}

use Vault24\Tests\{Runner, Failure};

$filter = $argv[1] ?? null;
$pass = $fail = 0;
$start = microtime(true);
foreach (Runner::$tests as [$name, $fn]) {
    if ($filter !== null && stripos($name, $filter) === false) continue;
    try {
        $fn();
        $pass++;
        echo "  \033[32mPASS\033[0m $name\n";
    } catch (\Throwable $e) {
        $fail++;
        $where = $e instanceof Failure ? basename($e->getTrace()[1]['file'] ?? $e->getFile()) . ':' . ($e->getTrace()[1]['line'] ?? $e->getLine()) : $e::class . ' @ ' . basename($e->getFile()) . ':' . $e->getLine();
        echo "  \033[31mFAIL\033[0m $name\n        {$e->getMessage()}  [$where]\n";
    }
}
printf("\n%d passed, %d failed (%.1fs)\n", $pass, $fail, microtime(true) - $start);
exit($fail > 0 ? 1 : 0);
