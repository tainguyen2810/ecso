# Thiết kế cơ sở dữ liệu

> MySQL 8, InnoDB, `utf8mb4_0900_ai_ci`. Phase 0 (2026-10-31): **đề xuất**. Migrations sẽ được viết theo phase trong ROADMAP.

## 1. Quy ước

- PK: `id BIGINT UNSIGNED AUTO_INCREMENT`. Entity lộ ra API có thêm `ulid CHAR(26) UNIQUE`.
- Bảng nghiệp vụ có `tenant_id BIGINT UNSIGNED NOT NULL` + FK `tenants(id)`; **mọi index nghiệp vụ bắt đầu bằng `tenant_id`**.
- Tiền: `DECIMAL(18,2)` + `currency CHAR(3)` (mặc định VND). Giá vốn: `DECIMAL(18,4)`.
- Số lượng: `INT` (đơn vị nhỏ nhất). Không dùng float.
- Thời gian: `TIMESTAMP` lưu UTC; hiển thị theo `tenants.timezone` (mặc định `Asia/Ho_Chi_Minh`).
- Soft delete chỉ cho dữ liệu danh mục (products, warehouses, suppliers). **Không soft delete/delete** ledger: `stock_movements`, `order_status_history`, `audit_logs`, `payments`.
- JSON chỉ dùng cho payload gốc của sàn / metadata, không dùng cho trường cần lọc.
- Dữ liệu bí mật: mã hóa ứng dụng (Laravel `encrypted` cast, key riêng `MARKETPLACE_CREDENTIALS_KEY`), không plaintext.

## 2. Danh sách bảng theo module

Ký hiệu: **U** = unique, **I** = index, **FK** = khóa ngoại.

### 2.1 Identity & Tenancy

| Bảng | Cột chính | Ràng buộc / Index |
|---|---|---|
| `users` | id, ulid, name, email, password, email_verified_at, locale, is_super_admin, status(active/locked), last_login_at, two_factor_secret(enc) | U(email) |
| `tenants` | id, ulid, name, slug, owner_user_id, timezone, currency, status(trial/active/suspended/closed), trial_ends_at, data_retention_days | U(slug) |
| `tenant_users` | id, tenant_id, user_id, role_id, status(invited/active/disabled), invited_by, joined_at | U(tenant_id,user_id); I(user_id) |
| `tenant_invitations` *(bổ sung)* | id, tenant_id, email, role_id, token_hash, expires_at, accepted_at | U(token_hash); I(tenant_id,email) |
| `roles` | id, tenant_id NULL (NULL = vai trò hệ thống), key, name, is_system | U(tenant_id,key) |
| `permissions` | id, key (vd `orders.update`), module, description | U(key) |
| `role_permissions` *(bổ sung)* | role_id, permission_id | PK(role_id,permission_id) |
| `personal_access_tokens` | (Sanctum) | |

Vai trò hệ thống seed: TENANT_OWNER, TENANT_ADMIN, MANAGER, WAREHOUSE_STAFF, ORDER_STAFF, ACCOUNTANT, VIEWER. SUPER_ADMIN là cờ `users.is_super_admin` + bảng quyền admin riêng, **không** là role trong tenant.

### 2.2 Billing

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `plans` | id, key(free/starter/pro/business), name, price_monthly, price_yearly, is_public, sort | U(key) |
| `features` *(bổ sung)* | id, key (vd `reports.advanced`, `automation.rules`, `limit.shops`), type(boolean/limit) | U(key) |
| `plan_features` *(bổ sung)* | plan_id, feature_id, enabled, limit_value NULL | PK(plan_id,feature_id) |
| `subscriptions` | id, tenant_id, plan_id, status(trialing/active/past_due/canceled/expired), billing_cycle, current_period_start/end, trial_ends_at, cancel_at | U(tenant_id) (1 subscription hiện hành/tenant) |
| `tenant_feature_overrides` *(bổ sung)* | tenant_id, feature_id, enabled, limit_value, reason, expires_at | PK(tenant_id,feature_id) |
| `usage_counters` *(bổ sung)* | tenant_id, metric(orders_month...), period(YYYYMM), value | U(tenant_id,metric,period) |
| `invoices` *(bổ sung)* | id, ulid, tenant_id, number, subscription_id, amount, tax, status, issued_at, paid_at | U(number) |
| `payments` | id, ulid, tenant_id, invoice_id, provider, provider_txn_id, idempotency_key, amount, status, raw(json), paid_at | U(provider,provider_txn_id); U(idempotency_key) |

