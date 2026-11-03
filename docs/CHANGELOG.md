# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [Unreleased]

### Phase 19 — Tag đơn, ngày hẹn giao, đổi hàng (2026-11-03)
- Tag và ngày hẹn giao trên mọi đơn (cả đơn sàn), lọc theo tag / ngày hẹn giao, có trong file Excel.
- Đổi hàng: tạo đơn đổi từ phiếu trả, giá trị hàng trả được bù trừ vào đơn mới (hai bút toán "Bù trừ đổi hàng", không tính là tiền mặt, không vào sổ quỹ và báo cáo thanh toán).
- Danh sách đơn: lưu bộ lọc, chọn cột hiển thị.

### Phase 18 — Thu chi: phiếu thu, phiếu chi, sổ quỹ (2026-11-03)
- Menu Thu chi (`/cash`): phiếu thu/chi lập tay (PT-/PC-), đối tượng khách hàng, NCC, nhân viên, đơn vị vận chuyển, khác; hủy có lý do; in phiếu A5 có số tiền bằng chữ.
- Danh sách chung phiếu lập tay và phiếu tự động (thu đơn, hoàn tiền, trả NCC), lọc theo ngày, loại, đối tượng, hình thức, trạng thái; xuất CSV.
- Phiếu thu nợ khách chia vào các đơn còn nợ (đơn cũ trước); phiếu chi trả nợ NCC ghi vào công nợ NCC.
- Loại phiếu do doanh nghiệp quản lý, có cờ tính vào kết quả kinh doanh; trang Tài chính có lợi nhuận sau thu chi khác.
- Sổ quỹ: gồm phiếu lập tay, tab Thu/Chi, tìm kiếm, giải thích thuật ngữ. Quyền mới `finance.vouchers`.

### Trung tâm báo cáo (2026-11-03)
- `/reports` thành trang tổng hợp: 6 nhóm (Bán hàng, Trả hàng, Khách hàng, Tài chính, Nhập hàng, Kho hàng), mỗi nhóm có số chính của kỳ và danh sách báo cáo chi tiết. Nhóm chỉ hiện với người có quyền tương ứng.
- Báo cáo mới: bán hàng theo sản phẩm / khách hàng / kho; trả hàng theo đơn và theo sản phẩm; thanh toán của khách theo phương thức / nhân viên / ngày; nhập hàng theo nhà cung cấp / sản phẩm / ngày; khách mua hàng (khách mới, quay lại); tồn kho tại một ngày bất kỳ theo từng kho; hàng dưới định mức (kèm số bán 30 ngày, số ngày còn đủ bán); hàng vượt tồn tối đa (số dư, giá trị dư); kiểm kê và điều chỉnh kho theo phiếu / sản phẩm / lý do; thanh toán nhập hàng theo phương thức / nhân viên / nhà cung cấp / ngày; công nợ khách hàng; công nợ nhà cung cấp.
- Báo cáo lãi lỗ (`/reports/profit-loss`): doanh thu thuần, giá vốn, lợi nhuận gộp, hao hụt kho, lợi nhuận; đặt cạnh kỳ trước cùng số ngày và % thay đổi; bấm dòng để xem chi tiết.
- Kỳ báo cáo, tab và bộ lọc nằm trên URL: mở báo cáo từ trang tổng hợp giữ nguyên kỳ đang xem, có thể lưu hoặc gửi link. Mọi báo cáo xuất CSV mở được bằng Excel.
- Báo cáo bán hàng & lợi nhuận chuyển sang `/reports/sales`.
- API: `GET /reports/{overview,product-sales,returns,payments,purchases,customers,stock-on-hand,low-stock,over-stock,adjustments,supplier-payments,profit-loss,receivables,payables}`.

