# Phase 11 — Mua hàng / nhập hàng

> 2026-11-03

## Đã làm
- Module `Purchasing`: nhà cung cấp, đơn nhập, nhận hàng nhiều đợt, trả hàng nhà cung cấp, phiếu chi, công nợ, gợi ý nhập hàng.
- Nhận hàng đi qua `InventoryDocumentService::receive` nên tồn, sổ kho và giá vốn bình quân dùng chung đúng một đường với phiếu nhập tay. Phiếu nhập hiện ở cả Kho hàng → Nhập kho và trong đơn nhập.
- Trả hàng nhà cung cấp đi qua `InventoryService::returnToSupplier` (thêm cùng `writeOff` cho xuất hủy ở Phase 12). Không lấy hàng đang giữ cho đơn khác.
- Công nợ: đơn nhập chỉ phát sinh nợ khi hàng về (giá trị nhận − giá trị trả − đã chi). Thanh toán chung (không gắn đơn) được phép, cho trường hợp trả trước.
- Phân quyền: xem `purchasing.view`; tạo/sửa/đặt/đóng/hủy/trả hàng `purchasing.manage`; nhận hàng `purchasing.manage` hoặc `inventory.receive` (nhân viên kho, giá nhập lấy theo đơn); chi tiền `purchasing.payment`.

## Test
- Pest `tests/Feature/Purchasing/PurchasingTest.php`: 7 test (nhận 2 đợt và giá vốn, đóng/hủy, công nợ qua chi/trả/hủy chi, gợi ý nhập, phân quyền nhân viên kho / kế toán, cô lập tenant, không xóa NCC đã giao dịch). Kiến trúc: Purchasing không dùng trực tiếp model sổ kho.
- Vitest: công nợ đơn, nhãn trạng thái, tạo đơn từ gợi ý.
- Playwright `e2e/purchasing.spec.ts`: tạo đơn → nhận một phần với giá mới → chi một phần → danh sách và chi tiết NCC → trả hàng → gợi ý nhập. Ảnh tại `docs/screenshots/phase-11/`.

## Hạn chế
- Chưa có thuế VAT đầu vào, chi phí vận chuyển phân bổ vào giá vốn, hay đơn vị tính quy đổi (thùng/cái).
- Gợi ý nhập tính theo một kho và chưa tính thời gian giao hàng của từng nhà cung cấp.
- Chưa in phiếu đặt hàng gửi nhà cung cấp.
