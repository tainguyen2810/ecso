# Kiến trúc hệ thống

> Phiên bản: Phase 0 (2026-10-31). Trạng thái: **đề xuất, chờ duyệt**.

## 1. Bối cảnh và hiện trạng

- Repository: `tainguyen2810/ecso` (tạo 2026-10-31). Phase 1 đã khởi tạo Laravel 12.70 + Vue 3.
- Môi trường build có sẵn: PHP 8.3.6, Node 22, Redis 7. MySQL 8 sẽ cài khi chạy test tích hợp (Phase 1).
- Mục tiêu triển khai: Ubuntu + aaPanel + Nginx + PHP-FPM 8.3 + MySQL 8 + Redis, **không Docker bắt buộc**.

## 2. Quyết định kiến trúc chính (ADR tóm tắt)

| # | Quyết định | Lý do | Phương án đã loại |
|---|---|---|---|
| ADR-01 | **Modular Monolith** Laravel 12, một codebase, một DB | Một người/nhóm nhỏ vận hành, deploy trên aaPanel, transaction tồn kho cần ACID trong một DB | Microservices (chi phí vận hành, transaction phân tán) |
| ADR-02 | **Single database, shared schema, cột `tenant_id`** | Rẻ, dễ backup, dễ migrate; đủ cho hàng nghìn tenant | DB-per-tenant (migrate N DB, khó trên aaPanel) |
| ADR-03 | **Vue 3 SPA + REST API `/api/v1`**, Laravel phục vụ SPA, auth bằng **Sanctum SPA cookie** (+ token cho API ngoài) | Đúng yêu cầu REST versioned, frontend tách biệt, cùng domain nên không phải lo CORS/CSRF phức tạp | Inertia (gắn chặt view với controller, khó mở API công khai) |
| ADR-04 | Khóa chính `BIGINT` nội bộ + `ulid` công khai cho mọi entity lộ ra API | Join nhanh; không lộ số thứ tự, giảm IDOR đoán ID | UUID làm PK (index lớn, chậm insert) |
| ADR-05 | **Redis + Horizon** cho queue, cache, lock, rate limit | Đã có trong stack, một dịch vụ cho nhiều việc | RabbitMQ/Kafka |
| ADR-06 | **Ledger tồn kho bất biến** (`stock_movements`) + bảng số dư (`stock_balances`) cập nhật trong cùng transaction với `SELECT ... FOR UPDATE` | Chính xác, kiểm toán được, tái tạo được số dư | Chỉ lưu số dư (mất lịch sử), event sourcing thuần (phức tạp) |
| ADR-07 | **Inbox pattern** cho webhook (lưu thô trước, xử lý sau) và **Outbox pattern** cho đẩy tồn kho lên sàn | Không mất sự kiện, idempotent, retry được | Xử lý webhook đồng bộ trong request |
| ADR-08 | Connector theo **Ports & Adapters**: `MarketplaceConnectorInterface` + khai báo capability | Thêm sàn không đụng nghiệp vụ lõi; dễ có Fake adapter để test | Gọi SDK sàn trực tiếp trong service |
| ADR-09 | Realtime: **polling nhẹ + SSE** ở MVP; Reverb chỉ khi cần (Phase 8 trở đi) | Tiết kiệm tài nguyên; aaPanel không phải chạy thêm websocket server | Reverb ngay từ đầu |
| ADR-10 | Quyền theo **permission**, gói theo **entitlement** (feature + limit), không hardcode tên vai trò/tên gói trong logic | Thương mại hóa linh hoạt | `if ($plan === 'pro')` |

## 3. Sơ đồ tổng thể

```
               ┌────────────── Nginx (HTTPS) ───────────────┐
 Browser ─────►│  /         → SPA (Vue 3, build tĩnh)        │
 (Vue SPA)     │  /api/v1/* → PHP-FPM (Laravel)              │
               │  /webhooks/{marketplace} → PHP-FPM          │
               └──────────────┬─────────────────────────────┘
                              │
 Marketplace ──webhook──►  WebhookController ──► webhook_events (inbox, raw)
 (Shopee/TikTok/Lazada)                         │
                                                ▼ dispatch
 Tiki event queue ◄──poll── PollEventsJob   ┌── Redis / Horizon ───────────────┐
 Polling (orders) ◄──poll── SyncOrdersJob   │ queues: webhooks, orders,        │
                                            │ inventory, sync-shopee, sync-... │
                                            └──────┬───────────────────────────┘
                                                   ▼
        ┌────────────── Laravel modules (domain services) ───────────────┐
        │ Orders → Inventory (reserve/release) → inventory_sync_outbox   │
        │                         │                                       │
        │                         ▼ PushInventoryJob                      │
        │                Marketplace connectors ───► API sàn              │
        └─────────────────────────┬──────────────────────────────────────┘
                                  ▼
                           MySQL 8 (InnoDB)
```

