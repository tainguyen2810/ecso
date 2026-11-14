export interface User {
    id: string;
    name: string;
    email: string;
    locale: string;
    email_verified: boolean;
    is_super_admin: boolean;
}

export interface Tenant {
    id: string;
    name: string;
    slug: string;
    timezone: string;
    currency: string;
    status: string;
    role?: string;
    setup_completed?: boolean;
    channels?: string[] | null;
    team_size?: string | null;
    is_demo?: boolean;
    demo_status?: 'preparing' | 'ready' | 'failed' | null;
    demo_expires_at?: string | null;
}

export interface Entitlement {
    enabled: boolean;
    limit: number | null;
}

export interface TenantContextData {
    tenant: Tenant;
    role: { key: string; name: string };
    permissions: string[];
    entitlements: Record<string, Entitlement>;
    /** Warehouses this member moves stock in; null = every warehouse. */
    warehouses?: string[] | null;
}

export interface Role {
    key: string;
    name: string;
    is_system: boolean;
    permissions?: string[];
    assignable?: boolean;
}

export interface Member {
    id: string;
    name: string;
    email: string;
    role: Role;
    status: 'active' | 'disabled';
    joined_at: string | null;
    is_me: boolean;
    /** Warehouses the member moves stock in; empty = every warehouse. */
    warehouses?: string[];
}

export interface Invitation {
    id: string;
    email: string;
    role: Role;
    invited_by: string | null;
    expires_at: string;
    status: 'pending' | 'accepted' | 'revoked' | 'expired';
}

export interface Paginated<T> {
    data: T[];
    meta: { current_page: number; last_page: number; total: number; per_page?: number };
}

export interface Ref {
    id: string;
    name: string;
}

export interface Variant {
    id?: string;
    sku: string;
    barcode: string | null;
    location?: string | null;
    name: string | null;
    options: Record<string, string>;
    ref_price: string | number | null;
    wholesale_price?: string | number | null;
    components?: { variant: { id: string; sku: string; name: string }; quantity: number }[];
    cost_price?: string;
    weight_gram: number | null;
    supplier?: Ref | null;
    supplier_sku?: string | null;
    length_mm?: number | null;
    width_mm?: number | null;
    height_mm?: number | null;
    sellable?: boolean;
    status?: string;
    product?: Ref & { image?: string | null; unit?: string | null };
    stock?: StockFigures;
}

export interface StockFigures {
    on_hand: number;
    reserved: number;
    available: number;
}

export interface Product {
    id: string;
    name: string;
    description: string | null;
    type?: 'standard' | 'combo';
    unit?: string | null;
    tags?: string[];
    vat_rate?: string | null;
    status: 'active' | 'inactive' | 'archived';
    category: Ref | null;
    brand: Ref | null;
    variants?: Variant[];
    variants_count?: number;
    images?: { id: number; url: string }[];
    stock?: StockFigures;
    created_at: string;
    updated_at: string;
}

export interface Warehouse {
    id: string;
    code: string;
    name: string;
    address: string | null;
    /** Standard parts (units since 1 July 2025); empty ward = address not standardised. */
    street?: string | null;
    province_code?: string | null;
    ward_code?: string | null;
    is_default: boolean;
    status: 'active' | 'inactive';
    /** 'defective' holds damaged returns and never sells. */
    purpose?: 'sale' | 'defective';
    sku_count?: number;
    on_hand?: number;
}

export interface StockRow extends StockFigures {
    variant: { id: string; sku: string; name: string | null; barcode: string | null };
    product: Ref & { image?: string | null };
    safety_stock: number;
    sellable: number;
    cost_price: string;
    ref_price?: string;
    wholesale_price?: string | null;
    created_at?: string | null;
}

export interface StockSummary {
    skus: number;
    on_hand: number;
    reserved: number;
    inventory_value: string;
    out_of_stock: number;
    low_stock: number;
}

export interface Movement {
    id: number;
    type: string;
    warehouse: { id: string; code: string; name: string };
    on_hand_delta: number;
    reserved_delta: number;
    on_hand_after: number;
    reserved_after: number;
    available_after: number;
    unit_cost: string | null;
    reference: { type: string; number: string | null } | null;
    reason: string | null;
    actor: string | null;
    created_at: string;
}

