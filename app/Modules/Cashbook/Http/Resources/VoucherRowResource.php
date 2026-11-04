<?php

namespace App\Modules\Cashbook\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * A row of the voucher ledger (VoucherLedger): a hand-entered voucher or an automatic one from an order payment,
 * refund or supplier payment, or a transfer between funds (direction "transfer", or one side of it when the list is
 * filtered to a fund).
 *
 * @property object $resource
 */
class VoucherRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'source' => $r->source,
            'id' => $r->id,
            'number' => $r->number,
            'direction' => $r->direction,
            'type' => ['id' => $r->type_id, 'name' => $r->type_name],
            'counts_in_pnl' => (bool) $r->counts_in_pnl,
            'party' => ['kind' => $r->party_kind, 'id' => $r->party_id, 'name' => $r->party_name],
            'method' => $r->method,
            'account' => $r->account_id ? ['id' => $r->account_id, 'name' => $r->account_name] : null,
            'counter_account' => $r->counter_account_name,
            'amount' => (float) $r->amount,
            'paid_at' => Carbon::parse($r->paid_at)->toIso8601String(),
            'document' => $r->document_kind ? ['kind' => $r->document_kind, 'id' => $r->document_id, 'number' => $r->document_number] : null,
            'reference' => $r->reference,
            'note' => $r->note,
            'created_by' => $r->created_by,
            'created_at' => $r->created_at ? Carbon::parse($r->created_at)->toIso8601String() : null,
            'voided_at' => $r->voided_at ? Carbon::parse($r->voided_at)->toIso8601String() : null,
            'void_reason' => $r->void_reason,
        ];
    }
}
