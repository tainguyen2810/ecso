# Báo cáo kiểm toán module Đơn hàng (ECSO)

Ngày: 04/11/2026 · Nhánh kiểm tra: `main` tại commit `cb6b5b1` (sau PR #17)

Phạm vi: Tạo đơn và giao hàng, Danh sách đơn hàng, Bán tại quầy (POS), Ca bán hàng, Khách trả hàng, liên kết đa kênh, tính nhất quán kho/tiền.

## 0. Cách kiểm tra

- Đọc toàn bộ backend liên quan: `app/Modules/Orders`, `app/Modules/Sales`, `ReservationLedger`/`InventoryService` (Inventory), `Marketplace` (webhook, ingest, WooCommerce), `Cashbook/VoucherLedger`, `Analytics` (báo cáo), `PermissionRegistry`, các migration của bảng `orders`, `order_items`, `order_payments`, `order_returns`, `cash_shifts`.
- Đọc frontend: `pages/orders/*`, `pages/sales/Pos.vue`, `ShiftPanel.vue`, `Shifts.vue`, `pages/returns/*`, `router.ts`.
- Dựng môi trường test cục bộ (MySQL 8 + PHP 8.3, CSDL test riêng, **không đụng dữ liệu thật**). Chạy 65 test hiện có của Đơn hàng/Bán hàng: **65/65 đạt**.
- Viết thêm 7 test tái hiện các lỗi nghi ngờ (file `tests/Feature/Audit/OrderAuditReproTest.php`, chưa đưa vào nhánh chính). **Cả 7 lỗi được tái hiện thật**, đánh dấu "✔ đã tái hiện" bên dưới.
- Chưa kiểm tra trên điện thoại thật: phần responsive chỉ đánh giá qua mã nguồn, ghi rõ ở mục 7.

Quy ước: **DONE** = có và đã xác minh bằng test/đọc luồng đầy đủ · **PARTIAL** = có nhưng thiếu · **MISSING** = chưa có · **BUG** = sai, cần sửa · **IMPROVE** = chạy được nhưng nên tối ưu.

---

## 1. Danh sách chức năng hiện có

| Mục | Màn hình | API chính | Service |
|---|---|---|---|
| Tạo đơn và giao hàng | `/orders/new`, `/orders/:id/edit`, `/orders/:id` | `POST /sales-orders`, `PUT /sales-orders/{id}`, `POST /sales-orders/{id}/actions/{confirm,pack,handover,deliver,complete,fail_delivery,cancel}`, `POST /orders/{id}/payments`, `POST /payments/{id}/void` | `ManualOrderService`, `PaymentService`, `OrderStockSync` |
| Danh sách đơn | `/orders` (tab trạng thái, bộ lọc, xem nhanh, chọn nhiều) | `GET /orders`, `/orders/pipeline`, `/orders/export`, `POST /orders/bulk`, `/orders/packing-slips`, `PUT /orders/{id}/assignee`, `/meta`, `/notes` | `OrderQuery`, `OrderActions`, `OrderExporter` |
| Bán tại quầy | `/pos` (nhiều giỏ, quét mã, F2/F9, in K80) | dùng chung `POST /sales-orders` với `stage=completed`; `GET /pos/summary` | `ManualOrderService` |
| Ca bán hàng | bảng ca trong POS, `/pos/shifts` | `GET/POST /pos/shifts`, `/current`, `/{id}/movements`, `/{id}/close` | `CashShiftService` |
| Khách trả hàng | `/returns`, `/returns/:id`, hộp "Tạo phiếu trả" trong chi tiết đơn | `POST /orders/{id}/returns`, `GET /returns`, `POST /returns/{id}/status`, `/inspect` | `ReturnService`, `ExchangeService` |
| Đơn sàn/website | chung danh sách `/orders` | `POST /webhooks/{marketplace}`, lệnh `ecso:orders:reconcile` 15 phút/lần | `WebhookReceiver`, `ProcessWebhookEvent`, `OrderSync`, `OrderIngestor`, `StatusMapper` |

Điểm mạnh nền tảng (giữ nguyên, không làm lại):
- Kho: giữ hàng → xuất kho → nhả/hoàn qua một `ReservationLedger` duy nhất, mỗi bút toán có khóa idempotency (`reserve:…`, `ship:{id}`, `release:{id}`, `restock:return:{id}`), khóa dòng tồn theo thứ tự SKU để tránh deadlock. Đã có test "giao hai lần chỉ trừ một lần", "hủy hai lần chỉ nhả một lần".
- Tiền: `paid_amount`/`refunded_amount` luôn tính lại từ các dòng thanh toán dưới khóa dòng đơn, không cộng dồn tay nên không lệch.
- Đơn sàn: webhook có chữ ký, chống trùng theo `dedupe_key`, bỏ qua bản chụp cũ hơn (`STALE`), từ chối chuyển trạng thái lùi, khóa dòng đơn khi ghi, tenant lấy từ `marketplace_accounts` chứ không từ payload.
- Phân quyền theo khóa quyền (`orders.view/update/create/payment/price/shifts`, `returns.view/inspect`), dữ liệu cách ly theo tenant bằng global scope (đã có test).

---

## 2. Chức năng đã hoàn thiện (DONE)

**Tạo đơn**
- Tạo đơn cho khách có sẵn hoặc khách mới (tìm lại theo số điện thoại chuẩn hóa, số điện thoại mã hóa khi lưu).
- Tìm sản phẩm theo tên/SKU/mã vạch (SkuPicker, F3), nhiều dòng, dòng dịch vụ không trừ kho.
- Xem tồn khả dụng theo kho đang chọn, "Kiểm tra tồn kho" ở mọi kho.
- Giá bán lẻ/sỉ, giá tùy chỉnh, chiết khấu từng dòng và toàn đơn, chặn chiết khấu vượt thành tiền; khóa sửa giá/chiết khấu theo Cấu hình và quyền `orders.price`.
- Phí vận chuyển, COD, thu nhiều lần (thanh toán một phần), ghi nợ khách có tên, chặn thu vượt số còn phải thu, hủy phiếu thu có lý do.
- Quy trình Chờ xác nhận → Chờ đóng gói → Đã đóng gói → Bàn giao (trừ kho) → Đã giao → Hoàn tất; không cho xuất kho khi thiếu hàng; giao thất bại tự mở phiếu hàng hoàn.
- Hủy trước khi xuất kho nhả hàng đang giữ (một lần duy nhất).
- Lịch sử trạng thái (ai, lúc nào, lý do) và nhật ký thao tác (audit log).
- In hóa đơn bán (A4/A5/K80/K57) và phiếu đóng gói.

**Danh sách đơn**
- Tab theo trạng thái kèm số đếm, phân trang phía server, sắp xếp.
- Lọc theo thời gian, trạng thái, nguồn (kênh riêng/sàn), sàn, gian hàng, kênh bán, khách, trạng thái thanh toán, nhân viên phụ trách, thẻ, ngày hẹn giao, cờ (thiếu hàng, chưa liên kết SKU, quá hạn, chưa in, còn nợ…).
- Tìm theo mã đơn, mã vận đơn, tên khách, số điện thoại khách đã lưu, SKU, tên sản phẩm.
- Thao tác hàng loạt (xác nhận, đóng gói, bàn giao, hủy, thu tiền nhanh, phân công) xử lý từng đơn và báo kết quả từng đơn.
- In nhiều phiếu đóng gói / hóa đơn một lần, xuất Excel theo bộ lọc (tối đa 20.000 dòng).

**POS**
- Tìm nhanh, quét mã vạch (Enter thêm ngay khi khớp mã), cộng dồn số lượng khi quét lại, chỉnh số lượng/giá/chiết khấu.
- Nhiều giỏ song song (giữ đơn, mở lại) lưu trong trình duyệt.
- Khách lẻ phải trả đủ, khách có tên được ghi nợ; tiền khách đưa, tiền thừa, gợi ý mệnh giá.
- Trừ kho ngay khi bán; không bán được khi kho thiếu (hai người bán cùng món cuối: chỉ một người thành công, nhờ khóa dòng tồn).
- Chặn bấm thanh toán nhiều lần trong cùng một tab (`paying`).

**Ca bán hàng**
- Mở ca với tiền đầu ca (gợi ý từ ca trước), mỗi người chỉ một ca mở (ràng buộc unique).
- Thu/chi tiền mặt trong ca, không rút quá tiền trong két.
- Doanh thu theo phương thức, tiền mặt thu, hoàn tiền mặt, tiền dự kiến trong két.
- Đóng ca: đếm theo mệnh giá, chênh lệch bắt buộc ghi lý do, nộp tiền, số liệu "đóng băng" khi đóng.
- Lịch sử ca, thu ngân chỉ xem ca của mình, quản lý (`orders.shifts`) xem/đóng ca của người khác, in biên bản bàn giao ca.

**Trả hàng**
- Tìm đơn gốc, trả toàn bộ hoặc một phần, không cho trả vượt số còn lại (tính cả các phiếu trước, trừ phiếu bị từ chối).
- Lý do trả chọn từ danh sách trong Cấu hình hoặc gõ tay.
- Luồng phiếu: Yêu cầu → Duyệt/Từ chối → Đang về → Đã nhận → Đã kiểm → Đóng.
- Kiểm hàng: số nhận = bán được + hỏng; chỉ phần bán được nhập lại kho đã chọn; combo nhập lại theo thành phần; hoàn tiền không kèm hàng không cộng kho.
- Đổi hàng: bù trừ giá trị hàng trả sang đơn mới bằng cặp bút toán "đổi hàng".

**Đa kênh (phần lõi)**
- Đơn sàn/website vào chung danh sách đơn, lưu nguồn (`marketplace`) và mã đơn ngoài (`external_order_id`), unique theo (gian hàng, mã đơn ngoài).
- Chống trùng webhook, chống bản chụp cũ, đối soát 15 phút/lần để bù webhook bị mất, retry có backoff khi API lỗi tạm thời.
- Mapping trạng thái theo bảng `marketplace_status_maps`; trạng thái lạ thành `UNKNOWN_STATUS` (không đoán).
- Mapping SKU chỉ theo mã chính xác; dòng chưa liên kết không giữ kho, tự giữ khi liên kết xong.
- Thao tác cần gửi lên sàn đi qua connector; sàn chưa hỗ trợ trả `UnsupportedCapability` thay vì giả thành công.

---

## 3. Chức năng chưa hoàn thiện (PARTIAL)

| # | Mục | Hiện trạng | Thiếu |
|---|---|---|---|
| PA1 | Thuế bán hàng | Cấu hình có `tax.sale_rate`, `prices_include_tax` | Đơn bán **không dùng** thuế: không có cột thuế, tổng tiền không đổi khi đặt thuế suất (✔ đã tái hiện T1). Hóa đơn không tách tiền thuế. |
| PA2 | Địa chỉ giao | Ô nhập tay, lấy địa chỉ chính của khách | Không chọn từ sổ địa chỉ của khách (`customer_addresses` đã có sẵn). |
| PA3 | Thanh toán kết hợp ở POS | Backend nhận tối đa 5 dòng thanh toán | Giao diện POS chỉ cho một phương thức mỗi giao dịch (ví dụ không thể "200k tiền mặt + 300k chuyển khoản"). |
| PA4 | Giữ đơn POS | Nhiều giỏ, lưu `localStorage` | Chỉ trên máy/trình duyệt đó; xóa dữ liệu trình duyệt là mất; không giữ hàng trong kho; quản lý không thấy. |
| PA5 | Lịch sử chỉnh sửa đơn | Audit ghi "order.updated" kèm tổng tiền | Không ghi chi tiết đổi gì (dòng nào, giá cũ/mới, khách cũ/mới); chi tiết đơn không hiển thị lịch sử sửa. |
| PA6 | Hủy đơn đã thu tiền | Hiện cảnh báo "cần ghi nhận hoàn tiền" | Không gợi ý/tạo sẵn phiếu hoàn; đơn hủy có tiền chưa hoàn không có bộ lọc riêng. |
| PA7 | Hoàn tiền trong phiếu trả | Phiếu có ô "Số tiền hoàn" | Con số này **không tạo bút toán hoàn tiền**; người dùng phải sang đơn bấm "Hoàn tiền" riêng, không có kiểm tra hai số khớp nhau, không chọn phương thức hoàn trong phiếu trả. |
| PA8 | Trạng thái thanh toán | unpaid/partial/paid theo số đã thu | Không phản ánh hoàn tiền: đơn đã hoàn hết vẫn hiện "Đã thanh toán". Không có trạng thái "Đã hoàn tiền / Hoàn một phần". |
| PA9 | Trạng thái vận chuyển | Gộp trong trạng thái đơn (Bàn giao/Đang giao/Đã giao/Giao thất bại) | Chưa tách riêng trạng thái giao hàng; chưa có kết nối hãng vận chuyển (GHN, GHTK…), chỉ nhập tay hãng và mã vận đơn. |
| PA10 | Hàng hỏng khi kiểm trả | Ghi số lượng hỏng | Hàng hỏng không đi đâu cả (không vào kho lỗi, không tạo phiếu hủy), khó đối soát. |
| PA11 | Chi tiền trong ca | Có thu/chi trong ca | Thu/chi trong ca **không xuất hiện trong Sổ quỹ** (Sổ quỹ chỉ gộp phiếu thu chi tay, thanh toán đơn, thanh toán NCC). |
| PA12 | Liên kết ca – đơn | Ca tính theo "người tạo + thời gian từ lúc mở ca" | Đơn/thanh toán không lưu `cash_shift_id`. Thu tiền khi chưa mở ca không thuộc ca nào; hủy phiếu thu của ca đã đóng không phản ánh ở đâu; quản lý hoàn tiền thay thu ngân thì tiền ra khỏi két thu ngân nhưng tính vào "ca" của quản lý. |
| PA13 | Tìm theo số điện thoại | Tìm được khách đã lưu | Không tìm được số điện thoại người nhận trên đơn khách lẻ hoặc đơn sàn (trường mã hóa, chưa có hash để tìm). |
| PA14 | Giá trị hàng trả | Giá trừ chiết khấu dòng | Không phân bổ chiết khấu toàn đơn, nên giá trị bù trừ đổi hàng/hoàn có thể cao hơn số khách thực trả. |
| PA15 | Đơn WooCommerce hoàn tiền một phần | — | Woo chỉ báo trạng thái; khoản hoàn từng phần trên website không được đọc về. |

---

## 4. Chức năng còn thiếu (MISSING)

| # | Chức năng | Ghi chú |
|---|---|---|
| M1 | **Chống tạo đơn/thu tiền trùng (idempotency)** | `POST /sales-orders` và `POST /orders/{id}/payments` không có khóa idempotency. Mạng chậm, timeout rồi bấm lại, hay mở 2 tab là tạo 2 đơn, trừ kho 2 lần, thu tiền 2 lần. Đây là rủi ro lớn nhất ở POS. |
| M2 | **Bắt buộc mở ca khi bán tại quầy (phía server)** | Chỉ giao diện POS chặn; API vẫn cho bán khi chưa mở ca (✔ đã tái hiện S1). |
| M3 | Đơn nháp | Không có trạng thái Nháp (không giữ kho). "Chờ xác nhận" đã giữ kho nên không thay được. |
| M4 | Lọc theo kho xuất | Danh sách đơn không lọc theo kho. |
| M5 | Kiểm soát sửa đồng thời | Có cột `version` nhưng API sửa đơn không kiểm tra; hai người sửa cùng lúc thì người lưu sau ghi đè người trước mà không biết. |
| M6 | Quyền xem đơn theo nhân viên | Ai có `orders.view` xem được mọi đơn của cửa hàng; chưa có "chỉ xem đơn mình tạo/được phân công". |
| M7 | Quyền riêng cho hủy đơn / hủy phiếu thu / sửa đơn | Hủy đơn dùng chung `orders.update`; sửa đơn chỉ cần `orders.create`; hủy phiếu thu dùng chung `orders.payment`. Nhân viên bán hàng mặc định đều có. |
| M8 | Mở lại / điều chỉnh ca đã đóng | Không có; sai sót sau khi đóng ca không sửa được có kiểm soát. |
| M9 | Hủy giao dịch POS đã hoàn tất có phân quyền | Đơn POS ở trạng thái Hoàn tất không hủy được, chỉ còn cách làm phiếu trả; không có "hủy hóa đơn trong ca" cho quản lý. |
| M10 | Trạng thái đơn sau khi trả hàng | Đơn kênh riêng trả hết hàng vẫn "Hoàn tất"; không có "Đã trả một phần / Đã trả hết". |
| M11 | Phiếu giao hàng riêng | Có hóa đơn và phiếu đóng gói; chưa có phiếu giao hàng cho shipper (ký nhận, thu COD). |
| M12 | Đối soát tiền đơn | Có lệnh kiểm tra tồn (`ecso:inventory:verify`) nhưng chưa có lệnh kiểm tra tiền (tổng thanh toán = `paid_amount`, đơn hủy còn tiền chưa hoàn, phiếu thu không thuộc ca nào). |

---

## 5. Lỗi phát hiện (BUG)

| # | Mức | Lỗi | Bằng chứng |
|---|---|---|---|
| **B1** | P0 | **Trả hàng cho đơn chưa xuất kho vẫn nhập lại kho → tồn kho ảo.** `ReturnService::create` không kiểm tra hàng đã thực sự rời kho. Đơn "Chờ xác nhận" 2 món (chỉ đang giữ), tạo phiếu trả 2 món rồi kiểm "bán được" → tồn từ 5 thành 7, đơn vẫn giữ 2. | ✔ đã tái hiện R1. Nút "Tạo phiếu trả" hiện ở mọi trạng thái đơn. |
| **B2** | P0 | **Kiểm hàng gửi trùng một dòng → nhập kho hai lần.** `inspect` không chặn `line_id` lặp: trả 1 món, cộng kho 2 món. | ✔ đã tái hiện R2 (gọi API trực tiếp; giao diện hiện tại không gửi trùng nhưng API phải tự bảo vệ). |
| **B3** | P0 | **WooCommerce: đơn hoàn tiền khi chưa giao bị trừ kho.** `refunded` được ánh xạ thẳng sang `RETURNED` (nhóm "đã xuất kho"). Đơn đang "processing" (mới giữ hàng) đổi sang "refunded" thì 2 món bị trừ khỏi kho dù chưa rời kho, và không có phiếu hàng hoàn nào để nhập lại. Đơn đã giao rồi hoàn tiền cũng không tự mở phiếu hàng hoàn. | ✔ đã tái hiện W1. Thuộc phần WooCommerce đang do luồng "Kết nối WooCommerce và giá theo phí sàn" phụ trách, sẽ phối hợp, không làm trùng. |
| **B4** | P1 | **Trả hàng không giảm công nợ / doanh thu.** Khách mua nợ 200.000đ, trả lại toàn bộ hàng: công nợ vẫn 200.000đ, đơn vẫn "Hoàn tất". Báo cáo doanh thu, lãi lỗ, bán chạy chỉ loại đơn `CANCELLED`/`RETURNED`, nên hàng trả của đơn kênh riêng vẫn tính là đã bán. | ✔ đã tái hiện R3; `TradeReports::EXCLUDED`, `SalesReport::sold()`, `Customer::withStats()`. |
| **B5** | P1 | **Quyền tạo phiếu trả không khớp giữa giao diện và API.** Giao diện hiện nút theo `returns.inspect`, API kiểm `orders.update`. Nhân viên kho thấy nút nhưng bị 403; nhân viên xử lý đơn không thấy nút nhưng API cho phép. | ✔ đã tái hiện P1. |
| B6 | P1 | **Sửa đơn chỉ kiểm quyền `orders.create`** (`SalesOrderController::update`), trong khi chuyển trạng thái cần `orders.update`. | Đọc mã. |
| B7 | P1 | **Tổng tiền phía POS và phía server có thể khác nhau.** `cartTotals` ép dòng âm về 0, server từ chối; POS dùng số thực (float) trong khi server dùng bcmath. Với VND số nguyên hiện tại ít gây lệch, nhưng khi thêm thuế % sẽ lệch làm tròn. | Đọc mã `pos.ts`. |
| B8 | P2 | Gửi hai dòng trùng một sản phẩm khi tạo phiếu trả → lỗi 500 (vi phạm unique) thay vì thông báo rõ. | Đọc mã + ràng buộc `order_return_lines`. |
| B9 | P2 | Hộp tạo phiếu trả điền sẵn số lượng = toàn bộ số đã mua, kể cả khi đã trả trước đó (server sẽ báo lỗi, nhưng gây khó chịu). | Đọc mã `OrderDetail.vue`. |
| B10 | P2 | Hủy phiếu thu dùng `window.prompt` (hộp thoại trình duyệt), không theo design system, dễ bấm nhầm trên điện thoại. | Đọc mã. |
| B11 | P2 | Tổng quan "Doanh thu hôm nay" tính cả đơn đã trả hàng/giao thất bại (chỉ loại đơn hủy). | `OrderController::summary`. |

---

## 6. Rủi ro về tồn kho, thanh toán và dữ liệu

**Đã an toàn (đã kiểm tra bằng test hiện có)**
- Không trừ kho hai lần khi giao/đồng bộ lặp; không nhả kho hai lần khi hủy lặp; webhook trùng/đến sai thứ tự; hai người bán cùng món cuối; tồn không bao giờ âm; tenant cách ly.

**Rủi ro còn lại, theo mức độ**
1. (P0) Tồn kho ảo do trả hàng cho đơn chưa xuất kho (B1), do kiểm trùng dòng (B2), do Woo hoàn tiền (B3).
2. (P0) Tạo đơn trùng / thu tiền trùng khi mạng chập chờn (M1). Ở POS: trùng đơn = trừ kho đôi + tiền két lệch.
3. (P1) Tiền két không khớp ca: bán khi chưa mở ca (M2), không có `cash_shift_id` (PA12), thu/chi trong ca không vào Sổ quỹ (PA11).
4. (P1) Công nợ và doanh thu không trừ hàng trả (B4).
5. (P1) Đơn sàn bàn giao khi kho thiếu: phần thiếu không trừ kho (chỉ cảnh báo), tồn hệ thống có thể cao hơn thực tế; cần màn đối soát xử lý các đơn này.
6. (P1) Sửa đơn đồng thời ghi đè (M5).
7. (P2) Mỗi lần tạo đơn khóa dãy số chứng từ của cả cửa hàng đến hết giao dịch: an toàn, nhưng khi nhiều quầy bán cùng lúc thì các quầy xếp hàng chờ nhau (vài chục ms/đơn, chấp nhận được với quy mô hiện tại).

**Quy tắc trừ kho theo kênh (hiện trạng, giữ nguyên)**
- Kênh riêng: không cho xuất kho khi thiếu hàng.
- Sàn/website: đơn đã có thật trên sàn nên vẫn ghi nhận, giữ phần có, đánh dấu thiếu, tự bù khi nhập hàng.
- Đề xuất không đổi quy tắc này, chỉ bổ sung đối soát.

---

## 7. Đề xuất cải thiện UI/UX (giữ design system hiện tại)

| # | Màn hình | Đề xuất |
|---|---|---|
| U1 | POS | Hộp "Thanh toán" cho phép chia nhiều phương thức (tiền mặt + chuyển khoản + thẻ), hiện số còn thiếu/tiền thừa theo thời gian thực. |
| U2 | POS | Khi mất mạng hoặc lỗi giữa chừng: giữ nguyên giỏ, nút "Thử lại" dùng lại cùng khóa giao dịch (không tạo đơn mới). |
| U3 | POS | Trên điện thoại: giỏ hàng là ngăn kéo cố định đáy màn hình với tổng tiền + nút thanh toán (hiện giỏ nằm dưới danh sách sản phẩm, phải cuộn xa). *Chưa thử trên máy thật, đánh giá theo mã.* |
| U4 | Danh sách đơn | Thêm bộ lọc Kho; trên điện thoại hiển thị dạng thẻ thay vì bảng cuộn ngang. |
| U5 | Chi tiết đơn | Chỉ hiện "Tạo phiếu trả" khi hàng đã xuất kho, điền sẵn số lượng còn có thể trả; ẩn/khóa nút theo đúng quyền API. |
| U6 | Chi tiết đơn | Thẻ "Lịch sử" gộp: trạng thái + sửa đơn (trước/sau) + thu/hoàn tiền + phiếu trả, theo thời gian. |
| U7 | Chi tiết đơn | Hủy đơn đã thu tiền: hộp hủy có sẵn phần "Hoàn tiền ngay" (số tiền, phương thức). Hủy phiếu thu dùng Dialog thay `window.prompt`. |
| U8 | Phiếu trả | Bước "Hoàn tiền" ngay trong phiếu trả (số tiền đề xuất = giá trị hàng trả sau phân bổ chiết khấu, chọn phương thức), tạo bút toán hoàn thật. Hàng hỏng: tùy chọn "nhập kho lỗi" hoặc "hủy". |
| U9 | Đơn hàng | Huy hiệu trạng thái thanh toán thêm "Đã hoàn tiền / Hoàn một phần"; huy hiệu "Đã trả hàng một phần/toàn bộ". |
| U10 | Ca | Nút "Mở lại ca" (chỉ quản lý, bắt buộc lý do, ghi nhật ký) và hiện danh sách giao dịch trong ca. |

Không đề xuất đổi sidebar.

---

## 8. Mức độ ưu tiên

**P0: sai dữ liệu kho/tiền, làm trước**
- B1 Chặn trả hàng cho phần chưa xuất kho (chỉ trả số lượng đã thực xuất trừ số đã trả).
- B2 Chặn dòng trùng khi kiểm hàng (và khi tạo phiếu trả, B8).
- M1 Idempotency cho tạo đơn và thu tiền (header `Idempotency-Key`, POS và form tạo đơn tự sinh khóa).
- B3 Woo `refunded`: phối hợp với luồng WooCommerce (đề xuất: chưa xuất kho → coi như hủy, nhả hàng; đã xuất kho → mở phiếu hàng hoàn, chờ kiểm).

**P1: nghiệp vụ sai hoặc thiếu kiểm soát**
- B4 Trả hàng giảm công nợ và doanh thu: **công nợ do luồng "Kiểm toán module khách hàng" sửa (hàm công nợ dùng chung), doanh thu/lãi lỗ do luồng "Kiểm toán module tài chính" sửa**; luồng này chỉ làm trạng thái trả một phần/toàn bộ trên đơn (M10) và dùng lại kết quả của hai luồng kia.
- PA7/U8 Hoàn tiền ngay trong phiếu trả, có kiểm tra không hoàn vượt.
- M2 + PA12 Bắt buộc ca mở khi bán tại quầy (server), gắn `cash_shift_id` cho đơn POS và phiếu thu.
- PA11 Thu/chi trong ca vào Sổ quỹ.
- B5, B6, M7 Sửa quyền: tạo phiếu trả, sửa đơn, thêm quyền hủy đơn / hủy phiếu thu.
- M5 Kiểm tra `version` khi sửa đơn.
- PA1 Thuế bán hàng theo Cấu hình (có test làm tròn).
- PA3/U1 Thanh toán kết hợp ở POS.
- PA8 Trạng thái thanh toán có hoàn tiền.

**P2: tối ưu**
- M3 Đơn nháp, M4 lọc kho, M6 quyền xem đơn theo nhân viên, M8 mở lại ca, M9 hủy hóa đơn POS có phân quyền, M11 phiếu giao hàng, M12 lệnh đối soát tiền.
- PA2 sổ địa chỉ, PA5/U6 lịch sử sửa chi tiết, PA13 tìm theo SĐT người nhận, PA14 phân bổ chiết khấu, PA10 hàng hỏng.
- B7, B9, B10, B11, U3, U4, U9, U10.

---

## 9. File / module / API cần thay đổi (dự kiến)

| Nhóm | File | Thay đổi |
|---|---|---|
| Trả hàng (B1, B2, B4, B8, PA7, PA14) | `app/Modules/Orders/Services/ReturnService.php`, `Http/Controllers/ReturnController.php`, `Models/OrderReturn.php`, `Policies/ReturnPolicy.php`, `pages/orders/OrderDetail.vue`, `pages/returns/ReturnDetail.vue` | Giới hạn theo số đã xuất kho (dùng `ReservationLedger::snapshot`), chặn trùng dòng, bước hoàn tiền tạo `OrderPayment` kind=refund, phân bổ chiết khấu đơn, cột `return_status` hoặc trường tính toán trên đơn. |
| Idempotency (M1) | middleware mới `app/Http/Middleware/IdempotencyKey.php` + bảng mới `idempotency_keys`, `routes` của `sales-orders` và `payments`, `Pos.vue`, `OrderForm.vue`, `OrderDetail.vue` | Lưu khóa + kết quả 24h theo tenant/user; gửi lại cùng khóa trả về kết quả cũ. |
| Ca (M2, PA11, PA12, M8) | `ManualOrderService`, `PaymentService`, `CashShiftService`, `CashShiftController`, `VoucherLedger`, migration thêm `cash_shift_id` (nullable) vào `orders`, `order_payments` | Kênh POS bắt buộc có ca mở; tổng ca tính theo `cash_shift_id` (giữ cách tính cũ cho ca cũ); thu/chi ca vào Sổ quỹ. |
| Quyền (B5, B6, M7, M6) | `PermissionRegistry`, `OrderPolicy`, `ReturnPolicy`, `SalesOrderController`, `CashShiftPolicy`, `OrderDetail.vue` | Thêm `orders.cancel`, `orders.payment.void`, `orders.view.all` (gán sẵn cho các vai trò hiện có để không ai mất quyền). |
| Báo cáo/công nợ (B4, B11) | `TradeReports`, `OrderReports`, `SalesReport`, `Customer::withStats`, `OrderController::summary` | Trừ giá trị hàng trả đã duyệt/kiểm. |
| Thuế (PA1) | migration thêm `tax_amount` (mặc định 0) vào `orders`, `order_items`; `ManualOrderService::refreshTotals`, `OrderForm.vue`, `Pos.vue`/`pos.ts`, mẫu in | Tính thuế theo Cấu hình, làm tròn đến đồng. Đơn cũ thuế = 0, không đổi tổng. |
| Sửa đồng thời (M5) | `SalesOrderController::update`, `OrderForm.vue` | Gửi `version`, sai thì báo "Đơn vừa được người khác sửa". |
| POS (PA3, U1–U3, B7) | `Pos.vue`, `pos.ts` | Thanh toán nhiều phương thức, khóa giao dịch, giao diện điện thoại. |
| Woo (B3) | `Connectors/WooCommerce/WooCommerceConnector.php`, `OrderIngestor`/`OrderStockSync` | **Phối hợp với luồng WooCommerce**, không tự sửa song song. |

Migration đều là **thêm cột nullable/mặc định** hoặc **thêm bảng**, không xóa/đổi cột cũ; mỗi migration có `down()` gỡ đúng phần thêm vào. Không cần chuyển đổi dữ liệu cũ (đơn cũ: thuế 0, `cash_shift_id` null → ca cũ vẫn tính theo thời gian như hiện nay).

---

## 10. Kế hoạch triển khai theo từng đợt

Mỗi đợt: một PR riêng, có test tự động (Pest) cho từng tình huống, CI xanh mới gộp vào `main`, sau đó gửi lệnh `sudo ecso-update main`. Thay đổi giao diện sẽ gửi mockup trước để duyệt.

| Đợt | Nội dung | Giao diện? | Test chính |
|---|---|---|---|
| **1 (P0, an toàn dữ liệu)** ✅ đã làm | B1, B2, B8, M1 idempotency (tạo đơn + thu tiền), B5/B6 quyền trả hàng/sửa đơn, B9 | Rất ít (ẩn nút, điền đúng số lượng): **không cần mockup** | trả đơn chưa xuất kho bị chặn; kiểm trùng dòng bị chặn; gửi lại cùng khóa không tạo đơn/thu tiền thứ hai; nhân viên không quyền bị chặn |
| **1b (P0, Woo)** | B3: gửi phát hiện + đề xuất cho luồng WooCommerce, họ sửa hoặc giao lại cho tôi | Không | hoàn tiền trước giao → nhả kho; sau giao → mở phiếu hàng hoàn |
| **2 (P1, trả hàng & tiền)** | M10 (B4 phần công nợ/báo cáo do luồng Khách hàng và Tài chính làm), PA7/U8 hoàn tiền trong phiếu trả, PA8, PA14, B11 | **Có, gửi mockup** (phiếu trả có bước hoàn tiền, huy hiệu trạng thái) | trả một phần nhiều lần; hoàn vượt bị chặn; công nợ/doanh thu giảm đúng; không hoàn hai lần |
| **3 (P1, ca & POS)** | M2, PA12, PA11, PA3/U1, U2, M8, M9 | **Có, gửi mockup** (thanh toán kết hợp, mở lại ca, hủy hóa đơn) | bán khi chưa mở ca bị chặn; tiền két đúng khi hoàn/hủy phiếu; thanh toán kết hợp; mở lại ca chỉ quản lý |
| **4 (P1, thuế & kiểm soát)** | PA1 thuế, M5 version, M7 quyền hủy | **Có, gửi mockup** (dòng thuế trên form/hóa đơn) | làm tròn thuế, giá đã/chưa gồm thuế; sửa đồng thời bị báo |
| **5 (P2)** | M3 nháp, M4 lọc kho, M6 xem đơn theo nhân viên, PA2, PA5/U6, PA13, M11, M12, U3/U4 điện thoại, U9, U10, PA10 | **Có, gửi mockup** | theo từng mục |

Bộ test Phase 10 sẽ được bổ sung dần theo từng đợt: tạo đơn thành công/thiếu thông tin, vượt tồn, hai người đặt cùng món, thanh toán trùng, hủy sau xuất kho, trả một phần, trả nhiều lần, đơn đồng bộ trùng, webhook trùng/sai thứ tự, API ngoài lỗi, nhân viên không quyền, truy cập tenant khác. Phần lớn nhóm đồng bộ/webhook/tenant đã có test và đang đạt; nhóm trả hàng, thanh toán trùng và ca sẽ thêm mới. Không chạy thử nghiệm nào trên dữ liệu thật (erp.itso.vn).

**Phân công giữa các luồng (để không làm trùng):** công nợ khi trả hàng → luồng Khách hàng; doanh thu/lãi lỗ khi trả hàng → luồng Tài chính; kiểm kê kho (stocktake) → luồng Sản phẩm, kho và nhập hàng; Woo hoàn tiền (B3) → luồng WooCommerce. Luồng này giữ: idempotency POS/tạo đơn/thu tiền, chặn trả hàng chưa xuất kho (B1), kiểm hàng trả trùng dòng (B2, khác với kiểm kê kho), ca bán hàng, quyền.
