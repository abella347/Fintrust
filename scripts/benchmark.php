<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
require __DIR__ . '/lib/SqlScript.php';
restore_error_handler();
ini_set('memory_limit', '1024M');

use App\Controllers\NotificationController;
use App\Core\Database;
use App\Helpers\Str;
use App\Models\AdminRepository;
use App\Models\TransactionRepository;
use App\Models\WalletRepository;

$o = getopt('', ['user::', 'password::', 'host::', 'port::', 'users::', 'transactions::', 'keep']);
$cfg = [
    'host' => is_string($o['host'] ?? null) ? $o['host'] : '127.0.0.1',
    'port' => (int) ($o['port'] ?? 3306),
    'user' => is_string($o['user'] ?? null) ? $o['user'] : 'root',
    'pass' => is_string($o['password'] ?? null) ? $o['password'] : '',
];
$USERS = max(50, (int) ($o['users'] ?? 2000));
$TXNS  = max(1000, (int) ($o['transactions'] ?? 150000));
const BENCH_DB = 'fintrust_bench';

$t0 = microtime(true);
$root = new PDO("mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4", $cfg['user'], $cfg['pass'], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "Building " . BENCH_DB . " from database/schema.sql...\n";
$sql = file_get_contents(base_path('database/schema.sql'));
// schema.sql runs inside whichever database is selected, so create and select ours first.
$root->exec('DROP DATABASE IF EXISTS ' . BENCH_DB);
$root->exec('CREATE DATABASE ' . BENCH_DB . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec('USE ' . BENCH_DB);
foreach (SqlScript::split($sql) as $stmt) {
    $root->exec($stmt);
}
$root->exec('USE ' . BENCH_DB);
$root->exec("SET time_zone = '+00:00'");

echo "Generating {$USERS} customers and {$TXNS} transactions...\n";
$insert = function (string $table, array $cols, array $rows) use ($root): void {
    foreach (array_chunk($rows, 1000) as $chunk) {
        $values = implode(',', array_map(
            fn ($r) => '(' . implode(',', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) ? (string) $v : $root->quote((string) $v)), $r)) . ')',
            $chunk
        ));
        $root->exec("INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES {$values}");
    }
};

$root->exec("INSERT INTO roles (id, name, description) VALUES (1,'user','Customer'),(2,'admin','Administrator'),(3,'support','Support')");
$hash = password_hash('Bench@Pass2026', PASSWORD_DEFAULT);
$first = ['Ada', 'Ben', 'Chidi', 'Ngozi', 'Tunde', 'Ifeoma', 'Kemi', 'Emeka', 'Zainab', 'Femi', 'Amaka', 'Yusuf', 'Bola', 'Obinna', 'Halima'];
$last = ['Nwosu', 'Adeyemi', 'Eze', 'Okeke', 'Bakare', 'Obi', 'Bello', 'Okafor', 'Musa', 'Ojo', 'Uche', 'Lawal', 'Ade', 'Okoro', 'Sani'];

$users = [[1, Str::uuid4(), 'Bench', 'Admin', 'admin@bench.test', '+2348000000000', $hash, 'active', '2026-01-01 00:00:00']];
$roles = [[1, 2]];
$wallets = [[1, '9000000001', null, 'system', 'Simulated Funding Account']];
for ($i = 2; $i <= $USERS + 1; $i++) {
    $users[] = [$i, Str::uuid4(), $first[$i % 15], $last[intdiv($i, 15) % 15], "user{$i}@bench.test",
                '+2348' . str_pad((string) $i, 9, '0', STR_PAD_LEFT), $hash, 'active', '2026-01-01 00:00:00'];
    $roles[] = [$i, 1];
    $wallets[] = [$i, (string) (1_000_000_000 + $i), $i, 'user', null];
}
$insert('users', ['id', 'public_id', 'first_name', 'last_name', 'email', 'phone', 'password_hash', 'status', 'email_verified_at'], $users);
$insert('user_roles', ['user_id', 'role_id'], $roles);
$insert('wallets', ['id', 'wallet_number', 'user_id', 'type', 'label'], $wallets);

