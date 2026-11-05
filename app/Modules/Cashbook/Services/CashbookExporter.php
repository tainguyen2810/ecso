<?php

namespace App\Modules\Cashbook\Services;

use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Cashbook\Models\CashAccount;
use App\Modules\Tenancy\Support\TenantContext;
use App\Shared\Support\ReportRange;
use App\Shared\Support\SafeRow;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The cash book (sổ quỹ) of a period as an Excel file written by the server: every row of the period, not only the
 * rows the screen lists, oldest first, with the opening balance, each row's balance and the closing balance. Members
 * without finance.balances get the same file without balances.
 */
class CashbookExporter
{
    private const METHODS = ['cash' => 'Tiền mặt', 'bank_transfer' => 'Chuyển khoản', 'card' => 'Thẻ', 'ewallet' => 'Ví điện tử', 'cod' => 'COD', 'other' => 'Khác', 'transfer' => 'Chuyển quỹ'];

    public function __construct(private readonly CashFlows $flows, private readonly AuditLogger $audit) {}

    public function stream(CarbonImmutable $from, CarbonImmutable $to, ?string $method, ?CashAccount $account): StreamedResponse
    {
        $context = app(TenantContext::class);
        $tenant = $context->tenant();
        $membership = $context->membership();
        $balances = $context->can('finance.balances');
        $this->audit->record('cash.cashbook_exported', $account, ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'account' => $account?->name, 'method' => $method]);

        return response()->streamDownload(
            fn () => TenantContext::run($tenant, fn () => $this->write($from, $to, $method, $account, $balances), $membership),
            'so-quy-'.$from->format('Ymd').'-'.$to->format('Ymd').'.xlsx',
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        );
    }

    private function write(CarbonImmutable $from, CarbonImmutable $to, ?string $method, ?CashAccount $account, bool $balances): void
    {
        $all = $this->flows->query($account?->id, $method);
        $accounts = DB::table('cash_accounts')->where('tenant_id', app(TenantContext::class)->id())->pluck('name', 'id');
        $opening = ($method ? 0.0 : (float) DB::table('cash_accounts')->where('tenant_id', app(TenantContext::class)->id())
            ->when($account, fn ($q) => $q->where('id', $account->id))->sum('opening_balance'))
            + (float) (clone $all)->where('paid_at', '<', $from)->sum('amount');
        $tz = ReportRange::timezone();

        $writer = new XlsxWriter;
        $writer->openToFile('php://output');
        $writer->addRow(SafeRow::fromValues(['Sổ quỹ '.($account !== null ? $account->name : 'tất cả quỹ').($method ? ' · '.(self::METHODS[$method] ?? $method) : '').' từ '.$from->format('d/m/Y').' đến '.$to->format('d/m/Y')]));
        $writer->addRow(SafeRow::fromValues(['Ngày', 'Chứng từ', 'Nội dung', 'Quỹ', 'Đối tượng', 'Hình thức', 'Thu', 'Chi', ...($balances ? ['Tồn quỹ'] : []), 'Tham chiếu', 'Người lập']));
        if ($balances) {
            $writer->addRow(SafeRow::fromValues(['', '', 'Tồn đầu kỳ', '', '', '', '', '', round($opening, 2), '', '']));
        }

        $balance = $opening;
        $in = 0.0;
        $out = 0.0;
        (clone $all)->whereBetween('paid_at', [$from, $to])->orderBy('paid_at')->orderBy('document')
            ->lazy(1000)->each(function ($e) use ($writer, $accounts, $tz, $balances, &$balance, &$in, &$out) {
                $amount = (float) $e->amount;
                $balance += $amount;
                $amount >= 0 ? $in += $amount : $out -= $amount;
                $writer->addRow(SafeRow::fromValues([
                    CarbonImmutable::parse($e->paid_at)->setTimezone($tz)->format('d/m/Y H:i'),
                    (string) $e->document,
                    $this->label($e),
                    (string) ($accounts[$e->account_id] ?? ''),
                    (string) $e->party,
                    self::METHODS[$e->method] ?? (string) $e->method,
                    $amount >= 0 ? round($amount, 2) : '',
                    $amount < 0 ? round(-$amount, 2) : '',
                    ...($balances ? [round($balance, 2)] : []),
                    (string) $e->reference,
                    (string) $e->created_by,
                ]));
            });

        $writer->addRow(SafeRow::fromValues(['', '', 'Cộng phát sinh', '', '', '', round($in, 2), round($out, 2), ...($balances ? [round($balance, 2)] : [])]));
        $writer->close();
    }

    private function label(object $e): string
    {
        return match ($e->type) {
            'voucher' => (string) $e->label,
            'transfer_in' => 'Nhận chuyển quỹ từ '.$e->label,
            'transfer_out' => 'Chuyển quỹ sang '.$e->label,
            default => CashTypeReport::KINDS[$e->type] ?? (string) $e->type,
        };
    }
}
