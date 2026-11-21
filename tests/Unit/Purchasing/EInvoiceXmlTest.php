<?php

use App\Modules\Purchasing\Support\EInvoiceXml;

it('reads invoices with namespace prefixes and decimal commas', function () {
    $xml = '<?xml version="1.0"?><inv:HDon xmlns:inv="urn:x"><inv:DLHDon><inv:TTChung><inv:THDon>HÓA ĐƠN BÁN HÀNG</inv:THDon><inv:KHMSHDon>2</inv:KHMSHDon><inv:KHHDon>K26TBB</inv:KHHDon><inv:SHDon>77</inv:SHDon><inv:NLap>2026-11-02T08:30:00</inv:NLap></inv:TTChung>'
        .'<inv:NDHDon><inv:NBan><inv:Ten>Văn phòng phẩm Hồng Hà</inv:Ten><inv:MST>0100100417</inv:MST></inv:NBan><inv:NMua><inv:HVTNMHang>Chủ shop</inv:HVTNMHang></inv:NMua>'
        .'<inv:DSHHDVu><inv:HHDVu><inv:TChat>1</inv:TChat><inv:THHDVu>Giấy A4</inv:THHDVu><inv:SLuong>2,5</inv:SLuong><inv:DGia>80000</inv:DGia><inv:ThTien>200000</inv:ThTien></inv:HHDVu>'
        .'<inv:HHDVu><inv:TChat>3</inv:TChat><inv:THHDVu>Chiết khấu</inv:THHDVu><inv:ThTien>20000</inv:ThTien></inv:HHDVu></inv:DSHHDVu>'
        .'<inv:TToan><inv:TgTTTBSo>180000</inv:TgTTTBSo></inv:TToan></inv:NDHDon></inv:DLHDon></inv:HDon>';
    $doc = EInvoiceXml::parse($xml);

    expect($doc['kind'])->toBe('sale')->and($doc['series'])->toBe('2K26TBB')->and($doc['issued_on'])->toBe('2026-11-02')
        ->and($doc['needs_authority_code'])->toBeFalse()->and($doc['buyer']['name'])->toBe('Chủ shop')
        ->and($doc['lines'])->toHaveCount(1)->and($doc['lines'][0]['quantity'])->toBe(2.5)
        ->and($doc['discount'])->toBe(20000.0)->and($doc['total'])->toBe(180000.0);
});

it('refuses files that are not invoices or carry entities', function () {
    expect(EInvoiceXml::parse(''))->toBeNull()
        ->and(EInvoiceXml::parse('<order><id>1</id></order>'))->toBeNull()
        ->and(EInvoiceXml::parse('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><HDon><DLHDon><TTChung><SHDon>&e;</SHDon></TTChung></DLHDon></HDon>'))->toBeNull();
});