### Khách hàng — nhóm, hồ sơ, bộ lọc, công nợ, nhập/xuất (2026-11-03)
- Nhóm khách hàng thành danh mục riêng (`/customers/groups`): mã, tên, mô tả, giá áp dụng (lẻ/buôn), chiết khấu %, cách thanh toán quen dùng, số khách mỗi nhóm. Nhóm chữ cũ được chuyển tự động.
- Hồ sơ khách: mã khách tự nhập (để trống thì tự đánh `KH-…`), ngày sinh, giới tính, mã số thuế, nhân viên phụ trách, tags, trạng thái đang/ngừng giao dịch.
- Danh sách: tab Tất cả / Đang giao dịch / Ngừng giao dịch / Đang nợ; lọc theo nhóm, giới tính, tháng sinh (nút "Tháng này"), nhân viên phụ trách, tag, khoảng chi tiêu, số đơn, ngày mua cuối, ngày thêm; bộ lọc nằm trên URL; chọn cột hiển thị (nhớ trên máy).
- Thao tác hàng loạt: chuyển nhóm, gắn/gỡ tag, giao nhân viên, đổi trạng thái, xóa (giữ lại khách đã có đơn), xuất các khách đã chọn.
- Nhập/xuất Excel khách hàng: tiêu đề tiếng Việt, file mẫu, khớp theo mã khách rồi số điện thoại, kiểm tra toàn bộ trước khi ghi. File xuất nhập lại được.
- Trang chi tiết: tab Đơn hàng / Công nợ (sổ nợ có số dư chạy) / Thu-hoàn tiền / Địa chỉ giao hàng (nhiều địa chỉ) / Ghi chú theo thời gian; thẻ điều khoản của nhóm.

### Phase 17 — Màn hình đơn hàng (2026-11-03)
- Dải "Đơn cần xử lý" (số đơn, tổng tiền theo hàng đợi), bấm để lọc.
- Xem nhanh đơn ngay trong danh sách; bộ lọc theo ngày, thanh toán, trạng thái, khách, sản phẩm.
- Hàng loạt: thanh toán nhanh, hủy đơn kèm lý do, in hóa đơn nhiều đơn. Xuất Excel theo bộ lọc.
- Chi tiết đơn: thanh tiến trình, sao chép đơn, tóm tắt khách hàng. Tạo đơn: phím tắt F1/F3/F4/F6/F7.
- Trả hàng: tab "Chưa nhận hàng", cột lý do.

### Phase 16 — Vị trí lưu kho, tồn tối đa, in tem từ phiếu nhập, lưu bộ lọc (2026-11-03)
- Vị trí lưu kho và tồn tối đa theo kho × SKU; gợi ý nhập hàng không vượt tồn tối đa.
- In tem mã vạch thẳng từ phiếu nhập kho và đơn nhập hàng.
- Danh sách sản phẩm: lưu bộ lọc, chọn cột hiển thị.

### Phase 15 — Đơn vị quy đổi (2026-11-03)
- SKU quy đổi (thùng, hộp, lốc) = N × SKU đơn vị gốc trong cùng sản phẩm; tồn giữ ở đơn vị gốc; bán, giá vốn, hàng hoàn, đồng bộ sàn tính theo đơn vị gốc.

### Phase 14 — Combo sản phẩm (2026-11-03)
- Loại sản phẩm Combo: một SKU gồm nhiều SKU thành phần, không có tồn riêng; tồn = số combo ghép được, theo kho.
- Đơn hàng, POS, hàng hoàn và đồng bộ tồn lên sàn xử lý combo trên SKU thành phần; giá vốn combo = tổng giá vốn thành phần.
- Chứng từ kho không nhận SKU combo.
- Sửa: danh sách sản phẩm kéo người dùng quay lại khi đang tải; số biến thể; nút kiểm hàng hoàn bị gửi thiếu kho.

### Phase 13 — Hoàn thiện module Sản phẩm (2026-11-03)
- Trang chi tiết sản phẩm: tồn theo từng kho (gồm hàng đang về), lịch sử kho, giá bán lẻ/buôn/giá vốn; nút Sao chép, In tem, Sửa.
- Trường mới: đơn vị tính, tags, thuế GTGT, giá bán buôn; có trong import/export.
- Tồn kho ban đầu ngay khi tạo sản phẩm (phiếu nhập tồn đầu kỳ).
- In tem mã vạch EAN-13 / Code 128 ra PDF: tem cuộn 50×30, 40×30, 35×22 mm và A4 65 tem.
- Phiếu điều chỉnh giá vốn (CAD) trong Kho hàng, lưu giá cũ và chênh lệch giá trị tồn.
- Trang Danh mục và thương hiệu; danh sách sản phẩm có lọc theo thương hiệu, tag, ngày tạo, sắp xếp, in tem và xóa hàng loạt.

