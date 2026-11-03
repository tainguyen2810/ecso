# Phase 18 — Thu chi: phiếu thu, phiếu chi, sổ quỹ

> 2026-11-03

## Đã làm
- **Menu Thu chi** (`/cash`, phím tắt `g q`), gồm 3 mục: Phiếu thu, phiếu chi; Sổ quỹ; Loại phiếu.
- **Phiếu thu, phiếu chi lập tay** cho các khoản ngoài đơn hàng, ví dụ quảng cáo, vận chuyển, lương, mặt bằng, thu nhập khác.
  - Mã phiếu tự tăng: `PT-000001` cho phiếu thu, `PC-000001` cho phiếu chi.
  - Đối tượng nộp/nhận có 5 loại: khách hàng, nhà cung cấp, nhân viên, đơn vị vận chuyển, đối tượng khác. Khách hàng và NCC tìm trong danh bạ, nhân viên chọn từ thành viên, còn lại nhập tên.
  - Số tiền hiện dấu chấm khi gõ, có dòng "bằng chữ". Có thêm ngày ghi nhận, hình thức, tham chiếu, diễn giải và cờ "Tính vào kết quả kinh doanh".
  - Nút "Lưu và lập tiếp" giữ đối tượng và loại phiếu để nhập nhiều phiếu liền nhau.
  - Phiếu không sửa. Muốn sửa thì hủy (bắt buộc ghi lý do) rồi lập lại. Phiếu đã hủy vẫn còn trong danh sách, có gạch ngang.
- **Thu nợ khách trong phiếu thu**: chọn khách đang nợ thì form tự bật "Thu nợ khách" và hiện các đơn còn nợ. Số tiền được trừ vào đơn cũ trước, ghi thành khoản thanh toán của từng đơn, nên công nợ khách và trạng thái thanh toán đơn luôn khớp. Nút "Thu hết" điền đúng số nợ.
- **Trả nợ NCC trong phiếu chi**: chọn NCC đang nợ thì có "Trả nợ nhà cung cấp". Khoản này ghi thành thanh toán NCC như trước, nên vẫn trừ vào công nợ.
- **Danh sách chung** gồm phiếu lập tay và phiếu tự động. Phiếu tự động là tiền thu đơn, hoàn tiền và trả NCC, có link về chứng từ gốc.
  - Có tab Tất cả/Phiếu thu/Phiếu chi và tab trạng thái Hoàn thành/Đã hủy.
  - Lọc theo khoảng ngày, loại phiếu, đối tượng, hình thức. Tìm theo mã phiếu, tên, mã đơn, tham chiếu.
  - Bộ lọc nằm trên URL nên lưu hoặc chia sẻ được. Có tổng thu, tổng chi, chênh lệch theo bộ lọc và nút xuất CSV.
- **Chi tiết phiếu**: in phiếu A5 có chữ ký người lập, người nộp/nhận, thủ quỹ và số tiền bằng chữ. Phiếu đã hủy in ra có dấu "ĐÃ HỦY".
- **Loại phiếu** (`/cash/types`): mỗi doanh nghiệp có sẵn 15 loại mặc định, thêm, đổi tên hoặc ngừng dùng được. Mỗi loại có mặc định "Tính KQKD". Riêng góp vốn và rút vốn mặc định không tính.
- **Sổ quỹ** có thêm phiếu thu chi lập tay, tab Thu/Chi, ô tìm kiếm, bảng giải thích thuật ngữ và nút sang lập phiếu.
- **Tài chính**: thêm thẻ "Lợi nhuận sau thu chi khác", bằng lãi gộp cộng phiếu thu trừ phiếu chi có tính KQKD. Thẻ ẩn khi lọc theo một gian hàng.
- **Báo cáo lãi lỗ** (`/reports/profit-loss`): thêm dòng "V. Thu nhập khác" và "VI. Chi phí khác" lấy từ phiếu có tính KQKD, bấm vào để mở danh sách phiếu.

## Thay đổi CSDL
- Bảng mới `cash_voucher_types`, `cash_vouchers`. Không sửa bảng cũ.
- Quyền mới `finance.vouchers` (lập và hủy phiếu), cấp cho Quản lý vận hành và Kế toán. Chủ doanh nghiệp và Quản trị có sẵn. Xem phiếu cần `finance.view`. Thu nợ khách cần `orders.payment`, trả nợ NCC cần `purchasing.payment`.

## API
- `GET/POST /cash-voucher-types`, `PATCH /cash-voucher-types/{id}`
- `GET /cash-vouchers` (lọc `direction`, `status`, `from`, `to`, `type`, `method`, `party_kind`, `q`), `GET /cash-vouchers/{source}/{id}`, `POST /cash-vouchers`, `POST /cash-vouchers/{id}/void`, `GET /cash-vouchers/staff`
- `GET /customers/{id}/debt`, `POST /customers/{id}/debt-receipts`

## Test
- Pest `tests/Feature/Cashbook/CashVoucherTest.php`: 4 test, gồm loại mặc định, lập/lọc/hủy phiếu, sổ quỹ và tài chính, thu nợ theo đơn cũ trước, phân quyền và tách doanh nghiệp.
- Vitest `pages/cash/voucher.spec.ts`: đọc số tiền bằng chữ, nội dung phiếu in.
- Playwright `e2e/cash.spec.ts`: lập phiếu chi, thu nợ khách, xem, hủy phiếu, loại phiếu, sổ quỹ. Ảnh ở `docs/screenshots/phase-18/`.

## Hạn chế
- Chưa có nhiều quỹ riêng (két, từng tài khoản ngân hàng). Hiện chỉ lọc sổ quỹ theo hình thức.
- Chưa có phiếu tự động cho tiền sàn chuyển về (cần dữ liệu đối soát từ sàn).
- Chưa có nút "Thu nợ" trên trang chi tiết khách. Hiện thu nợ qua Thu chi → Lập phiếu thu.
