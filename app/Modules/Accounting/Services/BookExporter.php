<?php

namespace App\Modules\Accounting\Services;

use App\Shared\Support\SafeRow;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** "Xuất Excel" of a book or of the tax declaration: title lines, then the table, then the totals. */
class BookExporter
{
    /** @param  array<string, mixed>  $book */
    public function book(array $book): StreamedResponse
    {
        [$header, $rows, $total] = match ($book['book']) {
            's1' => [
                ['Ngày', 'Diễn giải', 'Chứng từ', 'Doanh thu tự khai', 'Doanh thu sàn', 'Thuế sàn đã khấu trừ (ước tính)'],
                array_map(fn ($r) => [$this->date($r['day']), $r['label'].' ('.$r['count'].' đơn)', implode(', ', $r['orders']).($r['count'] > count($r['orders']) ? '…' : ''), $r['self'], $r['market'], $r['withheld']], $book['rows']),
                ['', 'Cộng', '', $book['totals']['self'], $book['totals']['market'], $book['totals']['withheld']],
            ],
            's2' => [
                ['Mã hàng', 'Tên hàng', 'Tồn đầu kỳ', 'Nhập trong kỳ', 'Xuất trong kỳ', 'Tồn cuối kỳ', 'Đơn giá vốn', 'Giá trị tồn cuối'],
                array_map(fn ($r) => [$r['sku'], $r['name'], $r['opening'], $r['in'], $r['out'], $r['closing'], $r['unit_cost'], $r['closing_value']], $book['rows']),
                ['', 'Cộng', $book['totals']['opening'], $book['totals']['in'], $book['totals']['out'], $book['totals']['closing'], '', $book['totals']['closing_value']],
            ],
            's3' => [
                ['Ngày', 'Số phiếu', 'Diễn giải', 'Loại chi phí', 'Số tiền'],
                array_map(fn ($r) => [$this->date($r['day']), $r['number'], $r['label'], $r['type'], $r['amount']], $book['rows']),
                ['', '', 'Cộng', '', $book['totals']['amount']],
            ],
            default => [
                ['Kỳ', 'Doanh thu tự khai', 'Thuế GTGT', 'Thuế TNCN', 'Tổng thuế phải nộp', 'Hạn nộp', 'Doanh thu sàn', 'Sàn đã khấu trừ (ước tính)'],
                array_map(fn ($r) => [$r['label'], $r['self'], $r['vat'], $r['pit'], $r['tax'], $this->date($r['deadline']), $r['market'], $r['withheld']], $book['rows']),
                ['Cộng', $book['totals']['self'], $book['totals']['vat'], $book['totals']['pit'], $book['totals']['tax'], '', $book['totals']['market'], $book['totals']['withheld']],
            ],
        };
        $title = [mb_strtoupper($book['title']), 'Mẫu '.$book['code'].' · '.$book['label'], $this->who($book['taxpayer'])];

        return $this->stream('so-'.$book['book'].'-'.$book['period'].'.xlsx', $title, $header, $rows, $total);
    }

    /** @param  array<string, mixed>  $d */
    public function declaration(array $d): StreamedResponse
    {
        $rows = array_map(fn ($g) => [$g['label'], $g['self'], $g['vat'].'%', $g['vat_amount'], $g['pit'].'%', $g['pit_amount']], array_filter($d['groups'], fn ($g) => $g['self'] != 0));
        $title = ['TỜ KHAI THUẾ ĐỐI VỚI HỘ KINH DOANH, CÁ NHÂN KINH DOANH', 'Kỳ tính thuế: '.$d['label'].' · Hạn nộp '.$this->date($d['deadline']), $this->who($d['taxpayer'])];
        $note = $d['market'] > 0 ? [['Không đưa vào tờ khai: doanh thu sàn TMĐT '.number_format($d['market'], 0, ',', '.').' đ (sàn đã khấu trừ, nộp thay khoảng '.number_format($d['withheld'], 0, ',', '.').' đ).']] : [];

        return $this->stream('to-khai-'.$d['period'].'.xlsx', $title, ['Nhóm ngành nghề', 'Doanh thu', 'Tỷ lệ GTGT', 'Thuế GTGT', 'Tỷ lệ TNCN', 'Thuế TNCN'], $rows, ['Tổng cộng', $d['self'], '', $d['vat'], '', $d['pit']], $note);
    }

    /**
     * @param  array<int, string>  $title
     * @param  array<int, string>  $header
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, mixed>  $total
     * @param  array<int, array<int, mixed>>  $after
     */
    private function stream(string $name, array $title, array $header, array $rows, array $total, array $after = []): StreamedResponse
    {
        return response()->streamDownload(function () use ($title, $header, $rows, $total, $after) {
            $writer = new XlsxWriter;
            $writer->openToFile('php://output');
            foreach ($title as $t) {
                $writer->addRow(SafeRow::fromValues([$t]));
            }
            $writer->addRow(SafeRow::fromValues(['']));
            $writer->addRow(SafeRow::fromValues($header));
            foreach ($rows as $r) {
                $writer->addRow(SafeRow::fromValues($r));
            }
            $writer->addRow(SafeRow::fromValues($total));
            foreach ($after as $r) {
                $writer->addRow(SafeRow::fromValues(['']));
                $writer->addRow(SafeRow::fromValues($r));
            }
            $writer->close();
        }, $name, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param  array<string, mixed>  $t */
    private function who(array $t): string
    {
        return trim(implode(' · ', array_filter([$t['name'] ?? null, $t['store'] ?? null, ($t['tax_code'] ?? null) ? 'MST '.$t['tax_code'] : null, $t['address'] ?? null])));
    }

    private function date(string $d): string
    {
        return implode('/', array_reverse(explode('-', $d)));
    }
}
