# Báo cáo Phase 6 — SaaS Commercial

> Ngày: 2026-10-31 · Nhánh: `phase-6-commercial`
> Thanh toán: **chuyển khoản ngân hàng, admin xác nhận** (quyết định của chủ dự án). Cổng online (VNPay/PayOS/MoMo) chưa làm: UNKNOWN, chưa có hợp đồng và credentials. Kiến trúc đã sẵn chỗ cắm.

## 1. Đã hoàn thành
- **Hóa đơn và thanh toán**
  - Mỗi hóa đơn có một mã nội dung chuyển khoản riêng (`ECSO` + 6 ký tự).
  - Admin đối chiếu sao kê rồi nhập mã giao dịch ngân hàng. Cùng một mã giao dịch chỉ được ghi nhận một lần, kể cả khi hai yêu cầu đến đồng thời (khóa unique ở DB).
  - Chuyển thiếu thì hóa đơn vẫn ở trạng thái chờ; các lần chuyển thiếu được cộng dồn. Tiền chuyển vào hóa đơn đã đóng được ghi `unapplied` để đối soát hoặc hoàn tiền.
- **Nâng gói**
  - Hệ thống tạo hóa đơn và trừ giá trị phần thời gian chưa dùng của kỳ hiện tại (làm tròn 1.000đ).
  - Gói chỉ đổi khi nhận đủ tiền. Mỗi lúc chỉ có một hóa đơn mở: chọn gói khác thì hóa đơn cũ bị hủy.
- **Hạ gói**
  - Bị chặn nếu đang dùng vượt giới hạn của gói mới (người dùng, shop, kho); màn hình chỉ rõ mục nào vượt.
  - Nếu đang trong kỳ đã trả, việc hạ gói được hẹn đến cuối kỳ. Thời gian đã trả không được hoàn tiền.
- **Dùng thử**: mỗi tenant được 1 lần, theo `trial_days` của gói. Hết hạn thì về Free.
- **`ecso:billing:sweep`** (chạy mỗi giờ)
  - Phát hành hóa đơn gia hạn 7 ngày trước khi hết kỳ; khi được thanh toán, kỳ mới nối tiếp kỳ cũ.
  - Hết kỳ mà chưa trả thì chuyển `past_due`; sau 3 ngày ân hạn thì về Free.
  - Áp dụng các lần hạ gói đã hẹn.
  - Chạy lại không gây tác dụng phụ. Không xóa dữ liệu, chỉ đổi entitlements.
- **Đếm đơn/tháng**
  - Dựa trên sự kiện `OrderImported`, chỉ phát sau khi transaction commit và chỉ cho đơn mới.
  - Đây là giới hạn **mềm**: đơn vẫn được nhập, vì từ chối đơn sẽ làm sai tồn kho. Trang Gói dịch vụ cảnh báo khi dùng từ 80% và khi vượt 100%.
- **Tài chính**
  - Có: doanh thu đặt hàng (trừ đơn hủy), doanh thu hoàn thành (đơn đã giao trong kỳ), giá vốn lấy từ sổ kho lúc xuất, hoàn tiền, lợi nhuận gộp ước tính, theo ngày và theo shop.
  - **Phí sàn và tiền sàn đã chuyển về = Chưa xác định (null)**, không ước đoán. Doanh thu được ghi rõ là không phải tiền thực nhận.
- **Báo cáo**
  - Bán chạy: tính theo số lượng đã xuất kho.
  - Hàng bán chậm (còn tồn, không bán trong 30/60/90 ngày, xếp theo giá trị tồn): mở theo entitlement `reports.advanced`, không theo tên gói. Thiếu entitlement thì trả 403 với `FEATURE_NOT_IN_PLAN`.
- **Admin**
  - Trang Hóa đơn & doanh thu: MRR, ARR, tenant trả phí/dùng thử/quá hạn, đã thu 30 ngày, hóa đơn chờ, MRR theo gói, tìm theo mã chuyển khoản, xác nhận thanh toán, hủy hóa đơn.
  - Trang Tổng quan có thêm MRR, shop kết nối, số đơn và webhook lỗi (chỉ số đếm, không có dữ liệu nghiệp vụ của tenant).
  - Cảnh báo khi chưa cấu hình tài khoản nhận tiền.
- **Mua hàng**: đánh dấu "Sau MVP".

## 2. File chính
- **Billing**: `app/Modules/Billing/`
  - `Services/SubscriptionService.php`, `PaymentService.php`, `UsageService.php`
  - `Payments/*` (gateway interface, chuyển khoản)
  - `Console/BillingSweepCommand.php`
  - `Listeners/CountOrderUsage.php`
  - `Http/Controllers/*`
