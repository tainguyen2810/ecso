# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [Unreleased]

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
