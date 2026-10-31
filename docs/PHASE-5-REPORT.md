# Báo cáo Phase 5 — TikTok Shop (khung connector)

> Ngày: 2026-10-31 · Nhánh: `phase-5-tiktok`
> **KHÔNG phải tích hợp TikTok Shop thật.** Không truy cập được Partner Center (proxy chặn), chưa có app key/secret và test shop. Mọi capability UNKNOWN.

## 1. Đã hoàn thành
- `TikTokConnector` đăng ký, UNKNOWN toàn bộ, mọi lời gọi trả `UnsupportedCapability`.
- Cấu hình credentials `TIKTOK_SHOP_APP_KEY`, `TIKTOK_SHOP_APP_SECRET` (rỗng ⇒ không kết nối được, báo `CREDENTIALS_MISSING`).
- Dùng chung luồng ủy quyền shop đã làm ở Phase 4; webhook TikTok bị từ chối và lưu để theo dõi.
- Thẻ TikTok Shop trên trang Kết nối sàn hiển thị đúng trạng thái.

## 2. File chính
`app/Modules/Marketplace/Connectors/TikTok/TikTokConnector.php`, `config/marketplaces.php`, `.env.example`, `tests/Feature/Marketplace/TikTokConnectorTest.php`, `e2e/marketplaces.spec.ts`.

## 3. Migrations
Không có.

## 4. API mới
Không có endpoint mới; `POST /marketplace-accounts/authorize/tiktok` và `POST /webhooks/tiktok` hoạt động theo luật chung.

## 5. Giao diện
`docs/screenshots/phase-5/01-tiktok-connect-refused.png`.

## 6. Test
Pest: 3 test mới (UNKNOWN, từ chối kết nối khi thiếu credentials và khi UNKNOWN, từ chối webhook). Playwright: 1 test mới.

## 7. Hạn chế
Toàn bộ API TikTok Shop chưa làm.

## 8. Rủi ro
TikTok đổi version API theo ngày; cần cô lập version trong client khi hiện thực.

## 9. Credentials cần
`TIKTOK_SHOP_APP_KEY`, `TIKTOK_SHOP_APP_SECRET`, test shop Việt Nam, quyền đọc tài liệu Partner Center.

## 10. Tiếp theo
Phase 6 (gói dịch vụ, thanh toán chuyển khoản, tài chính, báo cáo).
