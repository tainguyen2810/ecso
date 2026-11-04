# Đồng bộ đơn hàng WooCommerce

Tài liệu cho chủ shop và người vận hành. Phần kỹ thuật chi tiết nằm trong `docs/ORDER-LIFECYCLE.md` và `docs/CHANGELOG.md`.

> Toàn bộ phần này đã được kiểm thử với cửa hàng Woo giả lập (`tests/Support/FakeWooStore.php`). Chưa chạy trên website thật. Sau khi cập nhật, hãy thử với một đơn thật trên website và đối chiếu.

## 1. Kết nối và mốc "đồng bộ đơn từ"

- Kết nối website ở **Kết nối sàn → WooCommerce** (màn hình duyệt của Woo, hoặc nhập khóa API).
- ECSO chỉ lấy **đơn tạo sau lúc bật đồng bộ đơn**. Mốc này hiện ở trang website, mục **Đồng bộ đơn từ**.
- Đơn tạo trước mốc bị bỏ qua, kể cả khi sau này được sửa trên website. ECSO không nhập đơn lịch sử.
- Tắt rồi bật lại "Đơn hàng Woo về ECSO" thì mốc được đặt lại thành lúc bật.

## 2. Đơn về ECSO bằng cách nào

- **Webhook**: website báo ngay khi có đơn mới, đơn sửa, đơn bị xóa hoặc khôi phục. ECSO kiểm tra chữ ký từng lần gửi, rồi đọc lại đơn từ website. Nội dung webhook không được tin trực tiếp.
- **Đối soát định kỳ**: ECSO hỏi website các đơn thay đổi gần đây, mỗi 5, 15 (mặc định) hoặc 30 phút tùy website. Đối soát bù cho webhook bị mất.
- Nút **Đồng bộ đơn ngay** chạy một lượt đối soát ngay lập tức.
- Webhook gửi lặp hoặc đến sai thứ tự không tạo đơn trùng và không làm trạng thái lùi lại.

## 3. Dữ liệu đơn

- ECSO lưu số đơn website, số điện thoại và email đầy đủ, địa chỉ thanh toán và giao hàng, mã khách Woo, mã giảm giá, thuế, phí khác, phí ship, mã giao dịch và số tiền đã hoàn.
- Thông tin cá nhân được mã hóa khi lưu và không ghi vào nhật ký.
- Thông tin đầy đủ chỉ áp dụng cho website của chính shop. Đơn Shopee, TikTok và các sàn khác vẫn che số điện thoại như cũ.
- **Thanh toán**: đơn trả online, có ngày thanh toán, được tính là Đã thanh toán. Đơn COD không tự coi là đã thu tiền, kể cả khi đơn hoàn tất trên Woo.

## 4. Khách hàng

- Đơn website tự gắn vào thẻ khách hàng, tìm theo thứ tự: mã khách trên website (khách có tài khoản), số điện thoại, rồi email (chỉ khi đúng một khách có email đó). ECSO không ghép khách theo tên. Chưa có thẻ thì ECSO tạo thẻ mới.
- Đơn sau chỉ điền các ô còn trống trên thẻ khách, không ghi đè thông tin đã có.
- Đơn không có số điện thoại hợp lệ (ví dụ "gọi zalo"), không email và không tài khoản thì vẫn vào ECSO nhưng không gắn khách.
- Đơn website đã thanh toán qua website nên **không tính vào công nợ** của khách.

## 5. Trạng thái và ánh xạ

| Trên Woo | Trong ECSO |
|---|---|
| pending | Chờ thanh toán |
| on-hold | Chờ xác nhận |
| processing | Chờ đóng gói |
| processing + ECSO đóng gói / bàn giao | Đã đóng gói / Đã bàn giao |
| completed | Hoàn tất |
| cancelled, failed, trash | Đã hủy |
| refunded | Đã hủy nếu chưa giao, Đã hoàn nếu đã giao |

