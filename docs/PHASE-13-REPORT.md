# Phase 13 — Hoàn thiện module Sản phẩm

> 2026-11-03. Đối chiếu với menu Sản phẩm của một phần mềm bán hàng phổ biến tại Việt Nam, bổ sung các chức năng ECSO còn thiếu. Giao diện tự thiết kế theo bộ UI sẵn có của ECSO.

## Đã làm
- **Trang chi tiết sản phẩm** (`/products/:id`): thông tin chung, ảnh, bảng biến thể với giá bán lẻ, giá bán buôn, giá vốn BQ và tồn có thể bán; tổng quan tồn kho (thực tế, có thể bán, đang giữ, hàng đang về, giá trị tồn).
  - Tab **Tồn theo kho**: từng SKU × từng kho đang hoạt động, gồm hàng đang về từ đơn nhập đã đặt hoặc nhận một phần.
  - Tab **Lịch sử kho**: sổ kho của mọi SKU trong sản phẩm, lọc theo kho.
  - Màn hình sửa chuyển sang `/products/:id/edit` (cần `products.manage`).
- **Sao chép sản phẩm**: mở form tạo mới đã điền sẵn thông tin, biến thể, thuộc tính và giá; SKU và mã vạch để trống vì phải là duy nhất.
- **Trường mới**: đơn vị tính, tags, thuế GTGT (không áp dụng / 0 / 5 / 8 / 10%), giá bán buôn theo SKU. Có trong import/export Excel (`wholesale_price`, `unit`).
- **Tồn kho ban đầu khi tạo sản phẩm**: nhập số lượng và giá vốn từng SKU, chọn kho; tạo một phiếu nhập nguồn "Tồn đầu kỳ" qua `InventoryDocumentService` (cần thêm quyền `inventory.receive`). Chỉ nhận khi tạo, không nhận khi sửa.
- **In tem mã vạch** (`/products/labels`): chọn SKU từ danh sách, trang chi tiết, nút "Lưu và in tem" trên form, hoặc quét mã vạch. Khổ tem cuộn 50×30, 40×30, 35×22 mm và giấy A4 65 tem. Tùy chọn in tên, giá, dòng tiêu đề; xem trước; xuất PDF.
  - Mã vạch tự sinh, không thêm thư viện: EAN-13 khi SKU có mã vạch 13 số đúng số kiểm tra, còn lại Code 128 (set B, chuyển set C cho dãy số). Đã đối chiếu từng bit với JsBarcode cho các mẫu SKU, số và EAN-13.
- **Danh mục và thương hiệu** (`/products/taxonomy`): thêm, đổi tên, xóa, danh mục cha–con dạng cây, số sản phẩm và link lọc danh sách.
- **Danh sách sản phẩm**: bộ lọc khác (thương hiệu, tag, ngày tạo), sắp xếp (mới/cũ, tên, tồn khả dụng), bộ lọc lưu trên URL, cột ngày tạo, thao tác hàng loạt "In tem mã vạch" và "Xóa" (sản phẩm còn tồn được giữ lại và báo lý do).

- **Phiếu điều chỉnh giá vốn** (`/inventory/cost-adjustments`, số phiếu CAD-): chọn SKU, nhập giá vốn mới, áp dụng ngay; lưu giá cũ, tồn lúc điều chỉnh và chênh lệch giá trị tồn; ghi nhật ký. Chỉ người có `inventory.adjust.approve` được tạo. Giá vốn vẫn chỉ được ghi trong `InventoryService` (`setCosts`, khóa SKU như phiếu nhập).

## API mới
- `GET/POST /inventory/cost-adjustments`, `GET /inventory/cost-adjustments/{id}`
- `GET /inventory/products/{product}/stock`, `GET /inventory/products/{product}/movements`
- `POST /products/bulk-delete`, `GET /products/tags`
- `GET /products`: thêm `tag`, `created_from`, `created_to`, `sort`
- `GET /variants`: thêm `ids[]`, `products[]` (lấy đúng SKU để in tem)
- `POST /products`: thêm `unit`, `tags`, `vat_rate`, `variants.*.wholesale_price`, `opening_warehouse_id`, `variants.*.opening_qty`, `variants.*.opening_cost`

## Test
- Pest `tests/Feature/Inventory/CostAdjustmentTest.php`: 2 test (giá cũ/mới, nhập sau tính bình quân từ giá mới, phân quyền, chặn SKU doanh nghiệp khác).
- Pest `tests/Feature/Catalog/ProductDetailsTest.php`: 7 test (trường mới, tồn ban đầu, kiểm tra kho và chặn khi sửa, bộ lọc và sắp xếp, xóa hàng loạt và phân quyền, tồn theo kho có hàng đang về và cách ly doanh nghiệp, lấy SKU để in tem).
- Vitest `labels.spec.ts`: 9 test (EAN-13, Code 128, bố cục tem cuộn và A4).
- Playwright `e2e/products.spec.ts`: 5 kịch bản, ảnh ở `docs/screenshots/phase-13/`.
- Chạy trên máy Windows: Catalog, Inventory và test mới đều đạt; Vitest 45/45; vue-tsc, Pint, PHPStan sạch; Playwright products + inventory 9/9. 5 test Arch/Platform lỗi do môi trường Windows (đường dẫn `\`, thiếu `pcntl`), không liên quan thay đổi này; CI Linux sẽ chạy đủ.

## Chưa làm
- Combo sản phẩm và đơn vị quy đổi (thùng/hộp/cái): cần thay đổi lõi tồn kho, đơn sàn và đồng bộ tồn, chờ quyết định.

- Nhãn vị trí lưu kho (bin), tồn tối đa theo kho.