### 2.3 Marketplace

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `marketplace_accounts` (= shop đã kết nối) | id, ulid, tenant_id, marketplace(shopee/tiktok/lazada/tiki), external_shop_id, shop_name, region, status(connected/expired/revoked/error), connected_at, last_synced_at, sync_enabled | **U(marketplace, external_shop_id)** – một shop sàn chỉ thuộc một tenant; I(tenant_id) |
| `marketplace_credentials` | id, marketplace_account_id, access_token(enc), refresh_token(enc), access_expires_at, refresh_expires_at, scopes(json), key_version | U(marketplace_account_id) |
| `marketplace_listings` | id, tenant_id, marketplace_account_id, external_product_id, title, status, raw(json), synced_at | U(marketplace_account_id, external_product_id) |
| `listing_variants` | id, tenant_id, listing_id, marketplace_account_id, external_sku_id, seller_sku, product_variant_id NULL, mapping_status(unmapped/suggested/mapped/ignored), mapped_by, mapped_at, last_pushed_qty, last_pushed_at, push_status | U(marketplace_account_id, external_sku_id); I(tenant_id, product_variant_id); I(tenant_id, mapping_status) |
| `marketplace_status_maps` *(bổ sung)* | marketplace, raw_status, normalized_status, reserve_policy | U(marketplace, raw_status) |

### 2.4 Catalog

> Phase 2: đã tạo `categories`, `brands`, `products`, `product_variants`, `product_images` (migration `2026_10_31_200000`). `product_bundles` để sau MVP.

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `categories`, `brands` | id, tenant_id, name, parent_id | U(tenant_id, name, parent_id) |
| `products` | id, ulid, tenant_id, name, category_id, brand_id, description, status(active/inactive/archived), deleted_at | I(tenant_id, status) |
| `product_variants` (SKU nội bộ) | id, ulid, tenant_id, product_id, sku, barcode, name, options(json: {Màu:Đỏ, Size:M}), cost_price (bình quân hiện hành), ref_price, weight_gram, status | **U(tenant_id, sku)**; U(tenant_id, barcode) NULLable; I(product_id) |
| `product_images` | id, tenant_id, product_id, variant_id NULL, path, sort | |
| `product_bundles` *(bổ sung, sau MVP)* | bundle_variant_id, component_variant_id, qty | Combo bán trên sàn trừ tồn thành phần |

### 2.5 Inventory (chi tiết tại INVENTORY-ENGINE.md)

> Phase 2: đã tạo `warehouses`, `stock_balances`, `stock_movements`, `stock_reservations`, `inventory_adjustments(+_lines)`, `stock_transfers(+_lines)`, **`stock_receipts(+_lines)`** (phiếu nhập, bổ sung), **`document_sequences`** (đánh số RCV-/ADJ-/TRF- theo tenant, bổ sung). Khác thiết kế: `stock_reservations` dùng `reference_type` + `reference_id` (Phase 3 trỏ tới order item) và cột `shortage`; idempotency nằm ở `stock_movements`. `marketplace_allocations`, `inventory_sync_outbox` làm ở Phase 4.

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `warehouses` | id, ulid, tenant_id, code, name, address, is_default, allow_negative(false), status | U(tenant_id, code) |
| `stock_balances` | id, tenant_id, warehouse_id, product_variant_id, on_hand, reserved, safety_stock, version, updated_at | **U(tenant_id, warehouse_id, product_variant_id)**; CHECK(on_hand >= 0 OR warehouse.allow_negative) ở tầng service; CHECK(reserved >= 0) |
| `stock_movements` | id, tenant_id, warehouse_id, product_variant_id, type, on_hand_delta(±), reserved_delta(±), on_hand_after, reserved_after, unit_cost, reference_type, reference_id, idempotency_key, actor_type, actor_id, reason, created_at | **U(tenant_id, idempotency_key)**; I(tenant_id, product_variant_id, created_at); I(reference_type, reference_id) |
| `stock_reservations` | id, tenant_id, warehouse_id, product_variant_id, order_item_id, quantity, status(active/consumed/released), idempotency_key, expires_at | **U(tenant_id, order_item_id, warehouse_id)**; U(tenant_id, idempotency_key); I(tenant_id, product_variant_id, status) |
| `inventory_adjustments` + `inventory_adjustment_lines` | phiếu kiểm kê/điều chỉnh, approval_status, approved_by | |
| `stock_transfers` + `stock_transfer_lines` | from_warehouse, to_warehouse, status(draft/in_transit/received/canceled) | |
| `marketplace_allocations` | id, tenant_id, marketplace_account_id, product_variant_id NULL (NULL = mặc định), mode(percent/fixed/all), value, warehouse_ids(json) | U(tenant_id, marketplace_account_id, product_variant_id) |
| `inventory_sync_outbox` *(bổ sung)* | id, tenant_id, listing_variant_id, desired_qty, status(pending/processing/succeeded/failed/rejected), attempts, next_attempt_at, last_error, coalesce_key | U(listing_variant_id) khi pending (dùng coalesce: một dòng pending/listing) ; I(status, next_attempt_at) |

