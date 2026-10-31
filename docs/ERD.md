# ERD

> Phase 0 (2026-10-31). Cột đầy đủ xem [DATABASE.md](DATABASE.md). Sơ đồ viết bằng Mermaid (GitHub hiển thị trực tiếp).

## 1. Tenancy, Access, Billing

```mermaid
erDiagram
    users ||--o{ tenant_users : "tham gia"
    tenants ||--o{ tenant_users : "có"
    roles ||--o{ tenant_users : "gán"
    roles ||--o{ role_permissions : ""
    permissions ||--o{ role_permissions : ""
    tenants ||--o{ roles : "vai trò tùy chỉnh"
    tenants ||--o{ tenant_invitations : ""
    tenants ||--|| subscriptions : "một gói hiện hành"
    plans ||--o{ subscriptions : ""
    plans ||--o{ plan_features : ""
    features ||--o{ plan_features : ""
    tenants ||--o{ tenant_feature_overrides : ""
    tenants ||--o{ usage_counters : ""
    tenants ||--o{ invoices : ""
    invoices ||--o{ payments : ""
    tenants ||--o{ audit_logs : ""
    tenants ||--o{ support_access_sessions : ""
```

## 2. Catalog, Marketplace, Inventory

```mermaid
erDiagram
    tenants ||--o{ products : ""
    products ||--o{ product_variants : "SKU nội bộ"
    products ||--o{ product_images : ""
    tenants ||--o{ marketplace_accounts : "shop đã kết nối"
    marketplace_accounts ||--|| marketplace_credentials : "token mã hóa"
    marketplace_accounts ||--o{ marketplace_listings : ""
    marketplace_listings ||--o{ listing_variants : ""
    product_variants ||--o{ listing_variants : "mapping 1-n"
    marketplace_accounts ||--o{ marketplace_allocations : ""
    product_variants ||--o{ marketplace_allocations : ""
    tenants ||--o{ warehouses : ""
    warehouses ||--o{ stock_balances : ""
    product_variants ||--o{ stock_balances : ""
    warehouses ||--o{ stock_movements : ""
    product_variants ||--o{ stock_movements : ""
    product_variants ||--o{ stock_reservations : ""
    warehouses ||--o{ stock_reservations : ""
    listing_variants ||--o{ inventory_sync_outbox : ""
    marketplace_accounts ||--o{ sync_cursors : ""
    marketplace_accounts ||--o{ sync_jobs : ""
    sync_jobs ||--o{ sync_attempts : ""
```

## 3. Orders, Fulfillment, Returns, Finance

```mermaid
erDiagram
    marketplace_accounts ||--o{ orders : ""
    orders ||--o{ order_items : ""
    order_items }o--o| product_variants : "sau mapping"
    order_items }o--o| listing_variants : ""
    order_items ||--o{ stock_reservations : "giữ tồn"
    orders ||--o{ order_status_history : ""
    orders ||--o{ order_notes : ""
    orders ||--o{ shipments : ""
    orders ||--o{ returns : ""
    returns ||--o{ return_lines : ""
    order_items ||--o{ return_lines : ""
    orders ||--o{ refunds : ""
    orders ||--o{ order_fees : ""
    marketplace_accounts ||--o{ settlements : ""
    settlements ||--o{ settlement_lines : ""
    settlement_lines }o--o| orders : ""
    webhook_events }o--o| marketplace_accounts : "map server-side"
```

## 4. Purchasing

```mermaid
erDiagram
    tenants ||--o{ suppliers : ""
    suppliers ||--o{ purchase_orders : ""
    purchase_orders ||--o{ purchase_order_lines : ""
    purchase_orders ||--o{ goods_receipts : ""
    goods_receipts ||--o{ goods_receipt_lines : ""
    goods_receipt_lines }o--|| product_variants : ""
    goods_receipts ||--o{ stock_movements : "RECEIPT"
    suppliers ||--o{ supplier_returns : ""
```

## 5. Quan hệ then chốt cần nhớ

- **Một `product_variant` (SKU nội bộ) ↔ nhiều `listing_variants`** (nhiều shop, nhiều sàn). Mapping chỉ bằng `external_sku_id` / `seller_sku` khớp chính xác hoặc người dùng xác nhận; gợi ý theo tên chỉ là `suggested`.
- **`stock_balances` là số dư, `stock_movements` là sự thật.** Số dư luôn tái tạo được bằng tổng movements.
- **`marketplace_accounts(marketplace, external_shop_id)` unique toàn hệ thống** ⇒ webhook map ra đúng một tenant.
