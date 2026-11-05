<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$opts = getopt('', ['user::', 'password::', 'host::', 'port::', 'worker::', 'mode::', 'start::']);
$cfg = [
    'host' => $opts['host'] ?? '127.0.0.1',
    'port' => (int) ($opts['port'] ?? 3306),
    'user' => $opts['user'] ?? 'root',
    'pass' => is_string($opts['password'] ?? null) ? $opts['password'] : '',
];
const DEMO_DB      = 'fintrust_lockdemo';
const WORKERS      = 10;
const START_KOBO   = 5_000_000; // ₦50,000
const AMOUNT_KOBO  = 2_000_000; // ₦20,000

function connect(array $cfg, ?string $db = DEMO_DB): PDO
{
    $dsn = "mysql:host={$cfg['host']};port={$cfg['port']};charset=utf8mb4" . ($db ? ";dbname={$db}" : '');
    return new PDO($dsn, $cfg['user'], $cfg['pass'], [
        PDO::ATTR_ERRMODE          => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function naira(int $kobo): string
{
    return ($kobo < 0 ? '-' : '') . '₦' . number_format(intdiv(abs($kobo), 100)) . '.' . str_pad((string) (abs($kobo) % 100), 2, '0', STR_PAD_LEFT);
}

if (isset($opts['worker'])) {
    $pdo  = connect($cfg);
    $mode = $opts['mode'];
    // Wait for the shared start time so all workers hit the DB together.
    while (microtime(true) < (float) $opts['start']) {
        usleep(200);
    }

    try {
        if ($mode === 'unsafe') {
            // Unsafe: read the balance, think, then write a new absolute value.
            $pdo->beginTransaction();
            $sender = (int) $pdo->query('SELECT balance_kobo FROM wallets WHERE id = 1')->fetchColumn();
            $recip  = (int) $pdo->query('SELECT balance_kobo FROM wallets WHERE id = 2')->fetchColumn();
            usleep(30_000); // Real code does work here: PIN check, limits, fraud rules...
            if ($sender < AMOUNT_KOBO) {
                $pdo->rollBack();
                echo "rejected\n";
                exit;
            }
            $pdo->prepare('UPDATE wallets SET balance_kobo = ? WHERE id = 1')->execute([$sender - AMOUNT_KOBO]);
            $pdo->prepare('UPDATE wallets SET balance_kobo = ? WHERE id = 2')->execute([$recip + AMOUNT_KOBO]);
            $pdo->prepare("INSERT INTO ledger_entries (wallet_id, entry_type, amount_kobo) VALUES (1,'debit',?),(2,'credit',?)")
                ->execute([AMOUNT_KOBO, AMOUNT_KOBO]);
            $pdo->commit();
            echo "success\n";
        } else {
            // Safe: lock both rows first (in a fixed order), then read, decide, write.
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $pdo->beginTransaction();
            $rows = $pdo->query('SELECT id, balance_kobo FROM wallets WHERE id IN (1, 2) ORDER BY id FOR UPDATE')
                        ->fetchAll(PDO::FETCH_KEY_PAIR);
            usleep(30_000);
            if ($rows[1] < AMOUNT_KOBO) {
                $pdo->rollBack();
                echo "rejected\n";
                exit;
            }
            $pdo->prepare('UPDATE wallets SET balance_kobo = balance_kobo - ? WHERE id = 1')->execute([AMOUNT_KOBO]);
            $pdo->prepare('UPDATE wallets SET balance_kobo = balance_kobo + ? WHERE id = 2')->execute([AMOUNT_KOBO]);
            $pdo->prepare("INSERT INTO ledger_entries (wallet_id, entry_type, amount_kobo) VALUES (1,'debit',?),(2,'credit',?)")
                ->execute([AMOUNT_KOBO, AMOUNT_KOBO]);
            $pdo->commit();
            echo "success\n";
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo 'error: ' . $e->getMessage() . "\n";
    }
    exit;
}

function resetDatabase(array $cfg): void
{
    $root = connect($cfg, null);
    $root->exec('DROP DATABASE IF EXISTS ' . DEMO_DB);
    $root->exec('CREATE DATABASE ' . DEMO_DB . ' CHARACTER SET utf8mb4');
    $pdo = connect($cfg);
    $pdo->exec('CREATE TABLE wallets (id INT PRIMARY KEY, owner VARCHAR(20), balance_kobo BIGINT NOT NULL) ENGINE=InnoDB');
    $pdo->exec("CREATE TABLE ledger_entries (id INT AUTO_INCREMENT PRIMARY KEY, wallet_id INT NOT NULL,
                entry_type ENUM('debit','credit') NOT NULL, amount_kobo BIGINT NOT NULL) ENGINE=InnoDB");
    $pdo->prepare("INSERT INTO wallets VALUES (1, 'Ada', ?), (2, 'Ben', 0)")->execute([START_KOBO]);
}

function runMode(array $cfg, string $mode): void
{
    resetDatabase($cfg);
    $start = microtime(true) + 0.6;
    $procs = [];

    for ($i = 0; $i < WORKERS; $i++) {
        $cmd = [PHP_BINARY, __FILE__, '--worker=1', "--mode={$mode}", "--start={$start}",
                "--host={$cfg['host']}", "--port={$cfg['port']}", "--user={$cfg['user']}", "--password={$cfg['pass']}"];
        $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $procs[] = [$p, $pipes];
    }

    $outcomes = ['success' => 0, 'rejected' => 0, 'error' => 0];
    foreach ($procs as [$p, $pipes]) {
        $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        proc_close($p);
        $key = str_starts_with($out, 'success') ? 'success' : (str_starts_with($out, 'rejected') ? 'rejected' : 'error');
        $outcomes[$key]++;
        if ($key === 'error') {
            echo "    worker error: {$out}\n";
        }
    }

    $pdo = connect($cfg);
    $bal = $pdo->query('SELECT id, balance_kobo FROM wallets ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    $ledger = $pdo->query("SELECT wallet_id, SUM(IF(entry_type='credit', amount_kobo, -amount_kobo)) FROM ledger_entries GROUP BY wallet_id")
                  ->fetchAll(PDO::FETCH_KEY_PAIR);
    $ledgerAda = (int) ($ledger[1] ?? 0) + START_KOBO; // Ledger only holds movements after the opening balance
    $ledgerBen = (int) ($ledger[2] ?? 0);

    $title = $mode === 'unsafe' ? 'UNSAFE: read, then write (no lock)' : 'SAFE: SELECT ... FOR UPDATE inside a transaction';
    echo "\n=== {$title} ===\n";
    printf("  Transfers reported successful: %d   rejected: %d   errors: %d\n", $outcomes['success'], $outcomes['rejected'], $outcomes['error']);
    printf("  Ada's wallet balance:  %-14s (ledger says %s)\n", naira((int) $bal[1]), naira($ledgerAda));
    printf("  Ben's wallet balance:  %-14s (ledger says %s)\n", naira((int) $bal[2]), naira($ledgerBen));
    printf("  Money in the system:   %s  (should always be %s)\n", naira((int) $bal[1] + (int) $bal[2]), naira(START_KOBO));

    $paidOut = $outcomes['success'] * AMOUNT_KOBO;
    $correct = $outcomes['success'] === 2 && (int) $bal[1] === START_KOBO - $paidOut && (int) $bal[2] === $paidOut
               && $ledgerAda === (int) $bal[1] && $ledgerBen === (int) $bal[2];
    echo $correct
        ? "  RESULT: PASS - no double spending, balances match the ledger.\n"
        : "  RESULT: FAIL - {$outcomes['success']} transfers \"succeeded\" for ₦" . number_format($paidOut / 100) .
          " from a ₦50,000 wallet, and the balances disagree with the ledger.\n";
}

echo "FinTrust race-condition demo: " . WORKERS . " simultaneous ₦20,000 transfers from a ₦50,000 wallet\n";
try {
    runMode($cfg, 'unsafe');
    runMode($cfg, 'safe');
    connect($cfg, null)->exec('DROP DATABASE IF EXISTS ' . DEMO_DB);
} catch (PDOException $e) {
    fwrite(STDERR, "Database error: {$e->getMessage()}\nIs MySQL running? Pass --user= and --password= if root has a password.\n");
    exit(1);
}
