# Nâng cấp module Khách hàng

> 2026-11-03

## Đã làm
- **Nhóm khách hàng** (`/customers/groups`, API `customer-groups`): danh mục riêng thay cho ô chữ tự gõ. Mỗi nhóm có mã (để trống thì tạo từ tên), tên, mô tả, giá áp dụng (bán lẻ / bán buôn), chiết khấu %, cách thanh toán quen dùng. Số khách của nhóm bấm được để mở danh sách đã lọc. Nhóm còn khách không xóa được.
- **Hồ sơ khách**: mã khách tự nhập (duy nhất trong doanh nghiệp; số tự động `KH-…` bỏ qua mã đã có người gõ tay), ngày sinh, giới tính, mã số thuế, nhân viên phụ trách (chỉ thành viên đang hoạt động), tags (gợi ý tag đã dùng), trạng thái đang / ngừng giao dịch.
- **Danh sách khách**:
  - Tab nhanh: Tất cả, Đang giao dịch, Ngừng giao dịch, Đang nợ.
  - Tìm theo tên, mã, email, số điện thoại (đủ số hoặc 4 số cuối).
  - "Bộ lọc khác": giới tính, tháng sinh (nút "Tháng này" để chúc mừng sinh nhật), nhân viên phụ trách (hoặc chưa giao), tag, tổng chi tiêu từ/đến, số đơn tối thiểu, mua lần cuối từ/đến, ngày thêm từ/đến. Bộ lọc nằm trên URL nên lưu bookmark hoặc gửi link được.
  - Sắp xếp thêm "Nhiều đơn nhất". Nút "Cột" chọn cột hiển thị (điện thoại, nhóm, phụ trách, tags, số đơn, chi tiêu, công nợ, mua gần nhất, ngày sinh, trạng thái, ngày thêm), nhớ trên máy.
  - Chọn nhiều khách: chuyển nhóm, gắn tag, gỡ tag, giao nhân viên phụ trách, đổi trạng thái, xóa (khách đã có đơn được giữ lại, báo số khách bỏ qua), xuất Excel các khách đã chọn.
- **Nhập / xuất Excel**: tiêu đề cột tiếng Việt (Mã khách hàng, Tên khách hàng, Số điện thoại, Email, Địa chỉ, Nhóm khách hàng, Ngày sinh, Giới tính, Mã số thuế, Tags, Trạng thái, Ghi chú). Khớp theo mã khách, không có mã thì theo số điện thoại, không bao giờ ghép theo tên. Ô trống khi cập nhật giữ nguyên dữ liệu cũ. Kiểm tra cả file trước (lỗi theo từng dòng), có lỗi thì không ghi dòng nào. File xuất có thêm số đơn, chi tiêu, công nợ, mua gần nhất và nhập lại được. Tối đa 5.000 dòng.
- **Trang chi tiết**: 4 thẻ số (số đơn, tổng chi tiêu, còn nợ, mua gần nhất) và các tab:
  - Đơn hàng (50 đơn gần nhất).
  - Công nợ: sổ nợ, mỗi đơn (trừ đơn hủy, đơn hoàn) cộng vào, mỗi lần thu tiền trừ đi, có số dư sau từng dòng.
  - Thu / hoàn tiền.
  - Địa chỉ: địa chỉ chính và các địa chỉ giao hàng thêm (nhãn, người nhận, số điện thoại), thêm, sửa, xóa.
  - Ghi chú theo dòng thời gian: ai cũng ghi được khi xem được khách (ví dụ kế toán ghi hẹn trả nợ); người viết hoặc người quản lý khách xóa được.
  - Thẻ "Nhóm và điều khoản mua hàng" cho nhân viên thấy giá, chiết khấu, cách thanh toán của nhóm khi tư vấn.

## Thay đổi CSDL
Migration `2026_11_07_100000_extend_customers` (xem `DATABASE.md`): bảng `customer_groups`, `customer_addresses`, `customer_notes`; cột mới trên `customers`; cột chữ `customers.group` được chuyển thành nhóm rồi bỏ. Đã thử up → down → up với dữ liệu nhóm cũ.

## Quyền
- Xem danh sách, nhóm, sổ nợ, thêm ghi chú: `customers.view`.
- Sửa khách, nhóm, địa chỉ, thao tác hàng loạt, nhập và **xuất** file: `customers.manage` (file xuất chứa số điện thoại, địa chỉ).

## Test
- Pest `tests/Feature/Sales/CustomerTest.php`: 8 test (nhóm và điều khoản, hồ sơ và mã khách, bộ lọc, thao tác hàng loạt, sổ nợ, địa chỉ và ghi chú, nhập/xuất, tách dữ liệu giữa doanh nghiệp). Toàn bộ test cũ vẫn đạt, trừ 5 test chỉ lỗi trên Windows như trước.
- Playwright `e2e/customers.spec.ts`: 4 kịch bản, ảnh ở `docs/screenshots/customers`. `e2e/sales.spec.ts` vẫn đạt.

## Chưa làm
- Tự áp giá buôn, chiết khấu và cách thanh toán của nhóm khi tạo đơn / bán tại quầy: điều khoản đang được lưu và hiển thị, chưa tự điền vào đơn.
- Tích điểm, hạng thẻ; phiếu thu nợ gộp nhiều đơn; địa chỉ chọn theo danh mục tỉnh/phường.
