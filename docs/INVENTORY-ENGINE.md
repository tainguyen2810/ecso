# Inventory Engine

> Module quan trọng nhất. Phase 0 (2026-10-31): **thiết kế chi tiết, chờ duyệt**. Hiện thực ở Phase 2 (lõi) và Phase 3–5 (đơn, đồng bộ sàn).

## 1. Định nghĩa số lượng

Tính theo từng cặp (kho, SKU nội bộ):

| Khái niệm | Công thức / nguồn | Ghi chú |
|---|---|---|
| **Physical (on_hand)** | `stock_balances.on_hand` | Hàng thực có trong kho. Chỉ đổi khi nhập, xuất, điều chỉnh, chuyển kho, hoàn nhập lại. |
| **Reserved** | `stock_balances.reserved` = Σ reservation `active` | Đã hứa cho đơn chưa xuất kho. |
| **Available** | `on_hand − reserved` | Có thể bán thêm. |
| **Safety stock** | `stock_balances.safety_stock` (theo kho/SKU) | Phần giữ lại không đăng lên sàn để chống oversell do trễ đồng bộ. |
| **Sellable** | `max(0, Σ_kho_được_phân_bổ (available − safety_stock))` | Theo tập kho mà shop được phép bán. |
| **Published (cho 1 listing)** | `allocation(sellable)` (xem §6) | Con số đẩy lên sàn. Lưu `listing_variants.last_pushed_qty`. |

Bất biến (invariant) được test tự động:
- `reserved >= 0`, `on_hand >= 0` (trừ kho `allow_negative` + movement loại `ADJUSTMENT` có phê duyệt).
- `on_hand == Σ on_hand_delta` và `reserved == Σ reserved_delta` của `stock_movements` cho cùng (tenant, kho, SKU).
- `reserved == Σ quantity` của `stock_reservations` `active`.

## 2. Loại movement

| Type | on_hand_delta | reserved_delta | Nguồn |
|---|---|---|---|
| `OPENING` | +n | 0 | Nhập tồn đầu kỳ |
| `RECEIPT` | +n | 0 | Phiếu nhập (goods_receipt) |
| `RESERVE` | 0 | +n | Đơn mới đủ điều kiện giữ tồn |
| `RELEASE` | 0 | −n | Đơn hủy trước khi xuất |
| `SHIP` | −n | −n | Xuất kho cho đơn (tiêu thụ reservation) |
| `SHIP_UNRESERVED` | −n | 0 | Xuất cho đơn không có reservation (đơn thủ công) |
| `RETURN_RESTOCK` | +n | 0 | Hàng hoàn đã kiểm, đạt điều kiện |
| `DAMAGE_OUT` | −n | 0 | Hủy hàng lỗi |
| `ADJUSTMENT_IN` / `ADJUSTMENT_OUT` | ±n | 0 | Kiểm kê, có phê duyệt |
| `TRANSFER_OUT` / `TRANSFER_IN` | −n / +n | 0 | Chuyển kho (2 movement cùng `reference`) |
| `SUPPLIER_RETURN` | −n | 0 | Trả nhà cung cấp |
| `RESERVATION_REALLOCATE` | 0 | ±n | Đổi kho giữ tồn cho đơn |

Mỗi movement bắt buộc có: `tenant_id`, `warehouse_id`, `product_variant_id`, `type`, delta, `reference_type`/`reference_id`, **`idempotency_key`**, `actor_type` (user/system/webhook/job) + `actor_id`, `created_at`, và snapshot `on_hand_after`, `reserved_after`, `unit_cost`.

## 3. API nội bộ: InventoryService (điểm ghi duy nhất)

```php
final class InventoryService
{
    public function receive(ReceiveCommand $cmd): MovementResult;        // RECEIPT, cập nhật giá vốn bình quân
    public function reserve(ReserveCommand $cmd): ReservationResult;     // RESERVE (có thể partial → shortage)
    public function release(ReleaseCommand $cmd): MovementResult;        // RELEASE
    public function ship(ShipCommand $cmd): MovementResult;              // SHIP
    public function restockReturn(RestockCommand $cmd): MovementResult;  // RETURN_RESTOCK
    public function adjust(AdjustCommand $cmd): MovementResult;          // ADJUSTMENT_* (cần permission inventory.adjust + phê duyệt)
    public function transfer(TransferCommand $cmd): TransferResult;      // TRANSFER_OUT + TRANSFER_IN
    public function recalculate(int $tenantId, int $variantId): void;    // tái tạo số dư từ ledger (kiểm tra)
}
```

