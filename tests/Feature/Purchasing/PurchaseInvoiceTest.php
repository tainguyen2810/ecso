<?php

use App\Modules\Catalog\Models\ProductVariant;
use App\Modules\Purchasing\Models\PurchaseInvoice;
use Illuminate\Http\UploadedFile;

function einvoiceXml(array $o = []): string
{
    $o += ['symbol' => 'C26TVT', 'number' => '00000932', 'seller' => '0315550147', 'buyer' => '8123456789', 'mccqt' => '00A1B2C3D4E5F6', 'date' => '2026-11-18', 'total' => '6480000'];
    $mccqt = $o['mccqt'] ? "<MCCQT>{$o['mccqt']}</MCCQT>" : '';

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<HDon xmlns:inv="http://laphoadon.gdt.gov.vn/2014/09/invoicexml/v1"><DLHDon Id="data">
<TTChung><PBan>2.0.1</PBan><THDon>HÓA ĐƠN GIÁ TRỊ GIA TĂNG</THDon><KHMSHDon>1</KHMSHDon><KHHDon>{$o['symbol']}</KHHDon><SHDon>{$o['number']}</SHDon><NLap>{$o['date']}</NLap><DVTTe>VND</DVTTe></TTChung>
<NDHDon><NBan><Ten>CÔNG TY TNHH MẠNG VIỆT TIẾN</Ten><MST>{$o['seller']}</MST><DChi>12 Trường Chinh, Tân Bình, TP HCM</DChi></NBan>
<NMua><Ten>Hộ kinh doanh Cửa hàng Mẫu</Ten><MST>{$o['buyer']}</MST></NMua>
<DSHHDVu>
<HHDVu><TChat>1</TChat><STT>1</STT><MHHDVu>DECO-X20-2P</MHHDVu><THHDVu>Bộ phát wifi Mesh TP-Link Deco X20 (2 pack)</THHDVu><DVTinh>Bộ</DVTinh><SLuong>4</SLuong><DGia>1180000</DGia><ThTien>4720000</ThTien><TSuat>8%</TSuat></HHDVu>
<HHDVu><TChat>1</TChat><STT>2</STT><MHHDVu>TL-SG108</MHHDVu><THHDVu>Switch 8 port Gigabit TP-Link TL-SG108</THHDVu><DVTinh>Cái</DVTinh><SLuong>6</SLuong><DGia>200000</DGia><ThTien>1200000</ThTien><TSuat>8%</TSuat></HHDVu>
<HHDVu><TChat>2</TChat><STT>3</STT><MHHDVu>CAP-1M</MHHDVu><THHDVu>Cáp mạng 1m (khuyến mại)</THHDVu><DVTinh>Sợi</DVTinh><SLuong>2</SLuong><DGia>0</DGia><ThTien>0</ThTien></HHDVu>
<HHDVu><TChat>1</TChat><STT>4</STT><THHDVu>Phí vận chuyển</THHDVu><DVTinh>Lần</DVTinh><SLuong>1</SLuong><DGia>80000</DGia><ThTien>80000</ThTien><TSuat>8%</TSuat></HHDVu>
<HHDVu><TChat>4</TChat><THHDVu>Hàng bảo hành 24 tháng</THHDVu></HHDVu>
</DSHHDVu>
<TToan><TgTCThue>6000000</TgTCThue><TgTThue>480000</TgTThue><TgTTTBSo>{$o['total']}</TgTTTBSo></TToan>
</NDHDon></DLHDon>{$mccqt}<DSCKS/></HDon>
XML;
}

function xmlUpload(string $name, string $xml): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'inv');
    file_put_contents($path, $xml);

    return new UploadedFile($path, $name, 'text/xml', null, true);
}

function zipUpload(array $files): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'zip');
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::OVERWRITE);
    foreach ($files as $name => $xml) {
        $zip->addFromString($name, $xml);
    }
    $zip->close();

    return new UploadedFile($path, 'hoa-don.zip', 'application/zip', null, true);
}

beforeEach(function () {
    [$this->tenant, $this->owner] = tenantWithOwner();
    $this->tenant->forceFill(['settings' => ['store' => ['tax_code' => '8123456789']]])->save();
    $this->deco = makeVariant($this->tenant, 'DECO-X20', ['product_name' => 'Bộ phát Deco X20 2 pack', 'cost_price' => 1150000]);
    $this->switch = makeVariant($this->tenant, 'SG108', ['product_name' => 'Switch TP-Link 8 port SG108', 'cost_price' => 190000]);
});

