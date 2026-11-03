# Phase 14 — Combo sản phẩm

> 2026-11-03

## Đã làm
- **Combo** là loại sản phẩm mới (`products.type = combo`): một SKU, không có tồn riêng, gồm các SKU thành phần với số lượng mỗi combo (`product_components`).
  - Tạo combo: nút "Thêm combo" ở danh sách, hoặc chọn loại "Combo" khi thêm sản phẩm. Chọn SKU thành phần bằng ô tìm/quét mã vạch, nhập số lượng; form hiện giá vốn combo và tổng giá nếu mua lẻ.
  - Thành phần phải là SKU thường của cùng doanh nghiệp; combo không chứa combo; combo không có tồn đầu kỳ. SKU đang là thành phần của combo không xóa được.
- **Tồn của combo** = số combo ghép được từ tồn khả dụng của thành phần (min của tồn ÷ số lượng mỗi combo), theo từng kho. Hiện ở danh sách sản phẩm, trang chi tiết, ô chọn SKU khi bán (POS, đơn tay) và số lượng đẩy lên sàn.
- **Bán combo** (đơn sàn, đơn tay, POS) đi qua `ReservationLedger`:
  - Giữ hàng: mỗi thành phần một reservation (`bundle_variant_id`, `bundle_quantity`), cùng thứ tự khóa theo SKU như mọi thao tác khác.
  - Đơn đọc lại theo đơn vị combo: đã giữ = số combo đủ thành phần, thiếu = phần còn lại.
  - Xuất kho trừ tồn từng thành phần; giá vốn dòng đơn = tổng giá vốn thành phần × số lượng mỗi combo.
  - Hủy trước khi giao nhả toàn bộ thành phần. Hàng hoàn kiểm đạt nhập lại kho dưới dạng thành phần.
- **Chặn combo trong chứng từ kho**: phiếu nhập, điều chỉnh, chuyển kho, kiểm kho, đơn nhập hàng, điều chỉnh giá vốn không nhận SKU combo (`InventoryService` từ chối; ô chọn SKU ẩn combo).
- **Đồng bộ tồn lên sàn**: khi tồn thành phần đổi, listing của combo chứa thành phần đó cũng được tính lại.
- Sửa thêm: danh sách sản phẩm không còn kéo người dùng quay lại khi bấm sang trang khác lúc đang tải; số biến thể trong danh sách hiển thị lại đúng; trang kiểm hàng hoàn chỉ cho xác nhận khi đã có kho nhập lại (trước đây có thể gửi thiếu kho nếu bấm quá nhanh).

## Thay đổi CSDL
- `products.type` (`standard` | `combo`), bảng `product_components`.
- `stock_reservations`: thêm `bundle_variant_id`, `bundle_quantity`; khóa duy nhất thành (doanh nghiệp, tham chiếu, kho, SKU) để một dòng đơn combo giữ được nhiều SKU.

## Test
- Pest `tests/Feature/Catalog/ComboTest.php`: 5 test (tồn combo, kiểm tra hợp lệ, giữ/xuất/giá vốn, hủy và thiếu hàng, hàng hoàn). Toàn bộ test Feature/Unit cũ vẫn đạt (trừ 3 test môi trường Windows như trước).
- Playwright `e2e/products.spec.ts`: thêm kịch bản tạo combo. Chạy cả bộ e2e trên CSDL mới như CI: đạt, trừ 1 test admin chỉ lỗi khi chạy ở cổng khác 8000.

## Hạn chế
- Combo chỉ có một tầng (không lồng combo). Đổi công thức combo không ảnh hưởng đơn đã giữ hàng.
- Đơn vị quy đổi (thùng/hộp/cái) chưa làm.
