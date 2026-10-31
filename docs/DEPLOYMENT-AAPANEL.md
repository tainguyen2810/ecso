# Triển khai trên Ubuntu + aaPanel

> Hoàn thiện ở Phase 8 (2026-10-31). Các file cấu hình mẫu nằm trong `deploy/`, các script nằm trong `scripts/`. Script deploy và backup/restore đã chạy diễn tập trên môi trường làm việc (xem §10). Chưa triển khai lên server aaPanel thật.

## 1. Yêu cầu server

| Thành phần | Phiên bản | Ghi chú |
|---|---|---|
| Ubuntu | 22.04 / 24.04 LTS | |
| aaPanel | bản ổn định mới | Cài Nginx, MySQL 8, PHP 8.3, Redis từ App Store của aaPanel |
| PHP extensions | bcmath, ctype, curl, dom, fileinfo, intl, mbstring, openssl, pcntl, pdo_mysql, redis, tokenizer, xml, zip, gd | Bật `pcntl` (Horizon) — aaPanel thường tắt `pcntl_*` trong `disable_functions`, cần bỏ cho **PHP CLI** |
| Node | 20+ | Chỉ cần khi build tại server; khuyến nghị build sẵn trong CI |
| Tài nguyên tối thiểu | 2 vCPU / 4 GB RAM (MVP), 8 vCPU / 16 GB cho ~50k đơn/ngày | |

## 2. Cấu trúc thư mục (deploy không gián đoạn)

```
/www/wwwroot/ecso/
  releases/20261031120000/     # mỗi lần deploy một thư mục
  shared/.env                  # chmod 600
  shared/storage/
  current -> releases/<mới nhất>   # Nginx root = current/public
```

## 3. Nginx (site aaPanel)

Mẫu đầy đủ: `deploy/nginx/ecso.conf`.

- Root: `/www/wwwroot/ecso/current/public`, rewrite Laravel (`try_files $uri $uri/ /index.php?$query_string;`).
- SSL Let's Encrypt qua aaPanel, bật Force HTTPS + HSTS.
- Chặn truy cập `.env`, `.git`, `storage` từ web.
- `client_max_body_size 20m` (import Excel).

## 4. Tiến trình nền (Supervisor — aaPanel có plugin "Supervisor")

| Tên | Lệnh | User |
|---|---|---|
| ecso-horizon | `php /www/wwwroot/ecso/current/artisan horizon` | www |
| ecso-scheduler | `php /www/wwwroot/ecso/current/artisan schedule:work` (hoặc cron `* * * * *` `schedule:run`) | www |

`stopwaitsecs=3600` cho Horizon để job dở được làm xong khi deploy. **Không chạy worker bằng phiên SSH.** File mẫu: `deploy/supervisor/ecso-horizon.conf`, `deploy/supervisor/ecso-scheduler.conf`.

Lệnh định kỳ do scheduler chạy: heartbeat (mỗi phút), `ecso:marketplace:push-due` (mỗi phút), `ecso:monitor` (5 phút), `ecso:orders:reconcile` (15 phút), `ecso:billing:sweep` (mỗi giờ), `ecso:inventory:verify` (02:30), `horizon:snapshot` (5 phút).

## 5. Cài đặt lần đầu

```bash
php artisan ecso:check-env      # kiểm PHP version, extensions, pcntl, quyền ghi, kết nối DB/Redis, APP_DEBUG=false, APP_KEY, key mã hóa token
php artisan ecso:install        # wizard CLI: tạo .env, key, migrate, seed permission/plan, tạo super admin (có validate)
php artisan horizon:install
php artisan storage:link        # ảnh sản phẩm (disk public, thư mục tenants/{ulid}/products, tên file ngẫu nhiên)
```

Cron Scheduler (đã có từ Phase 1) chạy thêm `ecso:inventory:verify` lúc 02:30 hằng ngày.

## 6. Quy trình deploy (`scripts/deploy.sh`, đã chạy diễn tập Phase 8)

1. Tạo thư mục release mới, giải nén artifact build từ CI (vendor + public/build có sẵn).
2. Link `shared/.env`, `shared/storage`.
3. `php artisan ecso:check-env`
4. `php artisan migrate --force` (migrations phải tương thích ngược: thêm cột trước, xóa cột ở release sau).
5. `php artisan config:cache route:cache view:cache event:cache`
6. Đổi symlink `current` (atomic `ln -sfn`), reload PHP-FPM.
7. `php artisan horizon:terminate` (Supervisor tự khởi động lại bằng code mới).
8. Gọi `/health`; nếu lỗi thì tự rollback về release trước.

Cách dùng: `BASE=/www/wwwroot/ecso FPM_RELOAD_CMD='/etc/init.d/php-fpm-83 reload' scripts/deploy.sh ecso-build.tar.gz`, chạy bằng user `www` (script từ chối chạy bằng root). Build tarball trong CI bằng `composer install --no-dev -o` và `npm ci && npx vite build`.

**Rollback**: trỏ `current` về release trước + `horizon:terminate`. Migration lỗi ⇒ dùng backup trước deploy.

## 7. Health check & giám sát

