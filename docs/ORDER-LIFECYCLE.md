# Order Lifecycle

> Phase 0 (2026-10-31): **thiết kế, chờ duyệt**. Hiện thực lõi ở Phase 3 (với Fake connector), map trạng thái thật ở Phase 4/5/7 sau khi đọc được tài liệu chính thức.

## 1. Trạng thái chuẩn hóa nội bộ

| Trạng thái nội bộ | Tab UI | Reserve policy | Ý nghĩa |
|---|---|---|---|
| `UNPAID` | Đơn mới | RESERVE (cấu hình được) | Chờ thanh toán online |
| `PENDING_CONFIRMATION` | Chờ xác nhận | RESERVE | Cần người bán xác nhận (nếu sàn có bước này) |
| `READY_TO_PACK` | Chờ đóng gói | RESERVE | Đã xác nhận, cần đóng gói |
| `PACKED` | Chờ bàn giao | RESERVE | Đã đóng gói / đã có vận đơn, chờ ĐVVC lấy |
| `HANDED_OVER` | Đang vận chuyển | CONSUME (xuất kho) | Đã bàn giao ĐVVC |
| `IN_TRANSIT` | Đang vận chuyển | — | |
| `DELIVERED` | Hoàn thành | — | Giao thành công (chưa chắc hết hạn khiếu nại) |
| `COMPLETED` | Hoàn thành | — | Sàn chốt hoàn thành |
| `CANCELLED` | Đã hủy | RELEASE nếu chưa xuất; nếu đã xuất ⇒ tạo quy trình hoàn (FAILED_DELIVERY) | |
| `FAILED_DELIVERY` | Trả hàng/hoàn tiền | — (chờ nhận hàng về) | Giao thất bại, hàng đang quay về |
| `RETURN_REQUESTED` … `RETURNED` | Trả hàng/hoàn tiền | — | Theo bảng `returns` |

- Lưu song song `orders.raw_status` (nguyên văn của sàn) và `orders.status` (nội bộ).
- Bảng `marketplace_status_maps` (seed theo từng sàn) map `raw_status → status`. **Hiện chưa map được trạng thái thật** vì chưa đọc được tài liệu chính thức (xem API-CAPABILITY-MATRIX); raw status lạ sẽ map tạm sang `UNKNOWN_STATUS`, giữ nguyên reservation và cảnh báo admin.
- Không giả định các sàn có cùng quy trình: một số bước (xác nhận, đóng gói, in vận đơn) có thể không tồn tại ở sàn nào đó ⇒ nút hành động trên UI dựa vào `capabilities()` của connector.

## 2. Đồ thị chuyển trạng thái (cho phép)

```
UNPAID → PENDING_CONFIRMATION | READY_TO_PACK | CANCELLED
PENDING_CONFIRMATION → READY_TO_PACK | CANCELLED
READY_TO_PACK → PACKED | CANCELLED
PACKED → HANDED_OVER | CANCELLED
HANDED_OVER → IN_TRANSIT | DELIVERED | FAILED_DELIVERY | CANCELLED*
IN_TRANSIT → DELIVERED | FAILED_DELIVERY
DELIVERED → COMPLETED | RETURN_REQUESTED
COMPLETED → RETURN_REQUESTED (trong thời hạn khiếu nại)
```
`CANCELLED*` sau khi đã xuất kho ⇒ không release, mà mở luồng hoàn hàng chờ nhận lại.

Chuyển ngược thứ tự (do sự kiện đến sai thứ tự) bị **bỏ qua có ghi log**, trừ khi `external_updated_at` mới hơn và chuyển đổi hợp lệ.

## 3. Luồng nhập đơn (webhook hoặc polling)

