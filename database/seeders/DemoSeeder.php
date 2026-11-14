<?php

namespace Database\Seeders;

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Geo\Services\VnAddress;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Marketplace\Connectors\ConnectorRegistry;
use App\Modules\Marketplace\Connectors\Fake\FakeMarketplace;
use App\Modules\Marketplace\Services\AccountService;
use App\Modules\Marketplace\Services\ListingService;
use App\Modules\Orders\Models\Order;
use App\Modules\Orders\Models\OrderReturn;
use App\Modules\Orders\Services\OrderActions;
use App\Modules\Orders\Services\ReturnService;
use App\Modules\Purchasing\Services\PurchaseService;
use App\Modules\Purchasing\Services\SupplierPaymentService;
use App\Modules\Purchasing\Services\SupplierReturnService;
use App\Modules\Purchasing\Services\SupplierService;
use App\Modules\Sales\Models\CustomerGroup;
use App\Modules\Sales\Models\CustomerNote;
use App\Modules\Sales\Models\SalesChannel;
use App\Modules\Sales\Services\CashShiftService;
use App\Modules\Sales\Services\CustomerService;
use App\Modules\Sales\Services\ManualOrderService;
use App\Modules\Sales\Services\PaymentService;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Local/E2E demo data only. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    /** Base32 TOTP secret of admin@demo.test (demo data only). */
    public const DEMO_ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

    public function run(TenantService $tenants): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder không chạy trên production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        $owner = $this->user('owner@demo.test', 'Nguyễn Văn Chủ');
        $staff = $this->user('staff@demo.test', 'Trần Thị Kho');
        $admin = $this->user('admin@demo.test', 'Super Admin', superAdmin: true);
        // Demo only (this seeder refuses to run on production): a fixed TOTP secret so the demo admin and the
        // e2e tests can pass 2FA. Real super admins set up their own secret on first login.
        if ($admin->two_factor_confirmed_at === null) {
            $admin->forceFill(['two_factor_secret' => self::DEMO_ADMIN_TOTP_SECRET, 'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => []])->save();
        }
        // Super admin without 2FA yet: shows the first-login setup screen.
        $this->user('security@demo.test', 'Security Admin', superAdmin: true);

        if ($owner->memberships()->exists()) {
            return;
        }

        $shop = $tenants->create($owner, ['name' => 'Cửa hàng Mẫu']);
        $tenants->create($owner, ['name' => 'Thời trang Demo']);

        $this->populate($shop, $owner, $staff);
    }

    /**
     * Fills one business with the demo catalog, stock, own-channel sales, purchasing and (when the marketplace
     * simulator is on) a simulated shop. Also used by `ecso:demo-tenant`, which never enables the simulator.
     */
    public function populate(Tenant $shop, User $owner, ?User $staff = null, bool $paidPlan = true): void
    {
        // Demo shop on Starter so the members screen has room for invitations (free plan allows 2 users).
        // A paid monthly period that started 10 days ago (as if the first invoice had been paid).
        // A user's own sample store (A2) stays on the free plan, so billing never touches it.
        if ($paidPlan) {
            Subscription::withoutGlobalScopes()->where('tenant_id', $shop->id)->update([
                'plan_id' => Plan::query()->where('key', 'starter')->value('id'),
                'billing_cycle' => 'monthly',
                'status' => Subscription::STATUS_ACTIVE,
                'current_period_start' => now()->subDays(10),
                'current_period_end' => now()->subDays(10)->addMonth(),
            ]);
        }

        // Without a second login (a user's sample store), the owner plays the warehouse staff too.
        if ($staff === null) {
            $staff = $owner;
        } else {
            TenantContext::run($shop, fn () => TenantUser::create([
                'user_id' => $staff->id,
                'role_id' => Role::system(Role::WAREHOUSE_STAFF)->id,
                'status' => TenantUser::STATUS_ACTIVE,
                'joined_at' => now(),
            ]));
        }

        Auth::setUser($owner);
        TenantContext::run($shop, fn () => $this->catalog($staff));
        if (ConnectorRegistry::fakeEnabled()) {
            Auth::setUser($owner);
            TenantContext::run($shop, fn () => $this->marketplace($owner, $staff));
        }
        Auth::setUser($owner);
        TenantContext::run($shop, fn () => $this->sales());
        Auth::setUser($owner);
        TenantContext::run($shop, fn () => $this->purchasing());
    }

    /** Suppliers and purchase orders in every state, with deliveries, a return and payments (Phase 11). */
    private function purchasing(): void
    {
        $suppliers = app(SupplierService::class);
        $purchases = app(PurchaseService::class);

        $minhLong = $suppliers->create(['name' => 'Xưởng May Minh Long', 'contact_name' => 'Anh Long', 'phone' => '0283 845 1122', 'address' => 'KCN Tân Bình, TP.HCM', 'tax_code' => '0312345678']);
        $anPhat = $suppliers->create(['name' => 'Công ty TNHH Phụ Kiện An Phát', 'contact_name' => 'Chị Hạnh', 'phone' => '0243 765 4321', 'email' => 'sales@anphat.example', 'address' => 'Long Biên, Hà Nội']);
        $suppliers->create(['name' => 'Kho Sỉ Chợ Lớn', 'contact_name' => 'Cô Mai', 'phone' => '0908 222 333', 'address' => 'Quận 5, TP.HCM']);

        $warehouse = Warehouse::query()->where('is_default', true)->firstOrFail();
        // SKUs no order uses, so the deliveries below do not fill the demo orders' shortages.
        $variants = ProductVariant::query()->whereNotIn('id', DB::table('order_items')->where('tenant_id', app(TenantContext::class)->id())->whereNotNull('product_variant_id')->pluck('product_variant_id'))->orderBy('id')->limit(6)->get();
        if ($variants->count() < 5) {
            return;
        }
        $cost = fn (ProductVariant $v) => max(10000, (int) round((float) $v->ref_price * 0.55 / 1000) * 1000);
        $line = fn (int $i, int $qty) => ['variant_id' => $variants[$i]->id, 'quantity' => $qty, 'unit_cost' => $cost($variants[$i])];

        // Fully received, part paid, two defective items sent back.
        $done = $purchases->create(['supplier' => $minhLong, 'warehouse' => $warehouse, 'lines' => [$line(0, 40), $line(1, 30)], 'place' => true, 'note' => 'Hàng thu đông']);
        $purchases->receive($done, [['variant_id' => $variants[0]->id, 'quantity' => 40], ['variant_id' => $variants[1]->id, 'quantity' => 30]], 'HĐ 0001234');
        app(SupplierReturnService::class)->create($minhLong, $warehouse, [['variant_id' => $variants[0]->id, 'quantity' => 2]], $done->fresh(), 'Lỗi đường may');
        app(SupplierPaymentService::class)->record($minhLong, ['method' => 'bank_transfer', 'amount' => 1000000, 'reference' => 'UNC 26110301'], $done->fresh());

        // A damaged item written off (approved), shown as "Hủy / mất" in the stock report.
        $writeOff = app(InventoryDocumentService::class)->createAdjustment($warehouse, [['variant_id' => $variants[1]->id, 'mode' => 'delta', 'quantity' => -1]], 'damaged', 'Ố màu, không bán được');
        app(InventoryDocumentService::class)->approveAdjustment($writeOff, 'Đã kiểm tra');

        // Half arrived; the rest is still expected.
        $partial = $purchases->create(['supplier' => $anPhat, 'warehouse' => $warehouse, 'lines' => [$line(2, 50), $line(3, 20)], 'place' => true, 'expected_at' => now()->addDays(3)->toDateString()]);
        $purchases->receive($partial, [['variant_id' => $variants[2]->id, 'quantity' => 25]]);

        // Ordered with 10 % VAT and freight paid to a carrier, nothing arrived yet; and a draft.
        $purchases->create(['supplier' => $minhLong, 'warehouse' => $warehouse, 'lines' => [[...$line(1, 20), 'tax_rate' => 10], [...$line(4, 15), 'tax_rate' => 10]], 'place' => true,
            'expected_at' => now()->addDays(7)->toDateString(), 'extra_cost' => 150000, 'extra_cost_note' => 'Xe tải Minh Long → kho']);
        $purchases->create(['supplier' => $anPhat, 'warehouse' => $warehouse, 'lines' => [$line(3, 10)], 'note' => 'Chờ báo giá']);
    }

    /** Own sales channels, customers and manual orders in several states (Phase 9). */
    private function sales(): void
    {
        $orders = app(ManualOrderService::class);
        $payments = app(PaymentService::class);
        $customers = app(CustomerService::class);

        $pos = SalesChannel::query()->where('name', SalesChannel::DEFAULT_NAME)->firstOrFail();
        $facebook = SalesChannel::query()->create(['name' => 'Fanpage Cửa hàng Mẫu', 'type' => 'facebook', 'is_active' => true]);
        $zalo = SalesChannel::query()->create(['name' => 'Zalo OA', 'type' => 'zalo', 'is_active' => true]);

        $vip = CustomerGroup::query()->create(['code' => 'VIP', 'name' => 'Khách VIP', 'description' => 'Mua trên 5 triệu/năm', 'price_tier' => 'retail', 'discount_percent' => 5, 'payment_method' => 'bank_transfer']);
        $wholesale = CustomerGroup::query()->create(['code' => 'SI', 'name' => 'Khách sỉ', 'description' => 'Đại lý, cửa hàng lấy sỉ', 'price_tier' => 'wholesale', 'discount_percent' => 0, 'payment_method' => 'bank_transfer']);
        CustomerGroup::query()->create(['code' => 'LE', 'name' => 'Khách lẻ', 'price_tier' => 'retail', 'discount_percent' => 0]);

        $people = collect([
            ['name' => 'Lê Thị Hoa', 'phone' => '0912345678', 'street' => '12 Nguyễn Trãi', 'ward_code' => '26740', 'group_id' => $vip->ulid, 'birthday' => now()->subYears(32)->format('Y-m-').'14', 'gender' => 'female', 'tags' => ['Zalo', 'Khách quen']],
            ['name' => 'Phạm Minh Tuấn', 'phone' => '0987654321', 'street' => '45 Lý Thường Kiệt', 'ward_code' => '00082', 'group_id' => $wholesale->ulid, 'gender' => 'male', 'tax_code' => '0109876543', 'tags' => ['Đại lý']],
            ['name' => 'Đỗ Thu Trang', 'phone' => '0909111222', 'street' => '8 Trần Phú', 'ward_code' => '20242', 'birthday' => '1995-02-20', 'gender' => 'female'],
            ['name' => 'Võ Quốc Bảo', 'phone' => '0933444555', 'street' => '102 Hai Bà Trưng', 'ward_code' => '27154', 'group_id' => $vip->ulid, 'gender' => 'male', 'tags' => ['Khách quen']],
            ['name' => 'Ngô Thanh Hà', 'phone' => '0977888999', 'address' => '5 Lê Duẩn, Huế'],
        ])->map(fn ($p) => $customers->create($p));
        $people[1]->addresses()->make(['label' => 'Kho Long Biên', 'recipient' => 'Anh Tuấn', 'phone' => '0987654321'])
            ->forceFill(app(VnAddress::class)->resolve('22 Ngọc Lâm', null, '00118'))->save();
        // A second card of the same business (same tax code), to show the duplicate check and the merge.
        $customers->create(['name' => 'Công ty TNHH Thương mại PMT', 'type' => 'company', 'tax_code' => '0109876543', 'source' => 'zalo', 'invoice_name' => 'CÔNG TY TNHH THƯƠNG MẠI PMT', 'invoice_email' => 'ketoan@pmt.example']);
        (new CustomerNote(['body' => 'Hẹn thanh toán phần còn lại vào cuối tháng.']))->forceFill(['customer_id' => $people[1]->id, 'created_by' => Auth::id()])->save();

        $variants = ProductVariant::query()->whereIn('id', DB::table('stock_balances')->where('tenant_id', app(TenantContext::class)->id())->whereRaw('on_hand - reserved > 30')->pluck('product_variant_id'))->orderBy('id')->limit(6)->get();
        if ($variants->count() < 3) {
            return;
        }
        $line = fn (int $i, int $qty) => ['variant_id' => $variants[$i % $variants->count()]->ulid, 'quantity' => $qty];

        // Counter sales, paid at once, in a closed cashier shift that came up 20 000 short.
        $shifts = app(CashShiftService::class);
        /** @var User $cashier */
        $cashier = Auth::user();
        $shift = $shifts->open($cashier, $pos->warehouse ?? Warehouse::query()->where('is_default', true)->first(), ['opening_cash' => 500000]);
        foreach ([[0, 1], [1, 2], [2, 1]] as $k => [$i, $qty]) {
            $price = (string) $variants[$i % $variants->count()]->ref_price;
            $orders->create(['channel_id' => $pos->ulid, 'stage' => 'completed', 'customer_id' => $k === 0 ? $people[3]->ulid : null, 'lines' => [$line($i, $qty)],
                'payments' => [['method' => $k === 1 ? 'bank_transfer' : 'cash', 'amount' => bcmul($price, (string) $qty, 2)]]]);
        }
        $shifts->move($shift, ['kind' => 'in', 'amount' => 200000, 'reason' => 'Đổi tiền lẻ']);
        $expected = $shifts->totals($shift)['expected_cash'];
        $shifts->close($shift, ['counted_cash' => bcsub($expected, '20000', 2), 'handed_over' => bcsub($expected, '520000', 2), 'closing_note' => 'Thối nhầm cho khách']);
        $shift->forceFill(['opened_at' => now()->subDay()->setTime(8, 0), 'closed_at' => now()->subDay()->setTime(17, 30)])->save();

        // Online orders waiting to be processed.
        $orders->create(['channel_id' => $facebook->ulid, 'customer_id' => $people[0]->ulid, 'shipping_fee' => 30000, 'is_cod' => true, 'carrier' => 'GHN', 'lines' => [$line(0, 2), $line(3, 1)], 'note' => 'Gọi trước khi giao']);
        $orders->create(['channel_id' => $zalo->ulid, 'customer_id' => $people[2]->ulid, 'shipping_fee' => 25000, 'stage' => 'confirmed', 'lines' => [$line(4, 1)]]);
        $deposit = $orders->create(['channel_id' => $facebook->ulid, 'customer_id' => $people[1]->ulid, 'stage' => 'confirmed', 'discount' => 50000, 'lines' => [$line(1, 10), $line(2, 5)]]);
        $payments->record($deposit, ['method' => 'bank_transfer', 'amount' => 500000, 'reference' => 'FT26110312345']);
        // Shipped with COD still to collect, and a delivered order on credit (customer debt).
        $shipped = $orders->create(['channel_id' => $facebook->ulid, 'customer_id' => $people[4]->ulid, 'shipping_fee' => 30000, 'is_cod' => true, 'stage' => 'confirmed', 'lines' => [$line(5, 1)]]);
        $orders->transition($shipped, 'handover', ['carrier' => 'GHTK', 'tracking_number' => 'S2611031234']);
        // She stopped trading after this last order (no new orders can be made for her).
        $people[4]->forceFill(['status' => 'inactive'])->save();
        $credit = $orders->create(['channel_id' => $zalo->ulid, 'customer_id' => $people[1]->ulid, 'stage' => 'completed', 'lines' => [$line(3, 6)]]);
        $payments->record($credit, ['method' => 'cash', 'amount' => 300000]);
        // Cancelled before packing.
        $cancelled = $orders->create(['channel_id' => $facebook->ulid, 'customer_id' => $people[2]->ulid, 'lines' => [$line(0, 1)]]);
        $orders->transition($cancelled, 'cancel', ['reason' => 'Khách đặt nhầm mẫu']);
    }

    /**
     * A shop on the FAKE marketplace with orders in every state, created through the real webhook pipeline.
     * Skipped when the Fake marketplace is disabled.
     */
    private function marketplace(User $owner, User $staff): void
    {
        if (! config('marketplaces.fake.enabled')) {
            return;
        }

        $account = app(AccountService::class)->connectFake('Shop Demo (sàn giả lập)', null);
        app(ListingService::class)->confirmSuggestions($account);
        $fake = app(FakeMarketplace::class);
        $actions = app(OrderActions::class);

        $mapped = DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)
            ->where('price', '>', 0)->whereNotLike('seller_sku', '%-CU')->pluck('sku_id')->all();
        $unmapped = DB::table('fake_marketplace_products')->where('shop_id', $account->external_shop_id)
            ->whereLike('seller_sku', '%-CU')->value('sku_id');

        mt_srand(7);
        $make = function (string $status, array $overrides = [], ?array $lines = null) use ($fake, $account, $mapped) {
            $lines ??= array_map(fn ($k) => ['sku_id' => $mapped[$k], 'quantity' => mt_rand(1, 2)], (array) array_rand($mapped, mt_rand(1, 2)));

            return $fake->createOrder($account->external_shop_id, $lines, $status, $overrides)['order_sn'];
        };

        foreach (['fake_unpaid', 'fake_pending', 'fake_pending', 'fake_ready', 'fake_ready', 'fake_ready', 'fake_ready', 'fake_packed', 'fake_packed'] as $status) {
            $make($status);
        }
        // Due soon and overdue orders.
        $make('fake_ready', ['ship_by' => now()->addHours(3)->toIso8601String()]);
        $make('fake_ready', ['ship_by' => now()->subHours(2)->toIso8601String()]);
        // An order with a listing that is not linked to an internal SKU.
        if ($unmapped) {
            $make('fake_ready', [], [['sku_id' => $unmapped, 'quantity' => 1], ['sku_id' => $mapped[0], 'quantity' => 1]]);
        }
        // An order larger than the stock: partial reservation, flagged as shortage.
        $make('fake_ready', [], [['sku_id' => $mapped[1], 'quantity' => 60]]);

        foreach (['fake_shipped', 'fake_in_transit', 'fake_delivered', 'fake_completed', 'fake_completed'] as $status) {
            $sn = $make('fake_ready');
            $fake->setStatus($sn, $status);
        }
        $cancelled = $make('fake_ready');
        $fake->setStatus($cancelled, 'fake_cancelled', ['cancel_reason' => 'Khách đổi ý']);

        // Cancel after handover: a parcel on its way back.
        $back = $make('fake_ready');
        $fake->setStatus($back, 'fake_shipped');
        $fake->setStatus($back, 'fake_cancelled', ['cancel_reason' => 'Khách từ chối nhận']);

        // A customer return received at the warehouse, waiting for inspection.
        $returned = Order::query()->where('external_order_id', $make('fake_ready'))->firstOrFail();
        $fake->setStatus($returned->external_order_id, 'fake_delivered');
        $item = $returned->items()->first();
        $return = app(ReturnService::class)->create($returned, ['type' => OrderReturn::TYPE_RETURN, 'reason' => 'Sai kích cỡ', 'lines' => [['order_item_id' => $item->id, 'quantity' => 1]]]);
        app(ReturnService::class)->move($return, OrderReturn::APPROVED);
        app(ReturnService::class)->move($return, OrderReturn::RECEIVED);

        $ready = Order::query()->where('status', 'READY_TO_PACK')->orderBy('id')->get();
        $actions->assign($ready[0], $staff);
        $actions->assign($ready[1], $staff);
        $actions->addNote($ready[0], 'Khách dặn gói kỹ, giao giờ hành chính.');
    }

    /**
     * Products, two warehouses, receipts, adjustments and a transfer, all created through the real services
     * so the ledger is consistent (ecso:inventory:verify passes on demo data).
     */
    private function catalog(User $staff): void
    {
        $products = app(ProductService::class);
        $documents = app(InventoryDocumentService::class);
        $inventory = app(InventoryService::class);

        $categories = collect(['Áo', 'Quần', 'Phụ kiện'])->mapWithKeys(fn ($n) => [$n => Category::create(['name' => $n])->id]);
        $brands = collect(['ECSO Basic', 'Saigon Denim', 'Mộc'])->mapWithKeys(fn ($n) => [$n => Brand::create(['name' => $n])->id]);

        $catalog = [
            ['Áo thun cotton cổ tròn', 'Áo', 'ECSO Basic', 'ATC', ['Trắng', 'Đen'], ['M', 'L'], 159000, 72000],
            ['Áo sơ mi linen tay dài', 'Áo', 'Mộc', 'SML', ['Be', 'Xanh'], ['M', 'L'], 389000, 175000],
            ['Áo polo pique', 'Áo', 'ECSO Basic', 'POL', ['Navy'], ['M', 'L', 'XL'], 249000, 110000],
            ['Quần jean slim fit', 'Quần', 'Saigon Denim', 'QJS', ['Xanh đậm'], ['29', '30', '31'], 459000, 210000],
            ['Quần kaki ống suông', 'Quần', 'Mộc', 'QKK', ['Kem', 'Rêu'], ['30', '32'], 359000, 160000],
            ['Mũ lưỡi trai thêu logo', 'Phụ kiện', 'ECSO Basic', 'MLT', ['Đen'], [], 129000, 45000],
            ['Túi tote canvas', 'Phụ kiện', 'Mộc', 'TTC', ['Mộc'], [], 189000, 70000],
            ['Tất cổ ngắn (combo 3 đôi)', 'Phụ kiện', 'ECSO Basic', 'TAT', [], [], 79000, 30000],
        ];

        $variants = [];
        foreach ($catalog as [$name, $category, $brand, $code, $colors, $sizes, $price, $cost]) {
            $combos = [];
            foreach ($colors ?: [null] as $color) {
                foreach ($sizes ?: [null] as $size) {
                    $combos[] = array_filter(['Màu' => $color, 'Size' => $size]);
                }
            }

            $product = $products->create([
                'name' => $name,
                'category_id' => $categories[$category],
                'brand_id' => $brands[$brand],
                'status' => 'active',
                'variants' => array_map(fn ($options) => [
                    'sku' => $code.($options ? '-'.Str::upper(Str::slug(implode('-', $options))) : ''),
                    'name' => implode(' / ', $options) ?: null,
                    'options' => $options ?: null,
                    'ref_price' => $price,
                ], $combos),
            ]);

            foreach ($product->variants()->get() as $variant) {
                $variants[] = ['variant' => $variant, 'cost' => $cost];
            }
        }

        $main = Warehouse::query()->where('is_default', true)->firstOrFail();
        $hanoi = app(WarehouseService::class)->create(['code' => 'HN', 'name' => 'Kho Hà Nội', 'street' => '120 Xuân Thủy', 'ward_code' => '00166']);

        mt_srand(2810);
        $opening = array_map(fn ($v) => [
            'variant_id' => $v['variant']->id, 'quantity' => mt_rand(0, 4) === 0 ? 3 : mt_rand(15, 80), 'unit_cost' => $v['cost'],
        ], $variants);
        $documents->receive($main, $opening, 'Tồn đầu kỳ', StockReceipt::SOURCE_OPENING);
        $documents->receive($hanoi, array_map(fn ($v) => [
            'variant_id' => $v['variant']->id, 'quantity' => mt_rand(5, 25), 'unit_cost' => $v['cost'],
        ], array_slice($variants, 0, 8)), 'Tồn đầu kỳ kho Hà Nội', StockReceipt::SOURCE_OPENING);
        $documents->receive($main, [
            ['variant_id' => $variants[0]['variant']->id, 'quantity' => 40, 'unit_cost' => 78000],
            ['variant_id' => $variants[1]['variant']->id, 'quantity' => 40, 'unit_cost' => 78000],
        ], 'Nhập bổ sung từ xưởng');

        // Safety stock on fast movers, and a few demo reservations (order engine arrives in Phase 3).
        foreach ($variants as $i => $v) {
            // Fast movers and the SKUs that are almost gone get a safety stock, so "Sắp hết" has data.
            if ($i < 6 || $opening[$i]['quantity'] <= 5) {
                $inventory->setSafetyStock($main, $v['variant'], 5);
            }
        }
        foreach (array_slice($variants, 0, 5) as $i => $v) {
            $inventory->reserve($main, $v['variant'], mt_rand(1, 4), 'demo_order', 1000 + $i, "demo-reserve-{$i}");
        }

        $approved = $documents->createAdjustment($main, [
            ['variant_id' => $variants[2]['variant']->id, 'mode' => 'count', 'quantity' => 18],
            ['variant_id' => $variants[3]['variant']->id, 'mode' => 'delta', 'quantity' => -2],
        ], 'stocktake', 'Kiểm kê cuối tháng');
        $documents->approveAdjustment($approved, 'Đã đối chiếu');

        Auth::setUser($staff);
        $documents->createAdjustment($main, [
            ['variant_id' => $variants[5]['variant']->id, 'mode' => 'delta', 'quantity' => -1],
        ], 'damaged', 'Rách đường may khi đóng gói');

        $transfer = $documents->createTransfer($main, $hanoi, [
            ['variant_id' => $variants[0]['variant']->id, 'quantity' => 10],
            ['variant_id' => $variants[4]['variant']->id, 'quantity' => 5],
        ], 'Bổ sung hàng cho kho Hà Nội');
        $documents->dispatchTransfer($transfer);
        $documents->createTransfer($hanoi, $main, [['variant_id' => $variants[8]['variant']->id, 'quantity' => 2]], 'Trả hàng về kho chính');
    }

    private function user(string $email, string $name, bool $superAdmin = false): User
    {
        $user = User::query()->firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => 'password1',
            'email_verified_at' => now(),
            'is_super_admin' => $superAdmin,
        ])->save();

        return $user;
    }
}