### 2.6 Orders & Fulfillment

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `orders` | id, ulid, tenant_id, marketplace_account_id, marketplace, external_order_id, raw_status, status (chuẩn hóa), payment_method, cod_amount, buyer_name(enc?), buyer_phone_masked, shipping_address(enc json), currency, subtotal, shipping_fee, discount, total, placed_at, paid_at, ship_by_at, external_updated_at, assigned_to, warehouse_id, raw(json), version | **U(marketplace_account_id, external_order_id)**; I(tenant_id, status, placed_at); I(tenant_id, marketplace_account_id, placed_at); I(tenant_id, ship_by_at) |
| `order_items` | id, tenant_id, order_id, external_item_id, listing_variant_id NULL, product_variant_id NULL, seller_sku, name, quantity, unit_price, discount, cost_snapshot, mapping_status | U(order_id, external_item_id); I(tenant_id, product_variant_id) |
| `order_status_history` | id, tenant_id, order_id, from_status, to_status, raw_status, source(webhook/poll/user/system), event_id, actor_id, occurred_at, created_at | I(tenant_id, order_id, occurred_at) |
| `order_notes` *(bổ sung)* | id, tenant_id, order_id, user_id, body | |
| `shipments` | id, tenant_id, order_id, external_package_id, carrier, tracking_number, label_path, status, shipped_at, delivered_at | U(order_id, external_package_id) |

### 2.7 Purchasing, Returns, Finance

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `suppliers` | id, tenant_id, code, name, phone, tax_code | U(tenant_id, code) |
| `purchase_orders` + `purchase_order_lines` | supplier_id, warehouse_id, status, expected_at, lines(variant, qty, unit_cost) | U(tenant_id, number) |
| `goods_receipts` + `goods_receipt_lines` | purchase_order_id NULL, warehouse_id, received_at, lines(variant, qty, unit_cost) | U(tenant_id, number) |
| `cost_adjustments` *(bổ sung)* | variant_id, old_cost, new_cost, reason, actor | |
| `supplier_returns` + lines | | |
| `returns` (hoàn từ khách) | id, tenant_id, order_id, external_return_id, status(requested/approved/in_transit/received/inspected/closed), raw_status | U(tenant_id, order_id, external_return_id) |
| `return_lines` *(bổ sung)* | return_id, order_item_id, qty, condition(sellable/damaged/missing), restock_warehouse_id, restocked_movement_id | |
| `refunds` | id, tenant_id, order_id, return_id NULL, amount, external_refund_id, status | U(tenant_id, external_refund_id) |
| `order_fees` | id, tenant_id, order_id, type(commission/payment/shipping/service/voucher_seller/ads/other), amount, source(estimated/settled) | I(tenant_id, order_id) |
| `settlements` + `settlement_lines` *(bổ sung lines)* | marketplace_account_id, external_settlement_id, period, payout_amount, status | U(marketplace_account_id, external_settlement_id) |

### 2.8 Sync, Webhooks, Audit, Notifications

| Bảng | Cột chính | Ràng buộc |
|---|---|---|
| `webhook_events` | id, marketplace, marketplace_account_id NULL, tenant_id NULL (gán sau khi map), event_type, external_event_id, payload_hash, signature_valid, payload(json), received_at, processed_at, status(received/processed/ignored/failed), attempts, error | **U(marketplace, external_event_id)** hoặc U(marketplace, payload_hash) khi sàn không có event id; I(status, received_at) |
| `sync_jobs` | id, tenant_id, marketplace_account_id, type(orders/products/inventory_push/reconcile), status, started_at, finished_at, stats(json) | I(tenant_id, type, created_at) |
| `sync_attempts` | id, sync_job_id, endpoint, http_status, duration_ms, error_code, error_message(redacted), created_at | I(sync_job_id) |
| `sync_cursors` | id, marketplace_account_id, resource, cursor, last_synced_at | U(marketplace_account_id, resource) |
| `idempotency_keys` *(bổ sung)* | tenant_id, key, request_hash, response(json), expires_at | U(tenant_id, key) – cho POST từ client |
| `audit_logs` | id, tenant_id NULL, actor_user_id, actor_type, action, subject_type, subject_id, changes(json), ip, user_agent, support_session_id NULL, created_at | I(tenant_id, created_at); I(subject_type, subject_id) |
| `support_access_sessions` *(bổ sung)* | id, tenant_id, admin_user_id, reason, approved_by_tenant_user_id, starts_at, ends_at, scope | Super Admin chỉ xem dữ liệu tenant trong phiên được cấp |
| `notifications` | (Laravel) + tenant_id | I(notifiable, read_at) |
| `feature_flags` *(bổ sung)* | key, enabled, rules(json) | U(key) |