Cấm ghi trực tiếp `stock_balances`/`stock_movements` ngoài service: kiểm tra bằng Pest `arch()` (chỉ `InventoryService` và repository của nó được gọi `StockBalance::update`/`StockMovement::create`).

## 4. Thuật toán ghi (dùng chung cho mọi lệnh)

```
DB::transaction(function () use ($cmd) {
    // 1. Idempotency: nếu đã có movement với (tenant_id, idempotency_key) → trả kết quả cũ, không làm gì.
    //    Insert có UNIQUE constraint là chốt chặn cuối cùng (chống race giữa 2 worker).
    // 2. Khóa các dòng stock_balances liên quan theo THỨ TỰ CỐ ĐỊNH (warehouse_id, variant_id) tăng dần
    //    SELECT ... FOR UPDATE   → tránh deadlock khi một đơn có nhiều SKU.
    //    Nếu dòng chưa tồn tại: INSERT ... ON DUPLICATE KEY UPDATE id=id rồi SELECT FOR UPDATE.
    // 3. Kiểm tra bất biến (không âm kho, reserved <= on_hand với RESERVE...).
    // 4. Cập nhật balance, version++.
    // 5. Insert stock_movement (kèm on_hand_after/reserved_after).
    // 6. Ghi/đổi stock_reservations nếu là lệnh liên quan đơn.
    // 7. DB::afterCommit → phát event AvailableStockChanged(tenant, variant_ids).
}, attempts: 3);   // retry khi deadlock (SQLSTATE 40001)
```

- Isolation: mặc định `REPEATABLE READ` của InnoDB + `FOR UPDATE` là đủ cho cập nhật số dư.
- Không dùng Redis lock thay cho row lock. Redis lock chỉ dùng để **gộp** job đẩy tồn (§7).
- Idempotency key được tạo **xác định (deterministic)** từ nguồn:
  - Reserve đơn: `reserve:{order_item_id}:{warehouse_id}`
  - Release: `release:{reservation_id}`
  - Ship: `ship:{order_item_id}:{shipment_id}`
  - Restock hoàn: `restock:{return_line_id}`
  - Thao tác người dùng: header `Idempotency-Key` từ client.

## 5. Giữ tồn cho đơn (reservation)

### 5.1 Chính sách theo trạng thái
Mỗi trạng thái chuẩn hóa có `reserve_policy` (xem [ORDER-LIFECYCLE.md](ORDER-LIFECYCLE.md)):
- `RESERVE`: PENDING_CONFIRMATION, READY_TO_PACK, PACKED (đơn còn hiệu lực, chưa xuất).
- `CONSUME`: HANDED_OVER/IN_TRANSIT (đã xuất kho) ⇒ chuyển reservation sang `SHIP`.
- `RELEASE`: CANCELLED trước khi xuất.
- `NONE`: UNPAID (tùy cấu hình tenant: đơn COD hoặc chưa thanh toán có giữ tồn hay không; mặc định **có giữ** để không bán vượt).

### 5.2 Chọn kho
1. Kho mặc định của shop (`marketplace_accounts.default_warehouse_id`).
2. Nếu thiếu, theo danh sách ưu tiên kho của tenant (không tách đơn ở MVP; tách đơn nhiều kho là sau MVP).

### 5.3 Đơn đến khi không đủ hàng (đơn đã tồn tại trên sàn)
Sàn đã nhận đơn nên hệ thống **không thể từ chối**. Xử lý:
- Giữ được bao nhiêu thì giữ bấy nhiêu (không âm kho), phần thiếu ghi `order_items.shortage_qty`.
- Đơn gắn cờ `STOCK_SHORTAGE`, tạo cảnh báo cho người bán, published của SKU về 0 ngay.
- Khi nhập hàng, job tự động bù reservation cho đơn thiếu theo thứ tự `placed_at`.

### 5.4 Hai đơn đồng thời
Hai worker cùng `reserve` một SKU: worker A khóa dòng balance, worker B chờ; B đọc lại sau khi A commit nên thấy `available` mới ⇒ không bao giờ giữ vượt tồn. Có test song song (§9).

## 6. Phân bổ tồn cho sàn (marketplace allocation)

Với mỗi listing_variant đã mapping:

```
sellable = max(0, Σ_{kho ∈ allocation.warehouse_ids} (on_hand − reserved − safety_stock))
published = match (allocation.mode) {
    'all'     => sellable,
    'percent' => floor(sellable * value / 100),
    'fixed'   => min(value, sellable),
}
```

