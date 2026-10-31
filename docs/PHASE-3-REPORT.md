# Báo cáo Phase 3 — Order Engine

> Ngày: 2026-10-31 · Nhánh: `phase-3-order-engine`

## 1. Đã hoàn thành
- Pipeline đơn hàng đầy đủ: webhook (xác thực chữ ký) → inbox → hàng đợi → gọi API lấy đơn → `OrderIngestor` → chính sách tồn kho. Tenant xác định ở server từ mapping shop, không tin dữ liệu từ webhook/frontend.
- Chống webhook trùng (dedupe key + idempotency key tồn), chống sự kiện cũ đến sau (`external_updated_at` micro giây), chống chuyển trạng thái lùi, trạng thái lạ ⇒ `UNKNOWN_STATUS` + cảnh báo.
- Tồn kho: giữ khi đơn mở, xuất khi bàn giao, nhả khi hủy; thiếu hàng được ghi nhận và tự bù theo đơn cũ trước; không bao giờ âm kho. Hủy sau khi gửi / giao thất bại ⇒ tự tạo phiếu hoàn.
- Trả hàng: luồng duyệt → nhận → kiểm hàng; chỉ phần "bán được" được nhập lại kho; hoàn tiền không cộng kho.
- Mapping SKU: chỉ gợi ý theo SKU trùng chính xác, người dùng xác nhận; không ghép theo tên.
- Đẩy tồn lên sàn qua outbox: debounce khi tăng, đẩy ngay khi giảm, backoff, circuit breaker, `unsupported` khi sàn không hỗ trợ.
- Thao tác đơn (xác nhận/đóng gói/bàn giao/hủy) gửi lên sàn qua connector; sàn chưa xác minh ⇒ `UNSUPPORTED_CAPABILITY`, không giả lập.
- Sàn giả lập FAKE để chạy thử end-to-end (chỉ bật ở local/staging).
- Giao diện: Đơn hàng, Chi tiết đơn, Trả hàng, Chi tiết phiếu trả/kiểm hàng, Kết nối sàn (ma trận capability, bảng điều khiển sàn giả lập, theo dõi webhook), Liên kết SKU, KPI đơn ở Tổng quan, in phiếu đóng gói PDF.

## 2. File chính đã tạo/sửa
- `app/Modules/Marketplace/**` (connector, FAKE, services, job đẩy tồn, controllers, policies, routes).
- `app/Modules/Orders/**` (domain trạng thái, ingestor, stock sync, actions, returns, query, job webhook, listeners, controllers).
- `app/Modules/Inventory/Services/{InventoryService,ReservationLedger,StockReader}.php` (fillShortage, restockReturn, đọc không gap-lock).
- `app/Shared/Casts/MicroTimestamp.php`, `config/marketplaces.php`, `routes/console.php`, `ModuleServiceProvider`.
- Frontend: `resources/js/apps/tenant/pages/{orders,returns,marketplaces}/*`, `Dashboard.vue`, `router.ts`, `navigation.ts`.
- Test: `tests/Feature/Orders/*`, `tests/Unit/OrderStatusTest.php`, `tests/Concurrency/OrderConcurrencyTest.php`, `e2e/orders.spec.ts`, `orders.spec.ts` (Vitest).

## 3. Migrations
- `2026_10_31_300000_create_marketplace_tables` — 10 bảng (xem DATABASE.md).
- `2026_10_31_300100_create_order_tables` — orders, order_items, order_status_history, order_notes, order_returns, order_return_lines.

## 4. API mới (`/api/v1`)
- Công khai: `POST webhooks/{marketplace}` (throttle 600/phút, xác thực chữ ký).
- Đơn: `GET orders`, `GET orders/summary`, `GET orders/assignees`, `POST orders/bulk`, `POST orders/packing-slips`, `GET orders/{id}`, `POST orders/{id}/actions/{confirm|pack|handover|cancel}`, `PUT orders/{id}/assignee`, `POST orders/{id}/notes`, `POST orders/{id}/sync`, `POST orders/{id}/returns`.
- Trả hàng: `GET returns`, `GET returns/{id}`, `POST returns/{id}/status`, `POST returns/{id}/inspect`.
- Sàn: `GET marketplaces`, `GET marketplace-accounts`, `POST marketplace-accounts/fake`, `PATCH marketplace-accounts/{id}`, `POST …/disconnect`, `POST …/sync-products`, `POST …/push-stock`, `GET marketplace-webhooks`, `GET marketplace-accounts/{id}/listings`, `POST …/listings/confirm-suggestions`, `PUT|DELETE listings/{id}/mapping`.
- Sàn giả lập: `GET|POST fake-marketplace/{account}/…` (tạo đơn, đổi trạng thái, gửi lại webhook, giả lập lỗi).

## 5. Giao diện
Ảnh tại `docs/screenshots/phase-3/`: 01 kết nối sàn · 02 liên kết SKU · 03 chọn SKU · 04 danh sách đơn · 05 lọc quá hạn · 06 thao tác hàng loạt · 07 chi tiết đơn thiếu hàng · 08 chi tiết đơn đã bàn giao · 09 trả hàng · 10 kiểm hàng hoàn · 11 tổng quan.

## 6. Test đã chạy (MySQL 8.0, Redis 7, local)
- Pest: **183 passed** (5.055 assertions), trong đó 41 test mới cho đơn/sàn.
- Concurrency: **5 passed** — mới: 1.000 đơn trên 50 SKU, 20 tiến trình, mỗi webhook gửi 2 lần ⇒ không bán vượt, không giữ trùng, sổ kho khớp (`ecso:inventory:verify` sạch).
- Vitest: **16 passed**. Playwright: **18 passed** (6 mới, chạy trên dữ liệu demo mới seed).
- PHPStan chạy trên CI (không tải được bản phân phối ở môi trường local).

## 7. Lỗi / hạn chế còn lại
- Chưa có connector sàn thật: Shopee/TikTok/Lazada/Tiki đều UNKNOWN (chờ credentials + tài liệu chính thức).
- Thay đổi số lượng trong đơn đã giữ tồn (hủy một phần) chưa hỗ trợ; dòng đơn giữ số lượng ban đầu.
- In vận đơn của sàn: chưa (cần API sàn). Chỉ có phiếu đóng gói nội bộ.
- Thẻ kho hiển thị loại chứng từ "Đơn hàng"/"Phiếu trả hàng" nhưng chưa có số đơn (tránh phụ thuộc chéo module).

## 8. Rủi ro
- Hành vi webhook/rate limit thật của từng sàn chưa biết; thiết kế inbox + đối soát 15 phút giảm rủi ro mất sự kiện.
- Đơn đã giao khi kho không đủ được đánh dấu thiếu hàng, cần kiểm kê để xử lý.

## 9. Credentials / phê duyệt cần
- App credentials + test shop của Shopee, TikTok Shop, Lazada, Tiki (chưa có).

## 10. Phase tiếp theo
Phase 4–5–7: khung connector Shopee/TikTok/Lazada/Tiki (UNKNOWN). Phase 6: gói dịch vụ, thanh toán chuyển khoản do admin xác nhận, tài chính, báo cáo.