- Trạng thái lạ, ví dụ do plugin thêm, thành **Chưa ánh xạ**. ECSO không tự đoán.
- Vào trang website, mục **Ánh xạ trạng thái**, chọn trạng thái tương ứng rồi bấm **Lưu**. Các đơn đang chờ ở trạng thái đó được chuyển ngay. Ánh xạ này chỉ áp dụng cho website đó.

## 6. Một chiều hay hai chiều

- **Trạng thái đơn hai chiều** đang bật (mặc định): khi xác nhận, đóng gói, bàn giao hoặc hủy trong ECSO, ECSO ghi lên website.
  - Đóng gói và bàn giao được ghi vào meta `_ecso_stage`, vì Woo không có các trạng thái này.
  - Hủy kèm lý do thì ECSO thêm ghi chú vào đơn trên website.
- Khi tắt (chỉ website → ECSO), các thao tác trên chỉ làm trong ECSO, website không bị thay đổi.
  - Website còn ở trạng thái trước ECSO thì ECSO giữ trạng thái của mình.
  - Website đi xa hơn, ví dụ Hoàn tất, thì website thắng.

## 7. Tồn kho

- ECSO là nơi giữ số tồn chính.
- Đơn chờ xử lý thì **giữ hàng**. Bàn giao hoặc hoàn tất thì **xuất kho**. Hủy trước khi giao thì **nhả hàng**.
- Hủy sau khi giao thì **không cộng kho ngay**. ECSO mở phiếu trả hàng, và kho chỉ cộng lại khi nhận và kiểm hàng.
- Đơn sửa số lượng hoặc dòng hàng trên website **trước khi giao** thì ECSO giữ hàng theo dòng mới. Sửa **sau khi giao** thì ECSO giữ số đã xuất và báo cảnh báo trên đơn.
- Woo cũng tự trừ tồn của nó. Số trên website được ECSO đẩy lại theo kho ECSO.

## 8. Hoàn tiền

- Mỗi lần hoàn tiền trên website (hoàn một phần, nhiều lần, theo dòng) được lưu một lần và hiện ở chi tiết đơn, mục **Trả hàng / hoàn tiền**.
- Hoàn **trước khi giao**: số lượng đã hoàn không còn được giữ hàng và không bị xuất kho.
- Hoàn **sau khi giao** có dòng hàng: ECSO mở phiếu **Yêu cầu trả** cho đúng các dòng đó. Kho chỉ cộng khi nhận và kiểm hàng.
- Hoàn chỉ tiền (phí ship, bồi thường): chỉ lưu số tiền.

## 9. Đơn bị xóa, webhook và lỗi đồng bộ

- Xóa đơn trên website **không xóa đơn ECSO**. Đơn được đánh dấu "Đã xóa trên website".
  - Chưa giao: đơn chuyển Đã hủy và nhả hàng.
  - Đã giao: đơn giữ nguyên và có cảnh báo.
  - Khôi phục từ thùng rác (khi chưa giao): đơn mở lại và giữ hàng lại.
- **Webhook**: Woo tự tắt webhook sau nhiều lần gửi lỗi. Mỗi giờ ECSO kiểm tra, bật lại webhook bị tắt và tạo lại webhook còn thiếu. Kết quả hiện ở trang website, mục **Webhook trên website**, có nút **Kiểm tra ngay**.
- **Lịch sử và lỗi đồng bộ** (Kết nối sàn → Lịch sử đồng bộ):
  - Mỗi lượt đối soát ghi kết quả, số đơn và thời gian chạy.
  - Đơn không đồng bộ được có một dòng lỗi theo nhóm: website không trả lời, khóa API bị từ chối, dữ liệu không đọc được, xung đột trạng thái.
  - Lỗi tự đóng khi đơn đồng bộ được ở lượt sau. Có thể bấm **Thử lại** hoặc **Bỏ qua**.

## Lệnh và lịch chạy

| Lệnh | Lịch | Việc |
|---|---|---|
| `ecso:orders:reconcile` | mỗi 5 phút | Đối soát đơn, từng website theo chu kỳ riêng |
| `ecso:woo:check-webhooks` | mỗi giờ, phút 17 | Bật lại webhook bị tắt, tạo webhook thiếu |
