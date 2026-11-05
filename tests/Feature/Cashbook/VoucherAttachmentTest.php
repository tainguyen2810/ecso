<?php

use App\Modules\Access\Models\Role;
use App\Modules\Cashbook\Models\CashVoucherAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(now()->setTime(5, 0));
    Storage::fake(CashVoucherAttachment::DISK);
});

it('keeps invoice photos and transfer slips with a voucher, privately per business', function () {
    [$tenant, $owner] = tenantWithOwner();
    [$other, $otherOwner] = tenantWithOwner();
    $type = cashType($owner, $tenant, 'payment', 'Thuê mặt bằng');
    $voucher = postOnce($owner, $tenant, '/api/v1/cash-vouchers', ['direction' => 'payment', 'type' => $type, 'party_kind' => 'other', 'party_name' => 'Chủ nhà', 'method' => 'bank_transfer', 'amount' => 5000000], 'a1')
        ->assertCreated()->json('data.id');

    $list = actingInTenant($owner, $tenant)->post("/api/v1/cash-vouchers/{$voucher}/attachments", ['file' => UploadedFile::fake()->image('uy-nhiem-chi.jpg')], ['Accept' => 'application/json'])
        ->assertCreated()->json('data');
    expect($list)->toHaveCount(1)->and($list[0])->toMatchArray(['name' => 'uy-nhiem-chi.jpg', 'is_image' => true]);
    actingInTenant($owner, $tenant)->post("/api/v1/cash-vouchers/{$voucher}/attachments", ['file' => UploadedFile::fake()->create('hoa-don.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();

    // Only images and PDF, 5 MB at most.
    actingInTenant($owner, $tenant)->post("/api/v1/cash-vouchers/{$voucher}/attachments", ['file' => UploadedFile::fake()->create('x.exe', 10)], ['Accept' => 'application/json'])->assertUnprocessable();
    actingInTenant($owner, $tenant)->post("/api/v1/cash-vouchers/{$voucher}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 6000, 'application/pdf')], ['Accept' => 'application/json'])->assertUnprocessable();

    actingInTenant($owner, $tenant)->get("/api/v1/cash-voucher-attachments/{$list[0]['id']}")->assertOk()->assertDownload('uy-nhiem-chi.jpg');
    // Another business cannot see the voucher or its files.
    actingInTenant($otherOwner, $other)->getJson("/api/v1/cash-vouchers/{$voucher}/attachments")->assertNotFound();
    actingInTenant($otherOwner, $other)->get("/api/v1/cash-voucher-attachments/{$list[0]['id']}")->assertNotFound();
    // A member without finance access cannot either.
    actingInTenant(addMember($tenant, Role::WAREHOUSE_STAFF), $tenant)->get("/api/v1/cash-voucher-attachments/{$list[0]['id']}")->assertForbidden();

    // Removing only hides the file: it stays on disk.
    actingInTenant($owner, $tenant)->deleteJson("/api/v1/cash-voucher-attachments/{$list[0]['id']}")->assertOk()->assertJsonCount(1, 'data');
    actingInTenant($owner, $tenant)->get("/api/v1/cash-voucher-attachments/{$list[0]['id']}")->assertNotFound();
    Storage::disk(CashVoucherAttachment::DISK)->assertExists(CashVoucherAttachment::query()->withoutGlobalScopes()->where('ulid', $list[0]['id'])->value('path'));
});