export interface DocumentLine {
    /** Transfer lines: id used when receiving. */
    line_id?: number;
    received_qty?: number;
    variant: { id: string; sku: string; name: string };
    quantity: number;
    unit_cost?: string;
    mode?: 'delta' | 'count';
    /** Count lines: system quantity seen when the SKU was counted. */
    system_qty?: number;
    /** Count lines not applied yet: stock now. */
    on_hand_now?: number;
    on_hand_before?: number;
    applied_delta?: number;
    reason?: string;
    line_note?: string;
}

export interface InventoryDocument {
    /** Transfers received short: the pending "thất lạc" adjustment. */
    shortage?: { ulid: string; number: string; status: string } | null;
    id: string;
    kind: 'receipt' | 'adjustment' | 'transfer';
    number: string;
    note: string | null;
    status?: string;
    reason?: string;
    source?: string;
    warehouse?: { id: string; code: string; name: string } | null;
    from_warehouse?: { id: string; code: string; name: string } | null;
    to_warehouse?: { id: string; code: string; name: string } | null;
    created_by: string | null;
    is_mine: boolean;
    created_at: string;
    decided_by?: string | null;
    decided_at?: string | null;
    decision_note?: string | null;
    counted_at?: string | null;
    dispatched_at?: string | null;
    received_at?: string | null;
    lines_count?: number;
    lines?: DocumentLine[];
    total_quantity?: number | null;
    total_value?: string | null;
}

export interface OrderSummary {
    id: string;
    marketplace: string;
    source?: 'manual' | 'marketplace';
    shop?: { id: string; name: string; marketplace: string } | null;
    channel?: { id: string; name: string; type: string } | null;
    customer?: { id: string; code: string; name: string } | null;
    paid_amount?: string;
    refunded_amount?: string;
    payment_status?: 'unpaid' | 'partial' | 'paid' | null;
    refund_status?: 'none' | 'partial' | 'full';
    balance_due?: string | null;
    external_order_id: string;
    status: string;
    version?: number;
    tax_rate?: string;
    prices_include_tax?: boolean;
    tax_amount?: string;
    raw_status: string;
    payment_method: string | null;
    is_cod: boolean;
    subtotal: string;
    shipping_fee: string;
    discount: string;
    total: string;
    buyer_name: string | null;
    buyer_phone: string | null;
    shipping_address: string | null;
    shipping_street?: string | null;
    shipping_province_code?: string | null;
    shipping_ward_code?: string | null;
    carrier: string | null;
    tracking_number: string | null;
    buyer_note: string | null;
    tags?: string[];
    deliver_on?: string | null;
    placed_at: string | null;
    paid_at: string | null;
    ship_by_at: string | null;
    handed_over_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    deleted_on_channel_at?: string | null;
    external_updated_at: string | null;
    /** WooCommerce (the seller's own website): full contact and payment details. */
    external_number?: string | null;
    buyer_email?: string | null;
    addresses?: { billing?: Record<string, string>; shipping?: Record<string, string> } | null;
    tax_total?: string;
    fee_total?: string;
    coupon_codes?: string[];
    transaction_id?: string | null;
    flags: { shortage: boolean; unmapped: boolean; overdue: boolean; due_soon: boolean; printed: boolean };
    sync_warning: string | null;
    warehouse?: { id: string; code: string; name: string } | null;
    assignee?: Ref | null;
    items_count?: number;
    items_preview?: { name: string; variant_name: string | null; quantity: number; sku: string | null }[];
}

export interface OrderItemDetail {
    id: number;
    name: string;
    variant_name: string | null;
    seller_sku: string | null;
    external_sku_id: string | null;
    quantity: number;
    /** Shipped and not yet covered by a return. */
    returnable: number;
    refunded_quantity?: number;
    unit_price: string;
    discount: string;
    tax?: string;
    mapping_status: string;
    /** A gift line added by a promotion (0 ₫); the server adds it again on save. */
    is_gift?: boolean;
    listing_id: string | null;
    variant: { id: string; sku: string; name: string } | null;
    reservation: { quantity: number; shortage: number; status: string } | null;
}

