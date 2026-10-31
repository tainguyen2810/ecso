<?php

namespace Database\Seeders;

use App\Modules\Access\Models\Role;
use App\Modules\Billing\Models\Plan;
use App\Modules\Billing\Models\Subscription;
use App\Modules\Catalog\Models\Brand;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Services\ProductService;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockReceipt;
use App\Modules\Inventory\Models\Warehouse;
use App\Modules\Inventory\Services\InventoryDocumentService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\WarehouseService;
use App\Modules\Tenancy\Models\TenantUser;
use App\Modules\Tenancy\Services\TenantService;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Local/E2E demo data only. Refuses to run in production.
 */
class DemoSeeder extends Seeder
{
    public function run(TenantService $tenants): void
    {
        if (App::isProduction()) {
            $this->command?->error('DemoSeeder không chạy trên production.');

            return;
        }

        $this->call(DatabaseSeeder::class);

        $owner = $this->user('owner@demo.test', 'Nguyễn Văn Chủ');
        $staff = $this->user('staff@demo.test', 'Trần Thị Kho');
        $this->user('admin@demo.test', 'Super Admin', superAdmin: true);

        if ($owner->memberships()->exists()) {
            return;
        }

        $shop = $tenants->create($owner, ['name' => 'Cửa hàng Mẫu']);
        $tenants->create($owner, ['name' => 'Thời trang Demo']);

        // Demo shop on Starter so the members screen has room for invitations (free plan allows 2 users).
        Subscription::withoutGlobalScopes()->where('tenant_id', $shop->id)
            ->update(['plan_id' => Plan::query()->where('key', 'starter')->value('id')]);

        TenantContext::run($shop, fn () => TenantUser::create([
            'user_id' => $staff->id,
            'role_id' => Role::system(Role::WAREHOUSE_STAFF)->id,
            'status' => TenantUser::STATUS_ACTIVE,
            'joined_at' => now(),
        ]));

        Auth::setUser($owner);
        TenantContext::run($shop, fn () => $this->catalog($staff));
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
        $hanoi = app(WarehouseService::class)->create(['code' => 'HN', 'name' => 'Kho Hà Nội', 'address' => 'Cầu Giấy, Hà Nội']);

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
