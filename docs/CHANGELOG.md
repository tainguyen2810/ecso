# Changelog

Định dạng theo [Keep a Changelog](https://keepachangelog.com/vi/1.1.0/).

## [Unreleased]

### Đồng bộ đơn WooCommerce, vòng E: giao diện và hướng dẫn (2026-11-04)
- **Trang website Woo**: nút "Đồng bộ đơn ngay"; chọn chu kỳ đối soát 5/15/30 phút; hiện mốc "Đồng bộ đơn từ"; công tắc "Trạng thái đơn hai chiều"; thẻ **Webhook trên website** (tình trạng, đã bật lại / tạo lại, nút Kiểm tra ngay); thẻ **Ánh xạ trạng thái** (trạng thái website → trạng thái ECSO, số đơn đang chưa ánh xạ, thêm mã trạng thái plugin). Ô "Lỗi đồng bộ" tính cả lỗi đơn đang mở và dẫn sang trang lỗi.
- **Trang Lịch sử và lỗi đồng bộ** (`/marketplaces/sync`): lỗi đang mở theo nhóm, thử lại / bỏ qua từng đơn, lỗi đã xử lý, lịch sử từng lượt đối soát (kết quả, số đơn, thời gian chạy).
- **Chi tiết đơn website**: số đơn website, trạng thái thanh toán, email, địa chỉ thanh toán khi khác địa chỉ giao, mã giao dịch, mã giảm giá, thuế, phí khác, tổng đã hoàn, số lượng đã hoàn từng dòng, danh sách hoàn tiền trên website (kèm phiếu trả hàng nếu có), nhãn "Đã xóa trên website".
- Danh sách đơn: hiện số đơn website và nhãn "Đã xóa trên website"; ô tìm kiếm tìm được theo số đơn website.
- Hướng dẫn: `docs/WOOCOMMERCE-ORDERS.md`.

### Đồng bộ đơn WooCommerce, vòng D: hoàn tiền (2026-11-04)
- ECSO đọc **từng lần hoàn tiền** của đơn Woo (`orders/{id}/refunds`) và lưu vào `order_refunds`: số tiền, lý do, dòng hàng và số lượng. Mỗi lần hoàn chỉ lưu một lần, webhook gửi lặp không tạo trùng. Tổng đã hoàn cập nhật vào đơn.
- Hoàn **một phần trước khi giao**: số lượng đã hoàn không còn được giữ hàng và không bị xuất kho (`order_items.refunded_quantity`).
- Hoàn **sau khi đã giao** có dòng hàng: ECSO mở một phiếu trả hàng "Yêu cầu trả" cho đúng các dòng đó. Kho chỉ cộng lại khi nhận và kiểm hàng trên phiếu, không bao giờ cộng thẳng từ lệnh hoàn tiền. Hoàn chỉ tiền (phí ship, bồi thường) thì chỉ lưu số tiền.
- Chi tiết đơn (API) có thêm `refunds` và `refunded_quantity` từng dòng.
- Migration `2026_11_12_120000_add_order_refunds`. Chỉ thử với cửa hàng Woo giả lập.

### Đồng bộ đơn WooCommerce, vòng C (2026-11-04)
- **Ánh xạ trạng thái riêng từng website** (`settings.status_map`), ưu tiên hơn ánh xạ chung. Dùng cho trạng thái do plugin thêm (ví dụ `dang-giao`). Lưu ánh xạ xong, các đơn đang "Chưa ánh xạ" với trạng thái đó được chuyển ngay. Đối soát hỏi Woo cả các trạng thái riêng này. API: `GET/PUT marketplace-accounts/{id}/status-map`.
- **Công tắc một chiều / hai chiều** (`settings.push_status`, mặc định hai chiều như trước). Một chiều: xác nhận, đóng gói, bàn giao, hủy chỉ làm trong ECSO, không ghi lên website. Khi website còn ở trạng thái trước ECSO, ECSO giữ trạng thái của mình và không báo xung đột. Khi website đi xa hơn (ví dụ hoàn tất), website thắng.
- Mỗi thao tác đơn vẫn ghi nhật ký (`order.pack`, `order.cancel`…), có đánh dấu thao tác chỉ trong ECSO.

### Đồng bộ đơn WooCommerce, vòng B (2026-11-04)
- **Đơn bị xóa trên website**: ECSO không xóa đơn. Đơn được đánh dấu "đã xóa trên website" (`orders.deleted_on_channel_at`). Đơn chưa ra khỏi kho thì chuyển Đã hủy và nhả hàng giữ. Đơn đã giao thì giữ nguyên, chỉ cảnh báo. Đơn chuyển vào thùng rác rồi được **khôi phục** thì mở lại và giữ hàng lại (nếu chưa giao). Website được đăng ký thêm webhook `order.deleted`, `order.restored`.
- **Đơn sửa trên website** (đổi số lượng, thêm/bớt dòng) khi chưa ra khỏi kho: ECSO nhả hàng giữ cũ và giữ lại theo dòng mới. Sửa sau khi đã xuất kho thì ECSO giữ số đã xuất và báo cảnh báo trên đơn.
- **Tự bật lại webhook**: lệnh `ecso:woo:check-webhooks` chạy mỗi giờ, bật lại webhook ECSO bị Woo tắt sau nhiều lần gửi lỗi và tạo lại webhook còn thiếu (cả website kết nối từ trước). Kết quả lần kiểm tra gần nhất lưu ở `settings.webhook_health`. Có API kiểm tra ngay `POST marketplace-accounts/{id}/check-webhooks`.
- **Chu kỳ đối soát theo từng website**: 5, 15 (mặc định) hoặc 30 phút (`settings.order_sync_interval`). Lệnh đối soát chạy mỗi 5 phút và bỏ qua website chưa tới lượt. Nút "Đồng bộ ngay": `POST marketplace-accounts/{id}/sync-orders`.
- **Lỗi đồng bộ** (`sync_errors`): mỗi đơn đồng bộ không được có một dòng lỗi theo nhóm (API, xác thực, dữ liệu, xung đột trạng thái), đếm số lần thử. Lần đồng bộ sau thành công thì lỗi tự đóng. API: `GET sync-errors`, `POST sync-errors/{id}/retry`, `POST sync-errors/{id}/resolve`, `GET sync-runs`.
- Migration `2026_11_12_110000_add_order_sync_errors`. Chỉ thử với cửa hàng Woo giả lập.

### Đồng bộ đơn WooCommerce, vòng A (2026-11-04)
- Chỉ lấy đơn **tạo sau lúc bật đồng bộ đơn** (`marketplace_accounts.orders_since`). Đơn cũ hơn bị bỏ qua cả khi website gửi cập nhật, không giữ hàng. Tắt rồi bật lại đồng bộ đơn thì tính lại từ lúc bật. Không nhập đơn lịch sử.
- Đơn website lưu đủ: số đơn website, SĐT và email đầy đủ, địa chỉ thanh toán/giao hàng (mã hóa), mã khách trên Woo, mã giảm giá, thuế (đơn và từng dòng), phí khác, mã giao dịch, số đã hoàn. Bản `raw` không còn chứa billing/shipping.
- Trạng thái thanh toán: có ngày thanh toán và không phải COD thì là Đã thanh toán; đơn COD hoàn tất trên Woo không tự coi là đã thu tiền.
- Đơn website gắn vào **khách hàng** theo SĐT (tạo thẻ khách nếu chưa có). SĐT không hợp lệ thì đơn vẫn vào, không gắn khách. Đơn sàn vẫn che thông tin người mua như cũ.
- Công nợ khách chỉ tính đơn bán tay; đơn website/sàn đã thanh toán qua kênh nên không thành nợ.
- **Nhật ký đồng bộ** (`sync_runs`): mỗi lượt đối soát ghi kết quả (OK / Một phần / Lỗi), số đơn tạo/cập nhật/lỗi. Một đơn website trả lỗi không chặn đơn khác và không đẩy mốc đồng bộ lên.
- Sửa: đơn Woo chuyển "Đã hoàn tiền" (refunded) trước khi giao thì thành Đã hủy và nhả hàng giữ, không trừ kho. Đơn đã giao rồi mới hoàn thì mở phiếu trả hàng; kho chỉ cộng lại khi nhận và kiểm hàng.
- Sửa: Enter trong ô tìm SKU khi quét hai mã liên tiếp nhanh hơn kết quả tìm kiếm không còn bỏ sót mã đầu.
- Migration `2026_11_12_100000_add_woo_order_details`. Chỉ thử với cửa hàng Woo giả lập trong test, chưa thử với website thật.

### Khách hàng, đợt A: công nợ thống nhất (2026-11-04)
- **Một quy tắc công nợ dùng chung** (`App\Modules\Sales\Services\CustomerDebt`): trang khách, danh sách khách, sổ công nợ, báo cáo Công nợ phải thu, màn hình thu nợ ở Sổ quỹ và tóm tắt khách trong chi tiết đơn giờ cùng một con số. Nợ một đơn = tổng đơn − giá trị trả hàng đã duyệt − (đã thu − đã hoàn). Chỉ tính đơn kênh riêng; không tính đơn sàn, đơn COD, đơn hủy, đơn hoàn toàn bộ, đơn giao thất bại. Không lưu số cộng dồn nên đơn đồng bộ/sửa lại không bao giờ bị cộng nợ hai lần.
- Trả hàng một phần (đã duyệt trở đi) giờ giảm công nợ. Sổ công nợ có thêm dòng **Trả hàng** và **Hoàn tiền**, mỗi dòng trỏ về đơn gốc.
- `CustomerDebt::returnValueSql()` / `orderReturnsSql()` cho các báo cáo khác dùng chung cách tính giá trị trả hàng (cùng quy tắc với `OrderReturn::goodsValue()`).
- "Mua gần nhất" không còn tính đơn đã hủy.
- Đổi khách trên đơn đã có phiếu thu: phiếu thu chuyển theo sang khách mới.
- Khách **Ngừng giao dịch** không được đưa vào đơn mới (đơn đang mở của họ vẫn sửa được).
- Hai đơn tạo cùng lúc với cùng số điện thoại khách mới: đơn sau dùng lại khách vừa tạo thay vì báo lỗi.
- Quyền mới `customers.import`, `customers.export`, `customers.delete` (tách khỏi `customers.manage`). Vai trò nào đang có `customers.manage` được giữ nguyên các quyền này khi cập nhật.
- Nhật ký: sửa khách ghi rõ trường nào đổi (giá trị cũ → mới; dữ liệu cá nhân chỉ ghi "đã đổi"); xuất và nhập file khách được ghi nhật ký.

### Tài chính, vòng A: sửa số liệu sai (2026-11-04)
- **Ngày theo giờ cửa hàng**: mọi báo cáo (Tài chính, Lãi lỗ, Tổng quan báo cáo, báo cáo bán hàng, trả hàng, thu chi), biểu đồ tổng quan đơn hàng, bộ lọc ngày của danh sách đơn và sổ quỹ đều tính ngày theo múi giờ cửa hàng (`tenants.timezone`, mặc định Asia/Ho_Chi_Minh), không còn theo UTC. Trước đây đơn từ 0h đến 7h sáng bị tính sang ngày hôm trước. Phần dùng chung: `App\Shared\Support\ReportRange`. Kết nối MySQL đổi mọi mốc thời gian được truyền vào truy vấn về UTC (`App\Shared\Database\LocalizedBindings`).
- **Hoàn tiền không còn bị trừ hai lần** (trang Tài chính): chỉ tính phiếu trả đã duyệt, và chỉ tính phiếu của đơn còn nằm trong doanh thu. Giá vốn của hàng trả đã nhập lại kho được cộng lại (`cogs_returned`), theo giá vốn lúc xuất. Đơn đang có yêu cầu trả hàng vẫn được tính doanh thu cho tới khi phiếu trả được duyệt.
- **Báo cáo lãi lỗ**: đơn hủy sau khi giao không còn bị trừ hàng trả. Giá trị hàng trả có chia chiết khấu theo dòng. Giá vốn hàng trả nhập kho tính theo giá vốn lúc xuất, không theo giá vốn hiện tại. Một quy tắc dùng chung: `App\Modules\Analytics\Support\ReturnValues`.
- Trang Tài chính ghi rõ cách tính lợi nhuận sau thu chi, có thêm dòng "Chưa trừ phí sàn". Thẻ Hoàn tiền ghi rõ chỉ tính phiếu trả đã duyệt.

### Phí sàn và giá sàn, đợt 3 (2026-11-04)
- **Bảng phí sàn** cho từng gian hàng (Cấu hình → Thuế và chính sách giá), gồm hoa hồng %, thanh toán %, dịch vụ %, phí cố định mỗi đơn, làm tròn lên và lãi tối thiểu trên giá vốn. Có thể đặt hoa hồng riêng theo danh mục. Khi chưa lưu thì bảng hiện số tham khảo (`config/marketplaces.php`, `pricing.reference_fees`), cần kiểm tra lại theo hợp đồng.
- Công thức giá sàn: (giá gốc + phí cố định) ÷ (1 − tổng % phí), làm tròn lên. Giá không được thấp hơn mức để còn giá vốn + lãi tối thiểu sau phí. Website WooCommerce vẫn bán theo giá gốc, không cộng phí sàn.
- **Bảng giá sàn** (`/marketplaces/prices`) so giá hiện tại với giá tính ra trên từng gian hàng, có các tab Chờ duyệt / Lỗ sau phí / Đang khóa. Người dùng áp dụng từng dòng hoặc tất cả, và khóa giá. Tùy chọn "Tự áp dụng giá mới" áp ngay khi giá gốc hoặc bảng phí đổi.
- Sàn chưa cấp API đổi giá thì **Áp dụng** ghi giá vào **file giá** (.xlsx), người dùng tải lên công cụ cập nhật hàng loạt của sàn. Lần đồng bộ sau thấy sàn hiện đúng giá mới thì ECSO tự đánh dấu xong. Hiện chưa sàn nào trong ECSO có API đổi giá được duyệt, nên mọi sàn đi đường file giá.
- **Kiểm tra phí sàn** (`/settings/pricing/fees`): nhập file đối soát (.xlsx/.csv), ECSO tính phí thực tế 30 ngày theo gian hàng và theo danh mục, bỏ đơn hủy và hoàn.
  - Lệnh `ecso:marketplace:check-fees` chạy mỗi ngày lúc 06:00. Khi tổng phí lệch từ 0,5 điểm % (cần đủ 20 đơn), lệnh báo cảnh báo lệch phí. Nó cũng báo các đơn bán lỗ sau phí trong 7 ngày. Cảnh báo mới được gửi email cho người có quyền cài đặt.
  - Tùy chọn "Tự cập nhật bảng phí" (mặc định tắt) cho ECSO tự sửa bảng phí theo số thực tế.
- File đối soát được nhận theo tên cột: file mẫu của ECSO, cộng một số tên cột dự kiến của Shopee/TikTok/Lazada. **Tên cột của sàn chưa được đối chiếu với file đối soát thật.** File không nhận ra cột thì bị từ chối kèm danh sách cột tìm thấy, ECSO không đoán.
- Migration `2026_11_11_120000_add_marketplace_pricing` thêm các bảng `channel_price_rules`, `marketplace_settlements`, `fee_alerts`. API nằm dưới `marketplace-pricing/*` (bảng phí, bảng giá, áp dụng, khóa, file giá, phí thực tế, nhập đối soát, file mẫu, xử lý cảnh báo).
- Sửa: ô nhập (`Input`) nhận giá trị rỗng `null`.

### WooCommerce đợt 2: giá hai chiều (2026-11-04)
- **Nguồn giá** từng SKU (`product_variants.price_source`): Tự động (còn hàng thì ECSO giữ giá, hết hàng thì website giữ), ECSO giữ, Website giữ. Đổi ở trang sản phẩm (`PUT /products/{id}/price-source`).
- ECSO giữ giá: sửa giá bán lẻ trong ECSO thì đẩy lên **giá thường** (`regular_price`) của Woo qua batch API (`PushPricesJob`); ai sửa giá trên website sẽ bị trả lại và ghi "Đã trả lại giá ECSO". ECSO không đụng giá khuyến mãi trên Woo.
- Website giữ giá: giá bán thực tế trên Woo (khuyến mãi nếu có, nếu không là giá thường) thành giá bán lẻ ECSO. Giá bằng 0 hoặc lệch quá 50% thì vào **Giá từ website chờ duyệt** ở trang gian hàng (áp dụng / bỏ qua / áp dụng tất cả); giá đã bỏ qua không hỏi lại cho đến khi website đổi giá khác.
- Chống vòng lặp: áp giá từ website không kích hoạt đẩy giá; giá website trùng giá ECSO thì không làm gì.
- Công tắc **Giá hai chiều** trên trang gian hàng (mặc định tắt); bật lên thì ECSO đọc lại toàn bộ sản phẩm website để áp nguồn giá.
- Trang sản phẩm: cột Nguồn giá, Giá trên website (trạng thái đẩy giá), thẻ **Lịch sử giá**.
- Migration `2026_11_11_110000_add_price_sync`: cột nguồn giá, cột theo dõi đẩy giá trên `listing_variants`, bảng `price_changes`. API: `GET marketplace-accounts/{id}/price-changes`, `POST price-changes/{id}/approve|reject`, `POST marketplace-accounts/{id}/price-changes/approve-all`, `GET products/{id}/prices`.
- Chỉ kiểm thử với website Woo giả lập (`tests/Support/FakeWooStore.php`), chưa thử trên website thật.

### Kết nối WooCommerce, đợt 1 (2026-11-04)
- WooCommerce là một kênh trong Kết nối sàn (`config/marketplaces.php`, `WooCommerceConnector`, REST API v3). Kết nối dễ nhất: nhập địa chỉ website → bấm **Phê duyệt** trên màn hình `/wc-auth/v1/authorize` của chính WooCommerce → Woo gửi khóa về `POST /api/v1/woocommerce/auth-callback` → người dùng quay về `/marketplaces/woocommerce/callback`. Dự phòng: dán Consumer key/secret, có hướng dẫn 3 bước ngay trong hộp thoại.
- Bảo vệ: chỉ HTTPS, chặn IP/máy nội bộ, khóa và secret webhook mã hóa, một website chỉ thuộc một doanh nghiệp, state dùng một lần. Host bỏ header Authorization thì tự chuyển sang khóa trên query string (Woo hỗ trợ qua HTTPS).
- Khi kết nối: đăng ký 5 webhook (đơn tạo/sửa, sản phẩm tạo/sửa/xóa) với secret riêng từng website, chữ ký HMAC-SHA256 base64; ping của Woo được trả 200. Ngắt kết nối thì gỡ webhook.
- Đơn Woo vào Danh sách đơn hàng (nguồn WooCommerce) và giữ hàng; trạng thái: pending→Chờ thanh toán, on-hold→Chờ xác nhận, processing→Chờ đóng gói, completed→Hoàn tất, cancelled/failed→Hủy, refunded→Hoàn. Đóng gói/bàn giao ghi meta `_ecso_stage` trên đơn Woo. Quét bù 15 phút/lần.
- Tồn kho: đẩy "có thể bán" lên Woo qua `products/batch` và `variations/batch`. Sản phẩm: lấy sản phẩm đơn và biến thể, gợi ý ghép theo SKU; webhook sản phẩm đọc lại đúng sản phẩm đó.
- Trang gian hàng Woo (`/marketplaces/:id/store`): bật/tắt đơn, tồn, sản phẩm mới (giá: đợt 2), kho trừ hàng, **Sản phẩm mới chờ duyệt** (duyệt tạo sản phẩm ECSO với giá khuyến mãi nếu có, hoặc bỏ qua).
- Migration `2026_11_11_100000_add_woocommerce_support`: `marketplace_accounts.base_url/settings`, `marketplace_credentials.webhook_secret`, `webhook_events.external_product_id`, `listing_variants.regular_price/sale_price`, bảng trạng thái `woocommerce`.
- `MarketplaceConnector::parseWebhook()` nhận thêm `$headers` (Woo gửi loại sự kiện và website trong header).

### Tài khoản demo (2026-11-04)
- Lệnh `php artisan ecso:demo-tenant <email> [--days=60] [--name=…] [--force]`: tạo đăng nhập mới (chủ + nhân viên kho `<tên>+kho@…`, mật khẩu ngẫu nhiên in một lần) và doanh nghiệp "… (dữ liệu mẫu)" có sản phẩm, tồn kho 2 kho, khách hàng, NCC, đơn nhập, phiếu kiểm/chuyển kho, ca bán hàng và đơn bán kênh riêng mỗi ngày trong N ngày (nhập hàng định kỳ mỗi tuần).
- Không tạo super admin, không bật sàn giả lập; trên production phải thêm `--force` (chỉ dùng cho máy chủ demo). Ngày tháng của dữ liệu lịch sử nhất quán (đơn, thanh toán, sổ kho cùng ngày).
- `DemoSeeder::populate()` tách phần đổ dữ liệu một doanh nghiệp để lệnh trên dùng lại; phần sàn giả lập chỉ chạy khi `FAKE_MARKETPLACE_ENABLED=true`.
- Biểu đồ cột 30 ngày: khe hẹp hơn, nhãn ngày cách quãng để không tràn.

### Giao diện: Tổng quan mới và quy chuẩn màu (2026-11-03)
- Sidebar xanh đậm, icon nét đơn sắc, nhóm menu ngăn bằng đường kẻ; nền trang xám nhạt.
- Tổng quan: kết quả kinh doanh hôm nay (so với hôm qua), doanh thu 7/30 ngày theo sàn (cột chồng), đơn chờ xử lý theo trạng thái, top sản phẩm bán chạy, thông tin kho, tỷ trọng doanh thu theo sàn, gian hàng kết nối, đơn gần đây, SKU cần nhập thêm.
- `GET /orders/summary` thêm `days` (7|30), `revenue_by_day`, `revenue_by_channel`, `queues`, `yesterday_ordered_revenue`, `today_cancelled`, `today_returns` (các trường cũ giữ nguyên).
- Thành phần dùng chung mới: `StatTile`, `IconBubble`, `charts/StackedBarChart`, `charts/DonutChart`, `Card caps`; token màu sidebar và màu kênh bán. Quy chuẩn: `docs/DESIGN-SYSTEM.md`.

### Mua hàng: VAT đầu vào, chi phí nhập, in phiếu đặt hàng (2026-11-03)
- Thuế GTGT theo từng dòng đơn nhập (0/5/8/10 %), VAT mặc định cho dòng mới. Công nợ NCC tính cả VAT; trả hàng NCC trả lại cả VAT.
- Chi phí nhập hàng (vận chuyển, bốc xếp) trên đơn nhập, phân bổ vào giá vốn theo giá trị hàng (theo số lượng nếu hàng 0 đồng). Chọn trả cho NCC (cộng công nợ) hoặc trả bên khác.
- Tùy chọn "Tính VAT vào giá vốn" cho doanh nghiệp không được khấu trừ thuế đầu vào; mặc định VAT không vào giá vốn.
- Đơn nhập hiện giá vốn dự kiến mỗi dòng, tổng VAT và chi phí đã nhận. In phiếu đặt hàng A4 gửi NCC.
- Đơn nhập cũ giữ 0 % VAT, không chi phí: số liệu không đổi.

### Ca bán hàng và két tiền (2026-11-03)
- Bán tại quầy yêu cầu mở ca: thu ngân đếm tiền đầu ca (gợi ý bằng số tiền ca trước để lại két). Mỗi thu ngân chỉ có một ca đang mở.
- Trong ca: nộp tiền vào két (tiền lẻ) và rút tiền ra (nộp két sắt, ngân hàng), không rút quá số tiền mặt đang có.
- Đóng ca: đếm theo mệnh giá hoặc nhập tổng, so với tiền mặt hệ thống (đầu ca + thu tiền mặt − hoàn tiền mặt + nộp − rút), bắt buộc ghi lý do khi lệch, ghi số nộp về và số để lại két. Số liệu được chốt khi đóng ca. In biên bản bàn giao ca K80.
- Trang `/pos/shifts`: lịch sử ca. Thu ngân xem ca của mình; quyền mới `orders.shifts` (Quản lý vận hành, Kế toán) xem mọi ca và đóng hộ ca bị quên.
- Nộp/rút tiền két là chuyển tiền nội bộ, không ghi vào sổ quỹ.

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
