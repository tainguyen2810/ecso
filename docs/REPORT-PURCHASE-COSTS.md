# Báo cáo: VAT đầu vào, chi phí nhập hàng, in phiếu đặt hàng

> 2026-11-03 · nhánh `claude/project-thread-iw20ot`

## Làm được gì
- **VAT theo dòng** trên đơn nhập: 0, 5, 8, 10 %. Có ô "VAT mặc định cho dòng mới".
- **Phải trả NCC** = tiền hàng + VAT (+ chi phí nếu chi phí đó trả cho NCC). Công nợ khi nhận hàng tăng theo giá thực nhận + VAT. Trả hàng NCC giảm công nợ cả phần VAT.
- **Chi phí nhập hàng** (vận chuyển, bốc xếp) nhập một số tổng trên đơn, có diễn giải.
  - Chọn "trả cho NCC" (cộng vào công nợ) hoặc trả bên khác.
  - Chi phí trả bên khác không vào công nợ NCC. Phần chi tiền cho đơn vị vận chuyển ghi bằng phiếu chi ở Sổ quỹ.
- **Giá vốn nhập kho** = giá thực nhận + phần chi phí phân bổ (+ VAT nếu chọn "Tính VAT vào giá vốn").
  - Chi phí chia theo giá trị đặt của từng dòng. Nếu cả đơn 0 đồng thì chia theo số lượng.
  - Mỗi đơn vị nhận về mang phần chi phí của nó, dù nhận trong đợt nào.
  - Giá vốn bình quân cập nhật như cũ qua phiếu nhập kho.
- Đơn nhập hiện cột VAT và **giá vốn dự kiến** từng dòng, cùng tổng VAT đầu vào và chi phí đã phân bổ của hàng đã nhận.
- **In phiếu đặt hàng A4** gửi NCC: thông tin NCC (MST, địa chỉ, liên hệ), dòng hàng, VAT, tổng, chỗ ký.

## Ví dụ (đã kiểm bằng test)
Áo 10 × 100.000 (VAT 10 %), quần 20 × 50.000 (VAT 8 %), xe tải 200.000 trả bên ngoài:
- Tiền hàng 2.000.000, VAT 180.000, phải trả NCC 2.180.000.
- Giá vốn áo 110.000, quần 55.000. VAT được khấu trừ nên không vào giá vốn.
- Trả lại 2 áo: công nợ giảm 220.000 (gồm 20.000 VAT).

## Kiểm thử
- Pest: `tests/Feature/Purchasing/PurchaseCostTest.php` (3 test). Toàn bộ 301 test đạt.
- Vitest: `costs.spec.ts` (cùng phép tính với máy chủ), `printOrder.spec.ts`.
- Playwright: `e2e/purchasing.spec.ts`: tạo đơn có VAT và chi phí → đặt → in → nhận hàng.

## Hạn chế
- Chi phí nhập chỉ nhập được khi tạo/sửa đơn (trước khi hàng về). Chưa có chi phí phát sinh sau khi đã nhập kho.
- Đóng đơn sớm thì phần chi phí của hàng chưa về không được phân bổ.
- VAT dòng mặc định chưa lấy từ thuế suất của sản phẩm.
- Chưa có báo cáo tổng hợp VAT đầu vào theo kỳ.

Ảnh: `docs/screenshots/purchase-costs/`.
