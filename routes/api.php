<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\BiometricController;
use App\Controllers\DashboardController;
use App\Controllers\HealthController;
use App\Controllers\NotificationController;
use App\Controllers\TransactionController;
use App\Controllers\UserController;
use App\Controllers\WalletController;
use App\Middleware\RequireAuth;
use App\Middleware\RequireCustomer;
use App\Middleware\RequirePermission;

$router->get('/api/health', [HealthController::class, 'show']);

// Authentication (public)
$router->post('/api/auth/register',            [AuthController::class, 'register']);
$router->post('/api/auth/login',               [AuthController::class, 'login']);
$router->post('/api/auth/logout',              [AuthController::class, 'logout']);
$router->post('/api/auth/forgot-password',     [AuthController::class, 'forgotPassword']);
$router->post('/api/auth/reset-password',      [AuthController::class, 'resetPassword']);
$router->post('/api/auth/verify-email',        [AuthController::class, 'verifyEmail']);
$router->post('/api/auth/resend-verification', [AuthController::class, 'resendVerification']);

// Customer: dashboard, wallet, money
$router->get('/api/dashboard',       [DashboardController::class, 'show'],  [RequireCustomer::class]);
$router->get('/api/wallet',          [WalletController::class, 'show'],     [RequireCustomer::class]);
$router->get('/api/wallet/balance',  [WalletController::class, 'balance'],  [RequireCustomer::class]);
$router->get('/api/wallet/lookup',   [WalletController::class, 'lookup'],   [RequireCustomer::class]);
$router->post('/api/wallet/deposit', [WalletController::class, 'deposit'],  [RequireCustomer::class]);

$router->post('/api/transactions/transfer/options', [BiometricController::class, 'transferOptions'], [RequireCustomer::class]);
$router->post('/api/transactions/transfer',   [TransactionController::class, 'transfer'], [RequireCustomer::class]);
$router->get('/api/transactions',             [TransactionController::class, 'index'],    [RequireCustomer::class]);
$router->get('/api/transactions/{reference}', [TransactionController::class, 'show'],     [RequireCustomer::class]);

// Any signed-in user
$router->get('/api/notifications',       [NotificationController::class, 'index'],    [RequireAuth::class]);
$router->post('/api/notifications/read', [NotificationController::class, 'markRead'], [RequireAuth::class]);

$router->get('/api/user/profile',  [UserController::class, 'profile'],        [RequireAuth::class]);
$router->put('/api/user/profile',  [UserController::class, 'updateProfile'],  [RequireAuth::class]);
$router->put('/api/user/password', [UserController::class, 'updatePassword'], [RequireAuth::class]);
$router->put('/api/user/pin',      [UserController::class, 'updatePin'],      [RequireCustomer::class]);

$router->get('/api/user/avatar',    [UserController::class, 'avatar'],       [RequireAuth::class]);
$router->post('/api/user/avatar',   [UserController::class, 'uploadAvatar'], [RequireAuth::class]);
$router->delete('/api/user/avatar', [UserController::class, 'removeAvatar'], [RequireAuth::class]);

// Face ID / fingerprint (WebAuthn) for approving transfers
$router->get('/api/user/biometrics',              [BiometricController::class, 'index'],               [RequireCustomer::class]);
$router->post('/api/user/biometrics/options',     [BiometricController::class, 'registrationOptions'], [RequireCustomer::class]);
$router->post('/api/user/biometrics',             [BiometricController::class, 'register'],            [RequireCustomer::class]);
$router->delete('/api/user/biometrics/{id}',      [BiometricController::class, 'remove'],              [RequireCustomer::class]);

// Staff (RBAC: config/permissions.php)
$P = RequirePermission::class;
$router->get('/api/admin/statistics',                     [AdminController::class, 'statistics'],     ["{$P}:reports.view"]);
$router->get('/api/admin/users',                          [AdminController::class, 'users'],          ["{$P}:users.view"]);
$router->get('/api/admin/users/{id}',                     [AdminController::class, 'user'],           ["{$P}:users.view"]);
$router->post('/api/admin/users/{id}/suspend',            [AdminController::class, 'suspendUser'],    ["{$P}:users.manage"]);
$router->post('/api/admin/users/{id}/reactivate',         [AdminController::class, 'reactivateUser'], ["{$P}:users.manage"]);
$router->post('/api/admin/wallets/{number}/freeze',       [AdminController::class, 'freezeWallet'],   ["{$P}:wallets.manage"]);
$router->post('/api/admin/wallets/{number}/unfreeze',     [AdminController::class, 'unfreezeWallet'], ["{$P}:wallets.manage"]);
$router->get('/api/admin/transactions',                   [AdminController::class, 'transactions'],   ["{$P}:transactions.view"]);
$router->get('/api/admin/transactions/{reference}',       [AdminController::class, 'transaction'],    ["{$P}:transactions.view"]);
$router->get('/api/admin/fraud-alerts',                   [AdminController::class, 'fraudAlerts'],    ["{$P}:fraud.view"]);
$router->put('/api/admin/fraud-alerts/{id}',              [AdminController::class, 'reviewAlert'],    ["{$P}:fraud.review"]);
$router->get('/api/admin/audit-logs',                     [AdminController::class, 'auditLogs'],      ["{$P}:audit.view"]);
$router->get('/api/admin/security',                       [AdminController::class, 'security'],       ["{$P}:security.view"]);
