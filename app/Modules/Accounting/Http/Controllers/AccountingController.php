<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\BookExporter;
use App\Modules\Accounting\Services\Books;
use App\Modules\Accounting\Support\HouseholdTax;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Tenancy\Support\TenantContext;
use App\Modules\Tenancy\Support\TenantSettings;
use App\Shared\Support\ReportRange;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Kế toán, thuế: tổng quan thuế, sổ sách, tờ khai, thiết lập thuế. Xem: finance.view; thiết lập: settings.manage. */
class AccountingController extends Controller
{
    public function __construct(private TenantContext $context) {}

    public function overview(Request $request, Books $books): JsonResponse
    {
        abort_unless($this->context->can('finance.view'), 403);
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2020', 'max:2100']])['year'] ?? ReportRange::now()->year);

        return response()->json(['data' => $books->overview($year)]);
    }

    public function booksSummary(Request $request, Books $books): JsonResponse
    {
        abort_unless($this->context->can('finance.view'), 403);
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? ReportRange::now()->format('Y-m');

        return response()->json(['data' => $books->summary($month)]);
    }

    public function book(Request $request, string $book, Books $books, BookExporter $exporter): JsonResponse|StreamedResponse
    {
        abort_unless($this->context->can('finance.view'), 403);
        abort_unless(array_key_exists($book, Books::BOOKS), 404);
        if ($book === 's2') {
            abort_unless($this->context->can('inventory.view'), 403);
        }
        $month = $request->validate(['month' => ['nullable', 'date_format:Y-m']])['month'] ?? ReportRange::now()->format('Y-m');
        $data = $books->book($book, $month);
        if ($request->boolean('export')) {
            app(AuditLogger::class)->record('report.exported', null, ['report' => 'book-'.$book, 'month' => $month]);

            return $exporter->book($data);
        }

        return response()->json(['data' => $data]);
    }

    public function declaration(Request $request, Books $books, BookExporter $exporter): JsonResponse|StreamedResponse
    {
        abort_unless($this->context->can('finance.view'), 403);
        $period = $request->validate(['period' => ['nullable', 'string', 'regex:/^\d{4}-(Q[1-4]|\d{2})$/']])['period'] ?? null;
        $tax = HouseholdTax::current();
        // Default: the last period that has ended, the one to declare now.
        $period ??= $tax->periodOf(HouseholdTax::bounds($tax->periodOf(ReportRange::now()))[0]->subDay());
        $data = $books->declaration($period);
        if ($request->boolean('export')) {
            return $exporter->declaration($data);
        }

        return response()->json(['data' => $data]);
    }

    public function settings(Books $books): JsonResponse
    {
        abort_unless($this->context->can('finance.view'), 403);

        return response()->json(['data' => $books->settingsView()]);
    }

    public function saveSettings(Request $request, Books $books, AuditLogger $audit): JsonResponse
    {
        abort_unless($this->context->can('settings.manage'), 403);
        $groups = array_keys(HouseholdTax::GROUPS);
        $data = $request->validate([
            'period' => ['required', 'in:month,quarter'],
            'threshold' => ['required', 'numeric', 'min:0', 'max:100000000000'],
            'taxpayer_name' => ['nullable', 'string', 'max:120'],
            'default_group' => ['required', Rule::in($groups)],
            'include_shipping' => ['boolean'],
            'category_groups' => ['nullable', 'array', 'max:500'],
            'category_groups.*' => [Rule::in($groups)],
            'rates' => ['required', 'array'],
            'rates.*.vat' => ['required', 'numeric', 'min:0', 'max:20'],
            'rates.*.pit' => ['required', 'numeric', 'min:0', 'max:20'],
        ]);
        $tenant = $this->context->tenant();
        $settings = $tenant->settings ?? [];
        $settings['household_tax'] = [
            'period' => $data['period'],
            'threshold' => (float) $data['threshold'],
            'taxpayer_name' => $data['taxpayer_name'] ?? null,
            'default_group' => $data['default_group'],
            'include_shipping' => (bool) ($data['include_shipping'] ?? false),
            'category_groups' => array_filter((array) ($data['category_groups'] ?? []), fn ($g, $k) => is_string($k) && strlen($k) <= 40, ARRAY_FILTER_USE_BOTH),
            'rates' => array_map(fn ($r) => ['vat' => (float) $r['vat'], 'pit' => (float) $r['pit']], array_intersect_key($data['rates'], array_flip($groups))) + TenantSettings::DEFAULTS['household_tax']['rates'],
        ];
        $tenant->settings = $settings;
        $tenant->save();
        $audit->record('tenant.settings_updated', $tenant, ['section' => 'household_tax']);

        return response()->json(['data' => $books->settingsView()]);
    }
}
