<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Marketplace\Models\ChannelPriceRule;
use App\Modules\Marketplace\Models\FeeAlert;
use App\Modules\Marketplace\Models\ListingVariant;
use App\Modules\Marketplace\Models\MarketplaceSettlement;
use App\Modules\Marketplace\Notifications\FeeAlertNotification;
use App\Modules\Marketplace\Services\MarketplacePricing;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/*
 * Round 3: marketplace prices from fee tables, the price board and price file, settlement import and the fee check.
 * Runs against the fake marketplace (no marketplace price API yet: applied prices go to the price file).
 */

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->card = makeVariant($this->tenant, 'AX210', ['product_name' => 'Card WiFi AX210', 'ref_price' => 454000]);
    stockIn($this->tenant, $this->card, 10); // cost 50 000 (helper)
    TenantContext::run($this->tenant, fn () => DB::table('product_variants')->where('id', $this->card->id)->update(['cost_price' => 320000]));
    $this->shop = fakeShop($this->tenant);
    $this->api = actingInTenant($this->owner, $this->tenant);
});

function feeTable($test, array $shop = [], array $categories = [])
{
    return $test->api->putJson("/api/v1/marketplace-pricing/rules/{$test->shop->ulid}", [
        'shop' => $shop + ['commission_pct' => 9, 'payment_pct' => 4.9, 'service_pct' => 6, 'fixed_fee' => 3000, 'rounding' => 100, 'min_margin_pct' => 8, 'auto_apply' => false, 'auto_update_fees' => false],
        'categories' => $categories,
    ]);
}

function settlementFile(array $rows): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
    $w = new XlsxWriter;
    $w->openToFile($path);
    $w->addRow(Row::fromValues(['Báo cáo đối soát (tiêu đề của sàn)']));
    $w->addRow(Row::fromValues(['Mã đơn hàng', 'Ngày đối soát', 'Giá trị hàng', 'Phí hoa hồng', 'Phí thanh toán', 'Phí dịch vụ', 'Phí cố định mỗi đơn', 'Thực nhận']));
    foreach ($rows as $r) {
        $w->addRow(Row::fromValues($r));
    }
    $w->close();

    return new UploadedFile($path, 'doi-soat.xlsx', null, null, true);
}

it('divides by the fees, rounds up and keeps the minimum margin', function () {
    $fee = ['total_pct' => 19.9, 'fixed_fee' => 3000.0, 'rounding' => 100, 'min_margin_pct' => 8.0];
    $q = MarketplacePricing::quote(454000, 320000, $fee);
    expect($q['price'])->toBe(570600.0)->and($q['net'])->toBe(454050.6)->and($q['raised'])->toBeFalse();

    // Base below cost: raised so that what is left after fees is cost + 8 %.
    $low = MarketplacePricing::quote(39000, 38000, $fee);
    expect($low['raised'])->toBeTrue()->and($low['price'])->toBe(55000.0)->and($low['net'])->toBeGreaterThanOrEqual(38000 * 1.08);
});

it('shows reference fees until saved, then puts the new marketplace price on the board and in the price file', function () {
    $this->api->getJson('/api/v1/marketplace-pricing/rules')->assertOk()->assertJsonPath('data.0.saved', false);
    feeTable($this)->assertOk();
    // The shop rule is not listed again as a category rule.
    $this->api->getJson('/api/v1/marketplace-pricing/rules')->assertJsonPath('data.0.saved', true)->assertJsonCount(0, 'data.0.categories');

    $board = $this->api->getJson('/api/v1/marketplace-pricing/board')->assertOk()->assertJsonPath('counts.pending', 1);
    $cell = $board->json('data.0.cells.'.$this->shop->ulid);
    expect($cell['new'])->toEqual(570600)->and($cell['status'])->toBeIn(['pending', 'loss']);

    $this->api->postJson('/api/v1/marketplace-pricing/board/apply', ['all' => true])->assertOk()->assertJsonPath('data.exported', 1);
    $this->api->getJson('/api/v1/marketplace-pricing/board?tab=all')->assertJsonPath('data.0.cells.'.$this->shop->ulid.'.status', 'export');

    $file = $this->api->get("/api/v1/marketplace-pricing/board/export?account={$this->shop->ulid}")->assertOk();
    expect($file->streamedContent())->not->toBeEmpty();
    expect(ListingVariant::withoutGlobalScopes()->where('product_variant_id', $this->card->id)->value('target_price'))->toBe('570600.00');
});