mt_srand(42); // Same data every run, so results are comparable
$balance = array_fill(1, $USERS + 1, 0);
$txRows = $leRows = $notifRows = $auditRows = [];
$start = time() - 180 * 86400;
$busy = 2; // One very active customer (user 2) to stress the history page
$flush = function () use (&$txRows, &$leRows, &$notifRows, &$auditRows, $insert): void {
    $insert('transactions', ['id', 'reference', 'type', 'status', 'amount_kobo', 'sender_wallet_id', 'recipient_wallet_id', 'initiated_by_user_id', 'description', 'failure_reason', 'created_at', 'completed_at'], $txRows);
    $insert('ledger_entries', ['transaction_id', 'wallet_id', 'entry_type', 'amount_kobo', 'balance_after_kobo', 'created_at'], $leRows);
    $insert('notifications', ['user_id', 'type', 'title', 'message', 'transaction_id', 'read_at', 'created_at'], $notifRows);
    $insert('audit_logs', ['actor_user_id', 'actor_type', 'action', 'entity_type', 'entity_id', 'new_values', 'ip_address', 'created_at'], $auditRows);
    $txRows = $leRows = $notifRows = $auditRows = [];
};

for ($id = 1; $id <= $TXNS; $id++) {
    $at = gmdate('Y-m-d H:i:s', $start + intdiv($id * 180 * 86400, $TXNS));
    $user = mt_rand(1, 10) === 1 ? $busy : mt_rand(2, $USERS + 1);
    $amount = mt_rand(1_000, 50_000) * 100;
    $ref = 'FT' . substr(str_replace('-', '', $at), 0, 8) . strtoupper(substr(hash('sha1', (string) $id), 0, 12));
    $roll = mt_rand(1, 100);

    if ($roll <= 20 || $balance[$user] < $amount) {             // Deposit
        $balance[1] -= $amount;
        $balance[$user] += $amount;
        $txRows[] = [$id, $ref, 'deposit', 'successful', $amount, 1, $user, $user, 'Wallet funding (simulated)', null, $at, $at];
        $leRows[] = [$id, 1, 'debit', $amount, $balance[1], $at];
        $leRows[] = [$id, $user, 'credit', $amount, $balance[$user], $at];
    } else {
        do { $to = mt_rand(2, $USERS + 1); } while ($to === $user);
        if ($roll <= 25) {                                          // Failed transfer (no ledger rows)
            $txRows[] = [$id, $ref, 'transfer', 'failed', $amount * 50, $user, $to, $user, 'Big purchase', 'INSUFFICIENT_FUNDS', $at, $at];
        } else {                                                    // Successful transfer
            $balance[$user] -= $amount;
            $balance[$to] += $amount;
            $txRows[] = [$id, $ref, 'transfer', 'successful', $amount, $user, $to, $user, 'Payment ' . $id, null, $at, $at];
            $leRows[] = [$id, $user, 'debit', $amount, $balance[$user], $at];
            $leRows[] = [$id, $to, 'credit', $amount, $balance[$to], $at];
            $notifRows[] = [$to, 'transfer_received', 'Money received', 'You received money.', $id, mt_rand(0, 3) ? $at : null, $at];
        }
    }
    $notifRows[] = [$user, 'transfer_sent', 'Transaction', 'Transaction recorded.', $id, mt_rand(0, 3) ? $at : null, $at];
    $auditRows[] = [$user, 'user', 'transfer.completed', 'transaction', $id, '{"amount_kobo":' . $amount . '}', '127.0.0.1', $at];
    if (count($txRows) >= 5000) {
        $flush();
        echo "  " . number_format($id) . " transactions\r"; flush();
    }
}
$flush();

