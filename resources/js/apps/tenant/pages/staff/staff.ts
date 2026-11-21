/** Nhân viên › Chấm công, Hoa hồng, Bảng lương: shapes the server returns and small helpers. */

export interface StaffProfile {
    title: string | null;
    pay_type: 'month' | 'hour';
    base_salary: number;
    hourly_rate: number;
    standard_days: number;
    allowance: number;
    insured_salary: number;
    dependents: number;
    sales_target: number;
    shift_start: string;
    shift_end: string;
    set: boolean;
}

export type DayStatus = 'work' | 'late' | 'leave' | 'off' | 'open' | 'no_out';
export interface DayCell {
    status: DayStatus;
    hours: number;
    late: number;
    in: string | null;
    out: string | null;
    source: 'shift' | 'phone' | 'manual';
    shifts: string[];
    note: string | null;
    located: boolean;
}
export interface AttendanceRow {
    id: string;
    name: string;
    title: string;
    shift: string;
    days: Record<string, DayCell>;
    hours: number;
    work_days: number;
    late: number;
}
export interface AttendanceMonth {
    period: string;
    label: string;
    days: number;
    today: number | null;
    weekends: number[];
    members: AttendanceRow[];
    manage: boolean;
}

export type RuleKind = 'counter' | 'online' | 'category_unit' | 'store_bonus';
export interface CommissionRule {
    id: string;
    name: string;
    kind: RuleKind;
    rate: number;
    amount: number;
    category_id: string | null;
    category: string | null;
    threshold: number;
    user_ids: string[] | null;
    is_active: boolean;
}
export interface CommissionRow {
    id: string;
    name: string;
    title: string;
    sales: number;
    commission: number;
    items: { rule: string; name: string; kind: RuleKind; base: number; unit: 'money' | 'units'; amount: number }[];
    target: number;
    target_pct: number | null;
}
export interface CommissionMonth {
    period: string;
    label: string;
    store_revenue: number;
    members: CommissionRow[];
    rules: CommissionRule[];
    manage: boolean;
}

export interface PayLine {
    id: string;
    name: string;
    title: string;
    pay_type: 'month' | 'hour';
    days: number;
    standard_days: number;
    hours: number;
    base_pay: number;
    commission: number;
    allowance: number;
    bonus: number;
    deduction: number;
    advance: number;
    insurance: number;
    employer_insurance: number;
    pit: number;
    gross: number;
    net: number;
    note: string | null;
    detail: { label: string; amount: number }[];
    profile: StaffProfile;
}
export interface Payroll {
    period: string;
    label: string;
    status: 'draft' | 'approved' | 'paid';
    approved_at: string | null;
    paid_at: string | null;
    lines: PayLine[];
    totals: { net: number; commission: number; insurance: number; employer_insurance: number; pit: number; count: number };
    rules: { employee_insurance: number; employer_insurance: number; personal_deduction: number; dependent_deduction: number };
    manage: boolean;
}

export const RULE_KINDS: Record<RuleKind, { label: string; hint: string }> = {
    counter: { label: 'Bán tại quầy', hint: '% doanh số các đơn bán trong ca' },
    online: { label: 'Bán online (chat, web, điện thoại)', hint: '% doanh số đơn nhân viên tạo ngoài quầy, đã giao' },
    category_unit: { label: 'Theo sản phẩm / dịch vụ', hint: 'Số tiền cho mỗi sản phẩm thuộc một danh mục (VD: mỗi lần lắp đặt)' },
    store_bonus: { label: 'Thưởng vượt mức', hint: 'Cộng thêm % doanh số mỗi người khi doanh số tháng của cửa hàng vượt mức' },
};

/** "8%" / "50.000 đ / sản phẩm" / "+0,5% khi cửa hàng > 500 triệu". */
export function ruleValue(r: Pick<CommissionRule, 'kind' | 'rate' | 'amount' | 'threshold'>, money: (n: number) => string): string {
    const pct = `${String(r.rate).replace('.', ',')}%`;
    if (r.kind === 'category_unit') return `${money(r.amount)} / sản phẩm`;
    if (r.kind === 'store_bonus') return `+${pct} khi cửa hàng vượt ${money(r.threshold)}`;
    return `${pct} doanh số`;
}

/** Short text in a day cell: hours worked, "P" paid leave, "N" day off, "?" checked in without checking out. */
export function cellText(c: DayCell | undefined): string {
    if (!c) return '';
    if (c.status === 'leave') return 'P';
    if (c.status === 'off') return 'N';
    if (c.status === 'open') return '…';
    if (c.status === 'no_out') return '?';
    return String(c.hours).replace('.', ',');
}

export function cellClass(c: DayCell | undefined): string {
    if (!c) return '';
    return {
        work: 'bg-emerald-50 text-emerald-800',
        late: 'bg-amber-100 text-amber-900',
        leave: 'bg-sky-50 text-sky-800',
        off: 'bg-rose-50 text-rose-700',
        open: 'bg-blue-50 text-blue-700',
        no_out: 'bg-orange-50 text-orange-700',
    }[c.status];
}

export const thisMonth = (now = new Date()) => `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
export function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number) as [number, number];
    const n = y * 12 + m - 1 + by;
    return `${Math.floor(n / 12)}-${String((n % 12) + 1).padStart(2, '0')}`;
}
export const monthLabel = (month: string) => `Tháng ${Number(month.slice(5))}/${month.slice(0, 4)}`;
