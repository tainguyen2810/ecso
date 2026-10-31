# Báo cáo Phase 4 — Shopee (khung connector)

> Ngày: 2026-10-31 · Nhánh: `phase-4-shopee`
> **Phase này KHÔNG phải tích hợp Shopee thật.** Không truy cập được tài liệu chính thức (open.shopee.com bị proxy chặn, HTTP 403) và chưa có partner credentials/test shop. Theo quy tắc dự án, mọi thứ chưa xác minh được ghi UNKNOWN, không đoán.

## 1. Đã hoàn thành
- `ShopeeConnector` đăng ký trong hệ thống, mọi capability UNKNOWN; mọi lời gọi trả `UnsupportedCapability`; webhook Shopee bị từ chối (không có lược đồ chữ ký đã xác minh).
- Luồng ủy quyền shop dùng chung cho mọi sàn thật (để Phase 4/5/7 chỉ còn phải hiện thực phần riêng của sàn):
  state ngẫu nhiên, dùng một lần, hết hạn 10 phút, gắn với tenant + người dùng ở server; một shop chỉ thuộc một tenant; kết nối lại cùng shop thì cập nhật, không tạo trùng; token mã hóa khi lưu; giới hạn số shop theo gói.
- Lỗi rõ ràng cho người dùng: `CREDENTIALS_MISSING` (nền tảng chưa khai báo app credentials), `UNSUPPORTED_CAPABILITY` (UNKNOWN).
- `ecso:check-env` liệt kê sàn chưa có credentials (thông tin, không làm lỗi).
- Giao diện: thẻ kết nối Shopee/TikTok/Lazada/Tiki với trạng thái thật, trang callback.

## 2. File chính
`app/Modules/Marketplace/Connectors/Shopee/ShopeeConnector.php`, `Connectors/Data/ShopAuthorization.php`, `Services/ShopAuthorizationService.php`, `Exceptions/CredentialsMissing.php`, `MarketplaceConnector` (3 method ủy quyền), `UnverifiedConnector`, `FakeConnector`, `config/marketplaces.php`, `CheckEnvCommand`, `pages/marketplaces/{Marketplaces,MarketplaceCallback}.vue`, `tests/Feature/Marketplace/ShopAuthorizationTest.php`, `tests/Support/AuthorizingTestConnector.php`, `e2e/marketplaces.spec.ts`.

## 3. Migrations
Không có.

## 4. API mới
- `POST /api/v1/marketplace-accounts/authorize/{marketplace}` → `{url}` hoặc 422 `CREDENTIALS_MISSING` / `UNSUPPORTED_CAPABILITY`.
- `POST /api/v1/marketplace-accounts/callback/{marketplace}` (`state`, `params`) → tài khoản shop.
- `GET /api/v1/marketplaces` thêm `credentials_configured`.

## 5. Giao diện
`docs/screenshots/phase-4/`: 01 thẻ kết nối sàn + thông báo thiếu credentials · 02 callback với state giả bị từ chối.

## 6. Test
- Pest: 9 test mới (`ShopAuthorizationTest`): Shopee UNKNOWN, từ chối kết nối khi thiếu credentials và khi UNKNOWN, webhook Shopee bị từ chối, luồng ủy quyền chung (token mã hóa, state một lần), state không dùng được ở tenant khác, chống chiếm shop, quyền, giới hạn gói, thao tác đơn Shopee trả UNKNOWN. Luồng chung được kiểm bằng một connector kiểm thử chỉ tồn tại trong `tests/` — không phải tích hợp Shopee.
- Playwright: 2 test mới.

## 7. Hạn chế
Toàn bộ API Shopee (ký request, token, đơn, sản phẩm, tồn, push, ánh xạ trạng thái, rate limit) chưa làm.

## 8. Rủi ro
Thời gian duyệt app Shopee chưa biết; tài liệu có thể yêu cầu đăng nhập Partner Center.

## 9. Credentials cần
`SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY`, một test shop, quyền đọc tài liệu Open Platform.

## 10. Tiếp theo
Phase 5 (TikTok Shop, khung tương tự).
