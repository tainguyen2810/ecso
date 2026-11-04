# Kiểm toán module Khách hàng (EzSell / ECSO)

Ngày kiểm toán: 04/11/2026 · Mã nguồn: `main` @ `cb6b5b1` (CI xanh, run #70).

Phạm vi đã đọc: `app/Modules/Sales` (Customer, CustomerGroup, CustomerAddress, CustomerNote, OrderPayment, CustomerService,
CustomerImporter/Exporter, CustomerLedger, PaymentService, ManualOrderService), `Cashbook/CashVoucherService` (thu nợ khách),
`Analytics` (LedgerReports.receivables, TradeReports.customers, BusinessReports), `Orders` (OrderController.customerStats,
OrderIngestor, ReturnService, ExchangeService), migrations `2026_11_03_600000`, `2026_11_07_100000`, các trang Vue
`pages/customers/*`, `OrderForm.vue`, `Pos.vue`, test `tests/Feature/Sales/CustomerTest.php`, `Cashbook/CashVoucherTest.php`.

Cách đánh giá: **Đã xác minh** = đọc code backend + có test tự động chạy xanh trên CI. **Có, chưa kiểm thử** = có code
nhưng không có test. **Thiếu** = không có trong code. Không có số liệu nào trong báo cáo này là ước đoán: mỗi lỗi đều chỉ ra
dòng code gây ra.

---

## 1. Các chức năng đang có

| Nhóm | Chức năng | Ở đâu |
|---|---|---|
| Danh sách | Phân trang server (5–100/trang), tìm theo tên, mã, email, SĐT (đủ số qua hash hoặc 4 số cuối) | `CustomerController::index/filtered` |
| | Lọc: nhóm, trạng thái, đang nợ, giới tính, tháng sinh, phụ trách, tag, tổng chi tiêu từ–đến, số đơn tối thiểu, ngày mua gần nhất, ngày tạo | như trên |
| | Sắp xếp 6 kiểu (dropdown, chỉ giảm dần trừ tên) | như trên |
| | Chọn cột hiển thị (lưu localStorage) | `CustomerList.vue` |
| | Thao tác hàng loạt: đổi nhóm, trạng thái, thêm/bỏ tag, giao phụ trách, xóa (bỏ qua khách có đơn), tối đa 500 | `CustomerService::bulk` |
| | Nhập CSV/XLSX: khớp theo mã → SĐT (không khớp theo tên), ô trống không ghi đè, kiểm tra toàn bộ trước, all-or-nothing, chạy thử, tối đa 5.000 dòng, file mẫu | `CustomerImporter` |
| | Xuất CSV/XLSX: tất cả hoặc các dòng đã tick, cùng cột với file nhập + số liệu mua | `CustomerExporter` |
| Hồ sơ | Mã KH tự sinh `KH-xxxxxx` hoặc tự nhập (duy nhất trong cửa hàng), tên, SĐT (mã hóa + blind index), email, địa chỉ (mã hóa), ngày sinh, giới tính, MST, nhóm, phụ trách, tags, trạng thái, ghi chú | `Customer`, `CustomerService` |
| | Nhiều địa chỉ giao hàng (tối đa 20, mã hóa) | `CustomerProfileController` |
| | Dòng thời gian ghi chú (ai cũng xem được thì thêm được) | như trên |
| Chi tiết | KPI: số đơn, tổng chi tiêu, còn nợ, mua gần nhất; tab Đơn hàng (50 đơn), Công nợ, Thu/hoàn tiền (20 dòng), Địa chỉ, Ghi chú | `CustomerDetail.vue` |
| Công nợ | Sổ công nợ có số dư lũy kế (đơn cộng, thu tiền trừ) | `CustomerLedger` |
| | Thu nợ khách từ Sổ quỹ: phân bổ vào đơn cũ nhất trước, khóa dòng, chặn thu vượt nợ | `CashVoucherService::collectCustomerDebt` |
| | Thanh toán một phần/toàn bộ trên đơn, hủy phiếu thu có lý do, tổng tiền đơn luôn tính lại từ phiếu dưới khóa | `PaymentService` |
| | Báo cáo "Công nợ phải thu" theo khách, tuổi nợ | `LedgerReports::receivables` |
| Nhóm | CRUD nhóm, mã tự sinh, chính sách giá (bán lẻ/bán buôn), % chiết khấu, PT thanh toán quen dùng; chặn xóa nhóm còn khách | `CustomerGroupController` |
| Bán hàng | Tạo đơn thủ công/POS chọn khách, hoặc tạo khách mới theo SĐT (`findOrCreate`); nhóm khách đổi chính sách giá trong form đơn | `ManualOrderService`, `OrderForm.vue` |
| Thống kê | Báo cáo khách hàng: khách mới/quay lại theo ngày, doanh thu theo khách | `TradeReports` |
| Bảo mật | Tenant scope toàn cục + `tenant_id` lấy từ server, quyền `customers.view` / `customers.manage`, throttle nhập/xuất, audit log tạo/sửa/xóa/bulk/nhóm | `BelongsToTenant`, `CustomerPolicy` |

## 2. Đã hoàn thiện và xác minh (code + test CI xanh)

- Quản lý nhóm, chặn xóa nhóm đang dùng; cách ly nhóm/khách giữa 2 tenant (`isolates groups and customers between businesses`).
- Hồ sơ đầy đủ trường (mã, ngày sinh, giới tính, MST, phụ trách, tags, trạng thái).
- Lọc theo trạng thái, tháng sinh, giới tính, tag, phụ trách, chi tiêu.
- Bulk update; bulk delete giữ khách có đơn; kế toán (không có `customers.manage`) bị chặn bulk.
- Sổ công nợ với số dư lũy kế (trường hợp đơn + thanh toán đơn giản).
- Địa chỉ giao hàng phụ + ghi chú; tenant khác xóa địa chỉ → 404.
- Nhập all-or-nothing, khớp mã → SĐT, chạy thử, báo lỗi theo dòng; xuất toàn bộ/theo id; kế toán bị chặn xuất.
- Thu nợ khách phân bổ đơn cũ nhất trước (`CashVoucherTest`).

Những phần còn lại ở mục 1 có code nhưng **chưa có test** (tìm kiếm SĐT 4 số cuối, lọc ngày mua gần nhất, chọn cột, xóa đơn lẻ…).

## 3. Chưa hoàn thiện (có một phần)

| Chức năng | Hiện trạng | Thiếu gì |
|---|---|---|
| Sắp xếp cột | Dropdown 6 kiểu, chỉ giảm dần | Bấm tiêu đề cột để sắp xếp, đổi chiều tăng/giảm |
| Xuất theo bộ lọc | Chỉ xuất tất cả hoặc dòng đã tick | Xuất đúng bộ lọc đang xem (`CustomerExporter` không nhận filter) |
| Lịch sử thay đổi | Audit log chỉ ghi `code`, không ghi trường nào đổi; không có UI xem lịch sử trên khách | Ghi diff trước/sau, tab "Lịch sử hoạt động" |
| Chi tiết khách | 5 tab, đơn tối đa 50, thanh toán tối đa 20, không phân trang | Tab Tổng quan, Trả hàng; phân trang server cho đơn/thanh toán |
| Nhiều địa chỉ | Lưu được nhưng **form tạo đơn và POS không cho chọn** địa chỉ phụ | Chọn địa chỉ giao hàng khi tạo đơn |
| Thông tin xuất hóa đơn | Chỉ có MST | Tên đơn vị, địa chỉ xuất HĐ, email nhận HĐ |
| Chiết khấu nhóm | Lưu `discount_percent`, hiển thị ở chi tiết | **Không áp dụng ở đâu cả** (OrderForm, POS, backend) |
| PT thanh toán quen dùng của nhóm | Lưu và hiển thị | Không tự chọn khi tạo đơn/POS |
| Chính sách giá theo nhóm | OrderForm đổi bán lẻ/bán buôn theo nhóm | POS không áp dụng; backend không kiểm tra |
| Phân quyền | 2 quyền `view`/`manage` | Tách quyền xuất, nhập, xóa, điều chỉnh công nợ; nhân viên xử lý đơn hiện xóa và xuất được toàn bộ SĐT khách |
| Nhập dữ liệu lớn | Đồng bộ trong request, 5.000 dòng | Hàng đợi cho file lớn (tùy chọn, P2) |
| Responsive | Bảng cuộn ngang, toolbar wrap | Dạng thẻ trên mobile cho danh sách và chi tiết |

## 4. Chức năng còn thiếu

- **Công nợ**: công nợ đầu kỳ, hạn thanh toán / hạn mức nợ theo khách hoặc nhóm, công nợ quá hạn, phiếu điều chỉnh công nợ có lý do, khách trả trước/tiền thừa (hiện chặn thu vượt nợ), xuất sổ công nợ theo khách.
- **Nhóm**: ngừng sử dụng nhóm (không có `is_active`), phân nhóm tự động theo điều kiện, quy tắc ưu tiên khi có nhiều chính sách giá.
- **Hồ sơ**: nguồn khách hàng (kênh tạo ra khách), loại khách cá nhân/doanh nghiệp, người cập nhật cuối.
- **Đa kênh**: bảng định danh theo kênh (mã khách ngoài + nguồn), phát hiện khách trùng, xem trước và gộp khách, nhật ký gộp.
- **CRM**: nhiệm vụ chăm sóc, nhắc gọi lại, phân loại khách tiềm năng; phân khúc "lâu không mua / mua thường xuyên / giá trị cao / nợ quá hạn" (có thể dựng từ bộ lọc sẵn có, chưa có view lưu sẵn).
- **Thống kê trên khách**: giá trị hoàn hàng, AOV, tần suất mua (báo cáo chung đã có khách mới/quay lại).
- **Xóa mềm**: khách bị xóa cứng (chỉ khi chưa có đơn); phiếu quỹ gắn khách bị `nullOnDelete` nên mất liên kết.

## 5. Lỗi phát hiện

Mức: 🔴 sai số liệu/tiền · 🟠 sai nghiệp vụ · 🟡 nhỏ.

| # | Mức | Lỗi | Nguyên nhân (code) |
|---|---|---|---|
| L1 | 🔴 | **Ba công thức công nợ khác nhau** cho cùng một khách: trang khách & danh sách & sổ công nợ dùng `total − paid_amount`; báo cáo Công nợ phải thu dùng `total − (paid − refunded)`; thu nợ Sổ quỹ dùng `total > paid_amount` chỉ đơn `manual`. Một đơn đã trả rồi được hoàn một phần sẽ hiện nợ ở báo cáo nhưng không nợ ở trang khách. | `Customer::scopeWithStats`, `LedgerReports::receivables`, `CashVoucherService::unpaidOrders` |
| L2 | 🔴 | **Trả hàng một phần không giảm công nợ**: chỉ đơn chuyển hẳn sang `RETURNED` mới bị loại; trả 1/2 số hàng của đơn ghi nợ thì khách vẫn nợ đủ tiền. | `withStats`, `CustomerLedger` chỉ lọc theo trạng thái đơn, không trừ `order_returns` |
| L3 | 🔴 | **Đơn sàn sẽ bị cộng vào công nợ** ngay khi có đơn sàn gắn `customer_id` (thread Woo đang chuẩn bị nối đơn ↔ khách): `withStats` và `CustomerLedger` không lọc `marketplace = manual`, mà đơn sàn có `paid_amount = 0` ⇒ toàn bộ đơn Woo/Shopee thành nợ. Hiện chưa xảy ra vì chưa có code gắn khách cho đơn sàn. | `Customer::scopeWithStats`, `CustomerLedger::entries` |
| L4 | 🟠 | **Đổi khách trên đơn đã có phiếu thu**: `order_payments.customer_id` giữ khách cũ ⇒ tab "Thu / hoàn tiền" và Sổ quỹ hiển thị phiếu ở khách cũ, khách mới không thấy. | `ManualOrderService::update` không cập nhật phiếu; `PaymentService` chụp `customer_id` lúc thu |
| L5 | 🟠 | Sổ công nợ bỏ qua phiếu hoàn tiền và phiếu bù trừ đổi hàng (`kind = refund`), nên không khớp báo cáo phải thu. | `CustomerLedger` chỉ lấy `kind = payment` |
| L6 | 🟠 | Tạo đơn với khách mới **không có SĐT** ⇒ mỗi đơn tạo một khách mới trùng tên. | `CustomerService::findOrCreate` |
| L7 | 🟠 | Hai đơn cùng lúc với cùng SĐT khách mới ⇒ một request lỗi 500 (vi phạm unique `phone_hash`) thay vì dùng lại khách. | `findOrCreate` không bắt `UniqueConstraintViolationException` |
| L8 | 🟠 | "Mua gần nhất" tính cả đơn **đã hủy** (các chỉ số khác loại đơn hủy/hoàn). | `withStats` → `last_order_at` không lọc trạng thái |
| L9 | 🟠 | Khách "Ngừng giao dịch" vẫn chọn được và tạo đơn được, không cảnh báo. | `ManualOrderService::customer` |
| L10 | 🟡 | Xuất file (chứa SĐT, địa chỉ giải mã) **không ghi audit log**. | `CustomerTransferController::export` |
| L11 | 🟡 | SĐT chỉ dò được đủ số hoặc đúng 4 số cuối; gõ 5–8 số không ra kết quả (giới hạn do mã hóa, cần nói rõ trên UI). | `CustomerController::filtered` |
| L12 | 🟡 | `phone_hash` dùng `APP_KEY`: nếu đổi APP_KEY thì mọi tìm kiếm/khớp SĐT hỏng (và dữ liệu mã hóa không đọc được). Cần ghi vào tài liệu vận hành. | `PhoneNumber::hash` |
| L13 | 🟡 | Danh sách và ô tìm khách trong form đơn đều chạy `withStats` (4 subquery tương quan + HAVING) cho mỗi lần gõ phím; với vài chục nghìn khách sẽ chậm. | `CustomerController::index`, `OrderForm.vue` |
| L14 | 🟡 | Người viết ghi chú xóa được ghi chú của mình mà không cần kiểm tra quyền xem khách. | `CustomerProfileController::destroyNote` |

Về **overflow tab**: component `Tabs` đã có `overflow-x-auto`; tôi chưa tái hiện được trên mã hiện tại. Sẽ chụp màn hình
danh sách và chi tiết ở 375 px khi làm mockup để xác định đúng chỗ tràn (nếu bạn có ảnh chụp lỗi thì gửi giúp).

## 6. Liên kết với đơn hàng và công nợ

- Nguồn sự thật của công nợ là **đơn + phiếu thu (`order_payments`)**, không có bảng sổ nợ riêng ⇒ đã đúng hướng "truy xuất về chứng từ gốc" và không cộng hai lần khi đồng bộ (công nợ luôn tính lại, không lưu số cộng dồn). Giữ nguyên thiết kế này.
- Cần **một hàm công nợ dùng chung** (`CustomerDebt`) để trang khách, sổ công nợ, báo cáo phải thu và thu nợ Sổ quỹ cùng một công thức (sửa L1, L2, L3, L5).
- Đề xuất công thức (cần bạn duyệt): `Nợ đơn = total − giá trị hàng trả đã duyệt − (đã thu − đã hoàn)`, chỉ đơn kênh riêng (`marketplace = manual`), trừ đơn hủy. Đầu kỳ và điều chỉnh là chứng từ riêng cộng/trừ vào cùng sổ.
- Câu hỏi nghiệp vụ: đơn **COD chưa đối soát** và đơn **giao thất bại** hiện tính là nợ của khách. Thường COD là tiền phải thu của hãng vận chuyển, không phải khách.

## 7. Dữ liệu đa kênh

- Đơn Shopee/TikTok/Lazada/Tiki: connector mới là khung (chưa có app credentials) ⇒ chỉ kiểm thử với dữ liệu giả, không tích hợp thật.
- WooCommerce: đơn đã đồng bộ nhưng `OrderIngestor` **không gắn khách** (chỉ lưu `buyer_name`, SĐT che). Thread "Kết nối WooCommerce" đang đề xuất lưu SĐT/email đầy đủ để nối khách ⇒ module khách nên cung cấp **dịch vụ định danh dùng chung** (`CustomerResolver`) để thread đó gọi, tránh làm hai lần.
- Thiếu: bảng `customer_identities (customer_id, source, account_id, external_id, phone_hash, email)`; quy tắc khớp theo độ tin cậy (external_id > SĐT đầy đủ > email; không bao giờ theo tên; SĐT che không dùng để khớp); không ghi đè thông tin chính bằng giá trị rỗng từ kênh; gộp khách có xem trước, chuyển đơn/phiếu/địa chỉ/ghi chú/định danh, lưu nhật ký gộp, chỉ trong một tenant.

## 8. Đề xuất cải thiện giao diện (sẽ làm mockup trước)

1. Danh sách: tiêu đề cột bấm để sắp xếp (↑↓), nút "Xuất theo bộ lọc", bộ lọc nhanh dạng chip (Nợ quá hạn, Lâu không mua, VIP…), dạng thẻ trên mobile.
2. Chi tiết: tab theo đề bài — Tổng quan · Đơn hàng · Công nợ · Thanh toán · Trả hàng · Địa chỉ · Ghi chú & lịch sử; nút "Thu nợ" và "Điều chỉnh công nợ" ngay trên tab Công nợ; phân trang server.
3. Hồ sơ: khối "Thông tin xuất hóa đơn", "Nguồn khách", "Định danh theo kênh".
4. Nhóm: trạng thái đang dùng/ngừng, điều kiện tự xếp nhóm.
5. Giữ nguyên DESIGN-SYSTEM.md (sidebar xanh đậm, Tabs underline, icon lucide đơn sắc).

## 9. Danh sách công việc

**P0 — đúng tiền, đúng dữ liệu (làm trước, không cần mockup lớn)**
1. `CustomerDebt` dùng chung + sửa L1, L2, L3, L5, L8; test đối chiếu 4 nơi cho cùng một khách.
2. Sửa L4 (cập nhật `customer_id` phiếu khi đổi khách), L6, L7 (khóa/bắt unique), L9 (cảnh báo khách ngừng giao dịch).
3. Audit log có diff trước/sau cho khách; ghi log xuất/nhập (L10).
4. Tách quyền: `customers.export`, `customers.import`, `customers.delete`, `customers.debt_adjust` (seed cho vai trò hiện có giữ nguyên hành vi).

**P1 — nghiệp vụ còn thiếu (cần mockup)**
5. Công nợ: đầu kỳ, điều chỉnh có lý do, hạn thanh toán + quá hạn, xuất sổ công nợ.
6. Chi tiết khách 7 tab + phân trang; chọn địa chỉ phụ khi tạo đơn/POS.
7. Áp dụng chiết khấu nhóm + PT thanh toán + chính sách giá ở OrderForm, POS và kiểm tra ở backend; ngừng sử dụng nhóm.
8. Danh sách: sắp xếp theo cột, xuất theo bộ lọc, mobile dạng thẻ, sửa overflow.
9. Định danh đa kênh + `CustomerResolver` (phối hợp thread Woo); phát hiện trùng + gộp có xem trước & nhật ký.
10. Thông tin xuất hóa đơn, nguồn khách.

**P2 — mở rộng**
11. CRM: nhiệm vụ chăm sóc, nhắc gọi lại, lịch sử liên hệ có loại (gọi/Zalo/gặp).
12. Phân nhóm tự động theo điều kiện (chạy định kỳ), phân khúc lưu sẵn.
13. Thống kê trên khách: AOV, tần suất, giá trị hoàn; xóa mềm/ẩn khách; nhập file lớn qua hàng đợi.

## 10. File, API, database cần thay đổi

| Hạng mục | Thay đổi |
|---|---|
| DB (chỉ thêm, không xóa cột) | `customers`: `source`, `customer_type`, `invoice_name/address/email`, `credit_limit`, `payment_terms_days`, `deleted_at`; `customer_groups`: `is_active`, `rules` (json); bảng mới `customer_debt_entries` (đầu kỳ/điều chỉnh, có lý do, người lập, hủy), `customer_identities`, `customer_merges`, P2 `customer_tasks` |
| Backend | mới `Sales/Services/CustomerDebt.php`, `CustomerResolver.php`, `CustomerMergeService.php`; sửa `Customer::scopeWithStats`, `CustomerLedger`, `LedgerReports::receivables`, `CashVoucherService::unpaidOrders`, `ManualOrderService` (đổi khách, khách ngừng, chiết khấu nhóm), `CustomerService` (findOrCreate, audit diff), `CustomerExporter` (filter), `PermissionRegistry`, `CustomerPolicy` |
| API | `GET customers/{id}/orders|payments|returns|activity` (phân trang); `POST customers/{id}/debt-adjustments`; `GET customers/{id}/ledger/export`; `GET customers/duplicates`; `POST customers/merge/preview`, `POST customers/merge`; `GET customers/export` nhận cùng tham số lọc như `index` |
| Frontend | `CustomerList.vue`, `CustomerDetail.vue` (tách component từng tab), `CustomerDialog.vue`, `CustomerGroups.vue`, `OrderForm.vue`, `Pos.vue`/`pos.ts`, mới `CustomerMergeDialog.vue` |

## 11. Rủi ro và kế hoạch kiểm thử

Rủi ro: (a) đổi công thức công nợ làm số nợ hiện tại trên demo thay đổi — đây là sửa sai, sẽ có bảng đối chiếu trước/sau;
(b) gộp khách không hoàn tác được ⇒ bắt buộc xem trước, lưu bản chụp khách bị gộp trong `customer_merges` để truy vết;
(c) trùng việc với thread Woo/đơn hàng ⇒ module khách chỉ cung cấp dịch vụ, thread kia gọi;
(d) migration chỉ thêm cột/bảng, có `down()`, `ecso-update` đã sao lưu DB trước khi migrate.

Test sẽ thêm (Pest, chạy trên CI MySQL 8):
- Công nợ: thu một phần/toàn bộ, hủy phiếu, hoàn tiền, trả hàng một phần, đổi hàng, đầu kỳ, điều chỉnh, đơn sàn không vào nợ, cùng một khách cho cùng số ở trang khách / sổ / báo cáo / Sổ quỹ.
- Đồng thời: hai lần thu nợ song song không vượt nợ; hai đơn cùng SĐT mới chỉ tạo một khách.
- Gộp: giữ đơn, phiếu, địa chỉ, ghi chú; không gộp khác tenant; không ghi đè bằng rỗng; nhật ký.
- Phân quyền: từng vai trò với xem/sửa/xuất/nhập/xóa/điều chỉnh; truy cập id khách của tenant khác qua API → 404.
- Playwright: danh sách & chi tiết ở 1280 px và 375 px.

## 12. Lộ trình triển khai (tránh làm lại)

1. **Đợt A (P0, backend)**: `CustomerDebt` + sửa lỗi L1–L10 + quyền mới + audit diff. Không đổi giao diện ngoài nhãn ⇒ không cần mockup. Làm trước vì mọi màn hình sau dùng hàm này.
2. **Đợt B (P1 công nợ + chi tiết khách)**: mockup tab Tổng quan/Công nợ/Trả hàng/Lịch sử, phiếu điều chỉnh, đầu kỳ, hạn nợ → duyệt → làm.
3. **Đợt C (P1 danh sách + nhóm + bán hàng)**: mockup danh sách (sắp xếp cột, mobile), nhóm (ngừng dùng), chiết khấu nhóm trong đơn/POS, chọn địa chỉ phụ.
4. **Đợt D (P1 đa kênh)**: `customer_identities`, `CustomerResolver` giao cho thread Woo dùng, trùng & gộp có xem trước.
5. **Đợt E (P2)**: CRM, phân nhóm tự động, thống kê mở rộng.

Mỗi đợt: một PR, test + CI xanh, tự merge vào `main`, gửi lệnh `sudo ecso-update main`.
