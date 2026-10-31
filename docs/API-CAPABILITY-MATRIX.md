# API Capability Matrix

> Ngày xác minh: **2026-10-31**. Người thực hiện: Claude (Phase 0).
> Môi trường xác minh: container cloud, **không có credentials**, **không có tài khoản Partner/Seller Center**, **không gọi API thật**.

## 0. Cách đọc tài liệu này

| Trạng thái | Ý nghĩa |
|---|---|
| `VERIFIED` | Đã gọi thử thành công bằng credentials thật (sandbox hoặc test shop). |
| `DOCUMENTED_NOT_TESTED` | Đã đọc được trong **tài liệu chính thức** ngày xác minh, chưa gọi thử. |
| `APPROVAL_REQUIRED` | Tài liệu chính thức nói cần phê duyệt app/scope trước khi dùng. |
| `UNAVAILABLE` | Tài liệu chính thức xác nhận không hỗ trợ. |
| `UNKNOWN` | Không đọc được tài liệu chính thức, hoặc tài liệu không nói rõ. **Không suy đoán.** |

**Nguyên tắc:** cột "Bằng chứng thứ cấp / kỳ vọng" ghi lại những gì đã thấy ở nguồn không chính thức (SDK cộng đồng, tài liệu đối tác tích hợp) hoặc hiểu biết trước đó. Chúng chỉ là **giả thuyết để lập kế hoạch**, không phải bằng chứng. Trạng thái vẫn là `UNKNOWN` cho tới khi đọc được tài liệu chính thức trong Partner Center bằng tài khoản thật.

## 1. Kết quả truy cập tài liệu chính thức (2026-10-31)

| Sàn | Cổng tài liệu chính thức | Kết quả truy cập |
|---|---|---|
| Shopee | open.shopee.com (Shopee Open Platform v2) | **Bị chặn** từ môi trường xác minh (SITE_BLOCKED / proxy 403). |
| TikTok Shop | partner.tiktokshop.com (Partner Center, docv2) | Trang tải được nhưng là SPA, nội dung API **không render** khi không đăng nhập; chỉ thấy khung điều hướng. |
| Lazada | open.lazada.com (Lazada Open Platform) | Trang trả về khung rỗng / lỗi khi fetch; nội dung **không đọc được**. |
| Tiki | open.tiki.vn (Tiki Open Platform) | **Đọc được** trang Guides (mục lục). Các trang tham chiếu endpoint (API Explorer) nằm trong console, cần đăng nhập. |

=> Hệ quả: hầu hết ô bên dưới là `UNKNOWN`. Việc nâng trạng thái là **đầu việc bắt buộc** đầu Phase 4 (Shopee), Phase 5 (TikTok), Phase 7 (Lazada, Tiki), và cần anh/chị cung cấp tài khoản developer.

## 2. Thông tin chung từng sàn

| Hạng mục | Shopee | TikTok Shop | Lazada | Tiki |
|---|---|---|---|---|
| API version | UNKNOWN (thứ cấp: Open API **v2**, path `/api/v2/...`) | UNKNOWN (thứ cấp: version theo ngày, ví dụ `202309`) | UNKNOWN (thứ cấp: LazOP REST, ký HMAC-SHA256) | UNKNOWN (docs đánh nhãn "Current", có "TIKI Theory V2") |
| Kiểu ủy quyền | UNKNOWN (thứ cấp: shop authorization qua `/api/v2/shop/auth_partner`, lấy token `/api/v2/auth/token/get`, refresh `/api/v2/auth/access_token/get`) | UNKNOWN (thứ cấp: OAuth app_key/app_secret, auth code → access/refresh token) | UNKNOWN (thứ cấp: OAuth code → `/auth/token/create`, refresh `/auth/token/refresh`) | **DOCUMENTED_NOT_TESTED**: OAuth 2.0 (authorization code, refresh token, client credentials) |
| Thời hạn token | UNKNOWN (thứ cấp: access token 4 giờ) | UNKNOWN | UNKNOWN | UNKNOWN |
| Ký request | UNKNOWN (thứ cấp: SHA256 với partner_id, timestamp, access_token, shop_id) | UNKNOWN | UNKNOWN | UNKNOWN |
| App cho SaaS nhiều seller (ISV/public app) | APPROVAL_REQUIRED (giả định thận trọng, cần xác minh) | APPROVAL_REQUIRED (giả định thận trọng, cần xác minh) | APPROVAL_REQUIRED (giả định thận trọng, cần xác minh) | UNKNOWN (docs tách "public app" và "in-house app" ở phần Event queue, gợi ý có public app) |
| Sandbox / test shop | UNKNOWN (thứ cấp: có sandbox) | UNKNOWN | UNKNOWN | UNKNOWN (không thấy nhắc trong Guides) |
| Rate limit | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN (không thấy nhắc trong Guides) |