- `GET /health` (công khai, tối giản): app up.
- `GET /health/ready` (header `X-Health-Token: $HEALTH_TOKEN`): DB, Redis, cache, scheduler heartbeat, kích thước từng queue, job lỗi 24 giờ, % ổ đĩa trống.
- Trang **Admin → Giám sát hệ thống**: queue, job lỗi (chỉ tên job và loại lỗi), scheduler, webhook 24 giờ, outbox đẩy tồn, shop đang ngắt mạch, ổ đĩa. Trang tự làm mới mỗi 30 giây.
- **Cảnh báo**: `ecso:monitor` chạy 5 phút một lần. Khi có vấn đề nó ghi log mức CRITICAL (mỗi vấn đề tối đa 1 lần/giờ). Đặt `LOG_STACK=daily,slack` và `LOG_SLACK_WEBHOOK_URL` để cảnh báo về Slack. Ngưỡng nằm trong `config/ecso.php` → `monitoring`.
- **Theo dõi lỗi ứng dụng**: lỗi chưa xử lý được ghi log (đã che token và dữ liệu cá nhân, xem SECURITY.md) và lỗi mức critical gửi qua kênh Slack. Sentry hoặc dịch vụ tương tự **chưa tích hợp** (chưa có tài khoản; có thể thêm `sentry/sentry-laravel` sau).
- Log xoay hằng ngày (`LOG_STACK=daily`, giữ 14 ngày).

## 8. Backup & restore

- **Backup DB**: `scripts/backup-db.sh /www/backup/ecso`, đặt cron aaPanel lúc 01:30 bằng user `www`.
  - Dùng `mysqldump --single-transaction` (không khóa ghi), có cả routines, triggers và events, nén gzip.
  - Mã hóa AES-256 (openssl, PBKDF2) khi đặt `BACKUP_ENCRYPTION_KEY_FILE` (file chứa passphrase, quyền 600, lưu thêm một bản ở nơi khác server).
  - Có file `.sha256` đi kèm. Mặc định giữ 14 ngày (`KEEP_DAYS`). Sau đó chép thư mục backup ra storage ngoài server (S3-compatible hoặc rclone).
  - Mật khẩu DB truyền qua file tạm, không nằm trên dòng lệnh.
- Bật binlog nếu cần point-in-time recovery.
- Storage (`shared/storage/app`): rsync/backup hằng đêm.
- **Restore**: `scripts/restore-db.sh <file> [db đích]`.
  - Mặc định restore vào DB riêng `<db>_restore_drill`. DB này phải được tạo và cấp quyền trước, vì user ứng dụng không có quyền CREATE DATABASE.
  - Script kiểm tra checksum, giải mã, nạp dữ liệu, rồi chạy `ecso:inventory:verify` trên DB vừa restore.
  - Script từ chối ghi đè DB đang chạy, trừ khi đặt `CONFIRM_RESTORE_OVER_LIVE=yes`. Trước khi làm vậy phải `php artisan down`.
  - Thủ tục restore production:
    1. `php artisan down`
    2. Backup trạng thái hiện tại.
    3. Restore vào `<db>_restore_drill` để kiểm tra.
    4. Nếu đạt, restore vào DB chính với `CONFIRM_RESTORE_OVER_LIVE=yes`.
    5. `php artisan up`
- **Diễn tập restore mỗi quý.** Ghi kết quả vào §10.

## 9. Checklist bảo mật server

`APP_DEBUG=false` • `.env` 600 • không `chmod 777` • MySQL/Redis chỉ nghe localhost, Redis có mật khẩu • SSH key-only • firewall chỉ mở 80/443/SSH/cổng aaPanel (đổi cổng mặc định, bật 2FA aaPanel) • cập nhật bảo mật định kỳ.

## 10. Diễn tập đã chạy (Phase 8, 2026-10-31, môi trường làm việc — chưa phải server thật)

| Diễn tập | Kết quả |
|---|---|
| Backup có mã hóa | File `.sql.gz.enc` 40 KB kèm checksum. Dữ liệu demo: 21 đơn, 80 bút toán kho, 1 hóa đơn. |
| Restore vào `ecso_restore_drill` | Checksum OK. Giải mã và nạp mất 1,7 giây. Số dòng orders/stock_movements/invoices khớp 21/80/1. `ecso:inventory:verify` báo "Sổ kho khớp". |
| Phát hiện hỏng dữ liệu | Cố tình sửa một số dư trong DB restore thì `ecso:inventory:verify` báo lệch `on_hand_vs_ledger` 68/75. |
| Restore ghi đè DB live khi chưa xác nhận | Bị từ chối. |
| User ứng dụng tự tạo DB drill | Bị MySQL từ chối, đúng như mong muốn (quyền tối thiểu). Đã ghi vào hướng dẫn. |
| `scripts/deploy.sh` bằng user `www` | Kiểm tra môi trường production đạt, migrate, cache, đổi symlink, `.env` vẫn 600, không có thư mục world-writable. |
| `scripts/deploy.sh` khi health check lỗi | Tự rollback `current` về release trước. |
| `scripts/deploy.sh` bằng root | Bị từ chối. |