// Cached balances are derived from the ledger: the books must reconcile.
$root->exec("UPDATE wallets w JOIN (SELECT wallet_id, SUM(IF(entry_type='credit', amount_kobo, -CAST(amount_kobo AS SIGNED))) b
             FROM ledger_entries GROUP BY wallet_id) l ON l.wallet_id = w.id SET w.balance_kobo = l.b");

$logins = $alerts = [];
for ($i = 0; $i < 50_000; $i++) {
    $u = mt_rand(2, $USERS + 1);
    $ok = mt_rand(1, 10) > 2;
    $logins[] = [$u, "user{$u}@bench.test", '10.0.' . mt_rand(0, 255) . '.' . mt_rand(1, 254), 'Bench', $ok ? 1 : 0, $ok ? null : 'bad_password',
                 gmdate('Y-m-d H:i:s', time() - mt_rand(0, 30 * 86400))];
}
$insert('login_attempts', ['user_id', 'email', 'ip_address', 'user_agent', 'successful', 'failure_reason', 'attempted_at'], $logins);
foreach (range(1, 3000) as $i) {
    $alerts[] = [mt_rand(2, $USERS + 1), ['LARGE_TRANSFER', 'RAPID_TRANSFERS', 'FAILED_TRANSFERS', 'PIN_FAILURES'][$i % 4],
                 ['low', 'medium', 'high', 'critical'][$i % 4], 'Benchmark alert', ['open', 'investigating', 'resolved', 'dismissed'][$i % 4],
                 gmdate('Y-m-d H:i:s', time() - mt_rand(0, 90 * 86400))];
}
$insert('fraud_alerts', ['user_id', 'rule_code', 'severity', 'reason', 'status', 'created_at'], $alerts);
$root->query('ANALYZE TABLE transactions, ledger_entries, notifications, audit_logs, login_attempts, fraud_alerts, wallets, users')->fetchAll(); // Refresh index statistics

$drift = (int) $root->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn();
$unbal = (int) $root->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn();
printf("Data ready in %.1fs — %s ledger entries, ledger check: %d unbalanced, %d out of step.\n\n",
    microtime(true) - $t0, number_format((int) $root->query('SELECT COUNT(*) FROM ledger_entries')->fetchColumn()), $unbal, $drift);

foreach (['DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => BENCH_DB,
          'DB_USERNAME' => $cfg['user'], 'DB_PASSWORD' => $cfg['pass']] as $k => $v) {
    putenv("{$k}={$v}");
}
Database::reset();

$busyWallet = 2;
$busyCount = (int) $root->query("SELECT COUNT(*) FROM transactions WHERE sender_wallet_id = 2 OR recipient_wallet_id = 2")->fetchColumn();
$someRef = $root->query('SELECT reference FROM transactions ORDER BY id DESC LIMIT 1 OFFSET 777')->fetchColumn();
$tx = new TransactionRepository();
$admin = new AdminRepository();
$since30 = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
$since6m = gmdate('Y-m-d H:i:s', time() - 183 * 86400);

$cases = [
    "History p1 (busy customer, {$busyCount} txns)" => fn () => $tx->list($busyWallet, [], 10, 0),
    'History p50 (offset 490)'               => fn () => $tx->list($busyWallet, [], 10, 490),
    'History search by name'                 => fn () => $tx->list($busyWallet, ['search' => 'Ngozi'], 10, 0),
    'History status=failed'                  => fn () => $tx->list($busyWallet, ['status' => 'failed'], 10, 0),
    'Transaction by reference (customer)'    => fn () => $tx->findVisible($someRef, $busyWallet),
    'Dashboard: 30-day totals'               => fn () => $tx->totalsSince($busyWallet, $since30),
    'Dashboard: 6-month chart'               => fn () => $tx->monthlyTotals($busyWallet, $since6m),
    'Daily limit: sent today'                => fn () => (new WalletRepository())->sentToday($busyWallet),
    'Notifications (busy customer)'          => fn () => NotificationController::summary(2, 20),
    'Admin: transactions p1'                 => fn () => $admin->transactions([], 20, 0),
    'Admin: transactions p250 (offset 4980)' => fn () => $admin->transactions([], 20, 4980),
    'Admin: failed transactions'             => fn () => $admin->transactions(['status' => 'failed'], 20, 0),
    'Admin: search by reference'             => fn () => $admin->transactions(['search' => $someRef], 20, 0),
    'Admin: statistics (overview)'           => fn () => $admin->statistics(),
    'Admin: users search'                    => fn () => $admin->users('ngozi', null, null, 15, 0),
    'Admin: open fraud alerts'               => fn () => $admin->fraudAlerts(['status' => 'open'], 20, 0),
    'Admin: audit log "transfer."'           => fn () => $admin->auditLogs(['action' => 'transfer.'], 25, 0),
    'Admin: security page'                   => fn () => $admin->security(),
];

