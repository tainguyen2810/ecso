import { Activity, Bell, Building2, CreditCard, Flag, LayoutDashboard, LifeBuoy, ScrollText, Settings2 } from 'lucide-vue-next';
import type { Component } from 'vue';

export interface AdminNavItem {
    name: string;
    label: string;
    path: string;
    icon: Component;
    phase?: number;
}

export const adminNav: AdminNavItem[] = [
    { name: 'dashboard', label: 'Tổng quan', path: '/admin', icon: LayoutDashboard },
    { name: 'tenants', label: 'Khách hàng', path: '/admin/tenants', icon: Building2 },
    { name: 'plans', label: 'Gói & thanh toán', path: '/admin/plans', icon: CreditCard, phase: 6 },
    { name: 'monitoring', label: 'Giám sát hệ thống', path: '/admin/monitoring', icon: Activity, phase: 8 },
    { name: 'flags', label: 'Feature flags', path: '/admin/flags', icon: Flag, phase: 6 },
    { name: 'support', label: 'Hỗ trợ khách hàng', path: '/admin/support', icon: LifeBuoy, phase: 8 },
    { name: 'audit', label: 'Audit logs', path: '/admin/audit', icon: ScrollText, phase: 8 },
    { name: 'notifications', label: 'Thông báo', path: '/admin/notifications', icon: Bell, phase: 8 },
    { name: 'config', label: 'Cấu hình hệ thống', path: '/admin/config', icon: Settings2, phase: 8 },
];
