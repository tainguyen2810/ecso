# Báo cáo Phase 7 — Lazada & Tiki (khung connector)

> Ngày: 2026-10-31 · Nhánh: `phase-7-lazada-tiki`
> **KHÔNG phải tích hợp Lazada/Tiki thật.** `open.lazada.com` và `open.tiki.vn` bị proxy chặn từ môi trường làm việc; chưa có app key/secret, chưa có shop test. Mọi capability là UNKNOWN.

## 1. Đã hoàn thành
- `LazadaConnector` và `TikiConnector` đã được đăng ký. Mọi capability là UNKNOWN, và mọi lời gọi đều trả `UnsupportedCapability` với trạng thái `unknown`.
- Cấu hình credentials qua `LAZADA_APP_KEY`, `LAZADA_APP_SECRET`, `TIKI_CLIENT_ID`, `TIKI_CLIENT_SECRET`. Nếu để trống thì không kết nối được và hệ thống báo `CREDENTIALS_MISSING`. `ecso:check-env` có liệt kê các biến này.
- Webhook của hai sàn bị từ chối (401) và được lưu lại để theo dõi.
- Tiki: chưa xác định được API có cho cập nhật tồn hay không, nên ghi UNKNOWN chứ không đoán là `unsupported`. Giao diện đã sẵn phần hướng dẫn cập nhật tồn thủ công trên Seller Center, dùng chung cho mọi sàn không cập nhật tồn được qua API.

## 2. File chính
- `app/Modules/Marketplace/Connectors/Lazada/LazadaConnector.php`
- `app/Modules/Marketplace/Connectors/Tiki/TikiConnector.php`
- `config/marketplaces.php`, `.env.example`
- `tests/Feature/Marketplace/LazadaTikiConnectorTest.php`
- `Marketplaces.vue`, `Listings.vue`, `orders/labels.ts`

## 3. Migrations
Không có.

## 4. API mới
Không có endpoint mới. `POST /marketplace-accounts/authorize/{lazada|tiki}` và `POST /webhooks/{lazada|tiki}` chạy theo luật chung.

## 5. Giao diện
`docs/screenshots/phase-7/01-lazada-tiki-connect-refused.png`: đủ 4 sàn, không sàn nào báo "Chưa có connector". Mỗi sàn có ghi chú về cập nhật tồn, và khi bấm kết nối thì bị từ chối rõ ràng.

## 6. Test
- Pest: 6 test mới (dataset cho 2 sàn), tổng 218 test pass.
- Playwright: 1 test mới.

## 7. Hạn chế
Chưa làm toàn bộ API của Lazada và Tiki. Event queue của Tiki (polling) sẽ dùng `orders.list` cùng `sync_cursors` khi được hiện thực.

## 8. Rủi ro
- Lazada có thể dùng gateway riêng theo quốc gia (chưa xác minh).
- Tiki có thể không cho seller cập nhật tồn qua API. Khi đó khách phải cập nhật tay; cần nói rõ điều này với khách hàng trước khi bán.

## 9. Credentials cần
- Lazada: `LAZADA_APP_KEY`, `LAZADA_APP_SECRET` và tài khoản seller test Việt Nam.
- Tiki: `TIKI_CLIENT_ID`, `TIKI_CLIENT_SECRET`.
- Quyền đọc tài liệu Open Platform của cả hai sàn.

## 10. Tiếp theo
Phase 8: Production Hardening.
