<?php

namespace App\Modules\Orders\Services;

use App\Modules\Orders\Domain\OrderStatus;
use App\Modules\Orders\Domain\ShipmentStatus;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\Shipment;
use App\Modules\Orders\Support\Tracking;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Throwable;

/**
 * Tracking numbers from a file (shipping round S5): order number, tracking number, carrier (and weight) per row, for
 * shops that make parcels in bulk on a carrier's site. Preview first; confirming opens or corrects each order's
 * shipment through ShipmentService, row by row, so one bad row does not stop the others.
 */
class ShipmentImporter
{
    public const MAX_ROWS = 2000;

    /** Header names (ASCII, lower case), most specific first. Tracking is looked for first: "mã vận đơn" ≠ "mã đơn". */
    private const COLUMNS = [
        'tracking' => ['ma van don', 'van don', 'tracking', 'ma vd'],
        'order' => ['ma don hang', 'ma don', 'so don', 'order'],
        'carrier' => ['don vi van chuyen', 'hang van chuyen', 'hang', 'carrier'],
        'weight' => ['khoi luong', 'can nang', 'weight'],
    ];

    public const CREATE = 'create';

    public const UPDATE = 'update';

    public const SKIP = 'skip';

    public const ERROR = 'error';

    public function __construct(private readonly ShipmentService $shipments, private readonly ShipmentSync $sync) {}