- **Analytics**: `app/Modules/Analytics/` (`FinanceReport`, `SalesReport`)
- **Admin**: `app/Modules/Admin/Http/Controllers/AdminBillingController.php`
- **Cấu hình**: `config/billing.php`
- **Frontend tenant**: `pages/Subscription.vue`, `pages/finance/Finance.vue`, `pages/finance/Reports.vue`, `pages/billing/labels.ts`
- **Frontend admin**: `apps/admin/pages/Billing.vue`

## 3. Migrations
`2026_10_31_400000_create_billing_payment_tables`:
- Thêm cột cho `subscriptions`.
- Tạo bảng mới `invoices`, `payments`, `usage_counters`.

Chi tiết xem DATABASE.md.

## 4. API mới
- **Tenant**
  - `GET /subscription` (mở rộng)
  - `POST /subscription/quote`, `POST /subscription/change`, `POST /subscription/trial`
  - `GET /invoices`, `GET /invoices/{id}`, `POST /invoices/{id}/cancel`
  - `GET /finance/summary`
  - `GET /reports/best-sellers`, `GET /reports/slow-movers`
- **Admin** (chỉ super admin): `GET /admin/billing/summary`, `GET /admin/invoices`, `POST /admin/invoices/{id}/payments`, `POST /admin/invoices/{id}/cancel`.
- **Công khai**: `POST /billing/notifications/{gateway}`.
  - Dùng cho IPN của cổng online sau này.
  - Tenant lấy từ hóa đơn (server-side), không lấy từ request.
  - Chuyển khoản không có IPN nên trả 404.

## 5. Giao diện
Ảnh trong `docs/screenshots/phase-6/`:
1. Gói dịch vụ, mức sử dụng
2. Báo giá nâng cấp có trừ tiền
3. Hóa đơn và thông tin chuyển khoản
4. Admin, hóa đơn
5. Admin xác nhận chuyển khoản
6. Báo cáo sau khi lên Pro
7. Tài chính

## 6. Test
- **Pest**: 212 test pass, trong đó 17 test mới.
  - Hóa đơn và hướng dẫn chuyển khoản; gói chỉ đổi khi đã trả.
  - Cùng mã giao dịch ghi nhận hai lần thì chỉ áp dụng một lần.
  - Chuyển thiếu cộng dồn; tiền vào hóa đơn đã đóng.
  - Nâng gói có trừ tiền; thay hóa đơn mở.
  - Hạ gói bị chặn rồi được hẹn và áp dụng cuối kỳ.
  - Dùng thử 1 lần và hết hạn.
  - Gia hạn, quá hạn, về Free.
  - IPN qua gateway giả lập ký HMAC: chữ ký giả bị từ chối, gửi trùng không áp dụng lại, `tenant_id` trong body bị bỏ qua.
  - Đếm đơn không tính trùng.
  - Phân quyền; tenant không thấy hóa đơn của nhau; MRR chỉ super admin xem được.
  - Tài chính: doanh thu khác tiền thực nhận, phí = null.
  - Báo cáo và gating theo entitlement.
- **Vitest**: 19 test.
- **Playwright**: 2 test mới, chạy hết luồng nâng gói → admin xác nhận → entitlement đổi → báo cáo nâng cao mở ra.

## 7. Hạn chế
- Chưa có cổng thanh toán online; admin phải đối chiếu sao kê thủ công.
- Chưa tự đọc sao kê ngân hàng (cần API ngân hàng hoặc dịch vụ như Casso/SePay; chưa có, UNKNOWN).
- Chưa xuất hóa đơn VAT điện tử và chưa tính thuế.
- Tài chính chưa có phí sàn và đối soát vì connector sàn chưa có API settlement.
- Giá các gói là giá tạm, sửa được trong DB; chưa có giao diện admin sửa gói hoặc override entitlement theo tenant (để sau MVP).
- Đổi chu kỳ trong cùng gói (tháng → năm) được xử lý như nâng cấp có trừ tiền.

## 8. Rủi ro
- Xác nhận thủ công phụ thuộc người vận hành: nhập sai số tiền thì hóa đơn ở trạng thái thiếu. Cách giảm thiểu: audit log ghi người xác nhận, và mã giao dịch là duy nhất.
- Pháp lý hóa đơn VAT khi thu tiền thật cần tư vấn kế toán.

## 9. Credentials cần
- `BILLING_BANK_NAME`, `BILLING_BANK_ACCOUNT_NUMBER`, `BILLING_BANK_ACCOUNT_NAME`, `BILLING_BANK_BRANCH` (tài khoản nhận tiền, không phải bí mật).
- Khi muốn thu tự động: hợp đồng và khóa của cổng thanh toán.

## 10. Tiếp theo
Phase 7: khung Lazada & Tiki.
