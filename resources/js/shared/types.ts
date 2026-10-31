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
    name: string | null;
    options: Record<string, string>;
    ref_price: string | number | null;
    cost_price?: string;
    weight_gram: number | null;
    status?: string;
    product?: Ref;
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
    is_default: boolean;
    status: 'active' | 'inactive';
    sku_count?: number;
    on_hand?: number;
}

export interface StockRow extends StockFigures {
    variant: { id: string; sku: string; name: string | null; barcode: string | null };
    product: Ref;
    safety_stock: number;
    sellable: number;
    cost_price: string;
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
    variant: { id: string; sku: string; name: string };
    quantity: number;
    unit_cost?: string;
    mode?: 'delta' | 'count';
    on_hand_before?: number;
    applied_delta?: number;
}

export interface InventoryDocument {
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
    shop?: { id: string; name: string; marketplace: string };
    external_order_id: string;
    status: string;
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
    carrier: string | null;
    tracking_number: string | null;
    buyer_note: string | null;
    placed_at: string | null;
    paid_at: string | null;
    ship_by_at: string | null;
    handed_over_at: string | null;
    cancelled_at: string | null;
    cancel_reason: string | null;
    external_updated_at: string | null;
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
    unit_price: string;
    discount: string;
    mapping_status: string;
    listing_id: string | null;
    variant: { id: string; sku: string; name: string } | null;
    reservation: { quantity: number; shortage: number; status: string } | null;
}

export interface OrderDetail extends OrderSummary {
    items: OrderItemDetail[];
    history: { from: string | null; to: string; raw_status: string | null; source: string; actor: string | null; note: string | null; occurred_at: string | null; created_at: string }[];
    notes: { id: number; body: string; user: string | null; created_at: string | null }[];
    returns: OrderReturnData[];
    actions: Record<string, { allowed: boolean; capability: string }>;
    capabilities: Record<string, string>;
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
    note: string | null;
    expects_goods: boolean;
    next: string[];
    order?: { id: string; external_order_id: string; status: string; marketplace: string };
    warehouse?: { id: string; code: string; name: string } | null;
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
    capabilities: Record<string, string>;
}

export interface MarketplaceAccountData {
    id: string;
    marketplace: string;
    marketplace_name: string;
    shop_name: string;
    external_shop_id: string;
    status: string;
    sync_enabled: boolean;
    warehouse: { id: string; code: string; name: string } | null;
    connected_at: string | null;
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
    name: string;
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
