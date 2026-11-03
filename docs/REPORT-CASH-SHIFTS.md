# Báo cáo: Ca bán hàng và két tiền

> 2026-11-03 · nhánh `claude/project-thread-iw20ot`

## Làm được gì
- **Mở ca** ở màn hình Bán tại quầy: chưa mở ca thì không thanh toán được. Tiền đầu ca gợi ý bằng số ca trước để lại két (theo kho).
- **Nộp / rút tiền két** trong ca, có lý do; không rút quá tiền mặt đang có.
- **Đóng ca**: đếm theo 10 mệnh giá (500.000 → 500 đ) hoặc nhập tổng. Hệ thống tính tiền mặt phải có = tiền đầu ca + thu tiền mặt − hoàn tiền mặt + nộp vào − rút ra, hiện ngay số thừa/thiếu. Lệch thì bắt buộc ghi lý do. Ghi số nộp về, phần còn lại để làm tiền đầu ca sau.
- Số liệu chốt khi đóng ca (hủy phiếu thu sau đó không làm đổi biên bản). In **biên bản bàn giao ca** khổ K80.
- **Lịch sử ca** `/pos/shifts`: lọc trạng thái, ngày. Thu ngân chỉ thấy ca của mình; người có quyền mới `orders.shifts` (Quản lý vận hành, Kế toán, Chủ, Quản trị) thấy mọi ca và đóng hộ ca bị quên.

## Cách tính
- Ca là một khoảng thời gian của một thu ngân: mọi khoản tiền người đó ghi nhận (thu, hoàn) từ lúc mở đến lúc đóng ca, kể cả thu tiền đơn giao hàng trong ca. Không thay đổi luồng bán hàng hay bảng thanh toán.
- Nộp/rút tiền két là chuyển tiền nội bộ giữa két và két sắt, không phải thu/chi, nên không vào sổ quỹ (phiếu thu/chi khác do phần "Sổ quỹ" xử lý).
- Cơ sở dữ liệu chặn hai ca cùng mở cho một người (khóa duy nhất), kể cả khi bấm hai lần cùng lúc.

## Kiểm thử
- Pest: 3 test mới (`tests/Feature/Sales/CashShiftTest.php`): tính két, chênh lệch, chốt số, gợi ý tiền đầu ca, phân quyền, tách doanh nghiệp. Toàn bộ 298 test đạt.
- Vitest: `shift.spec.ts` (đếm mệnh giá, nhãn chênh lệch, biên bản in). `vue-tsc` sạch.
- Playwright: `e2e/pos.spec.ts` mở ca → bán → rút tiền → đếm két → đóng ca → lịch sử.

## Hạn chế
- Chặn bán khi chưa mở ca nằm ở màn hình POS; API tạo đơn không bắt buộc ca (đơn tạo từ trang Đơn hàng không cần ca).
- Chưa có in biên bản khổ A4 và chưa gắn ca với máy POS/quầy cụ thể (chỉ gắn với thu ngân và kho).

Ảnh: `docs/screenshots/cash-shifts/`.
