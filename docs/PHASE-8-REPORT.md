# Báo cáo Phase 8 — Production Hardening

> Ngày: 2026-10-31 · Nhánh: `phase-8-hardening`
> Chưa có server production hoặc staging. Các script deploy và backup/restore đã được diễn tập trên môi trường làm việc. Kịch bản k6 đã viết nhưng **chưa chạy**.

## 1. Đã hoàn thành
- **2FA cho super admin (TOTP, RFC 6238, không dùng thư viện ngoài)**
  - Lần đăng nhập đầu tiên bắt buộc cài 2FA bằng mã QR hoặc nhập khóa thủ công, sau đó nhận 8 mã khôi phục.
  - Mỗi phiên đăng nhập mới đều phải nhập mã. Một mã (bước 30 giây) chỉ dùng được một lần, và mỗi mã khôi phục cũng chỉ dùng được một lần.
  - Secret và mã khôi phục được lưu mã hóa; mã khôi phục chỉ lưu dạng hash.
  - Trạng thái "đã qua 2FA" gắn với session: session được tạo lại khi qua 2FA và mất khi đăng xuất.
  - Nếu chưa qua 2FA, API admin trả 403 với `TWO_FACTOR_SETUP_REQUIRED` hoặc `TWO_FACTOR_REQUIRED`.
- **Header bảo mật**: `nosniff`, `X-Frame-Options: DENY`, Referrer-Policy, Permissions-Policy và COOP trên mọi response. Trên production thêm CSP chặt và HSTS.
- **Che dữ liệu nhạy cảm trong log**: áp dụng cho mọi kênh, ở mọi độ sâu của context (token, secret, chữ ký, Authorization, cookie, mật khẩu, mã 2FA, số điện thoại, địa chỉ, tên người mua; email bị che một phần).
- **Giám sát**
  - Trang Admin → Giám sát hệ thống hiển thị: queue (đọc kích thước thật trên Redis), job lỗi, scheduler heartbeat, webhook 24 giờ, outbox đẩy tồn, shop đang ngắt mạch, ổ đĩa.
  - Trang không hiển thị nội dung lỗi, vì có thể chứa dữ liệu người mua.
  - `ecso:monitor` chạy 5 phút một lần, ghi log mức CRITICAL (gửi Slack qua kênh log, chống lặp 1 giờ).
  - `/health/ready` dùng cùng nguồn số liệu.
- **Backup / restore**
  - `scripts/backup-db.sh`: mysqldump nhất quán, gzip, mã hóa AES-256 (PBKDF2), checksum, giữ theo số ngày.
  - `scripts/restore-db.sh`: restore vào DB diễn tập, kiểm tra checksum, sau đó chạy kiểm tra sổ kho. Script từ chối ghi đè DB đang chạy nếu chưa xác nhận.
- **Deploy**
  - `scripts/deploy.sh`: release theo thư mục, đổi symlink nguyên tử, `check-env --production`, migrate, cache, `horizon:terminate`, health check, tự rollback khi lỗi; từ chối chạy bằng root.
  - Cấu hình mẫu cho Supervisor (Horizon, scheduler) và Nginx.
- **Load test**: kịch bản k6 có ngưỡng p95 và tỉ lệ lỗi (`loadtest/k6-orders.js`), cùng hướng dẫn trong LOAD-TEST.md.
- **CI**: thêm `composer audit` và `npm audit --omit=dev --audit-level=high`.
- **`ecso:check-env --production`**: kiểm thêm 2FA admin, cookie secure và mã hóa session.
- **Tài liệu**:
  - Mới: RELEASE-CHECKLIST, LOAD-TEST.
  - Cập nhật: DEPLOYMENT-AAPANEL (deploy, giám sát, backup/restore, kết quả diễn tập), SECURITY §4 (kết quả audit).

## 2. File chính
- **2FA**
  - `app/Modules/Identity/Services/Totp.php`, `TwoFactorService.php`
  - `app/Modules/Admin/Http/Middleware/EnsureSuperAdmin.php`, `Http/Controllers/TwoFactorController.php`
  - `resources/js/apps/admin/pages/TwoFactor.vue`
