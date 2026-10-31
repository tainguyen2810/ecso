# ECSO

Nền tảng SaaS quản lý bán hàng đa sàn (Shopee, TikTok Shop, Lazada, Tiki) cho người bán tại Việt Nam.

Stack: Laravel 12 (PHP 8.3), Vue 3 + TypeScript, Tailwind 4, MySQL 8, Redis, Horizon, Sanctum. Kiến trúc modular monolith, triển khai trên Ubuntu + aaPanel.

## Trạng thái

| Phase | Nội dung | Trạng thái |
|---|---|---|
| 0 | Discovery & Architecture | ✅ [báo cáo](docs/PHASE-0-REPORT.md) |
| 1 | SaaS Foundation: auth, multi-tenant, RBAC, dashboard | ✅ [báo cáo](docs/PHASE-1-REPORT.md) |
| 2 | Catalog & Inventory | ⏳ |

## Chạy local

```bash
composer install && npm ci
cp .env.example .env && php artisan key:generate   # điền DB_*, REDIS_*
php artisan ecso:install                            # kiểm tra môi trường, migrate, seed, tạo super admin
php artisan db:seed --class=DemoSeeder              # tuỳ chọn: dữ liệu demo
npm run build && php artisan serve
```

Test: `./vendor/bin/pest` (MySQL 8, DB `ecso_test`), `npm test`, `npx playwright test`. Chi tiết ở [docs/TESTING.md](docs/TESTING.md).

## Tài liệu

| File | Nội dung |
|---|---|
| [ARCHITECTURE](docs/ARCHITECTURE.md) | Kiến trúc, ADR, module, connector |
| [DATABASE](docs/DATABASE.md) / [ERD](docs/ERD.md) | Thiết kế CSDL |
| [API-CAPABILITY-MATRIX](docs/API-CAPABILITY-MATRIX.md) | Khả năng API từng sàn |
| [INVENTORY-ENGINE](docs/INVENTORY-ENGINE.md) | Lõi tồn kho |
| [ORDER-LIFECYCLE](docs/ORDER-LIFECYCLE.md) | Vòng đời đơn hàng |
| [MULTI-TENANCY](docs/MULTI-TENANCY.md) | Multi-tenant, RBAC, entitlement |
| [SECURITY](docs/SECURITY.md) | Bảo mật |
| [TESTING](docs/TESTING.md) | Kiểm thử |
| [DEPLOYMENT-AAPANEL](docs/DEPLOYMENT-AAPANEL.md) | Triển khai |
| [ROADMAP](docs/ROADMAP.md) | Lộ trình, phụ thuộc, rủi ro |
| [CHANGELOG](docs/CHANGELOG.md) / [HANDOVER](docs/HANDOVER.md) | Thay đổi, bàn giao |
