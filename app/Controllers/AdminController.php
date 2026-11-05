<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\Cache;
use App\Helpers\Dates;
use App\Helpers\Money;
use App\Helpers\Validate;
use App\Models\AdminRepository;
use App\Models\UserRepository;
use App\Services\AdminService;

/** /api/admin/* - every route is protected by RequirePermission in routes/api.php. */
final class AdminController
{
    private AdminRepository $repo;

    public function __construct()
    {
        $this->repo = new AdminRepository();
    }

    /* ---------------- overview ---------------- */

    public function statistics(Request $r): void
    {
        // The ledger checks read every ledger row, so the figures are cached for
        // 60 seconds (docs/performance.md). ?fresh=1 forces a recount.
        $stats = Cache::remember('admin-statistics', 60, function () {
            return ['generated_at' => gmdate('Y-m-d\\TH:i:s\\Z')] + $this->repo->statistics();
        }, $r->query('fresh') === '1');
        $alerts = $this->repo->fraudAlerts(['status' => 'open'], 5, 0)['items'];
        $txns = $this->repo->transactions([], 8, 0)['items'];
        $stats['recent_alerts'] = array_map([$this, 'alert'], $alerts);
        $stats['recent_transactions'] = array_map([$this, 'txn'], $txns);
        Response::success($stats);
    }

    /* ---------------- users ---------------- */

    public function users(Request $r): void
    {
        $v = new Validate();
        $status = $v->oneOf($r->query('status'), 'status', ['active', 'suspended', 'closed']);
        $role = $v->oneOf($r->query('role'), 'role', ['user', 'support', 'admin']);
        $v->throwIfErrors('Check your filters.');
        [$page, $per] = $this->paging($r, 15);
        $result = $this->repo->users(mb_substr(Validate::str($r->query('search')), 0, 60), $status, $role, $per, ($page - 1) * $per);
        Response::success([
            'items' => array_map([$this, 'userRow'], $result['items']),
            'pagination' => $this->pagination($page, $per, $result['total']),
        ]);
    }

    public function user(Request $r): void
    {
        $u = $this->repo->userByPublicId((string) $r->param('id'));
        if (!$u) {
            throw new HttpException(404, 'User not found.', [], 'NOT_FOUND');
        }
        $txns = $u['wallet_id'] ? $this->repo->transactions(['search' => $u['wallet_number']], 10, 0)['items'] : [];
        $alerts = $this->repo->fraudAlerts(['user' => $u['public_id']], 10, 0)['items'];
        $logins = (new UserRepository())->recentLogins((int) $u['id'], 10);

        Response::success([
            'user' => $this->userRow($u) + [
                'pin_set'          => (bool) $u['has_pin'],
                'pin_locked_until' => Dates::iso($u['pin_locked_until']),
                'failed_logins'    => (int) $u['failed_login_count'],
            ],
            'transactions' => array_map([$this, 'txn'], $txns),
            'alerts'       => array_map([$this, 'alert'], $alerts),
            'logins'       => array_map(fn ($l) => [
                'successful' => (bool) $l['successful'], 'ip_address' => $l['ip_address'],
                'user_agent' => $l['user_agent'], 'attempted_at' => Dates::iso($l['attempted_at']),
            ], $logins),
            'can_manage'   => ['users' => Auth::can('users.manage'), 'wallets' => Auth::can('wallets.manage')],
        ]);
    }

    public function suspendUser(Request $r): void
    {
        $out = (new AdminService())->setUserStatus(Auth::id(), (string) $r->param('id'), 'suspended', $r->input('reason'));
        Response::success($out, 'Account suspended.');
    }

    public function reactivateUser(Request $r): void
    {
        $out = (new AdminService())->setUserStatus(Auth::id(), (string) $r->param('id'), 'active', $r->input('reason'));
        Response::success($out, 'Account reactivated.');
    }

    public function freezeWallet(Request $r): void
    {
        $out = (new AdminService())->setWalletStatus(Auth::id(), (string) $r->param('number'), 'frozen', $r->input('reason'));
        Response::success($out, 'Wallet frozen.');
    }

    public function unfreezeWallet(Request $r): void
    {
        $out = (new AdminService())->setWalletStatus(Auth::id(), (string) $r->param('number'), 'active', $r->input('reason'));
        Response::success($out, 'Wallet unfrozen.');
    }

    /* ---------------- transactions ---------------- */

