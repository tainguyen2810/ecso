# ECSO

Nền tảng SaaS quản lý bán hàng đa sàn (Shopee, TikTok Shop, Lazada, Tiki) cho người bán tại Việt Nam.

Stack dự kiến: Laravel 12 (PHP 8.3), Vue 3 + TypeScript, Tailwind, MySQL 8, Redis, Horizon. Kiến trúc modular monolith, triển khai trên Ubuntu + aaPanel.

## Trạng thái

- Phase 0 (Discovery & Architecture): hoàn thành tài liệu, chưa có code. Xem [docs/PHASE-0-REPORT.md](docs/PHASE-0-REPORT.md).

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
