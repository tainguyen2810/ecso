# Báo cáo Phase 1 — SaaS Foundation

Ngày: 2026-10-31. Nhánh: `phase-1-foundation`. Trạng thái: **hoàn thành, chờ anh duyệt PR và xác nhận trước Phase 2.**

## 1. Đã hoàn thành
- Khởi tạo dự án Laravel 12.70 + Vue 3/TypeScript/Tailwind 4 trong repo `ecso`, cấu trúc modular monolith (`app/Modules/{Identity, Tenancy, Access, Billing, Audit, Admin, Platform}`).
- Xác thực: đăng ký, đăng nhập (Sanctum SPA cookie, giới hạn 5 lần sai), đăng xuất, xác minh email bằng link ký số, quên/đặt lại mật khẩu (không lộ email có tồn tại hay không), khóa tài khoản.
- Multi-tenant: một user nhiều doanh nghiệp; chọn doanh nghiệp qua header `X-Tenant` nhưng server luôn kiểm membership; mọi model nghiệp vụ tự lọc theo tenant và **báo lỗi nếu thiếu context** thay vì trả dữ liệu mọi tenant; `tenant_id` gửi từ client luôn bị ghi đè.
- RBAC: 25 quyền, 7 vai trò hệ thống, Laravel Policies; chống leo quyền (không ai gán được vai trò Chủ doanh nghiệp, không tự đổi quyền mình, chỉ gán vai trò có tập quyền nằm trong quyền của mình).
- Mời nhân viên qua email (token chỉ lưu bản băm, hạn 7 ngày, dùng 1 lần, đúng email mới nhận được), thu hồi, đổi vai trò, khóa, xóa.
- Gói dịch vụ nền: Free/Starter/Pro/Business với entitlements cấu hình trong DB (không hardcode tên gói); đã áp dụng giới hạn số người dùng.
- Nhật ký thao tác append-only, tự che mật khẩu/token.
- Super Admin: dashboard số liệu nền tảng + danh sách khách hàng, **không** xem được dữ liệu nghiệp vụ của tenant.
- Vận hành: `php artisan ecso:check-env`, `php artisan ecso:install`, `/health`, `/health/ready`, heartbeat scheduler, CI GitHub Actions.

## 2. File chính
- Backend: `app/Modules/**`, `app/Providers/ModuleServiceProvider.php`, `bootstrap/app.php`, `config/ecso.php`, `database/{migrations,seeders,factories}`.
- Frontend: `resources/js/shared/**` (API client, stores, UI kit), `resources/js/apps/tenant/**`, `resources/js/apps/admin/**`.
- Test: `tests/{Feature,Unit,Arch}`, `resources/js/**/*.spec.ts`, `e2e/*.spec.ts`.
- CI: `.github/workflows/ci.yml`. Docs: cập nhật ARCHITECTURE, TESTING, HANDOVER, CHANGELOG, ROADMAP, DEPLOYMENT-AAPANEL.

## 3. Database migrations
`users` (thêm ulid, locale, is_super_admin, status, last_login_at), `tenants`, `permissions`, `roles`, `role_permissions`, `tenant_users`, `tenant_invitations`, `plans`, `features`, `plan_features`, `subscriptions`, `tenant_feature_overrides`, `audit_logs`, `personal_access_tokens`, cùng bảng mặc định của Laravel (sessions, cache, jobs, failed_jobs, password_reset_tokens).

## 4. API endpoints (`/api/v1`)
| Nhóm | Endpoint |
|---|---|
| Auth | `POST auth/register`, `POST auth/login`, `POST auth/logout`, `GET auth/me`, `POST auth/forgot-password`, `POST auth/reset-password`, `POST auth/email/verification-notification`, `GET auth/email/verify/{id}/{hash}` |
| Doanh nghiệp | `GET tenants`, `POST tenants`, `GET tenant`, `PATCH tenant` |
| Nhân viên | `GET members`, `PATCH members/{id}`, `DELETE members/{id}`, `GET roles`, `GET/POST members/invitations`, `DELETE members/invitations/{id}`, `GET invitations/{token}`, `POST invitations/{token}/accept` |
| Gói | `GET subscription` |
| Nhật ký | `GET audit-logs` (cursor pagination) |
| Admin | `GET admin/dashboard`, `GET admin/tenants` |
| Health | `GET /health`, `GET /health/ready` |

## 5. Giao diện
19 ảnh chụp trong `docs/screenshots/phase-1/` (Playwright tự chụp): đăng nhập, đăng ký, quên mật khẩu, xác minh email, dashboard, chuyển doanh nghiệp, nhân viên, mời nhân viên, lời mời, gói dịch vụ, nhật ký, cài đặt, module chưa làm, giao diện nhân viên kho, trang không có quyền, mobile, admin dashboard, admin khách hàng.

## 6. Test đã chạy (2026-10-31, MySQL 8.0.46, PHP 8.3.6, Node 22)
| Bộ | Kết quả |
|---|---|
| Pest (Unit + Feature + Arch) trên MySQL 8 | **72 pass / 251 assertions** |
| Vitest | **8 pass** |
| Playwright E2E (Chromium) | **7 pass** |
| vue-tsc, Pint | sạch |

Phạm vi test: tenant isolation (thiếu header, tenant không thuộc về, ID của tenant khác, membership bị khóa, tenant bị tạm khóa, super admin không có quyền ngầm, fail-closed), ma trận quyền 6 vai trò, leo quyền, lời mời (sai email, hết hạn, thu hồi, dùng lại), giới hạn gói, đăng nhập/khóa/throttle, xác minh email, đặt lại mật khẩu, audit append-only và che dữ liệu, lệnh cài đặt, health check.

## 7. Lỗi còn tồn tại / giới hạn
- **CI chưa chạy trên GitHub**: file workflow đã có, sẽ chạy khi mở PR.
- **Larastan (PHPStan) chưa cài**: môi trường build hiện tại không tải được gói này (bị chặn khi tải từ GitHub). Đề xuất bổ sung ở Phase 2 trong CI.
- Gửi email thật cần cấu hình SMTP (đang `MAIL_MAILER=log`).
- Chuyển quyền sở hữu doanh nghiệp, 2FA, vai trò tùy chỉnh qua giao diện: chưa làm (đã có chỗ trong thiết kế).
- Thanh toán, nâng/hạ gói, dùng thử: Phase 6.

## 8. Rủi ro
- Chưa có tài khoản developer của các sàn (không ảnh hưởng Phase 2–3, ảnh hưởng Phase 4–5).
- Cần quyết định cổng thanh toán trước Phase 6.

## 9. Cần credentials / phê duyệt
Không cần cho Phase 1–3. Từ Phase 4: tài khoản Shopee Open Platform / TikTok Shop Partner Center + test shop.

## 10. Đề xuất phase tiếp theo
**Phase 2 — Catalog & Inventory**: sản phẩm, SKU, biến thể, kho, sổ kho bất biến, giữ tồn có khóa dòng, điều chỉnh/chuyển kho, test đồng thời trên MySQL.
