<?php

namespace App\Modules\Inventory\Support;

use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Per-tenant document numbers (RCV-000001, ADJ-000001, TRF-000001). Must run inside the caller's transaction.
 */
final class DocumentNumber
{
    public static function next(string $prefix): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('DocumentNumber::next must run inside a transaction.');
        }

        $tenantId = app(TenantContext::class)->id();
        $table = DB::table('document_sequences');

        $table->insertOrIgnore(['tenant_id' => $tenantId, 'prefix' => $prefix, 'last_value' => 0]);

        $current = (int) DB::table('document_sequences')
            ->where('tenant_id', $tenantId)->where('prefix', $prefix)
            ->lockForUpdate()->value('last_value');

        DB::table('document_sequences')
            ->where('tenant_id', $tenantId)->where('prefix', $prefix)
            ->update(['last_value' => $current + 1]);

        return sprintf('%s-%06d', $prefix, $current + 1);
    }
}