### Phase 12 — Kiểm kho, xuất hủy, cảnh báo tồn thấp, báo cáo (2026-11-03)
- Kiểm kho (`/inventory/stocktake`): tải toàn bộ SKU của kho, quét mã vạch (mỗi lần +1) hoặc nhập số đếm, lọc chưa đếm / có chênh lệch, lưu tạm trên máy, tùy chọn coi SKU chưa đếm là 0. Tạo phiếu kiểm kê (điều chỉnh theo số đếm) để duyệt như quy trình cũ.
- Xuất hủy: nút "Xuất hủy" mở phiếu điều chỉnh lý do Hàng hỏng, nhập số lượng hủy; tồn giảm khi duyệt. Báo cáo tách riêng "Hủy / mất".
- Cảnh báo tồn thấp: thẻ "Cần nhập thêm" trên Tổng quan (SKU ≤ tồn an toàn) và email hằng ngày 07:30 cho người có quyền mua hàng (`ecso:inventory:low-stock-digest`). API `GET /inventory/low-stock`.
- Báo cáo (`/reports`): Bán hàng & lợi nhuận theo ngày / kênh (sàn và kênh riêng) / nhân viên, gồm giá vốn, lợi nhuận gộp, biên; Xuất nhập tồn theo SKU (tồn đầu, nhập mua, khách hoàn, chuyển, điều chỉnh, xuất bán, trả NCC, hủy, tồn cuối, giá trị); Sổ quỹ (thu khách, hoàn tiền, chi NCC, tồn quỹ, lọc theo hình thức). Xuất CSV. Báo cáo bán chạy/bán chậm chuyển sang `/reports/products`.
- Trang Tài chính: phần "theo gian hàng" có thêm kênh bán riêng.

### Phase 11 — Mua hàng / nhập hàng (2026-11-03)
- Nhà cung cấp (`/purchasing/suppliers`, phím tắt `g n`): danh bạ, ngừng giao dịch thay vì xóa khi đã có giao dịch, tổng nhập, đã trả, còn nợ (hoặc trả trước).
- Đơn nhập hàng: nháp → đã đặt → nhận một phần → đã nhận đủ, hoặc đóng sớm / hủy. Sửa được đến khi hàng về.
- Nhận hàng nhiều đợt: mỗi đợt tạo phiếu nhập kho (RCV) gắn với đơn, cập nhật tồn và giá vốn bình quân theo giá thực nhận; không nhận vượt số còn chờ. Nhân viên kho nhận hàng được nhưng không đổi giá.
- Trả hàng nhà cung cấp (TNC): xuất kho ngay (SUPPLIER_RETURN), giảm công nợ theo giá nhập của đơn, không trả vượt số đã nhận.
- Chi tiền nhà cung cấp theo đơn (không vượt số còn nợ) hoặc thanh toán chung; hủy phiếu chi nhập nhầm.
- Gợi ý nhập hàng: bán trung bình/ngày 30 ngày qua × số ngày cần đủ hàng + tồn an toàn − khả dụng − đang về; chọn dòng → tạo đơn nhập.
- Quyền mới `purchasing.payment` (Quản lý vận hành, Kế toán). `purchasing.view` / `purchasing.manage` đã có từ trước.

