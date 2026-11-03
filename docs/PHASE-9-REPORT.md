# Phase 9 — Bán hàng kênh riêng và khách hàng

> 2026-11-03 · Làm được ngay, không cần API sàn.

## Đã làm
- Kênh bán riêng, tạo/sửa/xử lý đơn bán tay, thu và hoàn tiền, in hóa đơn K80/A5, danh bạ khách hàng có công nợ. Chi tiết luồng ở ORDER-LIFECYCLE §9, bảng mới ở DATABASE (Phase 9).
- Đơn kênh riêng đi qua đúng lõi tồn kho của đơn sàn (giữ hàng, trừ kho khi bàn giao, nhả khi hủy, trả hàng qua kiểm hàng), thêm một quy tắc chặt hơn: không xuất kho khi còn thiếu hàng.

## Test
- Pest: 12 test mới (`tests/Feature/Sales/SalesOrderTest.php`): giữ/trừ/nhả tồn, chặn xuất kho khi thiếu, bán tại quầy vượt tồn không để lại đơn, thu nhiều lần, chặn thu vượt, hoàn tiền, hủy phiếu, sửa đơn giữ lại hàng, trả hàng, phân quyền, cô lập tenant, chặn thao tác tay trên đơn sàn. Toàn bộ: 246 test pass.
- Vitest: hóa đơn K80/A5 (thoát ký tự, bỏ phiếu đã hủy, số còn phải trả), nhãn.
- Playwright: `e2e/sales.spec.ts` (5 kịch bản), toàn bộ 31 e2e pass. Ảnh tại `docs/screenshots/phase-9/`.

## Hạn chế
- Công nợ khách tính trên đơn chưa hủy/chưa hoàn; hoàn tiền là dòng tiền ra riêng, không làm giảm công nợ.
- Chưa có bảng giá theo nhóm khách, khuyến mãi, tích điểm.
- Báo cáo doanh thu theo kênh riêng làm ở Phase 12.