it('leaves locked prices alone and follows a new base price when auto apply is on', function () {
    feeTable($this, ['auto_apply' => true])->assertOk()->assertJsonPath('data.applied', 1);
    $listing = ListingVariant::withoutGlobalScopes()->where('product_variant_id', $this->card->id)->sole();

    TenantContext::run($this->tenant, fn () => ProductVariant::query()->find($this->card->id)->update(['ref_price' => 500000]));
    // (500 000 + 3 000) / 0.801 = 627 965.04 → 628 000
    expect($listing->fresh()->target_price)->toBe('628000.00');

    $this->api->postJson('/api/v1/marketplace-pricing/board/lock', ['listing_ids' => [$listing->ulid], 'locked' => true])->assertOk();
    TenantContext::run($this->tenant, fn () => ProductVariant::query()->find($this->card->id)->update(['ref_price' => 520000]));
    expect($listing->fresh()->target_price)->toBe('628000.00');
    $this->api->getJson('/api/v1/marketplace-pricing/board?tab=locked')->assertJsonPath('counts.locked', 1);
});

it('imports a settlement report, measures the actual fees and raises a drift alert that can be applied', function () {
    Notification::fake();
    feeTable($this)->assertOk();
    $orders = collect(range(1, 20))->map(fn () => fakeOrder($this->shop, [[$this->card, 1]]));
    // The marketplace charged 9 % + 5.6 % + 6 % (payment fee went up), 3 000 đ per order.
    $rows = $orders->map(fn ($o) => [$o->external_order_id, '03/11/2026', 570600, 51354, 31954, 34236, 3000, 450056])->all();

    $this->api->post('/api/v1/marketplace-pricing/settlements/import', ['account' => $this->shop->ulid, 'file' => settlementFile($rows)])
        ->assertOk()->assertJsonPath('data.imported', 20)->assertJsonPath('data.linked', 20);

    $fees = $this->api->getJson('/api/v1/marketplace-pricing/fees')->assertOk();
    expect($fees->json('data.0.actual.payment_pct'))->toEqual(5.6)
        ->and($fees->json('data.0.actual.total_pct'))->toEqual(20.6)
        ->and($fees->json('alerts.0.type'))->toBe('fee_drift');

    $this->api->postJson('/api/v1/marketplace-pricing/alerts/'.$fees->json('alerts.0.id').'/apply')->assertOk()->assertJsonPath('data.status', 'applied');
    $rule = ChannelPriceRule::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->whereNull('category_id')->sole();
    expect($rule->payment_pct)->toBe('5.60')->and($rule->suggested_from['orders'])->toBe(20);

    // The same figures do not raise the alert again.
    $this->artisan('ecso:marketplace:check-fees')->assertSuccessful();
    expect(FeeAlert::withoutGlobalScopes()->where('type', 'fee_drift')->where('status', 'open')->count())->toBe(0);
});

it('updates the fee table by itself when asked, and flags orders sold at a loss after fees', function () {
    Notification::fake();
    feeTable($this, ['auto_update_fees' => true])->assertOk();
    $orders = collect(range(1, 20))->map(fn () => fakeOrder($this->shop, [[$this->card, 1]]));
    DB::table('order_items')->whereIn('order_id', $orders->pluck('id'))->update(['cost_snapshot' => 320000]);
    $rows = $orders->map(fn ($o, $i) => [$o->external_order_id, now()->format('d/m/Y'), 570600, 51354, 31954, 34236, 3000, $i === 0 ? 300000 : 450056])->all();
    foreach ($rows as $r) {
        TenantContext::run($this->tenant, fn () => MarketplaceSettlement::query()->create([
            'marketplace_account_id' => $this->shop->id, 'external_order_id' => $r[0], 'order_id' => $orders->firstWhere('external_order_id', $r[0])->id,
            'settled_at' => now(), 'order_amount' => $r[2], 'commission_fee' => $r[3], 'payment_fee' => $r[4], 'service_fee' => $r[5], 'fixed_fee' => $r[6], 'net_amount' => $r[7],
        ]));
    }

    $this->artisan('ecso:marketplace:check-fees')->assertSuccessful();

    $rule = ChannelPriceRule::withoutGlobalScopes()->where('marketplace_account_id', $this->shop->id)->whereNull('category_id')->sole();
    expect((float) $rule->payment_pct)->toBe(5.6)
        ->and(FeeAlert::withoutGlobalScopes()->where('type', 'fee_drift')->value('status'))->toBe('applied')
        ->and(FeeAlert::withoutGlobalScopes()->where('type', 'loss_orders')->value('data')['count'])->toBe(1);
    Notification::assertSentTo($this->owner, FeeAlertNotification::class);
});

it('refuses a file without an order id column and lists nothing as imported', function () {
    $path = tempnam(sys_get_temp_dir(), 'ds').'.xlsx';
    $w = new XlsxWriter;
    $w->openToFile($path);
    $w->addRow(Row::fromValues(['Ngày', 'Số tiền']));
    $w->close();

    $this->api->post('/api/v1/marketplace-pricing/settlements/import', ['account' => $this->shop->ulid, 'file' => new UploadedFile($path, 'x.xlsx', null, null, true)])
        ->assertStatus(422);
    expect(MarketplaceSettlement::withoutGlobalScopes()->count())->toBe(0);
});