    public function transactions(Request $r): void
    {
        $v = new Validate();
        $f = [
            'search'  => mb_substr(Validate::str($r->query('search')), 0, 40),
            'type'    => $v->oneOf($r->query('type'), 'type', ['transfer', 'deposit', 'reversal', 'adjustment']),
            'status'  => $v->oneOf($r->query('status'), 'status', ['pending', 'successful', 'failed', 'reversed']),
            'from'    => $v->date($r->query('from'), 'from'),
            'to'      => $v->date($r->query('to'), 'to'),
            'min'     => $this->optionalAmount($v, $r->query('min'), 'min'),
            'max'     => $this->optionalAmount($v, $r->query('max'), 'max'),
            'flagged' => $r->query('flagged') === '1',
        ];
        $v->throwIfErrors('Check your filters.');
        [$page, $per] = $this->paging($r, 20);
        $result = $this->repo->transactions($f, $per, ($page - 1) * $per);
        Response::success([
            'items' => array_map([$this, 'txn'], $result['items']),
            'pagination' => $this->pagination($page, $per, $result['total']),
        ]);
    }

    public function transaction(Request $r): void
    {
        $t = $this->repo->transaction(strtoupper((string) $r->param('reference')));
        if (!$t) {
            throw new HttpException(404, 'Transaction not found.', [], 'NOT_FOUND');
        }
        $out = $this->txn($t);
        $out['ledger'] = array_map(fn ($l) => [
            'entry_type' => $l['entry_type'], 'amount_kobo' => (int) $l['amount_kobo'],
            'balance_after_kobo' => (int) $l['balance_after_kobo'],
            'wallet_number' => $l['wallet_number'], 'wallet_type' => $l['wallet_type'],
        ], $t['ledger']);
        $out['ip_address'] = $t['ip_address'];
        Response::success($out);
    }

    /* ---------------- fraud ---------------- */

    public function fraudAlerts(Request $r): void
    {
        $v = new Validate();
        $f = [
            'status'    => $v->oneOf($r->query('status'), 'status', ['open', 'investigating', 'resolved', 'dismissed']),
            'severity'  => $v->oneOf($r->query('severity'), 'severity', ['low', 'medium', 'high', 'critical']),
            'rule_code' => $v->oneOf($r->query('rule'), 'rule', ['LARGE_TRANSFER', 'RAPID_TRANSFERS', 'FAILED_TRANSFERS', 'LIMIT_EXCEEDED',
                                                             'PIN_FAILURES', 'LOGIN_FAILURES', 'CREDENTIAL_STUFFING', 'TRANSFER_OVER_BALANCE']),
        ];
        $v->throwIfErrors('Check your filters.');
        [$page, $per] = $this->paging($r, 20);
        $result = $this->repo->fraudAlerts($f, $per, ($page - 1) * $per);
        Response::success([
            'items' => array_map([$this, 'alert'], $result['items']),
            'pagination' => $this->pagination($page, $per, $result['total']),
            'can_review' => Auth::can('fraud.review'),
        ]);
    }

    public function reviewAlert(Request $r): void
    {
        $id = (int) $r->param('id');
        $out = (new AdminService())->reviewAlert(Auth::id(), $id, $r->input('status'), $r->input('note'));
        Response::success($out, 'Alert updated.');
    }

    /* ---------------- audit & security ---------------- */

    public function auditLogs(Request $r): void
    {
        $v = new Validate();
        $f = [
            'action'     => mb_substr(Validate::str($r->query('action')), 0, 60),
            'actor'      => mb_substr(Validate::str($r->query('actor')), 0, 100),
            'actor_type' => $v->oneOf($r->query('actor_type'), 'actor_type', ['user', 'admin', 'system']),
        ];
        $v->throwIfErrors('Check your filters.');
        [$page, $per] = $this->paging($r, 25);
        $result = $this->repo->auditLogs($f, $per, ($page - 1) * $per);
        Response::success([
            'items' => array_map(fn ($a) => [
                'id' => (int) $a['id'], 'action' => $a['action'], 'actor_type' => $a['actor_type'],
                'actor' => $a['actor_email'] ? ['name' => $a['actor_name'], 'email' => $a['actor_email']] : null,
                'entity_type' => $a['entity_type'], 'entity_id' => $a['entity_id'] !== null ? (int) $a['entity_id'] : null,
                'old_values' => $a['old_values'] ? json_decode($a['old_values'], true) : null,
                'new_values' => $a['new_values'] ? json_decode($a['new_values'], true) : null,
                'ip_address' => $a['ip_address'], 'created_at' => Dates::iso($a['created_at']),
            ], $result['items']),
            'pagination' => $this->pagination($page, $per, $result['total']),
        ]);
    }

