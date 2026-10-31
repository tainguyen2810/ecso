# Handover

> Cập nhật sau mỗi phase. Lần cập nhật: Phase 8, 2026-10-31.

## Trạng thái hiện tại

- Phase hoàn thành: **Phase 0 (tài liệu)**, **Phase 1 (SaaS Foundation)** – PHASE-1-REPORT.md, **Phase 2 (Catalog & Inventory)** – PHASE-2-REPORT.md, **Phase 3 (Order Engine)** – PHASE-3-REPORT.md, **Phase 4/5 (khung Shopee/TikTok Shop, chưa tích hợp thật)**, **Phase 6 (SaaS Commercial)** – PHASE-6-REPORT.md, **Phase 7 (khung Lazada/Tiki)**, **Phase 8 (Hardening)** – PHASE-8-REPORT.md. Tổng hợp: FINAL-REPORT.md.
- Sàn giả lập: bật bằng `FAKE_MARKETPLACE_ENABLED=true` (chỉ local/staging). Production phải để `false`.
- Lệnh định kỳ mới: `ecso:marketplace:push-due` (mỗi phút), `ecso:orders:reconcile` (15 phút). Worker cần thêm queue `webhooks`.
- Lệnh định kỳ Phase 6: `ecso:billing:sweep` (mỗi giờ). Xác nhận chuyển khoản tại `/admin/billing`.
- Super admin bắt buộc 2FA. Tài khoản demo `admin@demo.test` dùng secret cố định `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP` (chỉ dữ liệu demo); `security@demo.test` cho thấy màn hình cài đặt lần đầu.
- Mất cả điện thoại lẫn mã khôi phục của super admin: trên server chạy `php artisan tinker --execute="App\Modules\Identity\Models\User::where('email','…')->update(['two_factor_secret'=>null,'two_factor_recovery_codes'=>null,'two_factor_confirmed_at'=>null]);"` rồi người đó cài lại 2FA ở lần đăng nhập sau (ghi vào sổ vận hành).
- Vận hành production: RELEASE-CHECKLIST.md, DEPLOYMENT-AAPANEL.md (deploy, backup/restore, giám sát), LOAD-TEST.md.
- Kiểm tra sổ kho bất kỳ lúc nào: `php artisan ecso:inventory:verify`.
- Repository: `tainguyen2810/ecso`.
- Tài liệu nằm tại `docs/` của repo.

## Việc chủ dự án cần làm

1. ~~Tạo repository GitHub~~ Đã có: `tainguyen2810/ecso` (2026-10-31).
2. Đăng ký tài khoản developer: Shopee Open Platform, TikTok Shop Partner Center (VN), Lazada Open Platform, Tiki Open Platform. Tạo app loại phục vụ nhiều seller.
3. Chuẩn bị test shop/sandbox cho từng sàn.
4. ~~Quyết định cổng thanh toán~~ Đã chọn chuyển khoản + admin xác nhận. Còn: tên miền, nhà cung cấp email, giá bán chính thức của các gói (giá hiện là tạm), thông tin xuất hóa đơn VAT.
5. Điền `BILLING_BANK_NAME`, `BILLING_BANK_ACCOUNT_NUMBER`, `BILLING_BANK_ACCOUNT_NAME`, `BILLING_BANK_BRANCH` trên server.

## Chạy local

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate   # sửa DB_*, REDIS_* trong .env
php artisan ecso:install                            # check-env, migrate, seed quyền + gói, tạo super admin
php artisan db:seed --class=DemoSeeder              # (tuỳ chọn) dữ liệu demo
php artisan storage:link                            # ảnh sản phẩm
npm run build && php artisan serve                  # http://localhost:8000 , admin: /admin
```
Môi trường dev không có worker thì đặt `QUEUE_CONNECTION=sync` (email mời nhân viên là job queue). Email ở môi trường dev ghi vào `storage/logs/laravel.log` (`MAIL_MAILER=log`).

## Thông tin vận hành

Chưa có môi trường production/staging. Khi có server: làm theo RELEASE-CHECKLIST.md, chạy load test k6 trên staging, đặt cron backup + chép ra ngoài server, cấu hình Slack webhook cho cảnh báo.

## Credentials cần (không gửi qua chat, nhập vào `.env` trên server/CI secrets)

| Biến | Dùng cho | Phase |
|---|---|---|
| `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY` | Shopee | 4 |
| `TIKTOK_SHOP_APP_KEY`, `TIKTOK_SHOP_APP_SECRET` | TikTok Shop | 5 |
| `LAZADA_APP_KEY`, `LAZADA_APP_SECRET` | Lazada | 7 |
| `TIKI_CLIENT_ID`, `TIKI_CLIENT_SECRET` | Tiki | 7 |
| `BILLING_BANK_*` (tài khoản nhận tiền, không phải bí mật) | Billing chuyển khoản | 6 |
| `HEALTH_TOKEN`, `LOG_SLACK_WEBHOOK_URL`, passphrase backup (`BACKUP_ENCRYPTION_KEY_FILE`) | Vận hành | 8 |
| Khóa cổng thanh toán online (VNPay/PayOS/MoMo) | Chưa dùng — cắm thêm khi có hợp đồng | sau MVP |
