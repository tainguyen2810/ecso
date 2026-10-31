# Release checklist

> Phase 8 (2026-10-31). Làm theo thứ tự cho mỗi lần phát hành production. Đánh dấu và lưu lại vào ghi chú release.

## A. Trước khi phát hành
- [ ] CI xanh trên commit sẽ phát hành, đủ các bước: Pint, PHPStan, `composer audit`, Pest, concurrency, `npm audit --omit=dev`, typecheck, Vitest, build, Playwright.
- [ ] CHANGELOG đã cập nhật, và migration tương thích ngược: chỉ thêm cột hoặc bảng; việc xóa cột để sang release sau.
- [ ] Đã chạy release trên staging; đã chạy load test (LOAD-TEST.md) nếu thay đổi chạm vào đơn, tồn kho hoặc webhook.
- [ ] Đã backup DB ngay trước khi deploy (`scripts/backup-db.sh`) và kiểm tra checksum.
- [ ] Thông báo bảo trì cho khách nếu có migration dài.

## B. Cấu hình production (`php artisan ecso:check-env --production` phải đạt)
- [ ] `APP_ENV=production`, `APP_DEBUG=false`, và `APP_URL` dùng https.
- [ ] `.env` có quyền 600, thuộc user chạy PHP. Không có thư mục 777.
- [ ] `QUEUE_CONNECTION=redis`. Horizon và scheduler chạy bằng Supervisor (`deploy/supervisor/*.conf`), không chạy bằng phiên SSH.
- [ ] `FAKE_MARKETPLACE_ENABLED=false` và `ECSO_ADMIN_2FA=true`.
- [ ] `SESSION_SECURE_COOKIE=true` và `SESSION_ENCRYPT=true`.
- [ ] `LOG_STACK=daily,slack`, `LOG_LEVEL=info`, và `LOG_SLACK_WEBHOOK_URL` đã đặt (cảnh báo từ `ecso:monitor`).
- [ ] `HEALTH_TOKEN` đã đặt; uptime monitor gọi `/health` (công khai) và `/health/ready` (có header token).
- [ ] `BILLING_BANK_*` đúng tài khoản nhận tiền.
- [ ] Credentials sàn chỉ khai báo khi connector đã VERIFIED. Không khai báo sớm, vì kết nối vẫn sẽ trả về UNKNOWN.

## C. Deploy
- [ ] `scripts/deploy.sh <build.tar.gz>` chạy bằng user `www`. Script tự kiểm tra môi trường, migrate, cache, đổi symlink, `horizon:terminate`, gọi health check, và tự rollback nếu health check lỗi.
- [ ] Theo dõi trang **Giám sát hệ thống** 15 phút: backlog, job lỗi, scheduler heartbeat, webhook.

## D. Sau khi phát hành
- [ ] Đăng nhập thử: tenant demo nội bộ và super admin (qua 2FA).
- [ ] Kiểm tra `php artisan ecso:inventory:verify`.
- [ ] Ghi lại phiên bản đã deploy, người deploy, thời điểm.
- [ ] Nếu có sự cố, rollback bằng cách trỏ `current` về release trước rồi chạy `horizon:terminate`. Nếu migration hỏng dữ liệu, restore bằng `scripts/restore-db.sh` theo hướng dẫn trong DEPLOYMENT-AAPANEL.md §8.

## E. Định kỳ
- [ ] Hằng ngày: backup tự động thành công (kiểm tra file mới, có checksum, đã chép ra ngoài server).
- [ ] Hằng quý: diễn tập restore vào DB riêng. `restore-db.sh` tự chạy kiểm tra sổ kho; ghi kết quả vào bảng diễn tập.
- [ ] Hằng tháng: `composer audit`, `npm audit`, cập nhật bảo mật OS/PHP/MySQL; rà soát danh sách super admin.
