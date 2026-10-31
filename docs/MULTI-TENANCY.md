# Multi-tenancy & RBAC

> Phase 0 (2026-10-31): **thiết kế**. Hiện thực ở Phase 1.

## 1. Mô hình

- Một database, cột `tenant_id` trên mọi bảng nghiệp vụ (ADR-02).
- Một user có nhiều tenant qua `tenant_users` (mỗi membership một role).
- Tenant hiện hành được chọn trên UI (switcher), gửi qua header `X-Tenant: <ulid>`; server **xác thực membership** mỗi request.

## 2. TenantContext

```php
final class TenantContext            // singleton theo request / theo job
{
    public function tenant(): Tenant;            // ném NoTenantContext nếu chưa đặt
    public function membership(): TenantUser;
    public function can(string $permission): bool;
    public function entitlements(): Entitlements;
    public static function run(Tenant $t, Closure $fn): mixed;  // cho job/command, khôi phục context sau khi chạy
}
```

- **HTTP**: middleware `ResolveTenant` (sau `auth:sanctum`) → tìm `tenant_users` active của user với tenant ulid; không có ⇒ 404 (không lộ tenant tồn tại).
- **Job**: mọi job nghiệp vụ implement `TenantAware` chứa `tenantId`; middleware job đặt context trước `handle()`, xóa sau. Job thiếu tenant ⇒ fail ngay.
- **Webhook**: tenant suy ra từ `marketplace_accounts` (server-side), không từ payload tự khai.
- **Console/Scheduler**: lặp qua tenant active, gọi `TenantContext::run()`.

## 3. Global scope và ghi dữ liệu

- Trait `BelongsToTenant`: thêm `TenantScope` (`where tenant_id = context`) và `creating` hook gán `tenant_id` từ context.
- Model có `tenant_id` mà không dùng trait ⇒ test kiến trúc fail.
- `tenant_id` nằm trong `$guarded`; FormRequest không bao giờ nhận `tenant_id`.
- Route model binding dùng `ulid` và đi qua scope ⇒ bản ghi tenant khác trả 404.
- Truy vấn bỏ scope (`withoutGlobalScope`) chỉ được phép trong module Admin/Sync có lý do, đánh dấu `@tenant-bypass` và có test.
- Validation `exists`/`unique` luôn thêm điều kiện tenant (rule helper `TenantRule::exists('products','id')`).

## 4. RBAC

- `permissions` seed theo module, ví dụ:
  `orders.view, orders.update, orders.assign, orders.print, products.view, products.manage, inventory.view, inventory.adjust, inventory.adjust.approve, inventory.transfer, purchasing.manage, returns.inspect, finance.view, reports.view, marketplace.connect, members.manage, billing.manage, settings.manage, audit.view`.
- Vai trò hệ thống (mặc định, tenant có thể tạo vai trò tùy chỉnh nếu gói cho phép):

| Permission nhóm | OWNER | ADMIN | MANAGER | WAREHOUSE | ORDER | ACCOUNTANT | VIEWER |
|---|---|---|---|---|---|---|---|
| Xem dữ liệu nghiệp vụ | ✓ | ✓ | ✓ | ✓ (kho, đơn) | ✓ (đơn, sp) | ✓ | ✓ |
| Xử lý đơn | ✓ | ✓ | ✓ | in/đóng gói | ✓ | | |
| Sản phẩm, mapping | ✓ | ✓ | ✓ | | | | |
| Điều chỉnh kho / duyệt | ✓ | ✓ | ✓ / ✓ | tạo phiếu | | | |
| Nhập hàng, kiểm hàng hoàn | ✓ | ✓ | ✓ | ✓ | | | |
| Tài chính, báo cáo | ✓ | ✓ | ✓ | | | ✓ | báo cáo |
| Kết nối sàn | ✓ | ✓ | | | | | |
| Nhân viên, phân quyền | ✓ | ✓ (không gán OWNER) | | | | | |
| Gói dịch vụ, thanh toán | ✓ | | | | | ✓ (xem hóa đơn) | |

- Laravel **Policies** cho từng model; Gate::before **không** cấp toàn quyền cho super admin trên dữ liệu tenant.
- Chống leo quyền: user chỉ gán được role có tập permission ⊆ permission của mình; không ai tự đổi role của mình; OWNER chỉ chuyển nhượng qua luồng riêng có xác nhận.

## 5. Entitlements (gói dịch vụ)

```php
$entitlements->allows('reports.advanced');      // boolean feature
$entitlements->limit('limit.shops');            // int|null (null = không giới hạn)
$usage->assertWithin('limit.shops');            // ném PlanLimitExceeded (HTTP 402/403 có mã lỗi)
```
- Giá trị = `plan_features` ⊕ `tenant_feature_overrides` (override thắng), cache Redis theo tenant, xóa cache khi đổi gói.
- Kiểm tra giới hạn tại điểm tạo: kết nối shop, mời user, tạo kho; đơn/tháng đếm bằng `usage_counters` (không chặn nhập đơn từ sàn khi vượt, mà cảnh báo + khóa tính năng xử lý sau grace period, vì chặn nhập đơn làm sai tồn kho).

## 6. Super Admin

- Ứng dụng riêng `/admin`, guard riêng, bắt buộc 2FA (Phase 8), IP allowlist tùy chọn.
- Thấy số liệu tổng hợp (số đơn, số shop, MRR, lỗi) — **không** thấy chi tiết đơn/khách hàng của tenant.
- Xem dữ liệu tenant chỉ qua `support_access_sessions`: có lý do, thời hạn, phạm vi; tenant owner chấp thuận (hoặc cờ khẩn cấp có ghi nhận); mọi truy cập ghi `audit_logs.support_session_id`.
