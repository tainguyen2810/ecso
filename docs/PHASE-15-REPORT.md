# Phase 15 — Đơn vị quy đổi (thùng / hộp / lốc)

> 2026-11-03

## Đã làm
- Trong form sản phẩm thường có thẻ **Đơn vị quy đổi**: thêm SKU như "Thùng 24 lon" = 24 × SKU đơn vị gốc, có SKU, mã vạch, giá bán lẻ, giá bán buôn riêng.
- Tồn chỉ giữ ở đơn vị gốc. SKU quy đổi được lưu như một combo một thành phần (`product_components`), nên dùng chung toàn bộ cơ chế của combo:
  - Có thể bán = số thùng đủ từ tồn khả dụng của đơn vị gốc, theo từng kho; hiện ở trang chi tiết, ô chọn SKU khi bán và số lượng đẩy lên sàn.
  - Bán 1 thùng giữ và xuất 24 lon; giá vốn dòng đơn = giá vốn lon × 24; hàng hoàn nhập lại 24 lon.
  - Phiếu nhập, kiểm, chuyển kho, đơn nhập hàng, điều chỉnh giá vốn chỉ nhận SKU đơn vị gốc.
- Kiểm tra: đơn vị gốc phải là SKU thường trong cùng sản phẩm (không quy đổi lồng nhau), hệ số ≥ 2, không nhập tồn đầu cho SKU quy đổi, combo không có đơn vị quy đổi và không dùng SKU quy đổi làm thành phần. Bỏ quy đổi thì SKU trở lại SKU thường; xóa sản phẩm xóa SKU quy đổi trước.
- Trang chi tiết ghi "= 24 × SKU gốc" dưới tên SKU quy đổi; tổng tồn không cộng trùng SKU quy đổi.

## Test
- Pest `tests/Feature/Catalog/PackSizeTest.php`: 4 test (tồn theo đơn vị gốc, bán thùng trừ lon và giá vốn, kiểm tra hợp lệ, bỏ quy đổi và xóa sản phẩm).
- Playwright `e2e/products.spec.ts`: kịch bản tạo sản phẩm có đơn vị quy đổi. Ảnh `docs/screenshots/phase-13/11-pack-form.png`, `12-pack-detail.png`.
