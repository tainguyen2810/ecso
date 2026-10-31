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
