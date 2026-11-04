<?php

namespace App\Modules\Cashbook\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The funds every business starts with, and which payment method lands in which fund by default:
 * cash in "Tiền mặt", bank transfers and card payments in the bank account (one per account saved in Cấu hình thanh
 * toán, the default one taking the methods), e-wallets, COD still held by the carrier, and "other".
 *
 * Works on raw tables with an explicit tenant id so the migration can run it for every business as well.
 */
final class DefaultCashAccounts
{
    /** Creates the default funds when the business has none yet. Returns the account id per payment method. */
    public static function ensure(int $tenantId): array
    {
        if (! DB::table('cash_accounts')->where('tenant_id', $tenantId)->exists()) {
            foreach (self::definitions($tenantId) as $i => $account) {
                DB::table('cash_accounts')->insert([
                    ...$account,
                    'ulid' => (string) Str::ulid(),
                    'tenant_id' => $tenantId,
                    'methods' => json_encode($account['methods']),
                    'sort' => $i,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return self::methodMap($tenantId);
    }

    /** @return array<string, int> payment method => account id, for the active accounts. */
    public static function methodMap(int $tenantId): array
    {
        $map = [];
        $accounts = DB::table('cash_accounts')->where('tenant_id', $tenantId)->where('is_active', true)->orderBy('sort')->orderBy('id')->get(['id', 'methods']);
        foreach ($accounts as $a) {
            foreach (json_decode((string) $a->methods, true) ?: [] as $method) {
                $map[$method] ??= (int) $a->id;
            }
        }

        return $map;
    }

    /** @return array<int, array{kind: string, name: string, bank_name: string|null, account_number: string|null, holder: string|null, methods: array<int, string>}> */
    private static function definitions(int $tenantId): array
    {
        $settings = json_decode((string) DB::table('tenants')->where('id', $tenantId)->value('settings'), true) ?: [];
        $banks = array_values(array_filter((array) data_get($settings, 'payments.bank_accounts', []), fn ($b) => is_array($b) && ! empty($b['number'])));
        $defaultBank = 0;
        foreach ($banks as $i => $b) {
            if (! empty($b['is_default'])) {
                $defaultBank = $i;
                break;
            }
        }

        $accounts = [['kind' => 'cash', 'name' => 'Tiền mặt', 'bank_name' => null, 'account_number' => null, 'holder' => null, 'methods' => ['cash']]];
        foreach ($banks as $i => $b) {
            $number = (string) $b['number'];
            $accounts[] = [
                'kind' => 'bank',
                'name' => trim(($b['bank'] ?? 'Ngân hàng').' '.substr($number, -4)),
                'bank_name' => $b['bank'] ?? null,
                'account_number' => $number,
                'holder' => $b['holder'] ?? null,
                'methods' => $i === $defaultBank ? ['bank_transfer', 'card'] : [],
            ];
        }
        if ($banks === []) {
            $accounts[] = ['kind' => 'bank', 'name' => 'Tài khoản ngân hàng', 'bank_name' => null, 'account_number' => null, 'holder' => null, 'methods' => ['bank_transfer', 'card']];
        }
        $accounts[] = ['kind' => 'ewallet', 'name' => 'Ví điện tử', 'bank_name' => null, 'account_number' => null, 'holder' => null, 'methods' => ['ewallet']];
        $accounts[] = ['kind' => 'cod', 'name' => 'Tiền COD (hãng vận chuyển giữ)', 'bank_name' => null, 'account_number' => null, 'holder' => null, 'methods' => ['cod']];
        $accounts[] = ['kind' => 'other', 'name' => 'Quỹ khác', 'bank_name' => null, 'account_number' => null, 'holder' => null, 'methods' => ['other']];

        return $accounts;
    }
}
