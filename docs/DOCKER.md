# Chạy ECSO bằng Docker

Cách này thay cho cài trên aaPanel không Docker (DEPLOYMENT-AAPANEL.md). Một lệnh dựng đủ: web (Nginx + PHP-FPM 8.3),
Horizon (hàng đợi), scheduler, MySQL 8, Redis 7. Không cần cài PHP hay MySQL lên server.

## Cài lần đầu

```bash
git clone https://github.com/tainguyen2810/ecso.git /www/wwwroot/banhang && cd /www/wwwroot/banhang
cp docker/.env.example .env      # sửa APP_URL, SANCTUM_STATEFUL_DOMAINS, DB_PASSWORD, MYSQL_ROOT_PASSWORD, ADMIN_*
docker compose -p banhang up -d --build
```

- `-p banhang` là tên compose project. **Mỗi app trên server phải có tên riêng**, không dùng lại tên của EzChat
  (`ezchat`) hay bản demo (`centralchat`), nếu không volume dữ liệu có thể bị ghi đè.
- Service `init` chạy một lần mỗi lần khởi động: tạo `APP_KEY` và `HEALTH_TOKEN` (lưu trong volume `storage`, file
  `storage/app/docker.env`), migrate, seed quyền và gói, tạo super admin từ `ADMIN_EMAIL`/`ADMIN_PASSWORD` (chỉ lần đầu).
  Sau lần đầu có thể xóa `ADMIN_PASSWORD` khỏi `.env`.
- Không điền `ADMIN_*` thì tạo super admin bằng: `docker compose -p banhang exec app php artisan ecso:install --skip-env-check`.
- Trình cài đặt trên web (`/install`) không dùng với Docker.

## Tên miền và HTTPS

App nghe ở `127.0.0.1:8090` (đổi bằng `HTTP_PORT`). Trên aaPanel: Website → thêm `banhang.ezchat.vn` → Reverse proxy tới
`http://127.0.0.1:8090`, bật SSL. Giữ `SESSION_SECURE_COOKIE=true` khi chạy HTTPS.

## Cập nhật

```bash
cd /www/wwwroot/banhang && git pull origin main && docker compose -p banhang up -d --build
```

`init` tự migrate trước khi web, Horizon, scheduler chạy bản mới. Horizon được 120 giây để làm xong job đang chạy.

## Vận hành

| Việc | Lệnh |
|---|---|
| Xem log | `docker compose -p banhang logs -f app horizon scheduler` |
| Lệnh artisan | `docker compose -p banhang exec app php artisan <lệnh>` |
| Kiểm tra sổ kho | `docker compose -p banhang exec app php artisan ecso:inventory:verify` |
| Sức khỏe | `curl http://127.0.0.1:8090/health` và `/health/ready?token=<HEALTH_TOKEN>` |
| Sao lưu DB tay | `docker compose -p banhang exec mysql sh -c 'mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction ecso' \| gzip > ecso-$(date +%F).sql.gz` |

- Sao lưu tự động `ecso:backup` (01:30) vẫn chạy trong scheduler, ghi vào volume `storage` (`storage/app/backups`). Nên chép
  thư mục này ra ngoài server.
- Dữ liệu nằm trong 3 volume: `banhang_mysqldata`, `banhang_redisdata`, `banhang_storage` (ảnh sản phẩm, bản sao lưu, khóa
  APP_KEY). **Không xóa** các volume này; mất `banhang_storage` là mất APP_KEY, dữ liệu mã hóa (token sàn) không đọc lại được.

## Hiệu năng

- OPcache bật, không kiểm tra lại file (code chỉ đổi khi build image mới), JIT tracing.
- PHP-FPM: `PHP_FPM_MAX_CHILDREN` (mặc định 16, khoảng 60 MB mỗi tiến trình). MySQL: `MYSQL_BUFFER_POOL` (mặc định 512M).
- Session, cache, hàng đợi dùng Redis. Config, route, view, event được cache khi container khởi động.
- Image chạy Ubuntu 24.04 LTS, PHP 8.3 từ kho Ubuntu (vá bảo mật đến 2029).
