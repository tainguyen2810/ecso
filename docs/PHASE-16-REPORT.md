# Phase 16 — Vị trí lưu kho, tồn tối đa, in tem từ phiếu nhập, lưu bộ lọc

> 2026-11-03

## Đã làm
- **Vị trí lưu kho và tồn tối đa** theo từng kho × SKU (`stock_balances.bin_location`, `max_stock`):
  - Sửa ngay trên bảng "Tồn theo kho" ở trang chi tiết sản phẩm. Nhân viên kho (`inventory.adjust`) đặt được vị trí; chỉ người duyệt (`inventory.adjust.approve`) đặt tồn tối đa.
  - Tồn vượt mức tối đa được tô màu. Gợi ý nhập hàng không đề xuất vượt tồn tối đa.
  - Ghi qua `InventoryService::setLocation`, không tạo dòng sổ kho, không đổi số lượng.
- **In tem từ chứng từ**: nút "In tem hàng nhập" trên phiếu nhập kho và "In tem hàng đã nhận" trên đơn nhập hàng, mở trang in tem với số tem bằng số lượng đã nhập (`/products/labels?lines=SKU:số lượng`).
- **Danh sách sản phẩm**: lưu bộ lọc hiện tại với tên, bấm để áp dụng lại, xóa được; chọn cột hiển thị. Lưu trên trình duyệt, riêng từng doanh nghiệp.
- Tồn an toàn không đặt được cho SKU combo / đơn vị quy đổi (giống các thao tác kho khác).

## Test
- Pest `tests/Feature/Inventory/StockLocationTest.php`: phân quyền vị trí / tồn tối đa, không đổi sổ kho. Toàn bộ Feature/Unit đạt (trừ 3 test môi trường Windows như trước).
- Playwright `e2e/products.spec.ts`: vị trí lưu kho, in tem từ phiếu nhập, lưu bộ lọc và ẩn cột. Ảnh `13-bin-location.png`, `14-saved-filters.png`.

## Hạn chế
- Bộ lọc đã lưu và cột hiển thị lưu trên trình duyệt, chưa đồng bộ giữa các máy.