export interface OrderDetail extends OrderSummary {
    items: OrderItemDetail[];
    /** Money off from promotions, already inside discount. */
    promotion_discount?: string;
    coupon_code?: string | null;
    promotions_off?: boolean;
    /** Points spent on the order (C5) and their value, already inside discount. */
    points_used?: number;
    points_discount?: string;
    promotions?: { id: string; name: string; type: string; code: string | null; amount: string }[];
    history: { from: string | null; to: string; raw_status: string | null; source: string; actor: string | null; note: string | null; occurred_at: string | null; created_at: string }[];
    edits?: { at: string | null; actor: string | null; changes: string[] }[];
    notes: { id: number; body: string; user: string | null; created_at: string | null }[];
    returns: OrderReturnData[];
    /** Shipments (vận đơn) of the order, oldest first. */
    shipments?: ShipmentData[];
    actions: Record<string, { allowed: boolean; capability: string }>;
    capabilities: Record<string, string>;
    editable: boolean;
    /** How much of what was shipped has come back on returns. */
    return_state?: 'none' | 'partial' | 'full';
    payments: OrderPaymentData[];
    /** Website order: what the store reports paid that nobody has confirmed in a fund yet (null otherwise). */
    unconfirmed_amount?: string | null;
    created_by: string | null;
    exchanged_from?: { id: string; number: string; credit: string; order: { id: string; external_order_id: string } }[];
    customer_stats: { orders_count: number; total_spent: string; debt: string; returns_count: number; failed_deliveries: number } | null;
    refunds?: OrderRefundData[];
}

/** A refund made on the store (WooCommerce). */
export interface OrderRefundData {
    id: string;
    external_refund_id: string;
    amount: string;
    reason: string | null;
    refunded_at: string | null;
    lines: { name: string | null; quantity: number; amount: string }[];
    return: { id: string; number: string; status: string } | null;
}

export interface OrderPaymentData {
    id: string;
    kind: 'payment' | 'refund';
    method: string;
    amount: string;
    reference: string | null;
    note: string | null;
    paid_at: string;
    created_by?: string | null;
    voided_at: string | null;
    void_reason: string | null;
    order?: { id: string; number: string };
}

export interface SalesChannel {
    id: string;
    name: string;
    type: string;
    is_active: boolean;
    warehouse?: { id: string; code: string; name: string } | null;
    orders_count?: number;
}

export interface CustomerGroup {
    id: string;
    code: string;
    name: string;
    description: string | null;
    price_tier: 'retail' | 'wholesale';
    discount_percent: string;
    payment_method: string | null;
    is_active?: boolean;
    payment_terms_days?: number | null;
    credit_limit?: string | null;
    auto_min_spent?: string | null;
    auto_min_orders?: number | null;
    customers_count: number | null;
    created_at: string | null;
}

export interface CustomerAddress {
    id: string;
    label: string | null;
    recipient: string | null;
    phone: string | null;
    address: string;
    street?: string | null;
    province_code?: string | null;
    ward_code?: string | null;
}

export interface CustomerNote {
    id: string;
    body: string;
    kind?: 'note' | 'call' | 'message' | 'meeting' | 'complaint';
    author: string | null;
    mine: boolean;
    created_at: string | null;
}

export interface CustomerLedgerEntry {
    type: 'order' | 'return' | 'payment' | 'refund' | 'opening' | 'adjustment' | 'receipt';
    entry_id?: string;
    ref: string | null;
    order_id: string | null;
    at: string | null;
    note: string | null;
    change: string;
    balance: string;
}

export interface Customer {
    id: string;
    code: string;
    name: string;
    loyalty_points?: number;
    type?: 'individual' | 'company';
    source?: string | null;
    invoice?: { name: string | null; address: string | null; email: string | null };
    phone: string | null;
    email: string | null;
    address: string | null;
    /** Standard parts (units since 1 July 2025); empty ward = address not standardised. */
    street?: string | null;
    province_code?: string | null;
    ward_code?: string | null;
    group: {
        id: string;
        code: string;
        name: string;
        price_tier: 'retail' | 'wholesale';
        discount_percent: string;
        payment_method: string | null;
        is_active?: boolean;
        payment_terms_days?: number | null;
        credit_limit?: string | null;
    } | null;
    assignee: { id: string; name: string } | null;
    birthday: string | null;
    gender: 'male' | 'female' | 'other' | null;
    tax_code: string | null;
    tags: string[];
    status: 'active' | 'inactive';
    note: string | null;
    orders_count: number | null;
    total_spent: string | null;
    debt: string | null;
    overdue?: string | null;
    payment_terms_days?: number | null;
    credit_limit?: string | null;
    last_order_at: string | null;
    created_at: string | null;
}