Tiến trình trên server (Supervisor/systemd): `php-fpm`, `horizon` (1 tiến trình quản lý nhiều worker), `schedule:work` hoặc cron `schedule:run` mỗi phút.

## 4. Cấu trúc module

```
app/
  Modules/
    Identity/        # User, đăng ký, đăng nhập, xác minh email, 2FA (sau MVP)
    Tenancy/         # Tenant, TenantUser, TenantContext, chuyển tenant, mời nhân viên
    Access/          # Roles, permissions, policies, gates
    Billing/         # Plans, features, entitlements, subscriptions, payments, invoices, usage
    Catalog/         # Products, variants (SKU), barcode, category, brand, images, import/export
    Inventory/       # Warehouses, balances, movements, reservations, adjustments, transfers, allocations
    Marketplace/     # Accounts, credentials, listings, mappings, Connector contracts
      Connectors/
        Shopee/ TikTokShop/ Lazada/ Tiki/ Fake/
    Sync/            # sync_jobs, attempts, cursors, outbox, reconciliation, circuit breaker
    Webhooks/        # inbox, verify, normalize, replay protection
    Orders/          # Orders, items, status mapping, history, assignment, notes, SLA
    Fulfillment/     # Shipments, packing slip, shipping label
    Purchasing/      # Suppliers, purchase orders, goods receipts, cost
    Returns/         # Customer returns, inspection, restock, refunds, supplier returns
    Finance/         # Fees, settlements, cost snapshots, profit
    Reporting/       # Dashboard aggregates
    Notifications/   # In-app, email
    Audit/           # Audit log, support access
    Admin/           # Super admin: tenants, plans, flags, system health
  Shared/            # Money, Quantity, Ulid, Result, exceptions, base classes
```

Mỗi module có cấu trúc giống nhau:

```
Modules/Inventory/
  Domain/        # Enum, Value Object, quy tắc thuần (không phụ thuộc framework nhiều)
  Models/        # Eloquent models
  Services/      # InventoryService, ReservationService ... (điểm vào duy nhất để ghi)
  Actions/       # Use case đơn lẻ
  Http/          # Controllers, FormRequests, Resources (API v1)
  Policies/
  Jobs/
  Events/ Listeners/
  Database/      # migrations, factories, seeders của module
  Routes/api.php
  Tests/         # Unit + Feature
  ModuleServiceProvider.php
```

**Quy tắc phụ thuộc giữa module**
- Module chỉ gọi module khác qua **Service public** hoặc **Event**, không truy cập trực tiếp Model của module khác để ghi.
- `Inventory` không biết gì về sàn; `Orders` gọi `InventoryService`; `Marketplace` nghe event `AvailableStockChanged` để ghi outbox.
- Kiểm tra bằng test kiến trúc (Pest `arch()`).

Frontend:

```
resources/js/
  apps/tenant/   # Tenant Dashboard (router, layouts, pages)
  apps/admin/    # Super Admin (bundle riêng, route guard riêng)
  shared/        # components (shadcn-vue), composables, api client, types
```

Hai SPA build thành hai entry Vite riêng (`/app`, `/admin`) để phân tách quyền và giảm bundle.

## 5. Luồng chính

### 5.1 Request có tenant
1. SPA gửi header `X-Tenant: <tenant_ulid>` (chỉ là **bộ chọn**).
2. Middleware `ResolveTenant` kiểm tra user có membership `active` trong tenant đó, nạp `TenantContext` (tenant, role, permissions, entitlements).
3. Global scope `TenantScope` tự thêm `where tenant_id = ?` cho mọi model `BelongsToTenant`; khi tạo mới, `tenant_id` lấy từ `TenantContext`, **mọi `tenant_id` trong payload bị bỏ qua**.
4. Policy kiểm tra permission + quyền sở hữu bản ghi.

### 5.2 Webhook / Polling
Xem [ORDER-LIFECYCLE.md](ORDER-LIFECYCLE.md). Tenant được xác định từ `marketplace_accounts` (shop_id của sàn → tenant), không bao giờ từ payload tự khai.

### 5.3 Đẩy tồn kho
Xem [INVENTORY-ENGINE.md](INVENTORY-ENGINE.md) §7.

## 6. Connector

