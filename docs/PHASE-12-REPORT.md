# Phase 12 — Kiểm kho, xuất hủy, cảnh báo tồn thấp, báo cáo

> 2026-11-03

## Đã làm
- **Kiểm kho**: màn hình đếm cho cả kho, dùng máy quét mã vạch. Khi xong, tạo một phiếu điều chỉnh lý do "Kiểm kê" ở chế độ đặt theo số đếm. Phiếu vẫn qua bước duyệt (`inventory.adjust.approve`) như trước. Không thêm bảng mới.
- **Xuất hủy**: dùng phiếu điều chỉnh lý do Hàng hỏng hoặc Thất lạc, có nút tắt riêng. Phiếu cần duyệt nên không ai tự trừ kho một mình. Trong báo cáo xuất nhập tồn, các phiếu này hiện ở cột "Hủy / mất", không lẫn với điều chỉnh khác.
- **Cảnh báo tồn thấp**: chỉ áp dụng cho SKU đã đặt tồn an toàn, để SKU mới hoặc ngừng bán không gây báo động giả.
  - Có thẻ cảnh báo trên Tổng quan.
  - Lệnh email `ecso:inventory:low-stock-digest` chạy 07:30 mỗi ngày, gửi cho thành viên có quyền `purchasing.manage`.
- **Báo cáo** (`BusinessReports`, chỉ đọc):
  - *Bán hàng & lợi nhuận*:
    - Lấy đơn đặt trong kỳ, bỏ đơn hủy và đơn hoàn.
    - Giá vốn lấy từ sổ kho lúc xuất. Dòng chưa có giá vốn được đếm và hiện cảnh báo.
    - Xem theo ngày, theo kênh (sàn và kênh riêng) hoặc theo nhân viên tạo đơn.
  - *Xuất nhập tồn*: tính từ sổ kho bất biến. Tồn đầu là tổng biến động trước kỳ. Giá trị tồn tính theo giá vốn bình quân hiện tại.
  - *Sổ quỹ*:
    - Thu: phiếu thu của khách. Chi: phiếu hoàn tiền và phiếu chi nhà cung cấp. Phiếu đã hủy bị loại ra.
    - Có tồn đầu, tồn cuối và số dư sau từng phiếu.
    - Lọc theo hình thức, ví dụ chỉ tiền mặt.
  - Phân quyền: Bán hàng cần `reports.view`. Xuất nhập tồn cần thêm `inventory.view`. Sổ quỹ cần `finance.view`.

## Test
- Pest `tests/Feature/Analytics/BusinessReportsTest.php`: 4 test.
  - Lợi nhuận theo kênh, nhân viên và ngày, có loại đơn hủy.
  - Xuất nhập tồn tách hủy với điều chỉnh, và tồn đầu của kỳ sau.
  - Sổ quỹ có thu, hoàn, chi NCC, lọc tiền mặt, tồn đầu kỳ sau, và phân quyền.
  - Cảnh báo tồn thấp: API và email chỉ gửi đúng người.
- Toàn bộ bộ test: Pest 259, Vitest 36, Playwright 36, tất cả đạt.
- Playwright `e2e/reports.spec.ts`. Ảnh ở `docs/screenshots/phase-12/`.

## Hạn chế
- Sổ quỹ chưa có tiền sàn chuyển về (payout), vì cần dữ liệu đối soát từ API sàn. Cũng chưa có phiếu thu/chi khác như tiền thuê hay lương.
- Giá trị tồn trong báo cáo xuất nhập tồn tính theo giá vốn hiện tại, không theo giá vốn tại thời điểm cuối kỳ.
- Kiểm kho đặt tồn bằng số đếm khi duyệt. Nếu có đơn xuất kho giữa lúc đếm và lúc duyệt thì sẽ lệch, nên cần duyệt ngay.
- Email cảnh báo chỉ gửi được khi máy chủ đã cấu hình gửi mail.