export interface CustomerSummary {
    first_order_at: string | null;
    average_order: string | null;
    purchase_interval_days: number | null;
    days_since_last_order: number | null;
    returned_value: string;
    terms_days: number | null;
    credit_limit: string | null;
    top_products?: { name: string; variant_name: string | null; sku: string | null; quantity: number; amount: string; orders: number }[];
}

/** A large customer file checked and imported in the background. */
export interface CustomerImportJob {
    id: string;
    file_name: string;
    status: 'pending_check' | 'checking' | 'checked' | 'queued' | 'importing' | 'done' | 'failed' | 'expired';
    total: number;
    processed: number;
    created: number;
    updated: number;
    error_count: number;
    errors: { row: number; message: string }[];
    message: string | null;
    by: string | null;
    started_at: string | null;
    finished_at: string | null;
    created_at: string | null;
}

export interface CustomerTask {
    id: string;
    title: string;
    due_at: string;
    done_at: string | null;
    late: boolean;
    assignee: string | null;
    customer: { id: string; code: string; name: string } | null;
}

export interface CustomerActivity {
    id: number;
    action: string;
    actor: string | null;
    changes: Record<string, unknown> | null;
    at: string | null;
}

export interface OrderReturnLineData {
    id: number;
    name: string | null;
    variant_name: string | null;
    sku: string | null;
    mapped: boolean;
    quantity: number;
    received_qty: number;
    sellable_qty: number;
    damaged_qty: number;
}

export interface OrderReturnData {
    id: string;
    number: string;
    type: string;
    status: string;
    reason: string | null;
    refund_amount: string | null;
    exchange_credit?: string;
    exchange_order?: { id: string; external_order_id: string; total: string } | null;
    goods_value?: string;
    /** Refunds booked from this return, and what may still be refunded. */
    refunded?: string;
    refundable?: string;
    note: string | null;
    expects_goods: boolean;
    next: string[];
    order?: { id: string; external_order_id: string; status: string; marketplace: string; customer?: string | null };
    warehouse?: { id: string; code: string; name: string } | null;
    damaged_warehouse?: { id: string; code: string; name: string } | null;
    lines?: OrderReturnLineData[];
    lines_count?: number;
    created_by?: string | null;
    inspected_by?: string | null;
    received_at: string | null;
    inspected_at: string | null;
    closed_at: string | null;
    created_at: string | null;
}

export interface MarketplaceCatalogEntry {
    key: string;
    name: string;
    available: boolean;
    is_fake: boolean;
    credentials_configured: boolean;
    /** oauth = marketplace app authorization, store = WooCommerce (store approval / API keys), fake = simulator */
    connect_mode?: 'oauth' | 'store' | 'app_keys' | 'fake';
    capabilities: Record<string, string>;
}

export interface MarketplaceSyncSettings {
    sync_orders: boolean;
    sync_stock: boolean;
    sync_prices: boolean;
    import_new_products: boolean;
    push_status?: boolean;
}

export interface WebhookHealth {
    ok: boolean;
    checked_at: string;
    missing: string[];
    reactivated: string[];
    error: string | null;
}

export interface SyncRunData {
    id: string;
    account: { id: string; name: string; marketplace: string } | null;
    resource: string;
    trigger: string;
    status: 'running' | 'ok' | 'partial' | 'failed';
    window_from: string | null;
    window_to: string | null;
    pages: number;
    counts: Record<string, number>;
    error: string | null;
    started_at: string;
    finished_at: string | null;
    duration_ms: number | null;
}

export interface SyncErrorData {
    id: string;
    account: { id: string; name: string; marketplace: string } | null;
    resource: string;
    external_id: string;
    category: 'api' | 'auth' | 'not_found' | 'data' | 'conflict';
    message: string;
    attempts: number;
    first_seen_at: string;
    last_seen_at: string;
    resolved_at: string | null;
    resolution: string | null;
}

export type SyncTaskType = 'webhook' | 'stock_push' | 'order_poll' | 'price_push';
export type SyncTaskStatus = 'pending' | 'processing' | 'success' | 'failed' | 'retry_scheduled' | 'cancelled' | 'dead_letter';