> Lưu ý về "APPROVAL_REQUIRED": cả 4 sàn đều yêu cầu đăng ký tài khoản developer và tạo app; với app phục vụ nhiều seller, việc xét duyệt là gần như chắc chắn. Ghi APPROVAL_REQUIRED ở đây là để **lập kế hoạch thời gian**, cần xác nhận lại khi đăng ký.

## 3. Ma trận theo method của `MarketplaceConnectorInterface`

| Method nghiệp vụ | Shopee | TikTok Shop | Lazada | Tiki |
|---|---|---|---|---|
| `authorize()` | UNKNOWN | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED (OAuth2 authorization code) |
| `exchangeToken()` | UNKNOWN | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED |
| `refreshToken()` | UNKNOWN | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED |
| `getShops()` | UNKNOWN | UNKNOWN (thứ cấp: "get authorized shops") | UNKNOWN (thứ cấp: seller info) | UNKNOWN |
| `getProducts()` | UNKNOWN | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED (mục "manage product information") |
| `getProductVariants()` | UNKNOWN (thứ cấp: tier variation / model) | UNKNOWN (thứ cấp: SKU trong product) | UNKNOWN (thứ cấp: SKU trong product) | UNKNOWN |
| `getOrders()` | UNKNOWN (thứ cấp: `/api/v2/order/get_order_list`) | UNKNOWN (thứ cấp: order search) | UNKNOWN (thứ cấp: `/orders/get`) | DOCUMENTED_NOT_TESTED (mục "list orders") |
| `getOrderDetails()` | UNKNOWN (thứ cấp: `/api/v2/order/get_order_detail`) | UNKNOWN | UNKNOWN (thứ cấp: `/order/items/get`) | DOCUMENTED_NOT_TESTED |
| `getInventory()` | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN (chỉ thấy khái niệm "inventory type") |
| `updateInventory()` | UNKNOWN (thứ cấp: `/api/v2/product/update_stock`) | UNKNOWN (thứ cấp: inventory update theo SKU) | UNKNOWN (thứ cấp: `/product/price_quantity/update`) | **UNKNOWN — rủi ro cao**: Guides không có mục cập nhật tồn kho riêng |
| `getShippingInfo()` | UNKNOWN (thứ cấp: shipment/package list, `logistics/ship_order`) | UNKNOWN (thứ cấp: package) | UNKNOWN | DOCUMENTED_NOT_TESTED (mục in nhãn, cập nhật trạng thái giao hàng) |
| `verifyWebhook()` | UNKNOWN (thứ cấp: "push mechanism") | UNKNOWN (thứ cấp: header `Authorization` = HMAC-SHA256(app_key + raw body, app_secret)) | UNKNOWN (thứ cấp: push mechanism) | Không phải webhook: **Event queue** (pull). DOCUMENTED_NOT_TESTED ở mức tồn tại tính năng |
| `normalizeWebhook()` | UNKNOWN | UNKNOWN (thứ cấp: `ORDER_STATUS_CHANGE`, `PACKAGE_UPDATE`, `PRODUCT_STATUS_CHANGE`; retry 4 lần) | UNKNOWN | DOCUMENTED_NOT_TESTED (event queue + subscriptions) |
| In vận đơn | UNKNOWN | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED (mục "print order labels") |
| Xác nhận / đóng gói đơn | UNKNOWN (thứ cấp: `logistics/ship_order`) | UNKNOWN | UNKNOWN | DOCUMENTED_NOT_TESTED (confirm ODF / seller delivery / dropship) |
| Hủy đơn từ phía seller | UNKNOWN (thứ cấp: `/api/v2/order/cancel_order`) | UNKNOWN | UNKNOWN | UNKNOWN |
| Trả hàng / hoàn tiền | UNKNOWN (thứ cấp: returns API) | UNKNOWN | UNKNOWN | UNKNOWN |
| Đối soát / settlement | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN |

### 3.1 Capability trong code (Phase 3)

`App\Modules\Marketplace\Connectors\Capability` khai báo 12 thao tác; mỗi connector trả `supported | unsupported | unknown`. Hệ thống chỉ gọi thao tác `supported`; còn lại trả `UnsupportedCapability` (HTTP 422, mã `UNSUPPORTED_CAPABILITY`). Trang "Kết nối sàn" hiển thị bảng này trực tiếp từ code.

