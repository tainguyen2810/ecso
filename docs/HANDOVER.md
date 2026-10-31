# Handover

> Cập nhật sau mỗi phase. Lần cập nhật: Phase 3, 2026-10-31.

## Trạng thái hiện tại

- Phase hoàn thành: **Phase 0 (tài liệu)**, **Phase 1 (SaaS Foundation)** – PHASE-1-REPORT.md, **Phase 2 (Catalog & Inventory)** – PHASE-2-REPORT.md, **Phase 3 (Order Engine)** – PHASE-3-REPORT.md.
- Sàn giả lập: bật bằng `FAKE_MARKETPLACE_ENABLED=true` (chỉ local/staging). Production phải để `false`.
- Lệnh định kỳ mới: `ecso:marketplace:push-due` (mỗi phút), `ecso:orders:reconcile` (15 phút). Worker cần thêm queue `webhooks`.
- Kiểm tra sổ kho bất kỳ lúc nào: `php artisan ecso:inventory:verify`.
- Repository: `tainguyen2810/ecso`.
- Tài liệu nằm tại `docs/` của repo.

## Việc chủ dự án cần làm

1. ~~Tạo repository GitHub~~ Đã có: `tainguyen2810/ecso` (2026-10-31).
2. Đăng ký tài khoản developer: Shopee Open Platform, TikTok Shop Partner Center (VN), Lazada Open Platform, Tiki Open Platform. Tạo app loại phục vụ nhiều seller.
3. Chuẩn bị test shop/sandbox cho từng sàn.
4. Quyết định: cổng thanh toán, tên miền, nhà cung cấp email.

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

Chưa có môi trường production. Xem DEPLOYMENT-AAPANEL.md khi triển khai.

## Credentials cần (không gửi qua chat, nhập vào `.env` trên server/CI secrets)

| Biến | Dùng cho | Phase |
|---|---|---|
| `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY` | Shopee | 4 |
| `TIKTOK_APP_KEY`, `TIKTOK_APP_SECRET` | TikTok Shop | 5 |
| `LAZADA_APP_KEY`, `LAZADA_APP_SECRET` | Lazada | 7 |
| `TIKI_CLIENT_ID`, `TIKI_CLIENT_SECRET` | Tiki | 7 |
| Payment gateway keys | Billing | 6 |