/** One row of the Sync Center queue (SyncActivityQuery): a webhook, stock push, order poll or price push. */
export interface SyncTaskData {
    id: string;
    key: number;
    type: SyncTaskType;
    account: { id: string; name: string; marketplace: string } | null;
    status: SyncTaskStatus;
    raw_status: string;
    object: string | null;
    action: string | null;
    attempts: number | null;
    next_retry_at: string | null;
    message: string | null;
    error_code: string | null;
    error_label: string | null;
    correlation_id: string | null;
    counts?: Record<string, number>;
    /** When the queue sorts and shows it: received (webhook), last pushed (stock/price), started (order poll). */
    time: string | null;
    created_at: string | null;
    started_at: string | null;
    completed_at: string | null;
    duration_ms: number | null;
    can_retry: boolean;
    can_cancel: boolean;
    related?: SyncTaskData[];
}

export interface SyncShopData {
    id: string;
    name: string;
    marketplace: string;
    status: string;
    sync_enabled: boolean;
    circuit_open: boolean;
    last_error: string | null;
    last_synced_at: string | null;
    last_order_poll_at: string | null;
    last_webhook_at: string | null;
    token_expires_at: string | null;
    open_errors: number;
    pending_push: number;
    stock_drift: number;
}

export interface SyncOverviewData {
    accounts: { total: number; active: number; disconnected: number; circuit_open: number; token_expiring: number; reconcile_overdue: number };
    tasks: { by_status: Record<SyncTaskStatus, number>; by_type: Record<SyncTaskType, Record<SyncTaskStatus, number>> };
    problems: { open_errors: number; errors_by_code: Record<string, number>; dead_letters: number; stock_drift: number; failed_by_code: Record<string, number> };
    last_synced_at: string | null;
    shops: SyncShopData[];
    error_codes: Record<string, { label: string; hint: string; auto_retry: boolean }>;
    permissions: { operate: boolean; export: boolean };
}

export interface StatusMapData {
    defaults: Record<string, string>;
    custom: Record<string, string>;
    seen: { raw_status: string; orders: number; unknown: number }[];
    statuses: string[];
}

export interface WooConnectChecks {
    https: boolean;
    woocommerce_version: string | null;
    products: number | null;
    orders: number | null;
    webhooks?: string[];
}

export interface NewListingData {
    id: string;
    title: string;
    image_url: string | null;
    variants: { name: string | null; seller_sku: string | null; price: string | null; regular_price: string | null; sale_price: string | null }[];
}

/** A website price waiting for approval (0, or more than 50% away from ECSO's). */
export interface PendingPriceChange {
    id: string;
    product_id: string | null;
    product_name: string | null;
    variant_name: string | null;
    sku: string | null;
    ecso_price: string | null;
    website_price: string;
    on_sale: boolean;
    diff_percent: number | null;
    reason: string | null;
    created_at: string;
}

export type PriceSource = 'auto' | 'ecso' | 'woo';

/** Price source, who holds the price now, prices on linked websites, and price history of one product. */
export interface ProductPrices {
    variants: {
        id: string;
        sku: string;
        price_source: PriceSource;
        holder: 'ecso' | 'channel';
        on_hand: number;
        channels: {
            account_id: string | null;
            shop_name: string | null;
            marketplace: string | null;
            price_sync: boolean;
            price: string | null;
            regular_price: string | null;
            sale_price: string | null;
            push_status: string | null;
            push_error: string | null;
            pushed_at: string | null;
        }[];
    }[];
    history: {
        id: string;
        sku: string | null;
        old_price: string | null;
        new_price: string;
        source: 'ecso' | 'channel';
        shop_name: string | null;
        status: 'applied' | 'pending' | 'rejected' | 'reverted';
        reason: string | null;
        actor: string | null;
        created_at: string;
    }[];
}

export interface MarketplaceAccountData {
    id: string;
    marketplace: string;
    marketplace_name: string;
    shop_name: string;
    external_shop_id: string;
    status: string;
    sync_enabled: boolean;
    base_url?: string | null;
    settings?: MarketplaceSyncSettings;
    warehouse: { id: string; code: string; name: string } | null;
    connected_at: string | null;
    orders_since?: string | null;
    order_sync_interval?: number;
    webhook_health?: WebhookHealth | null;
    last_synced_at: string | null;
    last_error: string | null;
    circuit_open: boolean;
    stats?: { listings: number; mapped: number; suggested: number; push_errors: number; pending_push: number };
}