it('imports XML and zip invoices once, checks them and lists them by what is left to do', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $r = $api->post('/api/v1/purchase-invoices/import', ['files' => [
        xmlUpload('C26TVT_932.xml', einvoiceXml()),
        zipUpload([
            'a.xml' => einvoiceXml(['number' => '118', 'seller' => '0317001234', 'buyer' => '0101010101']),
            'b.xml' => einvoiceXml(['number' => '119', 'mccqt' => '']),
            'c.xml' => einvoiceXml(), // same as the first file
            'note.txt' => 'x',
            'broken.xml' => '<HDon><oops',
        ]),
    ]], ['Accept' => 'application/json'])->assertOk();
    expect($r->json('data.added'))->toBe(3)->and($r->json('data.duplicates'))->toBe(1)->and($r->json('data.failed'))->toBe(['broken.xml']);

    $first = PurchaseInvoice::withoutGlobalScopes()->where('number', '00000932')->sole();
    expect($first->series)->toBe('1C26TVT')->and((float) $first->total)->toBe(6480000.0)->and((float) $first->shipping)->toBe(80000.0)
        ->and($first->lines)->toHaveCount(3)->and($first->lines[2]['promo'])->toBeTrue()->and($first->issues)->toBe([])
        ->and((float) $first->vat_rate)->toBe(8.0);
    expect(PurchaseInvoice::withoutGlobalScopes()->where('number', '118')->value('issues'))->toBe(['buyer_mismatch'])
        ->and(PurchaseInvoice::withoutGlobalScopes()->where('number', '119')->value('issues'))->toBe(['no_authority_code']);

    $list = $api->getJson('/api/v1/purchase-invoices?month=2026-11')->assertOk();
    $list->assertJsonPath('meta.counts.all', 3)->assertJsonPath('meta.counts.review', 2)->assertJsonPath('meta.counts.open', 3);
    // Only the valid invoice's VAT counts as input VAT.
    expect((float) $list->json('meta.summary.vat'))->toBe(480000.0);
    $api->getJson('/api/v1/purchase-invoices?tab=review')->assertJsonCount(2, 'data');
    $api->getJson('/api/v1/purchase-invoices?month=2026-10')->assertJsonPath('meta.counts.all', 0);

    // "Không đưa vào" and back.
    $wrong = PurchaseInvoice::withoutGlobalScopes()->where('number', '118')->value('ulid');
    $api->postJson("/api/v1/purchase-invoices/{$wrong}/ignore", ['note' => 'Hoá đơn của chi nhánh khác'])->assertOk()->assertJsonPath('data.status', 'ignored');
    $api->getJson('/api/v1/purchase-invoices?tab=ignored')->assertJsonCount(1, 'data');
    $api->postJson("/api/v1/purchase-invoices/{$wrong}/restore")->assertOk()->assertJsonPath('data.status', 'open');

    $api->post('/api/v1/purchase-invoices/import', ['files' => [xmlUpload('x.pdf', 'pdf')]], ['Accept' => 'application/json'])->assertUnprocessable();
    $api->get('/api/v1/purchase-invoices/export?month=2026-11')->assertOk();
});

it('turns an invoice into a purchase order, links it and remembers the supplier codes', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->post('/api/v1/purchase-invoices/import', ['files' => [xmlUpload('a.xml', einvoiceXml())]], ['Accept' => 'application/json'])->assertOk();
    $id = PurchaseInvoice::withoutGlobalScopes()->value('ulid');

    $draft = $api->getJson("/api/v1/purchase-invoices/{$id}")->assertOk()->json('data.draft');
    expect($draft['lines'])->toHaveCount(3)->and($draft['shipping'])->toEqual(80000)->and($draft['invoice']['series'])->toBe('1C26TVT')
        ->and($draft['lines'][0]['variant']['sku'])->toBe('DECO-X20')->and($draft['total_matches'])->toBeTrue();

    $supplier = $api->postJson('/api/v1/suppliers', ['name' => 'Công ty TNHH Mạng Việt Tiến', 'tax_code' => '0315550147'])->json('data.id');
    $body = [
        'supplier_id' => $supplier, 'warehouse_id' => defaultWarehouse($this->tenant)->ulid, 'purchase_invoice_id' => $id,
        'lines' => [['variant_id' => $this->deco->ulid, 'quantity' => 4, 'unit_cost' => 1180000], ['variant_id' => $this->switch->ulid, 'quantity' => 6, 'unit_cost' => 200000]],
        'supplier_codes' => [['id' => $this->deco->ulid, 'code' => 'DECO-X20-2P'], ['id' => $this->switch->ulid, 'code' => 'TL-SG108']],
    ];
    $po = $api->postJson('/api/v1/purchase-orders', $body)->assertCreated()->json('data');
    $api->getJson("/api/v1/purchase-invoices/{$id}")->assertJsonPath('data.status', 'linked')->assertJsonPath('data.purchase_order.id', $po['id']);
    expect(ProductVariant::withoutGlobalScopes()->find($this->deco->id)->supplier_sku)->toBe('DECO-X20-2P');

    // Once only, and a linked invoice stays.
    $api->postJson('/api/v1/purchase-orders', $body)->assertUnprocessable();
    $api->deleteJson("/api/v1/purchase-invoices/{$id}")->assertUnprocessable();
    $api->postJson("/api/v1/purchase-invoices/{$id}/ignore")->assertUnprocessable();
});

it('keeps invoices to members who can see purchasing, and to their own business', function () {
    $api = actingInTenant($this->owner, $this->tenant);
    $api->post('/api/v1/purchase-invoices/import', ['files' => [xmlUpload('a.xml', einvoiceXml())]], ['Accept' => 'application/json'])->assertOk();
    $id = PurchaseInvoice::withoutGlobalScopes()->value('ulid');

    [$other, $otherOwner] = tenantWithOwner('Shop B');
    actingInTenant($otherOwner, $other)->getJson("/api/v1/purchase-invoices/{$id}")->assertNotFound();
    actingInTenant($otherOwner, $other)->getJson('/api/v1/purchase-invoices')->assertJsonPath('meta.counts.all', 0);
});