    /** @return list<array{order: string, tracking: string, carrier: string|null, weight_g: int|null}> */
    public function read(string $path, string $extension): array
    {
        $reader = strtolower($extension) === 'xlsx' ? new XlsxReader : new CsvReader;
        $reader->open($path);
        $map = null;
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $cells = array_map(fn ($v) => trim((string) ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v)), $row->toArray());
                    if ($map === null) {
                        $map = $this->header($cells);

                        continue;
                    }
                    $order = trim($cells[$map['order']] ?? '');
                    $tracking = trim($cells[$map['tracking']] ?? '');
                    if ($order === '' && $tracking === '') {
                        continue;
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw ValidationException::withMessages(['file' => 'File có hơn '.self::MAX_ROWS.' dòng. Tách thành nhiều file.']);
                    }
                    $weight = $map['weight'] !== null ? (int) preg_replace('/\D/', '', $cells[$map['weight']] ?? '') : 0;
                    $rows[] = [
                        'order' => mb_substr($order, 0, 64),
                        'tracking' => mb_substr($tracking, 0, 100),
                        'carrier' => $map['carrier'] !== null && filled($cells[$map['carrier']] ?? null) ? mb_substr($cells[$map['carrier']], 0, 100) : null,
                        'weight_g' => $weight > 0 ? $weight : null,
                    ];
                }
                break; // the first sheet only
            }
        } finally {
            $reader->close();
        }
        if ($map === null) {
            throw ValidationException::withMessages(['file' => 'Không tìm thấy dòng tiêu đề có cột "Mã đơn hàng" và cột "Mã vận đơn".']);
        }

        return $rows;
    }

    /**
     * What confirming would do with each row. Writes nothing.
     *
     * @param  list<array{order: string, tracking: string, carrier?: string|null, weight_g?: int|null}>  $rows
     * @return list<array<string, mixed>>
     */
    public function preview(array $rows): array
    {
        $seen = [];
        $result = [];
        foreach ($rows as $i => $row) {
            $result[] = $this->plan($row, $i + 1, $seen);
        }

        return $result;
    }

    /**
     * @param  list<array{order: string, tracking: string, carrier?: string|null, weight_g?: int|null}>  $rows
     * @return array{rows: list<array<string, mixed>>, created: int, updated: int, skipped: int, failed: int}
     */
    public function import(array $rows): array
    {
        $seen = [];
        $done = [];
        foreach ($rows as $i => $row) {
            $plan = $this->plan($row, $i + 1, $seen);
            if (in_array($plan['action'], [self::CREATE, self::UPDATE], true)) {
                try {
                    $order = Order::query()->where('external_order_id', $plan['order'])->firstOrFail();
                    $carrier = $row['carrier'] ?? null;
                    if ($plan['action'] === self::CREATE) {
                        $this->shipments->create($order, ['mode' => Shipment::SOURCE_MANUAL, 'carrier' => $carrier ?? $order->shipping_carrier, 'tracking_number' => $plan['tracking'], 'weight_g' => $row['weight_g'] ?? null]);
                    } else {
                        $shipment = $this->sync->main($order) ?? throw ValidationException::withMessages(['order' => 'Đơn không còn vận đơn để sửa.']);
                        $this->shipments->update($shipment, array_filter(['tracking_number' => $plan['tracking'], 'carrier' => $carrier, 'weight_g' => $row['weight_g'] ?? null], fn ($v) => $v !== null));
                    }
                } catch (ValidationException $e) {
                    $plan = ['action' => self::ERROR, 'note' => collect($e->errors())->flatten()->first()] + $plan;
                } catch (Throwable $e) {
                    report($e);
                    $plan = ['action' => self::ERROR, 'note' => 'Không lưu được dòng này.'] + $plan;
                }
            }
            $done[] = $plan;
        }
        $count = fn (string $a) => count(array_filter($done, fn ($r) => $r['action'] === $a));

        return ['rows' => $done, 'created' => $count(self::CREATE), 'updated' => $count(self::UPDATE), 'skipped' => $count(self::SKIP), 'failed' => $count(self::ERROR)];
    }

    /**
     * @param  array{order: string, tracking: string, carrier?: string|null, weight_g?: int|null}  $row
     * @param  array<string, int>  $seen  tracking number => row, within the file
     * @return array<string, mixed>
     */
    private function plan(array $row, int $line, array &$seen): array
    {
        $tracking = Tracking::normalize($row['tracking']);
        $base = ['line' => $line, 'order' => trim($row['order']), 'tracking' => $tracking, 'carrier' => $row['carrier'] ?? null, 'order_id' => null];
        $error = fn (string $note) => ['action' => self::ERROR, 'note' => $note] + $base;

        if ($base['order'] === '') {
            return $error('Thiếu mã đơn hàng.');
        }
        if ($tracking === null) {
            return $error('Thiếu mã vận đơn.');
        }
        $key = mb_strtoupper(str_replace(' ', '', $tracking)); // "vtp 777" and "VTP777" are the same parcel
        if (isset($seen[$key])) {
            return $error("Mã vận đơn trùng với dòng {$seen[$key]} trong file.");
        }
        $seen[$key] = $line;

        $order = Order::query()->where('external_order_id', $base['order'])->first();
        if ($order === null) {
            return $error('Không tìm thấy đơn hàng.');
        }
        $base['order_id'] = $order->ulid;
        if (! ShipmentService::shipsItself($order)) {
            return $error('Đơn sàn: mã vận đơn do sàn cấp.');
        }
        if (in_array($order->status, [OrderStatus::CANCELLED, OrderStatus::RETURNED, OrderStatus::UNPAID], true)) {
            return $error('Đơn đã hủy, đã hoàn hoặc chưa thanh toán.');
        }
        try {
            Tracking::assertUnique($order, $tracking);
        } catch (ValidationException $e) {
            return $error((string) collect($e->errors())->flatten()->first());
        }

        $main = $this->sync->main($order);
        if ($main === null) {
            return ['action' => self::CREATE, 'note' => null] + $base;
        }
        if ($main->tracking_number === $tracking) {
            return ['action' => self::SKIP, 'note' => 'Đơn đã có vận đơn này.'] + $base;
        }
        if (! in_array($main->status, [ShipmentStatus::PENDING, ShipmentStatus::AWAITING_PICKUP], true)) {
            return $error("Đơn đã có vận đơn {$main->tracking_number} đã giao cho hãng. Sửa trong chi tiết vận đơn nếu cần.");
        }

        return ['action' => self::UPDATE, 'note' => $main->tracking_number ? "Thay mã {$main->tracking_number}" : 'Thêm mã vận đơn'] + $base;
    }

    /** @param list<string> $cells @return array{order: int, tracking: int, carrier: int|null, weight: int|null}|null */
    private function header(array $cells): ?array
    {
        $names = array_map(fn ($c) => trim(preg_replace('/\s+/', ' ', Str::lower(Str::ascii(preg_replace('/^\xEF\xBB\xBF/', '', $c) ?? ''))) ?? ''), $cells);
        $found = [];
        foreach (self::COLUMNS as $field => $aliases) {
            foreach ($aliases as $alias) {
                foreach ($names as $i => $name) {
                    if ($name !== '' && ! in_array($i, $found, true) && ($name === $alias || str_contains($name, $alias))) {
                        $found[$field] = $i;
                        break 2;
                    }
                }
            }
        }

        return isset($found['order'], $found['tracking']) ? ['order' => $found['order'], 'tracking' => $found['tracking'], 'carrier' => $found['carrier'] ?? null, 'weight' => $found['weight'] ?? null] : null;
    }
}