$results = [];
printf("%-44s %10s %10s\n", 'Query', 'median ms', 'max ms');
echo str_repeat('-', 66) . "\n";
foreach ($cases as $name => $fn) {
    $times = [];
    for ($r = 0; $r < 5; $r++) {
        $s = hrtime(true);
        $fn();
        $times[] = (hrtime(true) - $s) / 1e6;
    }
    sort($times);
    $results[$name] = $times[2];
    printf("%-44s %10.1f %10.1f%s\n", $name, $times[2], max($times), $times[2] > 100 ? '   <-- slow' : '');
}

$explain = [
    'Customer history, BEFORE (one OR query: merge + sort every row)' =>
        "SELECT t.id FROM transactions t WHERE (t.sender_wallet_id = 2 OR (t.recipient_wallet_id = 2 AND t.status IN ('successful','reversed'))) ORDER BY t.created_at DESC, t.id DESC LIMIT 10",
    'Customer history, AFTER (each half read newest-first from its index)' =>
        "SELECT id FROM ((SELECT t.id, t.created_at FROM transactions t WHERE t.sender_wallet_id = 2 ORDER BY t.created_at DESC, t.id DESC LIMIT 10)
         UNION ALL (SELECT t.id, t.created_at FROM transactions t WHERE t.recipient_wallet_id = 2 AND t.status IN ('successful','reversed') ORDER BY t.created_at DESC, t.id DESC LIMIT 10)) p
         ORDER BY created_at DESC, id DESC LIMIT 10",
    'Ledger balance check (per-transaction totals)' =>
        'SELECT COUNT(*) FROM v_unbalanced_transactions',
    'Admin newest transactions' =>
        'SELECT t.id FROM transactions t ORDER BY t.created_at DESC, t.id DESC LIMIT 20',
    'Successful volume (overview)' =>
        "SELECT SUM(amount_kobo) FROM transactions WHERE status = 'successful'",
    'Failed logins by IP (security page)' =>
        'SELECT ip_address, COUNT(*) FROM login_attempts WHERE successful = 0 AND attempted_at > UTC_TIMESTAMP() - INTERVAL 1 DAY GROUP BY ip_address',
];
echo "\nEXPLAIN (type = how rows are found; key = index used; rows = estimated rows read)\n";
foreach ($explain as $label => $q) {
    echo "\n  {$label}\n";
    foreach ($root->query('EXPLAIN ' . $q)->fetchAll() as $row) {
        printf("    table=%-3s type=%-12s key=%-28s rows=%-8s %s\n", $row['table'], $row['type'], $row['key'] ?? 'NULL', $row['rows'], $row['Extra'] ?? '');
    }
}

if (!isset($o['keep'])) {
    $root->exec('DROP DATABASE ' . BENCH_DB);
    echo "\nDropped " . BENCH_DB . " (use --keep to inspect it).\n";
}
file_put_contents(base_path('storage/cache/benchmark-last.json'), json_encode($results, JSON_PRETTY_PRINT));
