# Security

> Phase 0 (2026-10-31): yêu cầu và biện pháp. Phase 8: kết quả audit ở §4.

## 1. Mô hình đe dọa chính

| Mối đe dọa | Biện pháp |
|---|---|
| Truy cập chéo tenant / IDOR | TenantScope, ULID công khai, route binding qua scope, Policies, test IDOR cho mọi endpoint (dataset tự sinh từ route list) |
| Leo quyền trong tenant | Permission-based, gán role ⊆ quyền của người gán, không tự sửa role mình |
| Lộ token sàn | Mã hóa AES-256 (Laravel encrypter, key riêng `MARKETPLACE_CREDENTIALS_KEY`, hỗ trợ xoay key qua `key_version`), không trả token ra API, không log |
| Giả mạo webhook | Verify chữ ký trên raw body, so sánh constant-time, timestamp window, dedupe event id |
| Gán tenant giả trong job/webhook | Tenant chỉ suy từ mapping server-side |
| CSRF / XSS | Sanctum SPA cookie + CSRF token, `SameSite=Lax`, CSP, Vue escape mặc định, cấm `v-html` với dữ liệu sàn |
| Brute force | Rate limit đăng nhập/quên mật khẩu theo IP + email, khóa tạm thời, thông báo |
| Thanh toán bị gọi lặp/giả | Verify chữ ký cổng thanh toán, idempotency theo `provider_txn_id`, chỉ tin IPN server-to-server |
| Super admin lạm quyền | Support access session có kiểm toán |
| Lộ dữ liệu cá nhân người mua | Mã hóa địa chỉ/sđt, che khi hiển thị theo quyền, xóa theo retention |
| File upload độc hại | Chỉ ảnh/CSV/XLSX, kiểm MIME thật, giới hạn kích thước, lưu ngoài webroot, tên ngẫu nhiên |
| Mass assignment | `$fillable` tường minh, FormRequest validate |
| SQL injection | Eloquent/Query Builder binding, cấm raw query có nối chuỗi (lint) |

## 2. Quy tắc bắt buộc

- `APP_DEBUG=false`, `APP_ENV=production` trên server; environment checker từ chối khởi động nếu sai.
- Không `chmod 777`; quyền: code `www:www 755/644`, `storage` và `bootstrap/cache` `775` cho user chạy PHP.
- Secrets chỉ ở `.env` (chmod 600), không commit; có `.env.example` đầy đủ.
- HTTPS bắt buộc, HSTS.
- Log có `RedactingProcessor` (Monolog) che: `access_token, refresh_token, password, sign, signature, authorization, phone, address, email` (một phần).
- Audit log cho: đăng nhập, đổi quyền, mời/xóa thành viên, kết nối/ngắt sàn, điều chỉnh kho, đổi giá vốn, đổi gói, support access.
- Dependency: `composer audit`, `npm audit` trong CI.
- Backup mã hóa trước khi đưa ra ngoài server.

## 3. Kiểm thử bảo mật (xem TESTING.md)

IDOR, cross-tenant, privilege escalation, webhook chữ ký sai/replay, CSRF, rate limit đăng nhập, mass assignment `tenant_id`, token không xuất hiện trong log/response.

## 4. Audit Phase 8 (2026-10-31)

| Hạng mục | Trạng thái | Bằng chứng |
|---|---|---|
| Cách ly tenant / IDOR | ✅ | Global scope fail-closed, test arch (mọi model có `tenant_id` phải dùng `BelongsToTenant`), các test cross-tenant trong từng module (đơn, kho, hóa đơn…). |
| Leo quyền | ✅ | Ma trận quyền theo vai trò (`MembersTest`), không gán vai trò vượt quyền người gán. |
| Super admin | ✅ **2FA TOTP bắt buộc** (Phase 8) | Mã dùng một lần cho mỗi bước thời gian; 8 mã khôi phục lưu dạng hash và mã hóa; trạng thái đã qua 2FA gắn với session và session được tạo lại khi qua 2FA; `ecso:check-env --production` báo lỗi nếu tắt. Test: `TwoFactorTest`, e2e `admin.spec.ts`. |
| Super admin xem dữ liệu tenant | ✅ | API admin chỉ trả số đếm. Trang giám sát chỉ hiện tên job và loại lỗi, không hiện nội dung lỗi (có thể chứa dữ liệu người mua). Support access có kiểm toán: **chưa làm (sau MVP)**, nên hiện admin không có đường nào xem dữ liệu tenant. |
| Header bảo mật | ✅ (Phase 8) | `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, `COOP`; trên production thêm CSP chặt (`script-src 'self'`, `frame-ancestors 'none'`) và HSTS. Test: `HardeningTest`. |
| Che dữ liệu nhạy cảm trong log | ✅ (Phase 8) | `App\Logging\RedactSecrets` gắn vào mọi kênh log: token, secret, chữ ký, Authorization, cookie, mật khẩu, mã 2FA, số điện thoại, địa chỉ, tên người mua; email bị che một phần. Test kiểm cả file log thật. |
| Token sàn | ✅ | Cast `encrypted`, ẩn khỏi JSON (Phase 3/4). |
| Webhook / IPN giả mạo | ✅ | Chữ ký và dedupe (Phase 3); IPN lấy tenant từ hóa đơn, chữ ký sai trả 401 (Phase 6). |
| Rate limit | ✅ | Đăng nhập (khóa theo email+IP), quên mật khẩu, 2FA (5/phút), đổi gói, webhook, IPN. |
| Phiên & cookie | ✅ | Sanctum SPA + CSRF; production bắt buộc `SESSION_SECURE_COOKIE` và `SESSION_ENCRYPT` (check-env). |
| Phụ thuộc | ✅ có ghi chú | `composer audit`: 0 lỗ hổng. `npm audit --omit=dev`: 0. `npm audit` gồm cả dev: 5 lỗi mức moderate trong `glob`/`minimatch` qua `@vue/test-utils` (chỉ dùng khi chạy test, không vào bundle), chờ bản vá upstream. CI đã thêm `composer audit` và `npm audit --omit=dev --audit-level=high`. |
| Server | 📋 | DEPLOYMENT-AAPANEL §9 và RELEASE-CHECKLIST mục B; script deploy từ chối chạy bằng root; không dùng chmod 777. |
| Pentest bên thứ ba | ❌ chưa làm | Khuyến nghị làm trước khi bán rộng. |
| Pháp lý dữ liệu cá nhân (NĐ 13/2023) | ❌ chưa làm | Cần tư vấn pháp lý: chính sách bảo mật, thời hạn lưu dữ liệu, quy trình xóa dữ liệu theo yêu cầu. |
