# Load test

> Phase 8 (2026-10-31).

## 1. Mục tiêu (ngưỡng trong `loadtest/k6-orders.js`)

| Kịch bản | Tải | Ngưỡng |
|---|---|---|
| `browse`: nhân viên xem danh sách đơn, chi tiết đơn, tóm tắt, tồn kho | 30 người dùng đồng thời trong 4 phút | p95 < 800 ms, lỗi < 1% |
| `ingest`: sàn đẩy đơn mới qua webhook thật (sàn giả lập) | 10 đơn/giây trong 4 phút (~36.000 đơn/giờ) | p95 < 1,5 s, lỗi < 1% |

Sau khi chạy, kiểm tra thêm:
- `php artisan ecso:inventory:verify` không có sai lệch.
- Trang Giám sát hệ thống: backlog về 0 trong vòng vài phút, và không có job lỗi.

## 2. Cách chạy (staging, không chạy trên production)

1. Bật `FAKE_MARKETPLACE_ENABLED=true` trên **staging**. Chạy `php artisan db:seed --class=DemoSeeder`, hoặc tự tạo một shop giả lập và map SKU.
2. Lấy các giá trị cần truyền vào:
   - Mã ULID của tenant: header `X-Tenant`, xem trong DevTools.
   - Mã ULID của shop giả lập: trên trang Kết nối sàn.
3. Chạy lệnh:
   `k6 run -e BASE_URL=https://staging… -e EMAIL=owner@demo.test -e PASSWORD=… -e TENANT=… -e FAKE_ACCOUNT=… loadtest/k6-orders.js`
4. Worker Horizon cần chạy như production, với Supervisor và queue `default,webhooks,sync`.

## 3. Kết quả đã có

| Hạng mục | Môi trường | Kết quả |
|---|---|---|
| 1.000 đơn đồng thời trên 50 SKU, 20 tiến trình (test `concurrency`, Phase 3) | MySQL 8 thật, CI | Không bán vượt tồn, sổ kho khớp, chạy trong CI mỗi PR |
| Import 10.000 SKU (Phase 2) | Local | ≈ 9 giây |
| Độ trễ tuần tự, 40 request mỗi endpoint, dữ liệu demo (21 đơn) | Local, `php artisan serve` một tiến trình | `orders` p95 45 ms · `orders/summary` 40 ms · `inventory/stock` 36 ms · `finance/summary` 38 ms. Đây chỉ là mốc so sánh, **không phải** kết quả chịu tải. |
| Kịch bản k6 ở trên | — | **Chưa chạy.** Môi trường làm việc không cho chạy binary k6 tải từ ngoài, và chưa có staging. Cần chạy trên staging trước khi mở bán. |
