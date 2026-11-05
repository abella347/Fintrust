<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';
restore_error_handler();

use App\Core\Database;
use App\Core\HttpException;
use App\Models\UserRepository;
use App\Models\WalletRepository;
use App\Services\TransferService;
use App\Services\WalletService;

$opts = getopt('', ['worker::', 'user::', 'to::', 'key::', 'start::']);

// Worker: one "HTTP request"
if (isset($opts['worker'])) {
    while (microtime(true) < (float) $opts['start']) {
        usleep(200);
    }
    try {
        $r = (new TransferService())->transfer((int) $opts['user'],
            ['recipient_wallet_number' => $opts['to'], 'amount' => '20000', 'description' => 'race', 'pin' => '4826'], $opts['key']);
        echo "OK {$r['data']['transaction']['reference']}\n";
    } catch (HttpException $e) {
        echo $e->errorCode() . "\n";
    } catch (Throwable $e) {
        echo 'ERROR ' . $e->getMessage() . "\n";
    }
    exit;
}

// Controller
function makeCustomer(string $label): array
{
    $users = new UserRepository();
    $email = 'race-' . $label . '-' . bin2hex(random_bytes(3)) . '@fintrust.test';
    $phone = '+2347' . random_int(0, 1) . str_pad((string) random_int(0, 99_999_999), 8, '0', STR_PAD_LEFT);
    return Database::transaction(function () use ($users, $email, $phone, $label) {
        $id = $users->create('Race', ucfirst($label), $email, $phone, password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT));
        $users->assignRole($id, 'user');
        $users->markEmailVerified($id);
        $wallet = (new WalletRepository())->createForUser($id);
        Database::connection()->prepare('INSERT INTO transaction_pins (user_id, pin_hash) VALUES (?, ?)')
            ->execute([$id, password_hash('4826', PASSWORD_DEFAULT)]);
        return ['id' => $id, 'wallet' => $wallet['wallet_number']];
    });
}

$pdo = Database::connection();
$balance = function (string $number) use ($pdo): int {
    $s = $pdo->prepare('SELECT balance_kobo FROM wallets WHERE wallet_number = ?');
    $s->execute([$number]);
    return (int) $s->fetchColumn();
};

$sender = makeCustomer('sender');
$recipient = makeCustomer('recipient');
(new WalletService())->deposit($sender['id'], ['amount' => '50000'], bin2hex(random_bytes(16)));
printf("Sender %s funded with ₦50,000. Firing 12 simultaneous ₦20,000 transfers...\n\n", $sender['wallet']);

$shared = bin2hex(random_bytes(16));
$keys = [];
for ($i = 0; $i < 8; $i++) {
    $keys[] = ['unique', bin2hex(random_bytes(16))];
}
for ($i = 0; $i < 4; $i++) {
    $keys[] = ['shared', $shared];
}

$start = microtime(true) + 0.8;
$procs = [];
foreach ($keys as [$kind, $key]) {
    $cmd = [PHP_BINARY, __FILE__, '--worker=1', "--user={$sender['id']}", "--to={$recipient['wallet']}", "--key={$key}", "--start={$start}"];
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $procs[] = [$kind, $p, $pipes];
}

$tally = [];
$sharedRefs = [];
foreach ($procs as [$kind, $p, $pipes]) {
    $out = trim(stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
    proc_close($p);
    $label = str_starts_with($out, 'OK') ? 'success' : $out;
    $tally["{$kind}: {$label}"] = ($tally["{$kind}: {$label}"] ?? 0) + 1;
    if ($kind === 'shared' && str_starts_with($out, 'OK')) {
        $sharedRefs[substr($out, 3)] = true;
    }
}
ksort($tally);
foreach ($tally as $k => $n) {
    printf("  %-40s x%d\n", $k, $n);
}

$s = $pdo->prepare("SELECT COUNT(*) FROM transactions t JOIN wallets w ON w.id = t.sender_wallet_id WHERE w.wallet_number = ? AND t.status = 'successful'");
$s->execute([$sender['wallet']]);
$moved = (int) $s->fetchColumn();
$senderBal = $balance($sender['wallet']);
$recipBal = $balance($recipient['wallet']);
$drift = (int) $pdo->query('SELECT COUNT(*) FROM v_wallet_reconciliation WHERE difference_kobo <> 0')->fetchColumn();
$unbalanced = (int) $pdo->query('SELECT COUNT(*) FROM v_unbalanced_transactions')->fetchColumn();

printf("\n  Successful transfers in the database: %d\n", $moved);
printf("  Shared key produced %d distinct transfer(s)\n", count($sharedRefs));
printf("  Sender balance:    ₦%s\n  Recipient balance: ₦%s\n", number_format($senderBal / 100, 2), number_format($recipBal / 100, 2));
printf("  Ledger: %d unbalanced transactions, %d wallets out of step\n\n", $unbalanced, $drift);

$ok = $moved === 2 && count($sharedRefs) <= 1 && $senderBal === 1_000_000 && $recipBal === 4_000_000 && $drift === 0 && $unbalanced === 0;
echo $ok ? "RESULT: PASS - no double spending, no duplicate payments, books balance.\n" : "RESULT: FAIL - something is wrong, see numbers above.\n";
exit($ok ? 0 : 1);
