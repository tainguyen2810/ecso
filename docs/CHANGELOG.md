# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [Unreleased]

### Phase 7 — Lazada & Tiki (khung) (2026-10-31)
- `LazadaConnector`, `TikiConnector` (UNKNOWN toàn bộ), cấu hình `LAZADA_APP_KEY/SECRET`, `TIKI_CLIENT_ID/SECRET`; dùng chung luồng ủy quyền shop.
- Hướng dẫn cập nhật tồn thủ công khi sàn chưa/không cập nhật tồn được qua API (thẻ sàn và trang Liên kết SKU); trạng thái đẩy tồn `unsupported` đổi nhãn thành "Cập nhật tay trên sàn".
- **Không** có tích hợp Lazada/Tiki thật: tài liệu bị chặn từ môi trường làm việc, chưa có credentials.

### Phase 6 — SaaS Commercial (2026-10-31)
- Thanh toán **chuyển khoản ngân hàng, admin xác nhận** (quyết định của chủ dự án). Thiết kế cắm thêm cổng (`PaymentGateway`, `GatewayRegistry`, IPN `POST /billing/notifications/{gateway}`); VNPay/PayOS/MoMo chưa làm (UNKNOWN, chưa có hợp đồng/credentials).
- Hóa đơn (`invoices`) với mã nội dung chuyển khoản riêng; thanh toán (`payments`) idempotent theo (provider, mã giao dịch): cùng một giao dịch ghi nhận lần hai không cộng thêm kỳ. Chuyển thiếu được cộng dồn; tiền vào hóa đơn đã đóng ghi `unapplied` để đối soát/hoàn.
- Nâng gói: tạo hóa đơn, trừ giá trị thời gian chưa dùng của kỳ hiện tại; gói đổi khi nhận đủ tiền. Hạ gói: chặn nếu đang dùng vượt giới hạn gói mới, hẹn đổi khi hết kỳ đã trả. Dùng thử 1 lần/tenant.
- `ecso:billing:sweep` (mỗi giờ): hết dùng thử → Free, phát hành hóa đơn gia hạn trước 7 ngày, áp dụng hạ gói khi hết kỳ, quá hạn → `past_due` → Free sau 3 ngày ân hạn. Không xóa dữ liệu, chỉ đổi entitlements.
- Đếm đơn/tháng (`usage_counters`, sự kiện `OrderImported`): giới hạn mềm, không chặn nhập đơn (chặn sẽ làm sai tồn kho), cảnh báo trên trang Gói dịch vụ.
- Tài chính: doanh thu đặt hàng, doanh thu hoàn thành, giá vốn (từ sổ kho), hoàn tiền, lợi nhuận gộp ước tính. Phí sàn và tiền sàn chuyển về = **Chưa xác định** (cần API đối soát).
- Báo cáo: bán chạy; hàng bán chậm theo entitlement `reports.advanced` (không theo tên gói).
- Admin: trang Hóa đơn & doanh thu (MRR/ARR, đã thu 30 ngày, hóa đơn chờ, xác nhận chuyển khoản, hủy hóa đơn); MRR trên Tổng quan.
- Mục Mua hàng chuyển sang "Sau MVP".

### Phase 5 — TikTok Shop (khung) (2026-10-31)
- `TikTokConnector` (UNKNOWN toàn bộ), cấu hình `TIKTOK_SHOP_APP_KEY` / `TIKTOK_SHOP_APP_SECRET`; dùng luồng ủy quyền chung của Phase 4.
- **Không** có tích hợp TikTok Shop thật: không truy cập được tài liệu Partner Center và chưa có credentials.

### Phase 4 — Shopee (khung) (2026-10-31)
- `ShopeeConnector` (UNKNOWN toàn bộ), cấu hình `SHOPEE_PARTNER_ID` / `SHOPEE_PARTNER_KEY`, `ecso:check-env` báo sàn chưa có credentials.
- Luồng ủy quyền shop dùng chung cho mọi sàn: state một lần gắn tenant + người dùng, chống chiếm shop của tenant khác, token mã hóa, giới hạn shop theo gói; lỗi `CREDENTIALS_MISSING` / `UNSUPPORTED_CAPABILITY` rõ ràng.
- Capability mới `shop.authorize`, `auth.refresh`.
- Giao diện: thẻ kết nối từng sàn trên trang Kết nối sàn, trang callback.
- **Không** có tích hợp Shopee thật: không truy cập được tài liệu chính thức và chưa có credentials.

