# Phase 19 — Tag đơn hàng, ngày hẹn giao, đổi hàng, lưu bộ lọc đơn

> 2026-11-03

## Đã làm
- **Tag đơn hàng** (`orders.tags`, JSON, tối đa 10 tag, bỏ trùng không phân biệt hoa thường) và **ngày hẹn giao cho khách** (`orders.deliver_on`):
  - Gắn khi tạo / sửa đơn kênh riêng; sửa ngay trên trang chi tiết của mọi đơn, kể cả đơn sàn (`PUT /orders/{id}/meta`, quyền `orders.update`). Gợi ý tag đã dùng (`GET /orders/tags`).
  - Danh sách đơn: tag hiện dưới mã đơn, "Hẹn dd/mm/yy" ở cột hạn giao; lọc theo tag và khoảng ngày hẹn giao; xuất Excel có thêm hai cột.
- **Đổi hàng**: từ phiếu trả hàng (trang phiếu hoặc khối Trả hàng của đơn) bấm "Tạo đơn đổi hàng" → form tạo đơn mở sẵn khách, kênh, kho của đơn cũ và hiện giá trị hàng trả.
  - Khi lưu (`exchange_return_id`), `ExchangeService` trừ giá trị hàng trả vào đơn mới: số trừ = nhỏ nhất của (giá trị hàng trả, số khách đã trả mà chưa hoàn ở đơn cũ, số còn phải thu của đơn mới).
  - Ghi thành hai bút toán cùng phương thức "Bù trừ đổi hàng": hoàn tiền ở đơn cũ và thu tiền ở đơn mới. Báo cáo tiền theo phương thức (ca thu ngân) không tính phần bù trừ là tiền mặt. Bút toán bù trừ không hủy được một phía.
  - Mỗi phiếu trả chỉ dùng cho một đơn đổi. Đơn sàn chỉ được liên kết, không bù trừ (sàn tự hoàn tiền).
  - Đơn đổi hiện dải "Đơn đổi hàng từ phiếu …"; phiếu trả hiện đơn đổi và số đã bù trừ.
- **Danh sách đơn**: lưu bộ lọc hiện tại với tên, chọn cột hiển thị (giống danh sách sản phẩm: lưu trên trình duyệt, riêng từng doanh nghiệp).

## Test
- Pest `tests/Feature/Sales/OrderTagsExchangeTest.php` (3 test): tag + hẹn giao + lọc + tách tenant + phân quyền; đổi hàng bù trừ, tiền mặt ca không đổi, không hủy một phía, mỗi phiếu dùng một lần; giới hạn số bù trừ.
- Playwright `e2e/order-tags-exchange.spec.ts`, ảnh trong `docs/screenshots/phase-19/`.

## Hạn chế
- Hủy đơn đổi sau khi đã bù trừ: hệ thống cảnh báo cần hoàn tiền như đơn đã thu tiền thường; chưa tự gỡ bù trừ về phiếu trả.
- Bộ lọc đã lưu và cột hiển thị lưu trên trình duyệt, chưa đồng bộ giữa các máy.
