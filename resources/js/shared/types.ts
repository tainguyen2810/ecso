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
