# Security

> Phase 0 (2026-10-31): **yêu cầu và biện pháp**. Audit đầy đủ ở Phase 8.

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