### Phase 10 — Bán tại quầy (POS) (2026-11-03)
- Màn hình `/pos` (phím tắt `g b`): lưới sản phẩm có tồn khả dụng theo kho, quét mã vạch (Enter thêm ngay, quét lại tăng số lượng), sửa giá và số lượng, giảm giá đơn.
- Nhiều đơn chờ song song (giữ trên trình duyệt khi tải lại trang), chọn khách để ghi nợ, khách lẻ bắt buộc trả đủ (kiểm ở cả máy chủ).
- Thanh toán tiền mặt / chuyển khoản / thẻ / ví, gợi ý mệnh giá, tính tiền thừa, phím F2 (tìm) và F9 (thanh toán), tự in hóa đơn K80.
- Tổng kết ca: số đơn, doanh số, tiền thu theo hình thức (đã trừ hoàn tiền), tiền mặt trong két. API `GET /pos/summary`.

### Phase 9 — Bán hàng kênh riêng và khách hàng (2026-11-03)
- Kênh bán riêng (quầy, cửa hàng, Facebook, Zalo, website, điện thoại): trang Thiết lập → Kênh bán riêng, mỗi kênh một kho xuất mặc định. Mỗi doanh nghiệp có sẵn kênh "Bán tại cửa hàng".
- Tạo đơn bán tay (`/orders/new`): quét mã vạch/tìm SKU, giá và giảm giá từng dòng, phí giao, giảm giá đơn, chọn khách cũ hoặc thêm khách mới (nhận ra số điện thoại đã có), chọn trạng thái khi lưu (chờ xác nhận / đã xác nhận / hoàn tất ngay), thu tiền ngay.
- Xử lý đơn kênh riêng ngay trên ECSO: xác nhận, đóng gói, bàn giao (đơn vị vận chuyển, mã vận đơn), đã giao, hoàn tất, giao thất bại, hủy. Sửa đơn khi chưa đóng gói. Không xuất kho khi thiếu hàng.
- Thu tiền nhiều lần, hoàn tiền, hủy phiếu nhập nhầm; trạng thái thanh toán trên danh sách đơn. In hóa đơn bán hàng khổ K80 hoặc A5.
- Khách hàng: danh bạ có tìm theo tên, mã, số điện thoại (đủ số hoặc 4 số cuối), nhóm khách, tổng mua, công nợ, lịch sử đơn và thu tiền. Số điện thoại và địa chỉ được mã hóa.
- Danh sách đơn: lọc theo nguồn (kênh riêng / sàn), theo kênh; nút "Tạo đơn".
- Quyền mới: `orders.create`, `orders.payment`, `customers.view`, `customers.manage` (đã gán cho các vai trò hệ thống).

### Trình cài đặt trên web (2026-10-31)
- `/install`: trình cài đặt 7 bước kiểu WordPress. Gồm mã cài đặt, kiểm tra máy chủ, database, Redis (có thể bỏ qua), thông tin website và tài khoản nhận tiền, quản trị viên, hoàn tất.
- Upload mới (chưa có `.env`) thì mọi trang tự chuyển đến `/install`; API trả `503 NOT_INSTALLED`.
- Chống chiếm quyền cài đặt: mỗi lượt gọi phải kèm mã một lần lưu trong `storage/app/install-token.txt`, chỉ người có quyền truy cập file trên server đọc được.
- Wizard tự khóa sau khi cài (`storage/app/installed.lock`); `ecso:install` cũng ghi file khóa này. Không mở lại được từ trình duyệt khi app đã có `APP_KEY`.
- `.env` được tạo ở chế độ production, quyền 600: tắt debug, mã hóa session, cookie secure khi dùng https, tắt sàn giả lập, `HEALTH_TOKEN` ngẫu nhiên, bật 2FA admin.
- Workflow `release.yml`: đẩy tag `v*` sẽ build gói zip/tar.gz có sẵn `vendor/` và giao diện build sẵn, đính kèm vào GitHub Release.
- Hướng dẫn mới INSTALL-AAPANEL.md (từng bước trên aaPanel).
- Sửa lỗi: Horizon trước đây chỉ xử lý queue `default`, bỏ sót queue `webhooks` và `sync` (xử lý webhook, đồng bộ sàn). Nay xử lý cả ba queue.

