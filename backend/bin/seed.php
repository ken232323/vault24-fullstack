<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__ . '/../src/autoload.php';

use Vault24\Http\Kernel;
use Vault24\Support\Config;

$cfg = Config::fromEnv(dirname(__DIR__));
if ($cfg->get('env') === 'production') {
    fwrite(STDERR, "Refusing to seed demo users when APP_ENV=production.\n");
    exit(1);
}
$k = new Kernel($cfg);

// ALL FICTIONAL. Demo passwords are deliberately published in the README.
$users = [
    ['Alice Demo',  'alice@vault24.test', 'Alice-Demo-Pass-24!', 'customer', 500000],
    ['Bob Demo',    'bob@vault24.test',   'Bob-Demo-Pass-2024!', 'customer', 250000],
    ['Sam Staff',   'staff@vault24.test', 'Staff-Demo-Pass-24!', 'staff', 0],
    ['Ada Admin',   'admin@vault24.test', 'Admin-Demo-Pass-24!', 'admin', 0],
];
foreach ($users as [$name, $email, $pw, $role, $opening]) {
    if ($k->db->one('SELECT 1 FROM users WHERE email = ?', [$email])) { echo "exists: $email\n"; continue; }
    $u = $k->auth->createUser($name, $email, $pw, $role);
    if ($opening > 0) {
        // Bootstrap-only: opening simulated balance. After this, balances change only via TransferService.
        $k->db->run('UPDATE accounts SET balance_minor = ? WHERE user_id = ?', [$opening, $u['id']]);
    }
    echo "created: $email ($role)" . ($u['account_ref'] ? " account {$u['account_ref']}" : '') . "\n";
}