### Phase 3 — Order Engine (2026-10-31)
- Module Marketplace: tài khoản shop (credential mã hóa), listing + listing variant, bảng ánh xạ trạng thái, inbox webhook, outbox đẩy tồn (debounce, backoff, circuit breaker), sync cursor. Connector interface + `UnverifiedConnector` (mọi thứ UNKNOWN) + sàn giả lập FAKE đầy đủ (webhook ký HMAC).
- Module Orders: đơn, dòng đơn, lịch sử trạng thái, ghi chú, phân công, trả hàng/kiểm hàng/nhập lại. `OrderIngestor` chống trùng, chống sai thứ tự, chống chuyển lùi. Chính sách giữ/xuất/nhả tồn theo trạng thái; bù thiếu hàng theo đơn cũ trước.
- Mapping SKU: chỉ gợi ý khi SKU trùng chính xác, người dùng xác nhận; map xong tự giữ tồn cho đơn đang mở.
- API: `/orders` (tabs + đếm, lọc, bulk, in phiếu, đồng bộ, ghi chú, phân công, tóm tắt), `/returns`, `/marketplaces`, `/marketplace-accounts`, `/listings`, `/fake-marketplace`, `POST /webhooks/{marketplace}`.
- Giao diện: Đơn hàng (tabs, lọc, cờ quá hạn/thiếu hàng/chưa map, bulk, in phiếu đóng gói PDF), chi tiết đơn, Trả hàng (kiểm hàng), Kết nối sàn (ma trận capability, bảng điều khiển sàn giả lập, theo dõi webhook), Liên kết SKU, KPI đơn trên Tổng quan.
- Sửa trong lúc test: 3 deadlock thật (gap lock khi khóa dòng chưa tồn tại, thứ tự khóa dòng đơn, outbox `updateOrCreate`) và lỗi đọc snapshot sau insert trùng, đều do test 1.000 đơn đồng thời phát hiện.

### Phase 2 — Catalog & Inventory (2026-10-31)
- Catalog: sản phẩm, biến thể (SKU duy nhất theo tenant, không phân biệt hoa thường, kể cả SKU đã xóa), mã vạch, danh mục, thương hiệu, ảnh (đường dẫn ngẫu nhiên theo tenant), thao tác trạng thái hàng loạt, kiểm tra SKU trực tiếp.
- Import CSV/XLSX (OpenSpout): kiểm tra toàn bộ trước, có lỗi thì không ghi gì; gộp biến thể theo `product_key`, không ghép theo tên; tồn đầu kỳ chỉ cho SKU mới. Export CSV/XLSX. 10.000 SKU ≈ 9 giây.
- Inventory: nhiều kho (giới hạn theo gói), sổ kho bất biến, giữ tồn có shortage, nhả/xuất theo reservation, phiếu nhập (giá vốn bình quân gia quyền), phiếu điều chỉnh có duyệt (đếm thực tế hoặc chênh lệch), phiếu chuyển kho (nháp → đang chuyển → đã nhận, hủy khi đang chuyển trả hàng về kho nguồn), tồn an toàn, thẻ kho.
- `InventoryService` là điểm ghi duy nhất (test arch); khóa dòng theo thứ tự cố định; idempotency key; sự kiện `AvailableStockChanged` sau commit.
- `ecso:inventory:verify` (chạy hằng ngày), `AllocationCalculator`.
- Quyền mới `inventory.receive`. Tenant mới tự có kho mặc định `MAIN`.
- Giao diện: Sản phẩm (danh sách, form + tạo nhanh biến thể, import), Kho hàng (tồn kho, thẻ kho, phiếu nhập/điều chỉnh/chuyển kho, danh sách kho), KPI sắp hết hàng trên Tổng quan. Ô chọn SKU hỗ trợ máy quét mã vạch.
- Sửa: 2 deadlock thật do test đa tiến trình phát hiện (INSERT IGNORE trên dòng có sẵn; khóa ngoại khi insert movement).
- CI: thêm PHPStan (Larastan level 5) và bước test concurrency.

### Phase 1 — SaaS Foundation (2026-10-31)
- Khởi tạo Laravel 12.70 (PHP 8.3), Sanctum (SPA cookie), Horizon, Pest; Vue 3 + TypeScript + Tailwind 4 + Pinia + Vue Router, Lucide.
- Identity: đăng ký, đăng nhập (giới hạn 5 lần sai/phút), đăng xuất, xác minh email (link ký số), quên/đặt lại mật khẩu, khóa tài khoản.
- Tenancy: tạo/chọn/chuyển doanh nghiệp, `TenantContext`, `BelongsToTenant` + `TenantScope` fail-closed, middleware `X-Tenant`.
- Access: 25 permission, 7 vai trò hệ thống, policies, chống leo quyền (không gán OWNER, không tự đổi quyền mình, chỉ gán vai trò có quyền ⊆ quyền của mình).
- Mời nhân viên qua email (token băm SHA-256, hạn 7 ngày, dùng một lần), thu hồi, đổi vai trò, khóa, xóa thành viên.
- Billing nền: plans/features/plan_features/subscriptions/overrides, `Entitlements`, giới hạn số user theo gói (tính cả lời mời đang chờ). 4 gói Free/Starter/Pro/Business (giá tạm).
- Audit log append-only, che dữ liệu nhạy cảm.
- Admin: dashboard số liệu nền tảng, danh sách tenant (không có dữ liệu nghiệp vụ).
- Lệnh `ecso:check-env`, `ecso:install`; `/health`, `/health/ready`; heartbeat scheduler.
- Giao diện Tenant Dashboard (12 mục menu, các module chưa làm hiển thị trạng thái theo phase) và Admin skeleton.
- Test: 72 Pest (MySQL 8), 8 Vitest, 7 Playwright E2E. CI GitHub Actions.

### Phase 0 — Discovery & Architecture (2026-10-31)
- Thêm bộ tài liệu kiến trúc: ARCHITECTURE, DATABASE, ERD, API-CAPABILITY-MATRIX, INVENTORY-ENGINE, ORDER-LIFECYCLE, MULTI-TENANCY, SECURITY, DEPLOYMENT-AAPANEL, TESTING, ROADMAP, HANDOVER.
- Chưa có code. Chưa có migration.
