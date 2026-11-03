# Phase 10 — Bán tại quầy (POS)

> 2026-11-03

## Đã làm
- Trang `/pos` dùng lại API tạo đơn của Phase 9 với trạng thái "Hoàn tất ngay": trừ kho ngay, không bán vượt tồn.
- Khách lẻ phải trả đủ (máy chủ từ chối nếu thiếu); chọn khách thì phần thiếu thành công nợ.
- Nhiều đơn chờ, tiền thừa, gợi ý mệnh giá, F2/F9, tự in hóa đơn K80, tổng kết ca theo thu ngân.

## Test
- Pest: 2 test mới (khách lẻ trả đủ / khách quen ghi nợ; tổng kết ca theo hình thức, trừ hoàn, chỉ đơn của thu ngân, phân quyền).
- Vitest: giỏ hàng (gộp khi quét lại, giảm giá, không âm), gợi ý mệnh giá.
- Playwright: `e2e/pos.spec.ts`. Ảnh tại `docs/screenshots/phase-10/`.

## Hạn chế
- Chưa có quản lý ca/két tiền riêng (mở ca, nộp tiền); tổng kết tính theo ngày và theo người thu.
- Chưa hỗ trợ máy in qua ESC/POS trực tiếp; in qua hộp thoại in của trình duyệt (chọn máy in K80).
- Chưa có chế độ ngoại tuyến.