- Mặc định: `all` cho mọi shop (mỗi shop thấy toàn bộ sellable). Rủi ro bán trùng giữa sàn được chặn bởi reservation + đẩy tồn nhanh + safety stock.
- Nếu một SKU nội bộ map nhiều listing trong **cùng một shop**, published áp dụng cho từng listing (cảnh báo người dùng về rủi ro).
- Combo/bundle (sau MVP): `published = min(published(thành_phần)/qty)`.

## 7. Đồng bộ tồn lên sàn (Outbox)

```
AvailableStockChanged(variant_ids)
   → ComputePublishedStock: với mỗi listing_variant mapped của variant
       desired = published(...)
       UPSERT inventory_sync_outbox (listing_variant_id) SET desired_qty=desired, status='pending', next_attempt_at=now()+debounce
   → PushInventoryJob (queue: sync-{marketplace}), gộp theo marketplace_account:
       - Redis lock theo account (chỉ 1 job đẩy/shop cùng lúc)
       - lấy các dòng pending đến hạn, nhóm thành batch theo giới hạn API
       - bỏ qua nếu desired == last_pushed_qty và last push thành công < 24h
       - gọi connector.updateInventory()
       - thành công: last_pushed_qty, push_status=ok, outbox=succeeded
       - lỗi tạm thời / rate limit: attempts++, next_attempt_at = backoff(attempts) + jitter
       - lỗi vĩnh viễn (SKU không tồn tại, bị khóa): outbox=rejected, cảnh báo người bán
       - capability không hỗ trợ (vd Tiki nếu không có API): outbox=unsupported, hiển thị "cần cập nhật tay"
```

- **Coalescing**: nhiều thay đổi liên tiếp chỉ để lại một dòng pending với giá trị mới nhất ⇒ ít gọi API, luôn đẩy số mới nhất.
- **Debounce** mặc định 3 giây; đơn mới làm giảm tồn thì đẩy ngay (debounce 0).
- **Circuit breaker** theo account: mở khi lỗi liên tiếp ≥ N, các job tạm dừng, cảnh báo admin, tự thử lại half-open.
- **Token hết hạn**: refresh một lần (Redis lock chống refresh song song); nếu refresh thất bại ⇒ account `expired`, dừng đẩy, thông báo kết nối lại.
- Không cam kết tức thời: UI hiển thị trạng thái đồng bộ từng listing (`Đã đồng bộ lúc ...`, `Đang chờ`, `Lỗi: ...`).

## 8. Đối soát (reconciliation)

| Job | Tần suất | Việc làm |
|---|---|---|
| `LedgerIntegrityCheck` | Hằng đêm | So `stock_balances` với tổng `stock_movements` và tổng reservation active; lệch ⇒ cảnh báo, **không tự sửa** |
| `MarketplaceStockReconcile` | Mỗi 1–6 giờ (theo gói) | `getInventory()` của sàn so với `last_pushed_qty`/desired; lệch ⇒ tạo outbox đẩy lại; ghi log |
| `StaleReservationCheck` | Mỗi giờ | Reservation của đơn đã ở trạng thái cuối mà chưa release/consume ⇒ cảnh báo + đề xuất xử lý |
| `OrderReconcile` | Mỗi 15–30 phút | Polling đơn cập nhật gần đây để bù webhook bị mất (xem ORDER-LIFECYCLE) |

## 9. Giá vốn

- Bình quân gia quyền theo SKU trên toàn tenant (MVP): khi `RECEIPT` n đơn vị giá c:
  `new_cost = (on_hand_total × old_cost + n × c) / (on_hand_total + n)`.
- `stock_movements.unit_cost` lưu snapshot; `order_items.cost_snapshot` chụp khi `SHIP` ⇒ báo cáo lịch sử không bị đổi khi giá vốn đổi.
- Điều chỉnh giá vốn tạo bản ghi `cost_adjustments`, không sửa movement cũ.

## 10. Kiểm thử bắt buộc cho module (Phase 2–3)

- Reserve song song: 20 tiến trình cùng giữ 1 SKU tồn 10 ⇒ tổng reserved = 10, 10 kết quả shortage. (Chạy thật trên MySQL, nhiều kết nối, không phải SQLite.)
- Lặp idempotency key ⇒ chỉ một movement.
- Hủy đơn sau khi reserve ⇒ release đúng số; hủy hai lần ⇒ không release lần hai.
- Hoàn tiền không kèm nhận hàng ⇒ không cộng kho.
- Chuyển kho ⇒ tổng on_hand toàn tenant không đổi.
- Property-based test: chuỗi lệnh ngẫu nhiên ⇒ bất biến §1 luôn đúng.
- Deadlock: đơn A (SKU1, SKU2) và đơn B (SKU2, SKU1) đồng thời ⇒ không treo, cả hai hoàn tất.


