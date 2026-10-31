# Roadmap

> Phase 0 (2026-10-31). Mỗi phase chỉ bắt đầu khi chủ dự án xác nhận phase trước. Ước lượng là thời gian làm việc của Claude + thời gian chờ review, **chưa tính** thời gian chờ sàn duyệt app.

## 1. Sơ đồ phụ thuộc

```
P0 Discovery ─► P1 SaaS Foundation ─► P2 Catalog & Inventory ─► P3 Order Engine ─┬─► P4 Shopee ─┐
                                                                                 ├─► P5 TikTok ─┼─► P7 Lazada & Tiki ─► P8 Hardening
                        P1 ─────────────────────────────► P6 SaaS Commercial ◄───┘              │
Song song (chủ dự án): đăng ký app Shopee/TikTok ngay ─► credentials trước P4/P5; Lazada/Tiki trước P7
```

- P4, P5 phụ thuộc **credentials + docs chính thức** (xem API-CAPABILITY-MATRIX). Không có ⇒ chỉ làm được phần khung connector + Fake, không được gọi là "tích hợp xong".
- P6 cần quyết định cổng thanh toán.
- P4 và P5 có thể đổi thứ tự tùy sàn nào được duyệt trước.

## 2. Chi tiết phase và tiêu chí nghiệm thu

### Phase 1 — SaaS Foundation ✅ (2026-10-31, xem PHASE-1-REPORT.md)
**Phạm vi:** khởi tạo Laravel 12 + Vue 3/TS/Tailwind/shadcn-vue, cấu trúc module, Sanctum SPA, đăng ký/đăng nhập/xác minh email/quên mật khẩu, tạo & chuyển tenant, mời nhân viên, RBAC + Policies, TenantContext + scope, audit log cơ bản, layout Tenant Dashboard (sidebar đủ mục, trang trống có empty state), Admin skeleton, `ecso:check-env`, `ecso:install`, CI.
**Migrations:** users, tenants, tenant_users, tenant_invitations, roles, permissions, role_permissions, audit_logs, notifications, personal_access_tokens, plans/features/plan_features (seed tối thiểu cho entitlement).
**Nghiệm thu:**
- Test tenant isolation + authorization dataset pass trên MySQL 8.
- User thuộc 2 tenant chuyển qua lại, không thấy dữ liệu chéo.
- Mời nhân viên qua email (mail log), chấp nhận lời mời, phân quyền đúng.
- Ảnh chụp: đăng nhập, đăng ký, chọn tenant, dashboard, nhân viên, admin.
- `ecso:check-env` báo đúng lỗi khi thiếu extension / APP_DEBUG=true.

### Phase 2 — Catalog & Inventory ✅ (2026-10-31, xem PHASE-2-REPORT.md)
**Phạm vi:** products, variants (SKU), barcode, category/brand, ảnh, import/export CSV/XLSX, kiểm SKU trùng; warehouses, balances, movements, reservations, adjustments (có phê duyệt), transfers, safety stock, allocation, InventoryService, thẻ kho, giá vốn bình quân.
**Nghiệm thu:** test concurrency (20 tiến trình), idempotency, bất biến ledger, property-based pass; import 10.000 SKU < 2 phút; ảnh chụp màn hình sản phẩm, tồn kho, thẻ kho, phiếu điều chỉnh, chuyển kho.

### Phase 3 — Order Engine
**Phạm vi:** orders, items, status map, history, reservation theo policy, hủy, hoàn/kiểm hàng/nhập lại, ghi chú, phân công, cảnh báo quá hạn, phiếu đóng gói (PDF), webhook inbox + pipeline, Fake connector đầy đủ, outbox đẩy tồn (tới Fake).
**Nghiệm thu:** test duplicate/out-of-order/cancel/return pass; mô phỏng 1.000 đơn đồng thời trên 50 SKU không oversell; ảnh chụp màn hình đơn hàng (tabs, lọc, bulk, chi tiết), trả hàng.

### Phase 4 — Shopee Integration
**Tiền điều kiện:** app Shopee được duyệt, test shop, đọc docs chính thức. **Phạm vi:** auth, token refresh, import sản phẩm + mapping, import đơn (polling + push), cập nhật tồn, webhook verify, reconcile, rate limit, circuit breaker.
**Nghiệm thu:** API-CAPABILITY-MATRIX cột Shopee chuyển sang VERIFIED cho các method đã làm; luồng thật trên test shop: đơn mới ⇒ reservation ⇒ tồn trên sàn giảm; ảnh chụp màn hình kết nối sàn, mapping.

### Phase 5 — TikTok Shop Integration
Như Phase 4 cho TikTok Shop.

### Phase 6 — SaaS Commercial
**Tiền điều kiện:** chọn cổng thanh toán. **Phạm vi:** plans, entitlements, trial, nâng/hạ gói, thanh toán + IPN idempotent, hóa đơn, usage limits, dashboard tài chính (doanh thu đặt/hoàn thành, phí, lợi nhuận ước tính/đối soát), báo cáo bán chạy/chậm, admin MRR.
**Nghiệm thu:** test limit + payment idempotency; ảnh chụp gói dịch vụ, thanh toán, tài chính, báo cáo, admin.

### Phase 7 — Lazada & Tiki
Như Phase 4. Tiki: polling event queue; nếu không có API cập nhật tồn ⇒ `UnsupportedCapability` + UI hướng dẫn cập nhật tay.

### Phase 8 — Production Hardening
Security audit, load test (k6 hoặc Artillery), queue monitoring, backup/restore diễn tập, error monitoring, tài liệu deploy hoàn chỉnh, release checklist, 2FA admin.

## 3. Rủi ro và giảm thiểu

| # | Rủi ro | Mức | Giảm thiểu |
|---|---|---|---|
| R1 | Không có/chậm phê duyệt app sàn (ISV) | Cao | Đăng ký ngay; P1–P3 không phụ thuộc sàn; Fake connector |
| R2 | Docs chính thức không truy cập được từ môi trường làm việc | Cao | Chủ dự án xuất PDF/ảnh docs từ Partner Center hoặc cấp tài khoản; cập nhật matrix |
| R3 | Tiki không có API cập nhật tồn cho seller | Trung bình | Thiết kế capability; ghi rõ cho khách hàng |
| R4 | Oversell do trễ đồng bộ giữa sàn | Cao | Reservation tức thời, đẩy ưu tiên khi giảm tồn, safety stock, reconcile |
| R5 | Rate limit sàn khi tenant lớn | Trung bình | Coalescing outbox, token bucket theo shop, batch API |
| R6 | Thay đổi API/version của sàn | Trung bình | Client version cô lập, contract test sandbox định kỳ |
| R7 | aaPanel tắt `pcntl`/`proc_open` | Thấp | `ecso:check-env` phát hiện, tài liệu hướng dẫn bật |
| R8 | Lỗi rò rỉ dữ liệu giữa tenant | Cao | Scope + test arch + test IDOR tự sinh mọi route |
| R9 | Pháp lý dữ liệu cá nhân (Nghị định 13/2023/NĐ-CP) | Trung bình | Mã hóa PII, retention, nhật ký truy cập; cần tư vấn pháp lý khi thương mại hóa |
| R10 | Chi phí vận hành tăng theo số tenant | Thấp | Monolith một VPS, chỉ tách khi số liệu chứng minh |
