# Phase 17 — Màn hình đơn hàng: đơn cần xử lý, xem nhanh, thao tác hàng loạt, xuất Excel

> 2026-11-03

Mục tiêu: xử lý đơn nhanh hơn trong ngày làm việc của cửa hàng (đối chiếu với các phần mềm bán hàng phổ biến ở Việt Nam; không làm hóa đơn điện tử).

## Đã làm
- **Dải "Đơn cần xử lý"** trên danh sách đơn (`GET /orders/pipeline`): số đơn và tổng tiền của Chờ xác nhận, Chờ thanh toán (đơn kênh riêng còn phải thu), Chờ đóng gói, Chờ bàn giao, Đang giao, Giao thất bại. Bấm ô nào thì danh sách lọc theo ô đó; thu gọn được (nhớ trên trình duyệt).
- **Xem nhanh**: mũi tên đầu dòng mở chi tiết ngay trong danh sách (sản phẩm, khách, ghi chú, tiền), nút Xem chi tiết / Sửa đơn / In hóa đơn.
- **Bộ lọc khác**: khoảng ngày đặt, trạng thái thanh toán, trạng thái đơn, khách hàng, sản phẩm (SKU); chip cho từng bộ lọc đang bật, nút Xóa bộ lọc. Thêm cờ "Còn phải thu" (`flags[]=payment_due`).
- **Thao tác hàng loạt** (`POST /orders/bulk`):
  - `pay` — Thanh toán nhanh: ghi nhận khách trả đủ số còn phải thu cho từng đơn kênh riêng (quyền `orders.payment`); đơn sàn, đơn đã hủy, đơn đã thu đủ được báo lại, không chặn các đơn khác.
  - `cancel` — Hủy đơn kèm lý do (đơn kênh riêng nhả hàng giữ ngay; đơn sàn gửi yêu cầu lên sàn).
  - In hóa đơn bán hàng cho nhiều đơn trong một lần in (mỗi đơn một trang).
- **Xuất Excel** (`GET /orders/export`): theo đúng bộ lọc đang xem, tối đa 20.000 đơn; đơn sàn chỉ có số điện thoại đã che.
- **Chi tiết đơn**: thanh tiến trình Đặt hàng → Xác nhận → Đóng gói → Xuất kho → Hoàn tất (có giờ từng bước, bước hủy / giao thất bại tô đỏ); nút **Sao chép** tạo đơn mới từ đơn này (bỏ dòng chưa liên kết SKU); khối **Khách hàng**: công nợ, tổng chi tiêu, số phiếu trả hàng, số lần giao thất bại.
- **Tạo đơn**: phím tắt F1 lưu đơn, F3 tìm sản phẩm, F4 tìm khách, F6 giảm giá đơn, F7 phí giao.
- **Trả hàng**: tab "Chưa nhận hàng" (phiếu chờ hàng hoàn về), cột Lý do.

## Test
- Pest `tests/Feature/Sales/OrderWorkbenchTest.php` (6 test): số liệu dải cần xử lý, thanh toán nhanh và phân quyền, hủy hàng loạt nhả tồn, xuất Excel theo bộ lọc và tách tenant, tóm tắt khách, tab chưa nhận hàng.
- Vitest `workflow.spec.ts` (thanh tiến trình), `invoice.spec.ts` (in nhiều hóa đơn).
- Playwright `e2e/order-workbench.spec.ts`, ảnh trong `docs/screenshots/phase-17/`.

## Chưa làm (cần thêm cột/bảng, chờ duyệt)
- Tag đơn hàng, ngày hẹn giao.
- Đổi hàng (phiếu trả gắn đơn mới, bù trừ tiền).
- Lưu bộ lọc và tùy chỉnh cột cho danh sách đơn.