| Capability | Shopee | TikTok Shop | Lazada | Tiki | Sàn giả lập (FAKE) |
|---|---|---|---|---|---|
| orders.webhook / fetch / list | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | supported |
| orders.confirm / pack / handover / cancel | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | supported |
| shipping.label | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | unsupported |
| products.list | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | supported |
| inventory.update / inventory.get | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | supported |
| returns.list | UNKNOWN | UNKNOWN | UNKNOWN | UNKNOWN | unsupported |

FAKE chỉ để kiểm thử pipeline nội bộ, **không** chứng minh tích hợp với sàn thật.

Từ Phase 4 có thêm `shop.authorize` (kết nối shop qua trang ủy quyền của sàn) và `auth.refresh` (làm mới token): UNKNOWN với mọi sàn thật, `unsupported` với FAKE (shop giả lập kết nối trực tiếp).

### 3.2 Shopee — trạng thái Phase 4 (2026-10-31)

- Kiểm tra lại truy cập `open.shopee.com` từ môi trường build: **bị chặn** (proxy trả 403). Không có partner credentials, không có test shop.
- Đã làm: `ShopeeConnector` (kế thừa `UnverifiedConnector`) được đăng ký; mọi capability = UNKNOWN; webhook `/api/v1/webhooks/shopee` luôn bị từ chối (401, lưu lại để theo dõi, không xử lý); thao tác đơn trả `UNSUPPORTED_CAPABILITY`.
- Đã làm (dùng chung cho mọi sàn): luồng ủy quyền shop — `POST /marketplace-accounts/authorize/{marketplace}` → trang của sàn → trang callback SPA `/marketplaces/{marketplace}/callback` → `POST /marketplace-accounts/callback/{marketplace}`. State ngẫu nhiên dùng một lần (lưu cache 10 phút, băm SHA-256), gắn với tenant + người dùng phía server; một shop chỉ thuộc một tenant; token lưu mã hóa; giới hạn số shop theo gói. Thiếu credentials ⇒ `CREDENTIALS_MISSING`.
- Chưa làm (chờ tài liệu + credentials, **không đoán**): ký request, URL ủy quyền, đổi code lấy token, refresh token, lấy đơn/sản phẩm, cập nhật tồn, chữ ký push, bảng ánh xạ trạng thái Shopee → nội bộ, rate limit.
- Để nâng lên VERIFIED: khai báo `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY` (server/CI secret), cấp test shop, mở truy cập tài liệu; sau đó hiện thực từng method trong `ShopeeConnector`, viết test hợp đồng với sandbox (`RUN_SANDBOX_TESTS=1`), rồi đổi capability tương ứng sang `supported`.

### 3.3 TikTok Shop — trạng thái Phase 5 (2026-10-31)

- Kiểm tra truy cập `partner.tiktokshop.com` từ môi trường build: **bị chặn** (proxy từ chối kết nối). Không có app key/secret, không có test shop.
- Đã làm: `TikTokConnector` (UNKNOWN toàn bộ), cấu hình `TIKTOK_SHOP_APP_KEY` / `TIKTOK_SHOP_APP_SECRET`, dùng chung luồng ủy quyền shop của Phase 4. Webhook `/api/v1/webhooks/tiktok` bị từ chối kể cả khi có header `Authorization` trông hợp lệ: thông tin thứ cấp về chữ ký HMAC-SHA256 **chưa được xác minh** nên không được dùng.
- Chưa làm: ký request, version API theo ngày, token, đơn, sản phẩm, tồn, webhook, ánh xạ trạng thái, rate limit.

### 3.4 Lazada — trạng thái Phase 7 (2026-10-31)

- Kiểm tra truy cập `open.lazada.com` từ môi trường build: **bị chặn** (proxy từ chối kết nối). Không có app key/secret, không có tài khoản seller test.
- Đã làm: `LazadaConnector` (UNKNOWN toàn bộ), cấu hình `LAZADA_APP_KEY` / `LAZADA_APP_SECRET`, dùng chung luồng ủy quyền shop. Webhook `/api/v1/webhooks/lazada` bị từ chối (401) và lưu lại để theo dõi.
- Chưa làm: ký request (thông tin thứ cấp: HMAC-SHA256 — chưa xác minh), token, đơn, sản phẩm, tồn, push, ánh xạ trạng thái, rate limit, khu vực (VN dùng gateway riêng — chưa xác minh).