    public function security(Request $r): void
    {
        $s = $this->repo->security();
        $iso = function (array $rows, array $cols): array {
            return array_map(function ($row) use ($cols) {
                foreach ($cols as $c) {
                    $row[$c] = Dates::iso($row[$c]);
                }
                return $row;
            }, $rows);
        };
        Response::success([
            'locked_accounts' => $iso($s['locked_accounts'], ['locked_until']),
            'pin_locked'      => $iso($s['pin_locked'], ['locked_until']),
            'top_ips'         => array_map(fn ($x) => ['ip_address' => $x['ip_address'], 'failures' => (int) $x['failures'],
                                     'accounts' => (int) $x['accounts'], 'last_attempt' => Dates::iso($x['last_attempt'])], $s['top_ips']),
            'recent_failures' => $iso($s['recent_failures'], ['attempted_at']),
            'suspended'       => $iso($s['suspended'], ['updated_at']),
            'frozen_wallets'  => $iso($s['frozen_wallets'], ['updated_at']),
            'can_manage'      => ['users' => Auth::can('users.manage'), 'wallets' => Auth::can('wallets.manage')],
        ]);
    }

    /* ---------------- shaping ---------------- */

    private function userRow(array $u): array
    {
        return [
            'public_id'      => $u['public_id'],
            'name'           => trim($u['first_name'] . ' ' . $u['last_name']),
            'email'          => $u['email'],
            'phone'          => $u['phone'],
            'status'         => $u['status'],
            'roles'          => $u['roles'] ? explode(',', $u['roles']) : [],
            'email_verified' => $u['email_verified_at'] !== null,
            'locked'         => $u['locked_until'] !== null && strtotime($u['locked_until'] . ' UTC') > time(),
            'last_login_at'  => Dates::iso($u['last_login_at']),
            'created_at'     => Dates::iso($u['created_at']),
            'wallet'         => $u['wallet_number'] ? [
                'wallet_number' => $u['wallet_number'],
                'balance_kobo'  => (int) $u['balance_kobo'],
                'status'        => $u['wallet_status'],
            ] : null,
            'open_alerts'    => isset($u['open_alerts']) ? (int) $u['open_alerts'] : null,
        ];
    }

    private function txn(array $t): array
    {
        $isDeposit = $t['sender_wallet_type'] === 'system';
        return [
            'reference'      => $t['reference'],
            'type'           => $t['type'],
            'status'         => $t['status'],
            'amount_kobo'    => (int) $t['amount_kobo'],
            'description'    => $t['description'],
            'failure_reason' => $t['failure_reason'],
            'sender'         => $isDeposit ? ['name' => 'Simulated funding', 'wallet_number' => $t['sender_wallet_number'], 'public_id' => null]
                                           : ['name' => $t['sender_name'], 'wallet_number' => $t['sender_wallet_number'], 'public_id' => $t['sender_public_id']],
            'recipient'      => ['name' => $t['recipient_name'], 'wallet_number' => $t['recipient_wallet_number'], 'public_id' => $t['recipient_public_id']],
            'flagged'        => (int) $t['alert_count'] > 0,
            'created_at'     => Dates::iso($t['created_at']),
        ];
    }

    private function alert(array $a): array
    {
        return [
            'id'          => (int) $a['id'],
            'rule_code'   => $a['rule_code'],
            'severity'    => $a['severity'],
            'reason'      => $a['reason'],
            'details'     => $a['details'] ? json_decode($a['details'], true) : null,
            'status'      => $a['status'],
            'user'        => $a['user_public_id'] ? ['public_id' => $a['user_public_id'], 'name' => $a['user_name'], 'email' => $a['user_email']] : null,
            'transaction_reference' => $a['transaction_reference'],
            'reviewer'    => $a['reviewer_name'] ?: null,
            'resolution_note' => $a['resolution_note'],
            'reviewed_at' => Dates::iso($a['reviewed_at']),
            'created_at'  => Dates::iso($a['created_at']),
        ];
    }

    private function paging(Request $r, int $default): array
    {
        return [max(1, (int) ($r->query('page') ?? 1)), max(1, min(100, (int) ($r->query('per_page') ?? $default)))];
    }

    private function pagination(int $page, int $per, int $total): array
    {
        return ['page' => $page, 'per_page' => $per, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per))];
    }

    private function optionalAmount(Validate $v, mixed $value, string $field): ?int
    {
        $s = Validate::str($value);
        if ($s === '') {
            return null;
        }
        try {
            return Money::toKobo($s);
        } catch (\InvalidArgumentException $e) {
            $v->add($field, $e->getMessage());
            return null;
        }
    }
}