- **Header và log**: `app/Http/Middleware/SecurityHeaders.php`, `app/Logging/RedactSecrets*.php`
- **Giám sát**
  - `app/Modules/Platform/Services/SystemMonitor.php`, `Console/MonitorCommand.php`
  - `AdminMonitoringController.php`, `pages/Monitoring.vue`
- **Script và cấu hình**: `scripts/*.sh`, `deploy/`, `loadtest/k6-orders.js`

## 3. Migrations
`2026_10_31_500000_add_two_factor_to_users`.

## 4. API mới
- `GET /admin/2fa`, `POST /admin/2fa/setup`, `POST /admin/2fa/confirm`, `POST /admin/2fa/verify` (giới hạn 5 lần/phút).
- `GET /admin/monitoring`.

## 5. Giao diện
Ảnh trong `docs/screenshots/phase-8/`:
1. Cài đặt 2FA (QR)
2. Mã khôi phục
3. Giám sát hệ thống
4. Màn hình nhập mã

## 6. Test
- **Pest**: 226 test pass, trong đó 8 test mới.
  - TOTP khớp test vector RFC 6238.
  - Chặn trang admin khi chưa cài 2FA hoặc chưa nhập mã; chống dùng lại mã.
  - Mã khôi phục chỉ dùng một lần; người không phải super admin không vào được.
  - Header bảo mật, CSP và HSTS trên production.
  - Che dữ liệu trong log, kiểm cả file log thật.
  - Giám sát không lộ nội dung lỗi; cảnh báo được ghi log.
- **Concurrency**: 5 test pass.
- **Vitest**: 19 test pass.
- **Playwright**: 26 test pass.
  - Luồng cài 2FA thật bằng mã tính từ secret.
  - API từ chối session chưa qua 2FA.
  - Các test admin cũ đã chuyển sang đăng nhập có 2FA.
- **Diễn tập**: kết quả ở DEPLOYMENT-AAPANEL §10.
  - Backup mã hóa rồi restore: số dòng khớp, sổ kho khớp.
  - Cố tình làm hỏng dữ liệu: hệ thống phát hiện.
  - Deploy thành công; deploy với health check lỗi thì tự rollback; chạy bằng root bị từ chối.

## 7. Hạn chế
- Chưa chạy load test k6. Môi trường làm việc không cho chạy binary tải từ ngoài, và chưa có staging. Hiện chỉ có mốc độ trễ tuần tự trên local (p95 khoảng 36–45 ms với dữ liệu nhỏ), cùng test 1.000 đơn đồng thời trong CI.
- Chưa tích hợp Sentry (chưa có tài khoản). Lỗi critical được cảnh báo qua log/Slack.
- Chưa có support access có kiểm toán, trang audit log cho admin, feature flags hoặc override gói: các mục này để sau MVP.
- Chưa làm pentest bên thứ ba và chưa có tư vấn pháp lý về dữ liệu cá nhân (NĐ 13/2023).
- npm audit (gồm cả dev): 5 lỗi mức moderate, chỉ nằm trong công cụ test, không vào sản phẩm.

## 8. Rủi ro
- Nếu mất cả điện thoại lẫn mã khôi phục, super admin bị khóa ngoài trang quản trị. Cách khôi phục hiện tại là xóa `two_factor_*` của user đó bằng SQL trên server (cần quyền DB). Quy trình này được ghi trong HANDOVER.
- Backup chỉ an toàn khi được chép ra ngoài server và passphrase được giữ ở nơi khác.

## 9. Credentials cần
- `HEALTH_TOKEN`
- `LOG_SLACK_WEBHOOK_URL`
- Passphrase backup
- Storage ngoài server (S3-compatible)
- Server và staging

## 10. Tiếp theo
- Dựng staging và chạy RELEASE-CHECKLIST, load test k6.
- Đăng ký app trên các sàn để chuyển connector từ UNKNOWN sang VERIFIED.
