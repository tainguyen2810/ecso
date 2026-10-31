# Cài ECSO trên aaPanel (từng bước, có trình cài đặt trên web)

> Dành cho người cài lần đầu trên một VPS. Cách làm giống WordPress: tải gói cài đặt, upload lên aaPanel, mở tên miền, rồi làm theo trình cài đặt.
> Nếu muốn deploy không gián đoạn (mỗi lần cập nhật là một thư mục release), xem thêm DEPLOYMENT-AAPANEL.md.

## 0. Cần chuẩn bị
- VPS Ubuntu 22.04 hoặc 24.04, tối thiểu 2 vCPU / 4 GB RAM.
- Một tên miền đã trỏ về IP của VPS (bản ghi A).
- aaPanel đã cài trên VPS (theo hướng dẫn trên trang aapanel.com).
- Gói cài đặt `ecso-vX.Y.Z.zip`, tải ở mục **Releases** của repo GitHub. Gói này đã có sẵn `vendor/` và giao diện build sẵn, nên server không cần Composer hay Node.

## 1. Cài phần mềm trong App Store của aaPanel
Cài đủ năm thứ sau:
- **Nginx** (bản mới nhất).
- **MySQL 8.0**. Không chọn MariaDB: ECSO cần MySQL 8.
- **PHP 8.3**.
- **Redis**. Không bắt buộc nhưng nên có khi chạy thật.
- **Supervisor Manager**, để chạy tiến trình nền.

## 2. Cấu hình PHP 8.3
Vào App Store → PHP 8.3 → **Setting**.

1. **Install extensions**: cài `fileinfo`, `intl`, `redis`, `opcache`. Những extension còn lại thường đã có sẵn.
2. **Disabled functions**: xóa các hàm sau khỏi danh sách:
   - `putenv`
   - `proc_open`
   - `symlink`
   - `pcntl_signal`, `pcntl_alarm` và các hàm `pcntl_*` khác (Horizon cần)
3. **Configuration**: đặt `upload_max_filesize` và `post_max_size` = 20M.

Sau đó **Restart** PHP 8.3.

## 3. Tạo database
Vào **Databases** → **Add database**:
- Tên database: ví dụ `ecso`.
- User: ví dụ `ecso`. Đặt mật khẩu mạnh và **ghi lại**, vì trình cài đặt sẽ hỏi.
- Charset: `utf8mb4`. Permission: `Local server`.

Để database **trống**. Trình cài đặt sẽ tự tạo bảng.

## 4. Tạo website
Vào **Website** → **Add site**:
- Domain: tên miền của bạn.
- PHP version: 8.3.
- Database: không tạo thêm (đã tạo ở bước 3).

## 5. Upload mã nguồn
1. Vào **Files**, mở `/www/wwwroot/<tên-miền>/`. Xóa các file mặc định aaPanel tạo sẵn (`index.html`, `404.html`, `.htaccess`). Giữ lại `.user.ini` nếu có.
2. Upload `ecso-vX.Y.Z.zip` vào thư mục này rồi **Unzip**.
3. Chuyển toàn bộ nội dung thư mục `ecso-vX.Y.Z/` ra ngoài, sao cho file `artisan` nằm đúng tại `/www/wwwroot/<tên-miền>/artisan`.
4. **Không** tạo file `.env`. Trình cài đặt sẽ tự tạo.

## 6. Cấu hình site
Vào Website → bấm tên site:

1. **Site directory**:
   - **Running directory** = `/public` → Save.
   - Nếu sau khi cài mà gặp lỗi `open_basedir`, tắt **Anti-XSS attack (open_basedir)**.
2. **URL rewrite**: chọn mẫu `laravel5` → Save.
3. **SSL**:
   - Chọn **Let's Encrypt**, tích tên miền → Apply.
   - Bật **Force HTTPS**.
4. **Quyền thư mục**: vào Files, chọn thư mục `/www/wwwroot/<tên-miền>` → **Permission**:
   - Owner: `www`, quyền `755`.
   - Tích **Apply to subdirectories**.
   - **Không dùng 777.**

## 7. Chạy trình cài đặt trên web
Mở `https://<tên-miền>`. Mọi trang sẽ tự chuyển đến `/install`.

| Bước | Việc cần làm |
|---|---|
| 1. Mã cài đặt | Vào **Files** của aaPanel, mở file `storage/app/install-token.txt` trong thư mục site, sao chép mã 32 ký tự rồi dán vào. Mã này để người lạ không cài đặt thay bạn khi site vừa upload xong. |
| 2. Kiểm tra máy chủ | Mọi dòng bắt buộc phải có dấu tích xanh. Dòng nào đỏ thì sửa theo gợi ý hiện ngay dưới dòng đó (ví dụ cài extension, sửa quyền thư mục), rồi bấm **Kiểm tra lại**. |
| 3. Database | Nhập host `127.0.0.1`, cổng `3306`, tên DB, user và mật khẩu đã tạo ở bước 3 → **Thử kết nối**. |
| 4. Redis | Nếu đã cài Redis: host `127.0.0.1`, cổng `6379`, mật khẩu (xem trong App Store → Redis → Setting) → **Thử kết nối**. Nếu không có Redis, chọn **Không dùng Redis**. |
| 5. Website | Tên hệ thống, địa chỉ `https://<tên-miền>`. Thông tin tài khoản ngân hàng nhận tiền thuê bao có thể nhập luôn hoặc sửa sau trong `.env`. |
| 6. Quản trị viên | Họ tên, email, mật khẩu (tối thiểu 12 ký tự, có cả chữ và số) → **Cài đặt**. |
| 7. Hoàn tất | Trang này hiện các lệnh chạy tiến trình nền, dùng cho bước 8. |