### Phase 8 — Production Hardening (2026-10-31)
- 2FA TOTP bắt buộc cho super admin: cài đặt bằng mã QR, 8 mã khôi phục, mỗi mã chỉ dùng được một lần, phải nhập mã ở mỗi phiên đăng nhập; API admin trả `TWO_FACTOR_SETUP_REQUIRED` / `TWO_FACTOR_REQUIRED`.
- Header bảo mật trên mọi response; trên production thêm CSP và HSTS.
- Che token, secret, mật khẩu, số điện thoại, địa chỉ, tên người mua và email trong mọi kênh log.
- Giám sát: trang Admin → Giám sát hệ thống, `ecso:monitor` cảnh báo mức CRITICAL (gửi Slack qua log), `/health/ready` đọc kích thước queue thật (Redis) thay vì bảng `jobs`.
- `scripts/backup-db.sh` (mã hóa, checksum, giữ theo số ngày), `scripts/restore-db.sh` (restore vào DB diễn tập rồi kiểm tra sổ kho), `scripts/deploy.sh` (zero-downtime, tự rollback), cấu hình mẫu Supervisor và Nginx.
- Kịch bản load test k6, RELEASE-CHECKLIST, LOAD-TEST, cập nhật DEPLOYMENT-AAPANEL và SECURITY (kết quả audit).
- CI thêm `composer audit` và `npm audit --omit=dev`. `ecso:check-env --production` kiểm thêm 2FA admin, cookie secure, mã hóa session.
- Các mục admin còn lại (feature flags, hỗ trợ khách hàng, audit logs, thông báo, cấu hình) chuyển sang "Sau MVP".

### Phase 7 — Lazada & Tiki (khung) (2026-10-31)
- `LazadaConnector`, `TikiConnector` (UNKNOWN toàn bộ), cấu hình `LAZADA_APP_KEY/SECRET`, `TIKI_CLIENT_ID/SECRET`; dùng chung luồng ủy quyền shop.
- Hướng dẫn cập nhật tồn thủ công khi sàn chưa/không cập nhật tồn được qua API (thẻ sàn và trang Liên kết SKU); trạng thái đẩy tồn `unsupported` đổi nhãn thành "Cập nhật tay trên sàn".
- **Không** có tích hợp Lazada/Tiki thật: tài liệu bị chặn từ môi trường làm việc, chưa có credentials.

### Phase 6 — SaaS Commercial (2026-10-31)
- Thanh toán **chuyển khoản ngân hàng, admin xác nhận** (quyết định của chủ dự án). Thiết kế cắm thêm cổng (`PaymentGateway`, `GatewayRegistry`, IPN `POST /billing/notifications/{gateway}`); VNPay/PayOS/MoMo chưa làm (UNKNOWN, chưa có hợp đồng/credentials).
- Hóa đơn (`invoices`) với mã nội dung chuyển khoản riêng; thanh toán (`payments`) idempotent theo (provider, mã giao dịch): cùng một giao dịch ghi nhận lần hai không cộng thêm kỳ. Chuyển thiếu được cộng dồn; tiền vào hóa đơn đã đóng ghi `unapplied` để đối soát/hoàn.
- Nâng gói: tạo hóa đơn, trừ giá trị thời gian chưa dùng của kỳ hiện tại; gói đổi khi nhận đủ tiền. Hạ gói: chặn nếu đang dùng vượt giới hạn gói mới, hẹn đổi khi hết kỳ đã trả. Dùng thử 1 lần/tenant.
- `ecso:billing:sweep` (mỗi giờ): hết dùng thử → Free, phát hành hóa đơn gia hạn trước 7 ngày, áp dụng hạ gói khi hết kỳ, quá hạn → `past_due` → Free sau 3 ngày ân hạn. Không xóa dữ liệu, chỉ đổi entitlements.
- Đếm đơn/tháng (`usage_counters`, sự kiện `OrderImported`): giới hạn mềm, không chặn nhập đơn (chặn sẽ làm sai tồn kho), cảnh báo trên trang Gói dịch vụ.
- Tài chính: doanh thu đặt hàng, doanh thu hoàn thành, giá vốn (từ sổ kho), hoàn tiền, lợi nhuận gộp ước tính. Phí sàn và tiền sàn chuyển về = **Chưa xác định** (cần API đối soát).
- Báo cáo: bán chạy; hàng bán chậm theo entitlement `reports.advanced` (không theo tên gói).
- Admin: trang Hóa đơn & doanh thu (MRR/ARR, đã thu 30 ngày, hóa đơn chờ, xác nhận chuyển khoản, hủy hóa đơn); MRR trên Tổng quan.
- Mục Mua hàng chuyển sang "Sau MVP".

