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
    store_counter: number;
    store_online: number;
    weeks: { label: string; from: string; to: string; amount: number }[];
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
    approved_by: string | null;
    lines: PayLine[];
    advances: { name: string; member: string; paid_at: string | null; method: string | null; amount: number }[];
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
        work: 'bg-emerald-100 text-emerald-800',
        late: 'bg-amber-200 text-amber-900',
        leave: 'bg-sky-100 text-sky-700',
        off: 'bg-rose-100 text-rose-700',
        open: 'bg-blue-600 text-white ring-2 ring-blue-200',
        no_out: 'bg-orange-100 text-orange-700',
    }[c.status];
}

export const thisMonth = (now = new Date()) => `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}`;
export function shiftMonth(month: string, by: number): string {
    const [y, m] = month.split('-').map(Number) as [number, number];
    const n = y * 12 + m - 1 + by;
    return `${Math.floor(n / 12)}-${String((n % 12) + 1).padStart(2, '0')}`;
}
export const monthLabel = (month: string) => `Tháng ${Number(month.slice(5))}/${month.slice(0, 4)}`;

export const WEEKDAYS = ['CN', 'T2', 'T3', 'T4', 'T5', 'T6', 'T7'] as const;

/** Headline figures of a month of attendance: who works today, work days, late arrivals, days to look at again. */
export function attendanceSummary(m: AttendanceMonth) {
    const today = m.today === null ? [] : m.members.map((r) => ({ r, c: r.days[String(m.today)] }));
    const cells = m.members.flatMap((r) => Object.values(r.days));
    const lateMembers = m.members.filter((r) => Object.values(r.days).some((c) => c.late > 0)).length;
    return {
        members: m.members.length,
        workingToday: today.filter(({ c }) => c && c.status !== 'leave' && c.status !== 'off').length,
        onShift: today.filter(({ c }) => c?.status === 'open').length,
        awayToday: today.filter(({ c }) => c?.status === 'leave' || c?.status === 'off').map(({ r }) => r.name),
        workDays: m.members.reduce((s, r) => s + r.work_days, 0),
        hours: Math.round(m.members.reduce((s, r) => s + r.hours, 0) * 10) / 10,
        late: cells.filter((c) => c.late > 0).length,
        lateMembers,
        noOut: cells.filter((c) => c.status === 'no_out').length,
        unpaid: cells.filter((c) => c.status === 'off').length,
    };
}

const AVATAR_TONES = ['bg-blue-50 text-blue-600', 'bg-emerald-50 text-emerald-600', 'bg-violet-50 text-violet-600', 'bg-amber-50 text-amber-600', 'bg-pink-50 text-pink-600', 'bg-slate-100 text-slate-600'];

/** Round avatar of a member: first and last initials ("Đỗ Ngọc Mai" → "ĐM") on a colour fixed by the name. */
export function avatar(name: string): { text: string; tone: string } {
    const words = name.trim().split(/\s+/).filter(Boolean);
    const text = (words.length > 1 ? `${words[0]![0]}${words[words.length - 1]![0]}` : (words[0] ?? '?').slice(0, 2)).toUpperCase();
    const hash = [...name].reduce((h, c) => (h * 31 + c.charCodeAt(0)) >>> 0, 7);
    return { text, tone: AVATAR_TONES[hash % AVATAR_TONES.length]! };
}