## 3. Index chiến lược

- Danh sách đơn: `(tenant_id, status, placed_at DESC)`, `(tenant_id, marketplace_account_id, placed_at)`.
- Lọc đơn theo SKU: qua `order_items(tenant_id, product_variant_id)` rồi join.
- Lịch sử kho: `(tenant_id, product_variant_id, created_at)` cho thẻ kho.
- Chống trùng: unique theo khóa ngoài của sàn (`external_order_id`, `external_event_id`, `external_sku_id`) **kèm account**, vì ID có thể trùng giữa các sàn.

## 4. Dung lượng và lưu trữ

- `webhook_events.payload`, `orders.raw`, `sync_attempts`: dọn theo `data_retention_days` của gói (job hằng đêm), giữ tối thiểu 30 ngày.
- `stock_movements`, `audit_logs`: không xóa trong thời gian tenant còn hoạt động; có thể partition theo năm khi > 50M dòng.

## Phase 3 — Marketplace & Orders (đã tạo)

Migrations `2026_10_31_300000_create_marketplace_tables`, `2026_10_31_300100_create_order_tables`.

| Bảng | Ghi chú chính |
|---|---|
| marketplace_accounts | U(marketplace, external_shop_id); `warehouse_id` kho xuất; `consecutive_failures`, `circuit_open_until` |
| marketplace_credentials | token mã hóa (encrypted cast), ẩn khỏi JSON |
| marketplace_listings / listing_variants | U(account, external_sku_id); `mapping_status` unmapped/suggested/mapped/ignored; trạng thái đẩy tồn |
| marketplace_status_maps | raw status → trạng thái nội bộ, theo sàn |
| webhook_events | inbox, U(marketplace, dedupe_key); không thuộc tenant scope (tenant được xác định server-side khi xử lý) |
| inventory_sync_outbox | U(listing_variant_id); pending/sent/failed, `available_at`, `attempts` |
| sync_cursors | mốc đối soát theo shop |
| fake_marketplace_products / fake_marketplace_orders | chỉ dùng cho sàn giả lập |
| orders | U(account, external_order_id); `external_updated_at` timestamp(6); `buyer_name`, `shipping_address` mã hóa; điện thoại lưu dạng che; cờ `has_shortage`, `has_unmapped`, `sync_warning` |
| order_items | U(order, external_item_id); `product_variant_id` cố định khi đã giữ tồn |
| order_status_history | nguồn (webhook/user/reconcile), người thao tác, raw status |
| order_notes | ghi chú nội bộ |
| order_returns / order_return_lines | U(tenant, number), U(tenant, order, source_key) chống tạo trùng phiếu tự động; số lượng nhận / bán được / hỏng |

Reservation của dòng đơn: `stock_reservations` với `reference_type = 'order_item'`.

## Phase 6 — Billing (đã tạo)

Migration `2026_10_31_400000_create_billing_payment_tables`.

| Bảng | Ghi chú chính |
|---|---|
| subscriptions (+cột) | `pending_plan_id`, `pending_billing_cycle` (hạ gói hẹn cuối kỳ), `trial_used_at` (dùng thử 1 lần), `past_due_since` |
| invoices | U(number), U(transfer_code); kind subscribe/upgrade/renewal; subtotal − credit = amount; status pending/paid/cancelled; kỳ được thanh toán `period_start/end` |
| payments | U(provider, provider_txn_id) — chống ghi nhận trùng; status applied/underpaid/unapplied; `recorded_by` (admin xác nhận), `raw` (IPN) |
| usage_counters | U(tenant, metric, period YYYY-MM); tăng nguyên tử bằng `INSERT … ON DUPLICATE KEY UPDATE` |

Thiết kế ban đầu ở §2.2 có `idempotency_key`, `tax`, `subscription_id`; bản triển khai dùng (provider, provider_txn_id) làm khóa idempotency và chưa tính thuế (chưa có thông tin pháp nhân/thuế suất — cần chủ dự án quyết khi xuất hóa đơn VAT).

## Phase 8 — Identity (đã tạo)

Migration `2026_10_31_500000_add_two_factor_to_users`: `users.two_factor_secret` (mã hóa), `two_factor_recovery_codes` (mã hóa, danh sách hash SHA-256), `two_factor_confirmed_at`.