Trình cài đặt tạo bảng, tạo tài khoản quản trị, ghi file `.env` (chế độ production, tắt debug, quyền 600), rồi **tự khóa**. Sau khi khóa, mở `/install` chỉ còn thấy thông báo "đã cài đặt".

## 8. Tiến trình nền (bắt buộc)
Không có tiến trình nền thì đơn từ sàn, đồng bộ tồn kho và gia hạn gói đều không chạy.

Vào **Supervisor Manager** → **Add daemon**, tạo hai tiến trình theo các lệnh ở bước 7 của trình cài đặt:

| Name | Run user | Run dir | Start command |
|---|---|---|---|
| `ecso-horizon` (có Redis) hoặc `ecso-queue` (không có Redis) | `www` | `/www/wwwroot/<tên-miền>` | lệnh đầu tiên ở bước 7 |
| `ecso-scheduler` | `www` | `/www/wwwroot/<tên-miền>` | lệnh thứ hai ở bước 7 |

Ví dụ khi có Redis: `/www/server/php/83/bin/php /www/wwwroot/<tên-miền>/artisan horizon`.

## 9. Kiểm tra sau khi cài
Mở **Terminal** của aaPanel:

```bash
cd /www/wwwroot/<tên-miền>
sudo -u www php artisan ecso:check-env --production
```

Mọi dòng phải là `OK`. Các dòng "App credentials" của từng sàn sẽ là `CHƯA CÓ` cho đến khi bạn đăng ký app với sàn.

Đăng nhập lần đầu bằng tài khoản quản trị: hệ thống bắt buộc cài **xác thực hai bước**. Quét mã QR bằng Google Authenticator hoặc ứng dụng tương tự, rồi lưu 8 mã khôi phục.

## 10. Việc nên làm tiếp
- **Email gửi đi** (quên mật khẩu, mời nhân viên): sửa nhóm `MAIL_*` trong `.env`, sau đó chạy `sudo -u www php artisan config:clear`.
- **Kết nối sàn**: khi có app credentials, điền `SHOPEE_*`, `TIKTOK_SHOP_*`, `LAZADA_*`, `TIKI_*` vào `.env`.
- **Backup database**: vào aaPanel → **Cron** → Shell script, chạy hằng ngày lúc 01:30 bằng user `www`:
  ```
  /www/wwwroot/<tên-miền>/scripts/backup-db.sh /www/backup/ecso
  ```
  Chi tiết (mã hóa, restore) xem DEPLOYMENT-AAPANEL.md §8.
- **Cảnh báo Slack**: đặt `LOG_STACK=daily,slack` và `LOG_SLACK_WEBHOOK_URL` trong `.env`.

## 11. Cập nhật phiên bản mới (cách đơn giản)
1. Backup database.
2. Tải gói mới, giải nén ra thư mục tạm.
3. Chép đè vào thư mục site, **trừ** file `.env` và thư mục `storage/`.
4. Chạy trong terminal:
   ```bash
   cd /www/wwwroot/<tên-miền>
   sudo -u www php artisan down
   sudo -u www php artisan migrate --force
   sudo -u www php artisan optimize
   sudo -u www php artisan horizon:terminate   # hoặc: php artisan queue:restart (khi không dùng Redis)
   sudo -u www php artisan up
   ```

Để cập nhật không gián đoạn và tự rollback khi lỗi, dùng `scripts/deploy.sh` theo DEPLOYMENT-AAPANEL.md §6.

## 12. Sự cố thường gặp

| Hiện tượng | Cách xử lý |
|---|---|
| Trang trắng hoặc lỗi 500 | Xem `storage/logs/laravel-*.log`. Kiểm tra lại owner `www` của thư mục site. |
| Trình cài đặt báo "Không ghi được file .env" | Owner của thư mục site phải là `www` (bước 6.4). |
| Trình cài đặt báo extension thiếu | Cài extension trong PHP 8.3 → Setting → Install extensions, rồi Restart PHP. |
| Báo "Máy chủ là MariaDB" | Gỡ MariaDB, cài MySQL 8.0 trong App Store. |
| Lỗi 404 ở mọi trang trừ trang chủ | Chưa chọn URL rewrite `laravel5` (bước 6.2). |
| Đơn từ sàn không về, đồng bộ không chạy | Tiến trình Supervisor chưa chạy (bước 8). Xem trang **Admin → Giám sát hệ thống**. |
| Muốn cài lại từ đầu | Xóa file `.env` và `storage/app/installed.lock`, dùng một database trống, rồi mở lại `/install`. |