export interface ListingVariantData {
    id: string;
    external_product_id: string | null;
    external_sku_id: string;
    title: string;
    name: string | null;
    seller_sku: string | null;
    price: string | null;
    marketplace_stock: number | null;
    mapping_status: string;
    variant: { id: string; sku: string; name: string } | null;
    last_pushed_qty: number | null;
    last_pushed_at: string | null;
    push_status: string | null;
    push_error: string | null;
    mapped_at: string | null;
}

export interface Supplier {
    id: string;
    code: string;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    address: string | null;
    tax_code: string | null;
    /** Days to pay after receiving the goods; null = no terms. */
    payment_terms_days?: number | null;
    note: string | null;
    is_active: boolean;
    orders_count?: number;
    purchased?: string;
    paid?: string;
    debt?: string;
    overdue?: string;
    created_at: string | null;
}

export interface SupplierPaymentData {
    id: string;
    /** "refund": money the supplier paid back. */
    kind?: 'payment' | 'refund';
    method: string;
    amount: string;
    reference: string | null;
    note: string | null;
    paid_at: string;
    created_by?: string | null;
    voided_at: string | null;
    void_reason: string | null;
    order?: { id: string; number: string } | null;
}

export interface SupplierReturnData {
    id: string;
    number: string;
    total: string;
    reason: string | null;
    created_by?: string | null;
    created_at: string | null;
    supplier?: { id: string; code: string; name: string };
    order?: { id: string; number: string } | null;
    warehouse?: { id: string; name: string };
    tax_amount?: string;
    lines?: { variant: { id: string; sku: string; name: string }; quantity: number; unit_cost: string; tax_rate?: string }[];
}

export interface PurchaseOrderLineData {
    variant: { id: string; sku: string; name: string } | null;
    quantity: number;
    received_qty: number;
    returned_qty: number;
    remaining: number;
    unit_cost: string;
    tax_rate: string;
    landed_unit_cost: string;
}

export interface PurchaseOrderData {
    id: string;
    number: string;
    status: string;
    supplier?: { id: string; code: string; name: string; contact_name?: string | null; phone?: string | null; email?: string | null; address?: string | null; tax_code?: string | null };
    warehouse?: { id: string; code: string; name: string };
    expected_at: string | null;
    invoice_date?: string | null;
    /** Set by hand; else received date + the supplier's payment terms (allocation.due_date). */
    due_date?: string | null;
    /** Still owed once money paid on account is spread over the orders, oldest first. */
    allocation?: { open: number; credit: number; due_date: string | null; overdue: boolean } | null;
    reference?: string | null;
    tags?: string[];
    discount?: string;
    ordered_at: string | null;
    received_at: string | null;
    closed_at: string | null;
    subtotal: string;
    tax_amount: string;
    extra_cost: string;
    extra_cost_note: string | null;
    extra_cost_to_supplier: boolean;
    vat_in_cost: boolean;
    total: string;
    received_value: string;
    received_tax: string;
    received_extra_cost: string;
    returned_value: string;
    paid_amount: string;
    balance_due: string;
    note: string | null;
    editable: boolean;
    created_by?: string | null;
    created_at: string | null;
    total_quantity: number | null;
    received_quantity: number | null;
    lines?: PurchaseOrderLineData[];
    receipts?: { id: string; number: string; created_at: string | null; created_by: string | null; total_quantity: number; total_value: string }[];
    payments?: SupplierPaymentData[];
    returns?: SupplierReturnData[];
}

export interface CashShiftTotals {
    orders: number;
    cancelled: number;
    revenue: string;
    by_method: Record<string, string>;
    collected: string;
    cash_sales: string;
    cash_refunds: string;
    cash_in: string;
    cash_out: string;
    opening_cash: string;
    expected_cash: string;
}

export interface CashShift {
    id: string;
    number: string;
    status: 'open' | 'closed';
    cashier?: { id: string; name: string };
    warehouse?: { id: string; name: string } | null;
    opened_at: string;
    opening_cash: string;
    opening_note: string | null;
    closed_at: string | null;
    closed_by?: string | null;
    expected_cash: string | null;
    counted_cash: string | null;
    difference: string | null;
    handed_over: string | null;
    kept_in_drawer: string | null;
    denominations: Record<string, number> | null;
    closing_note: string | null;
    totals: CashShiftTotals;
    movements?: { kind: 'in' | 'out'; amount: string; reason: string; created_by: string | null; created_at: string; voucher?: { id: string; number: string } | null }[];
}

