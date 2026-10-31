# Triển khai trên Ubuntu + aaPanel

> Phase 0 (2026-10-31): **khung hướng dẫn**. Lệnh cụ thể (setup command, health check, script deploy) sẽ được viết và chạy thử ở Phase 1, hoàn thiện ở Phase 8.

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

- Root: `/www/wwwroot/ecso/current/public`, rewrite Laravel (`try_files $uri $uri/ /index.php?$query_string;`).
- SSL Let's Encrypt qua aaPanel, bật Force HTTPS + HSTS.
- Chặn truy cập `.env`, `.git`, `storage` từ web.
- `client_max_body_size 20m` (import Excel).

## 4. Tiến trình nền (Supervisor — aaPanel có plugin "Supervisor")

| Tên | Lệnh | User |
|---|---|---|
| ecso-horizon | `php /www/wwwroot/ecso/current/artisan horizon` | www |
| ecso-scheduler | `php /www/wwwroot/ecso/current/artisan schedule:work` (hoặc cron `* * * * *` `schedule:run`) | www |

`stopwaitsecs=3600` cho Horizon để job dở được làm xong khi deploy. **Không chạy worker bằng phiên SSH.**

## 5. Cài đặt lần đầu

```bash
php artisan ecso:check-env      # kiểm PHP version, extensions, pcntl, quyền ghi, kết nối DB/Redis, APP_DEBUG=false, APP_KEY, key mã hóa token
php artisan ecso:install        # wizard CLI: tạo .env, key, migrate, seed permission/plan, tạo super admin (có validate)
php artisan horizon:install
```

## 6. Quy trình deploy (script `deploy.sh`, Phase 1/8)

1. Tạo thư mục release mới, giải nén artifact build từ CI (vendor + public/build có sẵn).
2. Link `shared/.env`, `shared/storage`.
3. `php artisan ecso:check-env`
4. `php artisan migrate --force` (migrations phải tương thích ngược: thêm cột trước, xóa cột ở release sau).
5. `php artisan config:cache route:cache view:cache event:cache`
6. Đổi symlink `current` (atomic `ln -sfn`), reload PHP-FPM.
7. `php artisan horizon:terminate` (Supervisor tự khởi động lại bằng code mới).
8. Gọi `/health` – lỗi ⇒ rollback.

**Rollback**: trỏ `current` về release trước + `horizon:terminate`. Migration lỗi ⇒ dùng backup trước deploy.

## 7. Health check & giám sát

- `GET /health` (công khai, tối giản): app up.
- `GET /health/ready` (token nội bộ): DB, Redis, Horizon đang chạy, queue backlog, scheduler heartbeat (< 2 phút), dung lượng đĩa.
- Log JSON (`storage/logs/*.json`), xoay log hằng ngày.
- Cảnh báo (email/Telegram) khi: failed jobs tăng, backlog > ngưỡng, connector circuit mở, backup thất bại.

## 8. Backup & restore

- DB: `mysqldump --single-transaction --routines --triggers` hằng đêm (cron aaPanel hoặc lệnh `ecso:backup`), nén + mã hóa, giữ 7 ngày/4 tuần/6 tháng, đẩy sang storage ngoài server (S3-compatible).
- Bật binlog nếu cần point-in-time recovery.
- Storage (`shared/storage/app`): rsync/backup hằng đêm.
- Restore: hướng dẫn từng bước + **diễn tập restore mỗi quý**, sau restore chạy `ecso:inventory:verify` kiểm bất biến tồn kho.

## 9. Checklist bảo mật server

`APP_DEBUG=false` • `.env` 600 • không `chmod 777` • MySQL/Redis chỉ nghe localhost, Redis có mật khẩu • SSH key-only • firewall chỉ mở 80/443/SSH/cổng aaPanel (đổi cổng mặc định, bật 2FA aaPanel) • cập nhật bảo mật định kỳ.