### Phase 5 — TikTok Shop (khung) (2026-10-31)
- `TikTokConnector` (UNKNOWN toàn bộ), cấu hình `TIKTOK_SHOP_APP_KEY` / `TIKTOK_SHOP_APP_SECRET`; dùng luồng ủy quyền chung của Phase 4.
- **Không** có tích hợp TikTok Shop thật: không truy cập được tài liệu Partner Center và chưa có credentials.

### Phase 4 — Shopee (khung) (2026-10-31)
- `ShopeeConnector` (UNKNOWN toàn bộ), cấu hình `SHOPEE_PARTNER_ID` / `SHOPEE_PARTNER_KEY`, `ecso:check-env` báo sàn chưa có credentials.
- Luồng ủy quyền shop dùng chung cho mọi sàn: state một lần gắn tenant + người dùng, chống chiếm shop của tenant khác, token mã hóa, giới hạn shop theo gói; lỗi `CREDENTIALS_MISSING` / `UNSUPPORTED_CAPABILITY` rõ ràng.
- Capability mới `shop.authorize`, `auth.refresh`.
- Giao diện: thẻ kết nối từng sàn trên trang Kết nối sàn, trang callback.
- **Không** có tích hợp Shopee thật: không truy cập được tài liệu chính thức và chưa có credentials.

### Phase 3 — Order Engine (2026-10-31)
- Module Marketplace: tài khoản shop (credential mã hóa), listing + listing variant, bảng ánh xạ trạng thái, inbox webhook, outbox đẩy tồn (debounce, backoff, circuit breaker), sync cursor. Connector interface + `UnverifiedConnector` (mọi thứ UNKNOWN) + sàn giả lập FAKE đầy đủ (webhook ký HMAC).
- Module Orders: đơn, dòng đơn, lịch sử trạng thái, ghi chú, phân công, trả hàng/kiểm hàng/nhập lại. `OrderIngestor` chống trùng, chống sai thứ tự, chống chuyển lùi. Chính sách giữ/xuất/nhả tồn theo trạng thái; bù thiếu hàng theo đơn cũ trước.
- Mapping SKU: chỉ gợi ý khi SKU trùng chính xác, người dùng xác nhận; map xong tự giữ tồn cho đơn đang mở.
- API: `/orders` (tabs + đếm, lọc, bulk, in phiếu, đồng bộ, ghi chú, phân công, tóm tắt), `/returns`, `/marketplaces`, `/marketplace-accounts`, `/listings`, `/fake-marketplace`, `POST /webhooks/{marketplace}`.
- Giao diện: Đơn hàng (tabs, lọc, cờ quá hạn/thiếu hàng/chưa map, bulk, in phiếu đóng gói PDF), chi tiết đơn, Trả hàng (kiểm hàng), Kết nối sàn (ma trận capability, bảng điều khiển sàn giả lập, theo dõi webhook), Liên kết SKU, KPI đơn trên Tổng quan.
- Sửa trong lúc test: 3 deadlock thật (gap lock khi khóa dòng chưa tồn tại, thứ tự khóa dòng đơn, outbox `updateOrCreate`) và lỗi đọc snapshot sau insert trùng, đều do test 1.000 đơn đồng thời phát hiện.