/** Round 3: fee table of one marketplace shop with its category overrides and the actual fees (settlement data). */
export interface FeeStats {
    orders: number;
    order_amount: number;
    commission_pct: number;
    payment_pct: number;
    service_pct: number;
    total_pct: number;
    fixed_fee: number;
    fixed_fee_per_order: number;
    shipping_fee: number;
    net_amount: number;
    category_name?: string;
}

export interface FeeRuleFields {
    commission_pct: number | string | null;
    payment_pct: number | string | null;
    service_pct: number | string | null;
    fixed_fee: number | string | null;
}

export interface FeeRuleRow {
    account: { id: string; shop_name: string; marketplace: string; marketplace_name: string };
    saved: boolean;
    shop: FeeRuleFields & { rounding: number | string; min_margin_pct: number | string; auto_apply: boolean; auto_update_fees: boolean; suggested_from: { days: number; orders: number; at: string } | null };
    categories: (FeeRuleFields & { category_id: string; category_name: string | null; actual: FeeStats | null })[];
    actual: FeeStats | null;
}

export interface FeeAlertData {
    id: string;
    type: 'fee_drift' | 'loss_orders';
    account: { id: string; shop_name: string; marketplace: string } | null;
    text: string;
    data: Record<string, unknown>;
    created_at: string;
}

export interface PriceBoardCell {
    listing_id: string;
    current: number | null;
    new: number | null;
    raised: boolean;
    new_outcome: { fees: number; net: number; profit: number | null; margin_pct: number | null } | null;
    current_outcome: { fees: number; net: number; profit: number | null; margin_pct: number | null } | null;
    status: 'pending' | 'loss' | 'locked' | 'export' | 'ok' | 'no_price';
    locked: boolean;
    below_margin: boolean;
    min_margin_pct: number | null;
}

export interface PriceBoardRow {
    variant_id: string;
    product_id: string | null;
    name: string | null;
    variant_name: string | null;
    sku: string;
    base: number;
    cost: number | null;
    flags: { pending: boolean; loss: boolean; locked: boolean };
    cells: Record<string, PriceBoardCell>;
}

/** Shipments (vận đơn), see app/Modules/Orders/Http/Resources/ShipmentResource.php. */
export interface ShipmentAction {
    label: string;
    allowed: boolean;
    reason: string | null;
}

export interface ShipmentEventData {
    status: string;
    status_label: string;
    description: string | null;
    source: string;
    actor: string | null;
    occurred_at: string;
}

export interface ShipmentData {
    id: string;
    carrier: string | null;
    carrier_name: string | null;
    source: 'manual' | 'self' | 'api' | 'marketplace' | 'import';
    tracking_number: string | null;
    carrier_account_id?: string | null;
    service?: string | null;
    service_name?: string | null;
    expected_delivery_at?: string | null;
    api_error?: string | null;
    raw_status?: string | null;
    status: string;
    status_label: string;
    packages: number;
    weight_g: number | null;
    cod_amount: string;
    cod_collected: string | null;
    cod_status: string;
    fee_estimated: string | null;
    fee_actual: string | null;
    fee_status: 'unknown' | 'estimated' | 'confirmed';
    payer: string;
    attempts: number;
    failure_reason: string | null;
    picked_up_at: string | null;
    delivered_at: string | null;
    failed_at: string | null;
    returned_at: string | null;
    cancelled_at: string | null;
    last_event_at: string | null;
    created_at: string | null;
    order?: {
        id: string;
        external_order_id: string;
        marketplace: string;
        channel: string | null;
        status: string;
        is_cod: boolean;
        recipient: string | null;
        phone: string | null;
        address: string | null;
        area: string | null;
    };
    actions?: Record<string, ShipmentAction>;
    warehouse?: { id: string; code: string; name: string } | null;
    events?: ShipmentEventData[];
}

export interface ShipmentSummary {
    tabs: Record<string, number>;
    late: number;
    late_days: number;
    cod_held: { carrier: string | null; carrier_name: string | null; amount: string; count: number }[];
}
