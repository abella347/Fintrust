<?php

declare(strict_types=1);

use App\Helpers\Money;
use App\Helpers\Str;

return [
    'whole naira converts to kobo' => function () {
        assert_same(150000, Money::toKobo('1500'));
    },
    'commas, spaces and ₦ symbol are accepted' => function () {
        assert_same(150050, Money::toKobo('₦ 1,500.50'));
    },
    'one decimal place means tens of kobo ("1500.5" = 150050)' => function () {
        assert_same(150050, Money::toKobo('1500.5'));
    },
    'smallest amount is one kobo' => function () {
        assert_same(1, Money::toKobo('0.01'));
    },
    'no float rounding: 0.29 is exactly 29 kobo' => function () {
        // (int) (0.29 * 100) === 28 in PHP because of floating-point error!
        assert_same(28, (int) (0.29 * 100), 'demonstrates the float bug');
        assert_same(29, Money::toKobo('0.29'));
    },
    'rejects zero' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('0'));
    },
    'rejects negative amounts' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('-500'));
    },
    'rejects three decimal places' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('10.005'));
    },
    'rejects scientific notation and hex' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('1e5'));
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('0x10'));
    },
    'rejects text and injection attempts' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo("100; DROP TABLE wallets"));
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('abc'));
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo(''));
    },
    'rejects absurdly large amounts' => function () {
        assert_throws(InvalidArgumentException::class, fn () => Money::toKobo('99999999999'));
    },
    'formats kobo as naira' => function () {
        assert_same('₦1,500.50', Money::format(150050));
        assert_same('₦0.05', Money::format(5));
        assert_same('-₦400,000.00', Money::format(-40000000));
        assert_same('1500.50', Money::toDecimalString(150050));
    },
    'round trip: toKobo(format(x)) === x' => function () {
        foreach ([1, 99, 100, 150050, 23499950, 99999999] as $kobo) {
            assert_same($kobo, Money::toKobo(Money::format($kobo)));
        }
    },
    'wallet numbers are 10 digits and never start with 0 or 9' => function () {
        for ($i = 0; $i < 500; $i++) {
            $n = Str::walletNumber();
            assert_true(preg_match('/^[1-8]\d{9}$/', $n) === 1, "bad wallet number {$n}");
        }
    },
    'transaction references are unique and well-formed' => function () {
        $refs = [];
        for ($i = 0; $i < 1000; $i++) {
            $refs[] = Str::transactionReference();
        }
        assert_same(1000, count(array_unique($refs)));
        assert_true(preg_match('/^FT\d{8}[0-9A-F]{12}$/', $refs[0]) === 1, 'format');
    },
    'uuid4 has the right shape' => function () {
        assert_true(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', Str::uuid4()) === 1);
    },
    'device names are short and readable (shown in login alerts)' => function () {
        assert_same('Safari on iPhone', App\Helpers\Str::deviceName('Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1'));
        assert_same('Edge on Windows', App\Helpers\Str::deviceName('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36 Edg/129.0'));
        assert_same('Chrome on Android', App\Helpers\Str::deviceName('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/129.0 Mobile Safari/537.36'));
        assert_same('Unknown device', App\Helpers\Str::deviceName(''));
    },
];