```
[1] Nhận
    Webhook: POST /webhooks/{marketplace}
      - Đọc raw body, verifyWebhook() (chữ ký) → sai: 401, ghi webhook_events(signature_valid=false)
      - Replay protection: timestamp lệch > 5 phút ⇒ từ chối (nếu sàn có timestamp); trùng external_event_id/payload_hash ⇒ trả 200, không xử lý lại
      - INSERT webhook_events (status=received) → trả 200 NGAY (sàn thường yêu cầu phản hồi nhanh)
      - Dispatch ProcessWebhookEvent (queue: webhooks)
    Polling: SyncOrdersJob theo sync_cursors (update_time từ - đến, có chồng lấn 5 phút)
    Tiki: PollEventQueueJob (event queue dạng pull)

[2] Xác định tenant: (marketplace, external_shop_id trong payload) → marketplace_accounts → tenant_id.
    Không tìm thấy hoặc account không active ⇒ event = ignored.
    Từ đây job chạy trong TenantContext::run($tenant, fn() => ...).

[3] Chuẩn hóa: webhook thường chỉ có order_id + trạng thái ⇒ gọi getOrderDetails() lấy dữ liệu đầy đủ (nguồn sự thật là API, không phải payload webhook).

[4] Upsert đơn theo U(marketplace_account_id, external_order_id) trong transaction:
    SELECT ... FOR UPDATE orders WHERE ... (khóa đơn để hai sự kiện cùng đơn không chạy chồng)
    - Nếu external_updated_at mới <= hiện tại ⇒ chỉ cập nhật trường không trạng thái, bỏ qua chuyển trạng thái.
    - Upsert order_items theo U(order_id, external_item_id); map listing_variant → product_variant.
    - Ghi order_status_history nếu trạng thái đổi.

[5] Xác định reserve policy theo status mới.

[6] Áp dụng tồn kho (InventoryService, idempotent):
    RESERVE  → reserve(order_item, kho) key reserve:{item}:{wh}
    CONSUME  → ship(...) key ship:{item}:{shipment}
    RELEASE  → release(reservation) key release:{reservation}
    Item chưa mapping SKU ⇒ không đụng tồn, order_item.mapping_status=unmapped, cảnh báo "đơn có sản phẩm chưa liên kết".

[7] afterCommit: AvailableStockChanged ⇒ [8] outbox ⇒ [9] PushInventoryJob ⇒ [10] ghi kết quả (INVENTORY-ENGINE §7).
```

Toàn bộ job: `ShouldBeUnique` theo `order:{account}:{external_order_id}` + `WithoutOverlapping`, retry backoff `[10, 30, 120, 600, 1800]` giây + jitter, sau cùng vào failed_jobs và hiển thị ở admin.

## 4. Hủy đơn

| Thời điểm hủy | Xử lý tồn |
|---|---|
| Trước khi xuất kho (UNPAID → PACKED) | `release` toàn bộ reservation active |
| Sau khi xuất kho (đã `SHIP`) | Không cộng kho. Tạo `returns` loại `CANCEL_AFTER_SHIP` trạng thái `in_transit`; chỉ cộng kho khi nhận hàng về và kiểm đạt |
| Hủy một phần (nếu sàn hỗ trợ) | Release theo từng item/quantity |

## 5. Trả hàng / hoàn tiền

```
RETURN_REQUESTED → APPROVED → IN_TRANSIT_BACK → RECEIVED → INSPECTED → CLOSED
                          └→ REFUND_ONLY (hoàn tiền không trả hàng) → CLOSED
```
- **Hoàn tiền (refund) không bao giờ tự cộng kho.** Refund chỉ ảnh hưởng tài chính.
- Cộng kho chỉ khi nhân viên kho xác nhận `RECEIVED` + `INSPECTED` với `condition = sellable` ⇒ `RETURN_RESTOCK` vào kho chọn. Hàng `damaged` ⇒ ghi nhận, không cộng (hoặc vào kho hàng lỗi riêng nếu tenant bật).
- Quyền: `returns.inspect` (WAREHOUSE_STAFF trở lên).

## 6. Sự kiện trùng và sai thứ tự

| Tình huống | Cơ chế chặn |
|---|---|
| Webhook gửi lặp | U(marketplace, external_event_id / payload_hash) ở inbox + idempotency key ở movement |
| Webhook và polling cùng mang một thay đổi | Upsert theo khóa đơn + so `external_updated_at` + idempotency key tồn kho |
| Sự kiện "đã giao" đến trước "đã bàn giao" | Lấy chi tiết từ API (trạng thái hiện tại thật); chuyển trạng thái nhảy cóc hợp lệ thì áp dụng các bước tồn kho bị bỏ qua (ví dụ CONSUME) |
| Sự kiện cũ đến sau sự kiện mới | `external_updated_at` cũ hơn ⇒ bỏ qua chuyển trạng thái |
| Webhook bị mất | `OrderReconcile` polling định kỳ |

## 7. Màn hình đơn hàng (Phase 3, giao diện sẽ gửi ảnh khi xong)

- Tabs theo §1 với bộ đếm; lọc theo sàn, shop, thời gian, trạng thái, SKU, nhân viên phụ trách, cờ (thiếu hàng, chưa mapping, quá hạn).
- Bulk: xác nhận, in phiếu đóng gói, in vận đơn (khi connector hỗ trợ), phân công.
- Chi tiết đơn: items + mapping, lịch sử trạng thái (raw + nội bộ), ghi chú nội bộ, reservation/kho, phí (ước tính/đối soát).
- Cảnh báo quá hạn: `ship_by_at` < now + ngưỡng.
