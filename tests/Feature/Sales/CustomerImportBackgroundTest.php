<?php

use App\Modules\Access\Models\Role;
use App\Modules\Audit\Services\AuditLogger;
use App\Modules\Sales\Jobs\ImportCustomersJob;
use App\Modules\Sales\Models\Customer;
use App\Modules\Sales\Models\CustomerImport;
use App\Modules\Sales\Services\CustomerImporter;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

function bigCustomerCsv(int $rows, ?callable $row = null, string $header = 'Tên khách hàng,Số điện thoại,Ghi chú'): UploadedFile
{
    $lines = [$header];
    for ($i = 1; $i <= $rows; $i++) {
        $lines[] = $row ? $row($i) : sprintf('Khách %d,09%08d,Nhập lô', $i, 10000000 + $i);
    }

    return UploadedFile::fake()->createWithContent('khach-lon.csv', "\xEF\xBB\xBF".implode("\n", $lines));
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->as = fn ($user = null) => actingInTenant($user ?? $this->owner, $this->tenant);
    config(['ecso.customer_import.sync_rows' => 5, 'ecso.customer_import.background_rows' => 40]);
    $this->count = fn () => TenantContext::run($this->tenant, fn () => Customer::query()->count());
});

it('checks a large file in the background, then imports every row in one go', function () {
    // Within the limit for an import done at once: unchanged behaviour.
    ($this->as)()->postJson('/api/v1/customers/import', ['file' => bigCustomerCsv(5), 'dry_run' => true])->assertOk()->assertJsonPath('created', 5);

    $res = ($this->as)()->postJson('/api/v1/customers/import', ['file' => bigCustomerCsv(30), 'dry_run' => true])->assertStatus(202)->assertJsonPath('queued', true);
    $id = $res->json('import.id');
    // (the test queue runs jobs at once: the check is already done)
    $check = ($this->as)()->getJson("/api/v1/customers/imports/{$id}")->assertOk()->json('data');
    expect($check)->toMatchArray(['status' => 'checked', 'total' => 30, 'created' => 30, 'updated' => 0, 'error_count' => 0])
        ->and(($this->count)())->toBe(0);
    ($this->as)()->getJson('/api/v1/customers/imports/latest')->assertJsonPath('data.id', $id);

    ($this->as)()->postJson("/api/v1/customers/imports/{$id}/run")->assertOk();
    $done = ($this->as)()->getJson("/api/v1/customers/imports/{$id}")->json('data');
    expect($done)->toMatchArray(['status' => 'done', 'created' => 30, 'processed' => 30])->and(($this->count)())->toBe(30);
    // Run twice: refused, nothing imported again; the file is gone once imported.
    ($this->as)()->postJson("/api/v1/customers/imports/{$id}/run")->assertUnprocessable();
    expect(($this->count)())->toBe(30)
        ->and(CustomerImport::query()->withoutGlobalScopes()->where('ulid', $id)->value('path'))->toBeNull();
    $card = TenantContext::run($this->tenant, fn () => Customer::query()->where('name', 'Khách 7')->first());
    expect($card->source)->toBe('import')->and($card->created_by)->toBe($this->owner->id);
});

it('writes nothing when one row is wrong, even after the check', function () {
    $bad = bigCustomerCsv(12, fn ($i) => $i === 9 ? 'Khách 9,12345,' : sprintf('Khách %d,09%08d,', $i, 20000000 + $i));
    $id = ($this->as)()->postJson('/api/v1/customers/import', ['file' => $bad, 'dry_run' => true])->assertStatus(202)->json('import.id');
    $check = ($this->as)()->getJson("/api/v1/customers/imports/{$id}")->json('data');
    expect($check['status'])->toBe('failed')->and($check['error_count'])->toBe(1)->and($check['errors'][0]['row'])->toBe(10);
    ($this->as)()->postJson("/api/v1/customers/imports/{$id}/run")->assertUnprocessable();

    // Checked, then the group the rows name is deleted before the import runs: nothing is written.
    $group = ($this->as)()->postJson('/api/v1/customer-groups', ['name' => 'Sỉ', 'price_tier' => 'wholesale'])->assertCreated()->json('data.id');
    Queue::fake();
    $file = bigCustomerCsv(12, fn ($i) => sprintf('Khách %d,09%08d,Sỉ', $i, 30000000 + $i), 'Tên khách hàng,Số điện thoại,Nhóm khách hàng');
    $id = ($this->as)()->postJson('/api/v1/customers/import', ['file' => $file, 'dry_run' => true])->json('import.id');
    $import = CustomerImport::query()->withoutGlobalScopes()->where('ulid', $id)->first();
    (new ImportCustomersJob($import->id, ImportCustomersJob::CHECK))->handle(app(CustomerImporter::class), app(AuditLogger::class));
    expect(CustomerImport::query()->withoutGlobalScopes()->where('ulid', $id)->value('status'))->toBe(CustomerImport::CHECKED);
    ($this->as)()->deleteJson("/api/v1/customer-groups/{$group}")->assertSuccessful();
    ($this->as)()->postJson("/api/v1/customers/imports/{$id}/run")->assertOk();
    Queue::assertPushed(ImportCustomersJob::class, fn ($job) => $job->mode === ImportCustomersJob::IMPORT);
    (new ImportCustomersJob($import->id, ImportCustomersJob::IMPORT))->handle(app(CustomerImporter::class), app(AuditLogger::class));
    // the same job delivered twice does nothing the second time
    (new ImportCustomersJob($import->id, ImportCustomersJob::IMPORT))->handle(app(CustomerImporter::class), app(AuditLogger::class));

    expect(($this->as)()->getJson("/api/v1/customers/imports/{$id}")->json('data'))->toMatchArray(['status' => 'failed', 'error_count' => 12])
        ->and(($this->count)())->toBe(0);
});

it('refuses files over the background limit, other businesses and staff without import rights', function () {
    $id = ($this->as)()->postJson('/api/v1/customers/import', ['file' => bigCustomerCsv(41), 'dry_run' => true])->assertStatus(202)->json('import.id');
    expect(($this->as)()->getJson("/api/v1/customers/imports/{$id}")->json('data'))->toMatchArray(['status' => 'failed', 'total' => 40]);

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/customers/imports/{$id}")->assertNotFound();
    $staff = addMember($this->tenant, Role::ACCOUNTANT);
    ($this->as)($staff)->getJson("/api/v1/customers/imports/{$id}")->assertForbidden();
});

it('drops files checked but never imported and marks stuck imports failed', function () {
    Queue::fake();
    $id = ($this->as)()->postJson('/api/v1/customers/import', ['file' => bigCustomerCsv(10), 'dry_run' => true])->json('import.id');
    $import = CustomerImport::query()->withoutGlobalScopes()->where('ulid', $id)->first();
    expect(Storage::disk('local')->exists($import->path))->toBeTrue();
    $import->forceFill(['status' => CustomerImport::CHECKED])->save();
    CustomerImport::query()->withoutGlobalScopes()->whereKey($import->id)->update(['updated_at' => now()->subDays(2)]);

    expect(CustomerImport::pruneStale())->toBe(1);
    $import->refresh();
    expect($import->status)->toBe(CustomerImport::EXPIRED)->and($import->path)->toBeNull();
});