## 11. Triển khai thực tế (Phase 2, 2026-10-31)

Phần này ghi lại chỗ code khác hoặc cụ thể hơn thiết kế ở trên. Code: `app/Modules/Inventory/Services/InventoryService.php`.

**API thực tế** (thay cho các Command object ở §3, cho gọn):

| Hàm | Movement | Ghi chú |
|---|---|---|
| `receive(warehouse, lines, key, ref, type)` | RECEIPT / OPENING / RETURN_RESTOCK | RECEIPT/OPENING cập nhật giá vốn bình quân; RETURN_RESTOCK không đổi giá vốn |
| `issue(warehouse, lines, key, ref, type)` | TRANSFER_OUT / DAMAGE_OUT / SUPPLIER_RETURN / SHIP_UNRESERVED | Không lấy phần đã giữ cho người khác (cần `available ≥ qty`) trừ kho `allow_negative` |
| `transferIn(warehouse, lines, key, ref)` | TRANSFER_IN | |
| `adjust(warehouse, lines{mode: delta/count}, key, ref, reason)` | ADJUSTMENT_IN / ADJUSTMENT_OUT | Số đếm (count) tính chênh lệch **dưới khóa** lúc duyệt; không cho `on_hand < 0`; cho phép `on_hand < reserved` (thực tế kiểm kê) ⇒ khả dụng âm, hiển thị "Thiếu hàng" |
| `reserve(warehouse, variant, qty, refType, refId, key, allowShortage)` | RESERVE | Giữ tối đa `available`, phần thiếu ghi `shortage`. Idempotent theo (reference, kho) **và** theo key |
| `release(reservation, key)` / `ship(reservation, key)` | RELEASE / SHIP | Chỉ tác động reservation `active`; gọi lặp là no-op |
| `setSafetyStock(warehouse, variant, n)` | — | Không tạo movement (không đổi on_hand/reserved); phát `AvailableStockChanged` |

**Thứ tự khóa (bắt buộc, chống deadlock):** `phiếu (adjustment/transfer) → product_variants (theo id tăng) → stock_balances (theo variant tăng, cùng kho)`.
- Mọi thao tác khóa dòng `product_variants` trước: **shared lock** (`LOCK IN SHARE MODE`), riêng phiếu nhập dùng **FOR UPDATE** để cập nhật giá vốn. Lý do: insert `stock_movements`/`stock_reservations` lấy shared lock khóa ngoại trên `product_variants`; nếu không khóa trước sẽ có vòng chờ với phiếu nhập (test concurrency đã bắt được lỗi này).
- Dòng `stock_balances` chưa có được tạo **trước** transaction và **chỉ insert dòng còn thiếu**. `INSERT IGNORE` trên dòng đã tồn tại lấy shared lock và gây deadlock với transaction đang giữ FOR UPDATE (test concurrency đã bắt được).
- Transaction retry 3 lần khi deadlock/lock wait timeout (`DB::transaction(..., 3)`).

**Idempotency key:** mỗi movement có key `"{key thao tác}#{n}"`; thao tác coi là đã chạy khi tồn tại `#0`. Phiếu điều chỉnh có thể không sinh movement nào (đếm đúng bằng tồn) nên ghi thêm 1 movement `MARKER` (delta 0, key `#done`), ẩn khỏi thẻ kho. Key do hệ thống sinh: `receipt:{id}`, `adjustment:{id}`, `transfer:{id}:out|in|return`. Header `Idempotency-Key` từ client **chưa làm** (phiếu đã có khóa trạng thái + key theo id phiếu).

**Kiểm tra bất biến:** `StockVerifier` + lệnh `php artisan ecso:inventory:verify [--tenant=]` (chỉ đọc, chạy hằng ngày 02:30, lỗi ghi log `critical`). Không có chức năng "sửa" sổ: sai lệch xử lý bằng phiếu điều chỉnh.

**Phân bổ cho sàn:** `Domain/AllocationCalculator` (all/percent/fixed, `sellable = max(0, Σ(on_hand − reserved − safety))`) đã có và test; bảng `marketplace_allocations` làm ở Phase 4 cùng `marketplace_accounts`.

**Safety stock** không chặn reservation (đơn đã tồn tại trên sàn), chỉ giảm số "có thể bán"/đẩy lên sàn.