```php
interface MarketplaceConnectorInterface
{
    public function marketplace(): Marketplace;            // enum
    public function capabilities(): CapabilitySet;          // khai báo tĩnh + theo account

    public function authorizationUrl(AuthContext $ctx): string;      // authorize()
    public function exchangeToken(string $code, AuthContext $ctx): TokenSet;
    public function refreshToken(MarketplaceAccount $acc): TokenSet;

    public function getShops(MarketplaceAccount $acc): array;
    public function getProducts(MarketplaceAccount $acc, ?Cursor $c): Page;
    public function getProductVariants(MarketplaceAccount $acc, string $externalProductId): array;
    public function getOrders(MarketplaceAccount $acc, OrderQuery $q, ?Cursor $c): Page;
    public function getOrderDetails(MarketplaceAccount $acc, array $externalOrderIds): array;
    public function getInventory(MarketplaceAccount $acc, array $externalSkuIds): array;
    public function updateInventory(MarketplaceAccount $acc, array $updates): InventoryUpdateResult;
    public function getShippingInfo(MarketplaceAccount $acc, string $externalOrderId): ShippingInfo;

    public function verifyWebhook(WebhookRequest $req): bool;
    public function normalizeWebhook(WebhookRequest $req): array; // NormalizedEvent[]
}
```

- Method không hỗ trợ ném `UnsupportedCapabilityException` (hoặc trả `Result::unsupported()`), **không bao giờ trả thành công giả**.
- Mỗi connector có: `HttpClient` riêng (ký request, timeout, retry backoff + jitter), `RateLimiter` (Redis token bucket theo account + endpoint), `CircuitBreaker` (Redis, theo marketplace + account), `ErrorMapper` → `MarketplaceError` chuẩn (`AUTH_EXPIRED`, `RATE_LIMITED`, `VALIDATION`, `NOT_FOUND`, `TEMPORARY`, `PERMANENT`).
- `FakeConnector` dùng cho test tự động và môi trường dev; **không bao giờ** được bật ở production (guard trong ServiceProvider).
- Log request/response qua `RedactingLogger` che `access_token`, `refresh_token`, `sign`, số điện thoại, địa chỉ.

## 7. Phi chức năng

| Hạng mục | Mục tiêu MVP |
|---|---|
| Độ trễ đồng bộ tồn kho | p95 < 60 giây từ lúc reservation đến lúc gọi API sàn (phụ thuộc rate limit sàn) |
| API nội bộ | p95 < 300 ms cho danh sách có phân trang |
| Phân trang | Cursor (keyset) cho danh sách lớn (đơn, movement) |
| Quy mô thiết kế | 1.000 tenant, 50.000 đơn/ngày trên một VPS 8 vCPU / 16 GB |
| Backup | Dump DB hằng ngày + binlog; storage hằng ngày; RPO 24h (MVP), RTO 4h |

## 8. Quyết định còn mở

1. Cổng thanh toán cho subscription (VNPay, MoMo, PayOS, chuyển khoản thủ công?) – cần anh/chị chọn trước Phase 6.
2. Tên miền và cách tách subdomain admin (`admin.` hay `/admin`).
3. Có cần xuất hóa đơn điện tử VAT cho khách SaaS không (ảnh hưởng Billing).
4. Dịch vụ email giao dịch (SMTP riêng, Amazon SES, Mailgun...).

## 9. Ghi chú hiện thực (Phase 1)

Những điểm hiện thực khác hoặc cụ thể hơn so với thiết kế ở trên:

| Hạng mục | Hiện thực | Lý do |
|---|---|---|
| Migrations | Đặt chung ở `database/migrations/` (không chia theo module) | Thứ tự chạy rõ ràng, quen thuộc khi deploy trên aaPanel |
| User model | `App\Modules\Identity\Models\User` | Giữ cấu trúc module |
| Route | Mỗi module có `Routes/api.php`, `ModuleServiceProvider` gắn vào `/api/v1` | |
| Tenant context | `TenantContext` (scoped singleton) + middleware `tenant` (header `X-Tenant`), chạy **trước** route model binding (middleware priority) | Model binding đã nằm trong scope tenant |
| Fail-closed | `TenantScope` ném `NoTenantContext` khi truy vấn model tenant mà chưa có context | Thà lỗi còn hơn trả dữ liệu mọi tenant |
| Bỏ scope có chủ đích | Đánh dấu `@tenant-bypass` (membership của user, tra lời mời theo token, Admin) | Dễ grep khi audit |
| UI components | Bộ component tự viết theo phong cách shadcn-vue (cva + tailwind-merge) trong `resources/js/shared/ui` | CLI shadcn-vue cần tải registry từ internet; bộ tự viết không phụ thuộc |
| Hai SPA | `resources/js/apps/tenant` (đường dẫn `/`) và `resources/js/apps/admin` (`/admin`), hai entry Vite | Phân tách quyền và bundle |
| Lệnh vận hành | `ecso:check-env`, `ecso:install` | |
| Health | `/health` (công khai), `/health/ready` (cần header `X-Health-Token`) | |
| Phím tắt | `g` rồi `d/o/p/i/m/s` để chuyển trang | |