### Phase 2 — Catalog & Inventory (2026-10-31)
- Catalog: sản phẩm, biến thể (SKU duy nhất theo tenant, không phân biệt hoa thường, kể cả SKU đã xóa), mã vạch, danh mục, thương hiệu, ảnh (đường dẫn ngẫu nhiên theo tenant), thao tác trạng thái hàng loạt, kiểm tra SKU trực tiếp.
- Import CSV/XLSX (OpenSpout): kiểm tra toàn bộ trước, có lỗi thì không ghi gì; gộp biến thể theo `product_key`, không ghép theo tên; tồn đầu kỳ chỉ cho SKU mới. Export CSV/XLSX. 10.000 SKU ≈ 9 giây.
- Inventory: nhiều kho (giới hạn theo gói), sổ kho bất biến, giữ tồn có shortage, nhả/xuất theo reservation, phiếu nhập (giá vốn bình quân gia quyền), phiếu điều chỉnh có duyệt (đếm thực tế hoặc chênh lệch), phiếu chuyển kho (nháp → đang chuyển → đã nhận, hủy khi đang chuyển trả hàng về kho nguồn), tồn an toàn, thẻ kho.
- `InventoryService` là điểm ghi duy nhất (test arch); khóa dòng theo thứ tự cố định; idempotency key; sự kiện `AvailableStockChanged` sau commit.
- `ecso:inventory:verify` (chạy hằng ngày), `AllocationCalculator`.
- Quyền mới `inventory.receive`. Tenant mới tự có kho mặc định `MAIN`.
- Giao diện: Sản phẩm (danh sách, form + tạo nhanh biến thể, import), Kho hàng (tồn kho, thẻ kho, phiếu nhập/điều chỉnh/chuyển kho, danh sách kho), KPI sắp hết hàng trên Tổng quan. Ô chọn SKU hỗ trợ máy quét mã vạch.
- Sửa: 2 deadlock thật do test đa tiến trình phát hiện (INSERT IGNORE trên dòng có sẵn; khóa ngoại khi insert movement).
- CI: thêm PHPStan (Larastan level 5) và bước test concurrency.

### Phase 1 — SaaS Foundation (2026-10-31)
- Khởi tạo Laravel 12.70 (PHP 8.3), Sanctum (SPA cookie), Horizon, Pest; Vue 3 + TypeScript + Tailwind 4 + Pinia + Vue Router, Lucide.
- Identity: đăng ký, đăng nhập (giới hạn 5 lần sai/phút), đăng xuất, xác minh email (link ký số), quên/đặt lại mật khẩu, khóa tài khoản.
- Tenancy: tạo/chọn/chuyển doanh nghiệp, `TenantContext`, `BelongsToTenant` + `TenantScope` fail-closed, middleware `X-Tenant`.
- Access: 25 permission, 7 vai trò hệ thống, policies, chống leo quyền (không gán OWNER, không tự đổi quyền mình, chỉ gán vai trò có quyền ⊆ quyền của mình).
- Mời nhân viên qua email (token băm SHA-256, hạn 7 ngày, dùng một lần), thu hồi, đổi vai trò, khóa, xóa thành viên.
- Billing nền: plans/features/plan_features/subscriptions/overrides, `Entitlements`, giới hạn số user theo gói (tính cả lời mời đang chờ). 4 gói Free/Starter/Pro/Business (giá tạm).
- Audit log append-only, che dữ liệu nhạy cảm.
- Admin: dashboard số liệu nền tảng, danh sách tenant (không có dữ liệu nghiệp vụ).
- Lệnh `ecso:check-env`, `ecso:install`; `/health`, `/health/ready`; heartbeat scheduler.
- Giao diện Tenant Dashboard (12 mục menu, các module chưa làm hiển thị trạng thái theo phase) và Admin skeleton.
- Test: 72 Pest (MySQL 8), 8 Vitest, 7 Playwright E2E. CI GitHub Actions.

### Phase 0 — Discovery & Architecture (2026-10-31)
- Thêm bộ tài liệu kiến trúc: ARCHITECTURE, DATABASE, ERD, API-CAPABILITY-MATRIX, INVENTORY-ENGINE, ORDER-LIFECYCLE, MULTI-TENANCY, SECURITY, DEPLOYMENT-AAPANEL, TESTING, ROADMAP, HANDOVER.
- Chưa có code. Chưa có migration.