### 3.5 Tiki — trạng thái Phase 7 (2026-10-31)

- Kiểm tra truy cập `open.tiki.vn` từ môi trường build: **bị chặn** ở Phase 7 (Phase 0 đọc được mục lục Guides: OAuth 2.0, event queue dạng polling). Không có client id/secret.
- Đã làm: `TikiConnector` (UNKNOWN toàn bộ), cấu hình `TIKI_CLIENT_ID` / `TIKI_CLIENT_SECRET`, dùng chung luồng ủy quyền shop. Webhook `/api/v1/webhooks/tiki` bị từ chối.
- **Cập nhật tồn**: UNKNOWN — không biết API cho seller có cho cập nhật tồn hay không. Không ghi `unsupported` khi chưa đọc được tài liệu. Giao diện đã sẵn: thẻ sàn ghi "Cập nhật tồn qua API: chưa xác minh — cập nhật tay trên Seller Center"; nếu sau này xác minh là không hỗ trợ, connector trả `UnsupportedCapability`, outbox ghi `unsupported` và trang Liên kết SKU hiển thị hướng dẫn cập nhật tay theo cột "Có thể bán".
- Chưa làm: event queue (polling — cần `orders.list` + cursor sẵn có trong `sync_cursors`), token, đơn, sản phẩm, ánh xạ trạng thái.

## 4. Rủi ro tích hợp đã thấy

1. **Không có credentials và không đọc được docs chính thức** ⇒ chưa thể cam kết lịch Phase 4–7. Mọi connector sẽ chỉ có Fake adapter cho tới khi có tài khoản.
2. **Tiki có thể không có API cập nhật tồn kho cho seller** (chưa thấy trong Guides). Nếu đúng, Tiki sẽ là sàn "chỉ đọc đơn", tồn kho phải chỉnh tay hoặc theo cơ chế khác (ví dụ hàng gửi kho Tiki). Connector phải trả `UnsupportedCapability`.
3. **Tiki dùng event queue (pull) thay vì webhook (push)** ⇒ cần job polling định kỳ có cursor, không phải endpoint nhận webhook.
4. **Phê duyệt app ISV** có thể mất nhiều tuần, cần bắt đầu đăng ký **song song với Phase 1–3**.
5. **Thay đổi version** (TikTok đổi version theo ngày) ⇒ connector phải cô lập version trong một lớp client riêng.

## 5. Việc cần làm để nâng trạng thái

| Việc | Ai | Khi |
|---|---|---|
| Đăng ký Shopee Open Platform (loại app phù hợp SaaS/ISV), tạo test shop | Anh/chị | Ngay, song song Phase 1 |
| Đăng ký TikTok Shop Partner Center (Vietnam), tạo app, xin scope order/product/inventory | Anh/chị | Ngay |
| Đăng ký Lazada Open Platform (ISV), xin seller test account Vietnam | Anh/chị | Trước Phase 7 |
| Đăng ký Tiki Open Platform (public app), xác nhận có API cập nhật tồn kho | Anh/chị | Trước Phase 7 |
| Đọc docs trong Partner Center, cập nhật bảng này, ghi URL + ngày | Claude | Đầu mỗi phase tích hợp |
| Gọi thử bằng test shop, nâng lên `VERIFIED` | Claude | Trong phase tích hợp |

## 6. Nguồn đã tham khảo (2026-10-31)

Chính thức:
- Tiki Open Platform Guides: https://open.tiki.vn/docs/docs/current/guides/ (đọc được)
- Tiki Open Platform: https://open.tiki.vn (đọc được)
- TikTok Shop Partner Center: https://partner.tiktokshop.com/docv2/page/partial-edit-product-202309 (không render nội dung)
- Lazada Open Platform: https://open.lazada.com/apps/doc/api?path=%2Fproduct%2Fprice_quantity%2Fupdate (không đọc được)
- Shopee Open Platform: https://open.shopee.com/developer-guide/16 (bị chặn)

Thứ cấp (chỉ để lập kế hoạch, không phải bằng chứng):
- LS Central – Shopee APIs: https://help.lscentral.lsretail.com/Content/LS-Retail/eCommerce/Shopee/Shopee-APIs.htm
- Hookdeck – TikTok Shop Webhooks: https://hookdeck.com/webhooks/platforms/guide-to-tiktok-shop-webhooks-features-and-best-practices
- Rollout – Shopee API essentials: https://rollout.com/integration-guides/shopee/api-essentials
- EcomPHP/tiktokshop-php: https://github.com/EcomPHP/tiktokshop-php
