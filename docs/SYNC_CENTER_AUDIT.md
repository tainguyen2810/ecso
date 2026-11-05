# SYNC_CENTER_AUDIT — Kiểm toán kiến trúc đồng bộ & thiết kế Trung tâm đồng bộ

- Ngày: 2026-11-05 · Commit kiểm toán: `0cb1d49` (main) · Phạm vi: chỉ đọc, chưa sửa code/DB/branch/cấu hình.
- Ký hiệu: **DONE** = đã có và có test · **PARTIAL** = có nhưng thiếu phần · **MISSING** = chưa có · **BUG** = có lỗi xác định từ source.

> **Kết luận ngắn:** EzSell **đã có một sync engine** khá chắc (inbox webhook + job xử lý, outbox tồn kho có debounce/backoff/circuit breaker, polling đối soát đơn, sync_runs/sync_errors, khóa dòng chống trùng đơn). Trung tâm đồng bộ **không cần và không được** xây engine mới. Việc cần làm là: (1) sửa 11 lỗi/rủi ro tìm thấy, (2) bổ sung lớp **quan sát – điều phối – đối soát** đọc trên các bảng sẵn có, (3) thêm phân bổ tồn theo kênh (đã thiết kế trong INVENTORY-ENGINE §6 nhưng chưa nối).

---

## 1. Kiến trúc đồng bộ hiện tại

```
                     ┌──────────── Sàn / Website ────────────┐
                     │  Woo REST v3   (Shopee/TikTok/Lazada/  │
                     │                 Tiki: skeleton UNKNOWN)│
                     └───▲───────────────┬──────────▲─────────┘
         push tồn / giá  │               │ webhook   │ poll (listOrders/getOrder)
                         │               ▼           │
 PushInventoryJob ◄── inventory_sync_outbox   POST /api/v1/webhooks/{mp}
 PushPricesJob    ◄── listing_variants.price_push_status   │ verify HMAC → webhook_events (inbox, dedupe_key)
        ▲                                            ▼
 InventorySyncPlanner ◄─ AvailableStockChanged   ProcessWebhookEvent (queue webhooks, 6 lần, backoff+jitter)
   (listener PlanInventoryPush)                       │
                                                      ▼
 Scheduler (routes/console.php)               OrderSync::syncOne → connector.getOrder → OrderIngestor
   ecso:marketplace:push-due   (1')                   (DB tx, khóa dòng order, STALE/IGNORED_TRANSITION,
   ecso:orders:reconcile       (5', mỗi shop 5/15/30') OrderStockSync → ReservationLedger)
   ecso:woo:check-webhooks     (giờ :17)          sync_runs (mỗi lượt poll) · sync_errors (mỗi đơn lỗi)
   ecso:monitor                (5')               order_status_history (nguồn: webhook/poll/user)
```

- Module: `app/Modules/Marketplace` (connector, webhook, listing, outbox, giá, phí) + `app/Modules/Orders` (OrderSync, OrderIngestor, StatusMapper, OrderStockSync, ProcessWebhookEvent, ReconcileOrdersCommand).
- Queue: Redis + Horizon, **một supervisor** cho 3 queue `webhooks, sync, default`, `timeout 60s`, `retry_after 90s`, production tối đa 10 process.
- Tenant: dòng có `tenant_id` + global scope fail-closed; job chạy trong `TenantContext::run`, tenant luôn lấy từ `marketplace_accounts` (không tin payload).

## 2. Connector đang có

| Kênh | Trạng thái | Ghi chú |
|---|---|---|
| WooCommerce | **DONE (chỉ test với FakeWooStore)** | Kết nối bằng khóa REST (wc-auth/nhập tay), webhook HMAC, đơn (lấy/list/thao tác confirm/pack/handover/cancel qua meta `_ecso_stage`), sản phẩm, tồn (batch), giá regular_price. Chưa chạy với website thật. |
| Shopee, TikTok, Lazada, Tiki | **Skeleton** | `UnverifiedConnector`: mọi capability UNKNOWN, mọi lệnh ném `UnsupportedCapability`, webhook luôn bị từ chối. Đúng nguyên tắc "không giả định API". |
| Fake | Dev/test | Tắt mặc định (`FAKE_MARKETPLACE_ENABLED`). |
| POS / đơn thủ công | Không qua connector | `Sales` module; không đồng bộ ra ngoài. |

Interface chung `MarketplaceConnector` + `Capability` (SUPPORTED/UNSUPPORTED/UNKNOWN) là điểm tái sử dụng chính khi thêm Shopee/TikTok.

## 3. Bảng kiểm toán theo hạng mục (Giai đoạn A)

| Hạng mục | Kết luận | Bằng chứng / ghi chú |
|---|---|---|
| Connector | PARTIAL | Chỉ Woo thật; 4 sàn skeleton. |
| API client | DONE (Woo) | `WooClient`: timeout 25s, phân loại 401/403 (auth), 404, 429/5xx (temporary). |
| OAuth / token | PARTIAL | Token mã hóa (`encrypted` cast), state 1 lần gắn tenant+user. **MISSING**: job làm mới token trước hạn (`refreshCredentials` không được gọi ở đâu); cảnh báo sắp hết hạn. |
| Webhook | DONE | Verify chữ ký, inbox `webhook_events` unique `(marketplace, dedupe_key)`, trả lời nhanh, xử lý trong queue. **BUG B4** (PII), **B11** (sự kiện kẹt). |
| Polling | DONE / BUG | `ecso:orders:reconcile`, cursor + overlap 5', giữ cursor khi lỗi tạm. **BUG B1, B10**. |
| Scheduler | BUG | 10 lệnh dùng `withoutOverlapping()` không hạn → **B1**. |
| Worker | PARTIAL | 1 supervisor chung; **B2** (timeout > retry_after). |
| Queue | PARTIAL | Có queue riêng `webhooks`/`sync`; không có bảng/hiển thị hàng đợi thống nhất. |
| Retry | DONE (tồn, webhook) / PARTIAL (giá, sản phẩm) | Outbox: backoff 10/30/120/600/1800s + jitter, tối đa 8 lần, circuit breaker 5 lỗi/300s. Webhook: 6 lần. Giá: 3 lần rồi `failed`, không tự thử lại. |
| Mapping sản phẩm | DONE | `marketplace_listings` unique `(account, external_product_id)`; duyệt sản phẩm mới. |
| Mapping SKU | DONE / BUG | Gợi ý theo SKU khớp chính xác, không tự map; người xác nhận; audit log. **BUG B3** (bỏ map không hủy outbox). |
| Đồng bộ tồn | PARTIAL | Outbox gộp theo listing, giảm đẩy ngay/tăng debounce 3s, combo theo thành phần, SKU ngừng bán → 0. **MISSING**: phân bổ theo kênh (buffer, %/cố định, tối đa), nhiều kho nguồn/shop. |
| Đồng bộ giá | DONE (Woo) / PARTIAL (sàn) | Nguồn giá theo biến thể (auto/ecso/woo), duyệt giá lệch >50% hoặc 0, lịch sử `price_changes`, làm tròn 100đ; sàn dùng file giá (không ghi đè qua API). |
| Đồng bộ đơn | DONE | Xem Phase 3 bên dưới. |
| Log | PARTIAL | `sync_runs` (chỉ đơn), `sync_errors` (chỉ đơn), `webhook_events`, `order_status_history`, audit log. **MISSING**: correlation ID, log cho đẩy tồn/giá/sản phẩm theo lượt, xuất log. |
| Error handling | PARTIAL | `ConnectorException{temporary, authExpired, notFound}` + 5 nhóm lỗi; chưa có 10 mã lỗi chuẩn. |
| Tenant isolation | DONE | Scope fail-closed, `ResolveTenant` trước `SubstituteBindings`, unique `(marketplace, external_shop_id)` toàn hệ thống, test Arch. Rủi ro nhỏ: **B7**. |
| Rate limiting | PARTIAL | Inbound: webhook 600/phút/IP, route có throttle riêng. Outbound: chỉ phản ứng (429 → retry) + circuit cho tồn; **MISSING** giới hạn chủ động theo shop. |

## 4. Kiểm tra theo từng phase yêu cầu

### Phase 1 — Dashboard đồng bộ: **MISSING** (có mảnh rời)
Có: trang `/marketplaces/sync` "Lịch sử và lỗi đồng bộ" (lỗi theo nhóm, lịch sử lượt poll), danh sách webhook 50 dòng gần nhất, `SystemMonitor` cho super admin (webhook 24h, outbox, circuit mở, failed jobs). Thiếu: thẻ tổng hợp (kênh hoạt động/mất kết nối/token hết hạn, tác vụ chờ/chạy/thành công/thất bại), lọc theo shop/kênh/loại dữ liệu/thời gian, cảnh báo tồn lệch.

### Phase 2 — Hàng đợi đồng bộ: **PARTIAL**
Không có "bảng task" chung — và **không nên tạo**. Các đơn vị công việc đã tồn tại:

| Nguồn | Trạng thái hiện có | Thiếu |
|---|---|---|
| `webhook_events` | received / processed / ignored / failed / rejected, attempts | next_retry_at, error_code, started_at, correlation_id, cancelled |
| `inventory_sync_outbox` | pending / succeeded / failed / rejected / unsupported, attempts, next_attempt_at, last_error | started_at, completed_at, error_code, cancelled, priority (giảm tồn đã được ưu tiên ngầm) |
| `sync_runs` | running / ok / partial / failed | correlation_id, error_code; **B5** kẹt "running" |
| `listing_variants.price_push_status` | pending / ok / failed / export | attempts, next_retry, error_code |
| Horizon `failed_jobs` | — | đã hiển thị cho admin |

Lease/lock: outbox dùng `Cache::lock("inventory-push:{shop}",120)`; đơn dùng `SELECT … FOR UPDATE` trên dòng order + unique key → **hai worker không xử lý trùng nghiệp vụ** (có test 1.000 đơn đồng thời). Recovery job treo: **MISSING** cho sync_runs/webhook kẹt.

→ Đề xuất: lớp đọc **SyncActivityQuery** hợp nhất 4 nguồn trên thành một danh sách "tác vụ" với trạng thái chuẩn (Pending, Processing, Success, Failed, Retry scheduled, Cancelled, Dead letter), không đổi luồng ghi.

### Phase 3 — Đồng bộ đơn hàng: **DONE phần lớn**
| # | Mục | KQ | Ghi chú |
|---|---|---|---|
|1-3| Đơn mới / cập nhật / hủy | DONE | Hủy trước giao trả giữ chỗ; hủy sau giao mở phiếu hoàn. |
|4| Hoàn hàng | DONE | Hoàn chỉ cộng kho khi nhận & kiểm; Woo "refunded" trước giao → hủy (đã sửa PR #22/#23). |
|5| Thanh toán | DONE (Woo) | COD không tự đánh dấu đã thu. |
|6| Trạng thái giao | PARTIAL | Woo không có trạng thái vận chuyển thật (dùng meta stage); phần vận chuyển thuộc thread "Kiểm toán vận chuyển". |
|7| Chống tạo trùng | DONE | Unique `(marketplace_account_id, external_order_id)`; account gắn tenant+kênh+shop → tương đương tenant/shop/kênh/external ID. |
|8| Mapping trạng thái | DONE | Bảng chung + map riêng từng shop; không đoán → UNKNOWN_STATUS. |
|9| Webhook trùng | DONE | dedupe_key = topic\|id\|date_modified; test. |
|10| Webhook sai thứ tự | DONE | Luôn đọc lại đơn từ API; snapshot cũ hơn → STALE; chuyển lùi → IGNORED_TRANSITION + sync_error "conflict". |
|11| Polling bù | DONE | reconcile 5/15/30'. |
|12| Đồng bộ lại theo khoảng thời gian | PARTIAL | `syncRecent($since)` hỗ trợ nhưng không có API/UI chọn khoảng. |
|13| Lưu external order ID | DONE | + external_number. |
|14| Lịch sử đồng bộ | DONE | order_status_history (nguồn, webhook_event_id, actor). |
|15| Không ghi đè thay đổi nội bộ | DONE | Shop một chiều giữ trạng thái ECSO; sửa dòng sau khi xuất kho chỉ cảnh báo. |

### Phase 4 — Đồng bộ tồn kho: **PARTIAL**
- DONE: tồn thực tế/giữ chỗ/khả dụng (`on_hand − reserved − safety_stock`), sổ kho bất biến, kho nguồn theo shop (`account.warehouse_id`), tồn công bố = sellable, nhiều đơn đồng thời (khóa theo thứ tự SKU, test 1.000 đơn/50 SKU), retry/backoff/circuit, **không bao giờ ghi tồn sàn vào kho nội bộ** (`marketplace_stock` chỉ để hiển thị), bán vượt tồn giữ nguyên (`warehouses.allow_negative`, đơn sàn luôn nhận và gắn cờ thiếu hàng).
- MISSING: **phân bổ theo kênh** — `AllocationCalculator` có mode all/percent/fixed nhưng Planner luôn dùng `MODE_ALL`; bảng `marketplace_allocations` (INVENTORY-ENGINE §6) chưa tạo. Hệ quả: mọi shop công bố **cùng một con số** — trái nguyên tắc "không mặc định cùng tồn công bố trên mọi kênh". Thiếu: tồn dự phòng theo kênh, tồn tối đa công bố, nhiều kho nguồn/shop, chính sách "không quản lý tồn" theo listing.
- MISSING: cảnh báo tồn lệch (dữ liệu đã có: `marketplace_stock` đọc về vs `last_pushed_qty`).
- Đồng bộ một chiều (ECSO → kênh) là đúng cho nguồn chính EzSell; hai chiều tồn **không đề xuất**.

### Phase 5 — Đồng bộ sản phẩm: **PARTIAL**
DONE: external product/variant ID, SKU, barcode (Woo `global_unique_id`), tên, mô tả, ảnh đầu, danh mục, trạng thái, thuộc tính (≤5), cân nặng; duyệt sản phẩm mới không tạo trùng SKU; listing chưa map nằm ở danh sách cần xử lý. Thiếu: thương hiệu; **B8** (sản phẩm bị xóa khỏi website chỉ được đánh dấu qua webhook); đồng bộ sản phẩm không ghi `sync_runs`.

### Phase 6 — Đồng bộ giá: **DONE (Woo), PARTIAL (sàn)**
Giá nội bộ, giá theo shop (bảng phí + công thức (G+F)/(1−r)), giá biến thể, làm tròn 100đ, nguồn giá, bật/tắt `sync_prices` (mặc định tắt), cảnh báo/duyệt, lịch sử. Không ghi sale_price Woo. Sàn: chỉ xuất file giá — không ghi đè qua API. Thiếu: giá khuyến mãi theo kênh, tự thử lại giá đẩy lỗi.

### Phase 7 — Nhật ký: **PARTIAL**
Có các bảng log rời. Thiếu: correlation ID xuyên webhook → job → ingest → push tồn; thời gian xử lý cho webhook/outbox; tìm kiếm theo mã đơn/SKU; xuất log có phân quyền; dọn log cũ (`webhook_events`, `sync_runs` không bao giờ bị xóa).

### Phase 8 — Xử lý lỗi: **PARTIAL**
Backoff + jitter + giới hạn lần: DONE (tồn, webhook). Dead letter: thực chất là `failed` (outbox) / `failed` (webhook) nhưng chưa có màn hình & thử lại thủ công cho tồn/webhook (chỉ thử lại lỗi đơn). Phân loại hiện 5 nhóm (api, auth, not_found, data, conflict) — cần map ra 10 mã chuẩn. Không retry thao tác không idempotent: đúng — set tồn/giá là **ghi tuyệt đối** (idempotent); thao tác đơn chạy đồng bộ, không tự retry.

### Phase 9 — Xung đột: **PARTIAL**
Có: chuyển trạng thái ngược (conflict), sửa dòng sau xuất kho (sync_warning), giá website lệch (chờ duyệt), SKU chưa map. Thiếu: danh sách xung đột chung có xem trước + người duyệt + lịch sử giải quyết; tồn khác nhau; SKU trùng giữa nhiều listing.

### Phase 10 — Đối soát: **MISSING**
Chưa có so sánh số đơn sàn vs EzSell, đơn thiếu/trùng, trạng thái lệch, tồn/giá lệch theo shop & khoảng thời gian. (Đối soát COD/phí sàn là module khác, đã có.)

### Phase 11 — Cấu hình: **PARTIAL**
Có (trang WooStore): bật/tắt shop, theo loại (đơn/tồn/giá/sản phẩm mới/đẩy trạng thái), kho nguồn, chu kỳ 5/15/30' (whitelist, không vượt), đồng bộ thủ công (đơn, sản phẩm, đẩy tồn), mốc `orders_since` (không nhập đơn cũ), map trạng thái. Thiếu: chính sách tồn theo kênh, quy tắc xung đột, giới hạn tốc độ theo shop. Lưu ý: bật lại `sync_stock` không tự đẩy lại tồn; audit log bỏ sót thay đổi `settings`.

### Phase 12 — Phân quyền & bảo mật: **PARTIAL**
DONE: tenant isolation, chữ ký webhook, mã hóa token, log redaction (`RedactSecretsProcessor`), audit cho map/kết nối/map trạng thái, chặn SSRF khi nhập URL Woo. Thiếu: chỉ có 2 quyền `marketplace.view` / `marketplace.connect` → retry, đồng bộ thủ công, cấu hình, xử lý xung đột, xuất log đều dùng chung `connect`.

### Phase 13 — UI: **PARTIAL** (xem §13)
### Phase 14 — Test: **PARTIAL** (xem §18) — đã có test trùng webhook, sai thứ tự, 1.000 đơn đồng thời, backoff/circuit, tenant arch; thiếu worker crash, job treo, dead letter, tồn lệch, khôi phục.

## 5. Chức năng hoàn thiện (DONE)
Inbox webhook có dedupe; xử lý webhook bất đồng bộ; đọc lại đơn từ API; chống trùng đơn bằng unique + khóa dòng; STALE / IGNORED_TRANSITION; outbox tồn gộp, debounce, backoff+jitter, circuit breaker; reconcile theo chu kỳ từng shop; bật lại webhook Woo bị tắt; map trạng thái riêng shop; gợi ý map SKU không tự map; nguồn giá + duyệt giá; refund trước/sau giao; tenant fail-closed; token mã hóa.

## 6. Chưa hoàn thiện (PARTIAL)
Hàng đợi hợp nhất; log xuyên suốt; phân loại lỗi; retry thủ công ngoài lỗi đơn; đồng bộ lại theo khoảng; tồn theo kênh; cấu hình chính sách; phân quyền chi tiết; giá đẩy lỗi không tự thử lại; đồng bộ sản phẩm không có lịch sử lượt.

## 7. Còn thiếu (MISSING)
Dashboard đồng bộ; đối soát; danh sách xung đột có phê duyệt; `marketplace_allocations`; nhiều kho nguồn/shop; cảnh báo tồn lệch; làm mới token + cảnh báo hết hạn; rate limiter chủ động; correlation ID; xuất log; dọn dữ liệu log; recovery tác vụ kẹt; màn hình giám sát liên tenant cho admin theo shop.

## 8. Bug (xác định từ source, chưa chạy tái hiện)

| Mã | Mức | Lỗi | Vị trí | Hậu quả |
|---|---|---|---|---|
| **B1** | P0 | `withoutOverlapping()` không truyền thời hạn → mutex mặc định **24 giờ**. Nếu tiến trình scheduler bị kill giữa chừng (deploy `ecso-update` restart supervisor, OOM), mutex còn lại. | `routes/console.php` (reconcile, push-due, check-webhooks, monitor…) | Reconcile đơn / đẩy tồn retry / giám sát **dừng im lặng tới 24h**; heartbeat scheduler vẫn xanh nên không cảnh báo. |
| **B2** | P1 | `SyncListingsJob::$timeout = 900` nhưng Redis `retry_after = 90`, Horizon `timeout 60`. | `Jobs/SyncListingsJob.php`, `config/queue.php`, `config/horizon.php` | Website nhiều sản phẩm: job bị nhả lại sau 90s và chạy **song song lần 2**, cuối cùng `MaxAttemptsExceeded`; có thể lỗi unique khi 2 bản cùng tạo listing_variant. |
| **B3** | P1 | `ListingService::unmap()` không xóa/hủy dòng `inventory_sync_outbox` đang pending. | `Services/ListingService.php` | Map nhầm → bỏ map → **vẫn đẩy tồn của SKU sai** lên listing một lần nữa. |
| **B4** | P1 | `webhook_events.payload` lưu nguyên body Woo (tên, SĐT, địa chỉ, email người mua) dạng rõ, không ai đọc, không bao giờ xóa. | `Services/WebhookReceiver.php` | Lưu dữ liệu cá nhân không cần thiết, trái nguyên tắc 9/Phase 7; bảng phình mãi. |
| **B5** | P2 | `syncRecent` chỉ bắt `ConnectorException`; lỗi khác hoặc request HTTP "Đồng bộ ngay" bị timeout để `sync_runs` ở `running` mãi. "Đồng bộ ngay" và "Lấy sản phẩm" chạy **đồng bộ trong request HTTP**. | `Orders/Services/OrderSync.php`, `SyncLogController::syncNow`, `MarketplaceAccountController::syncProducts` | Lịch sử hiện "đang chạy" sai; request dài bị cắt. |
| **B6** | P2 | Lỗi auth khi đồng bộ đơn chỉ ghi sync_error, **không** chuyển shop sang `expired` (chỉ đẩy tồn mới làm). | `OrderSync` | Cảnh báo "mất kết nối" không nhất quán. |
| **B7** | P2 | Shop đã ngắt (revoked) ở tenant A chặn vĩnh viễn tenant B kết nối cùng website. | `WooCommerceConnectService:180`, `ShopAuthorizationService:80` | Không lộ dữ liệu, nhưng kẹt nghiệp vụ. |
| **B8** | P2 | Lấy toàn bộ sản phẩm không đánh dấu listing đã biến mất khỏi website. | `ListingService::sync` | Listing "ma" vẫn được đẩy tồn → lỗi rejected lặp. |
| **B9** | P2 | Giá đẩy lỗi tạm sau 3 lần → `failed` vĩnh viễn, không có lịch thử lại. | `Jobs/PushPricesJob.php` | Giá website lệch đến khi người dùng sửa giá lần nữa. |
| **B10** | P1 | Reconcile chạy **tuần tự trong tiến trình scheduler** cho mọi tenant (mỗi đơn đổi = 1–2 lệnh GET, timeout 25s). | `ReconcileOrdersCommand` | Khi nhiều shop, 1 website chậm làm trễ tất cả; kết hợp B1 càng nguy hiểm. |
| **B11** | P2 | Sự kiện webhook `received` mà job bị mất (Redis restart) không bao giờ được xếp lại. | `WebhookReceiver` / không có sweeper | Đơn được reconcile bù; **sự kiện sản phẩm thì không**. |

Phụ: `AccountService::update` ghi audit sau khi đã `unset($data['settings'])` → thay đổi bật/tắt đồng bộ không có trong nhật ký; bật lại `sync_stock` không tự lên kế hoạch đẩy tồn.

## 9. Rủi ro đồng bộ
- B1 + B10: mất đơn bị trễ nhiều giờ mà không ai biết. Cần cảnh báo "reconcile chưa chạy > 2× chu kỳ".
- Một supervisor chung: job sản phẩm lớn chiếm worker của webhook (balance auto giảm nhẹ nhưng chưa tách).
- Không có rate limiter chủ động: khi có Shopee/TikTok (giới hạn QPS chặt), reconcile + push tồn + webhook cùng gọi có thể bị khóa app.
- Woo mới chỉ test với store giả lập.

## 10. Rủi ro tồn kho
- Mọi shop công bố cùng một số (chưa có phân bổ/dự phòng theo kênh) → khi bán cùng lúc trên nhiều kênh, rủi ro bán vượt cao hơn mức người bán muốn.
- B3 (đẩy tồn SKU sai sau khi bỏ map).
- Tồn sàn bị chỉnh tay trên website không được phát hiện (thiếu cảnh báo lệch); hệ thống sẽ ghi đè lại ở lần đẩy kế tiếp (đúng với nguồn chính EzSell, nhưng nên báo).
- Đã an toàn: không cộng/trừ hai lần (ledger theo reservation key idempotent), không nhập tồn sàn vào kho.

## 11. Rủi ro đơn trùng
Thấp. Unique `(marketplace_account_id, external_order_id)` + insert-then-lock tránh deadlock + test đồng thời. Rủi ro còn lại: đơn thủ công nhập lại đơn sàn bằng tay (ngoài phạm vi sync) và B7 khi một shop kết nối lại sang tenant khác (đơn cũ ở tenant cũ).

## 12. Rủi ro dữ liệu chéo tenant
Thấp. Điểm cần giữ khi mở rộng: `webhook_events` **không** có tenant scope (cố ý) → mọi màn hình Sync Center đọc bảng này phải lọc `tenant_id` tường minh (như `webhooks()` đang làm); job `withoutGlobalScopes()` chỉ để tìm account rồi vào `TenantContext`; dashboard admin liên tenant chỉ cho super admin.

## 13. Phần có nguy cơ xung đột với phiên "Kết nối WooCommerce và giá theo phí sàn"

Hiện **không có PR mở** của phiên đó (chỉ #48 địa chỉ). Phiên Woo đang chờ test đơn thật; khi test thật có thể quay lại sửa đúng các file sau:

| File | Mức | Cách tránh |
|---|---|---|
| `Connectors/WooCommerce/*`, `WooCommerceConnectService.php`, `CheckWebhooksCommand` | Cao | Sync Center **không sửa**. Chỉ đọc kết quả. |
| `Orders/Services/OrderIngestor.php`, `OrderSync.php` | Cao | Chỉ thay đổi nhỏ (B5, B6, correlation ID) trong 1 PR riêng, báo phiên Woo trước. |
| `PriceSync.php`, `PushPricesJob.php`, `MarketplacePricing*` | Cao | B9 làm sau cùng, phối hợp. |
| `SyncLogController.php`, `pages/marketplaces/SyncLog.vue`, `WooStore.vue` | Trung bình | Sync Center **mở rộng** SyncLog thành Trung tâm đồng bộ (cùng route), không đụng WooStore. |
| `ConnectorException.php`, `SyncErrorLog`, `SyncError` | Trung bình | Thêm trường `code` có mặc định — tương thích ngược. |
| `MarketplaceAccount::SYNC_DEFAULTS/settings` | Trung bình | Chỉ thêm key mới. |
| `OrderActions`, `OrderStatus` | Thấp–TB | Thuộc thread vận chuyển/đơn; không đụng. |

Ranh giới: **Kết nối sàn** = tài khoản, khóa API, webhook, map trạng thái, trang cửa hàng. **Sync Center** = giám sát, hàng đợi, lỗi, xung đột, đối soát, chính sách tồn kênh.

## 14. Đề xuất cấu trúc dữ liệu (không tạo engine mới)

1. **Bổ sung cột vào bảng sẵn có** (`sync_center_columns`):
   - `webhook_events`: `correlation_id char(26)`, `error_code varchar(32)`, `started_at`, `next_retry_at`; index `(tenant_id, status, received_at)`; **bỏ lưu payload** (giữ `payload_hash`).
   - `inventory_sync_outbox`: `correlation_id`, `error_code`, `started_at`, `completed_at`; thêm trạng thái `cancelled`.
   - `sync_runs`: `correlation_id`, `error_code`; cho phép `resource` = orders | products | stock | prices.
   - `sync_errors`: `error_code` (10 mã chuẩn), `details json` (giá trị EzSell vs kênh), `resolution_note`; `resource` mở rộng order | product | listing | stock | price.
2. **`marketplace_allocations`** (theo INVENTORY-ENGINE §6): `tenant_id, marketplace_account_id, product_variant_id NULL (=mặc định shop), mode all|percent|fixed, value, buffer_qty, max_qty, warehouse_ids json, unique(account, variant)`.
3. **`sync_reconciliations`** + **`sync_reconciliation_items`**: một lượt đối soát (shop, loại, khoảng, trạng thái, counts, người yêu cầu) và từng chênh lệch (missing / duplicate / status_mismatch / stock_mismatch / price_mismatch / unmapped, external_id, order_id / listing_variant_id, giá trị 2 bên, resolved_at, resolution, resolved_by).
4. **Không tạo** bảng `sync_tasks`: hàng đợi là lớp đọc `SyncActivityQuery` (UNION 4 nguồn §4 Phase 2).
5. Mã lỗi chuẩn (enum `SyncErrorCode`): `token_expired, permission_denied, rate_limited, api_unavailable, network_timeout, invalid_data, missing_mapping, conflict, duplicate_event, internal_error`; map từ `ConnectorException` (authExpired→token_expired/permission_denied theo HTTP, 429→rate_limited, 5xx→api_unavailable, ConnectionException→network_timeout…).

## 15. Đề xuất UI (giữ design system, không thêm menu cấp 1)

Trang **"Trung tâm đồng bộ"** = nâng cấp `/marketplaces/sync`, vào từ menu **Kết nối sàn** (nút/tab con), các tab:
1. **Tổng quan** — thẻ: tổng kênh, đang hoạt động, mất kết nối, token sắp hết hạn, lần đồng bộ gần nhất/shop, tác vụ chờ/đang chạy/thành công/thất bại (24h), lỗi cần xử lý, cảnh báo tồn lệch; bộ lọc shop/kênh/loại dữ liệu/thời gian.
2. **Trạng thái kết nối** — bảng shop: trạng thái, webhook khỏe, reconcile gần nhất, circuit; nút "Mở trang cửa hàng" (không sao chép quản lý tài khoản).
3. **Hàng đợi** — danh sách tác vụ hợp nhất, trạng thái chuẩn, lần thử, lần thử kế tiếp; hủy/thử lại có quyền.
4. **Nhật ký** — tìm theo mã đơn/SKU/correlation ID, chi tiết, xuất CSV (quyền riêng, audit).
5. **Lỗi cần xử lý** — nâng cấp tab hiện có, 10 mã lỗi, gợi ý cách xử lý.
6. **Xung đột** — xem trước giá trị 2 bên, chọn cách giải quyết, người duyệt, lịch sử.
7. **Đối soát** — chọn shop + khoảng, chạy (job nền), xem chênh lệch, xử lý từng dòng/nhóm có xem trước.
8. **Cấu hình** — chính sách tồn theo kênh (phân bổ, dự phòng, tối đa, kho nguồn), quy tắc xung đột; các công tắc đồng bộ hiện có chỉ **hiển thị + liên kết** về trang cửa hàng.

Admin (super admin): mở rộng trang Giám sát hiện có với bảng theo tenant/shop. Mockup sẽ gửi trước khi code (quy tắc dự án).

## 16. API / service tái sử dụng
`ConnectorRegistry`, `MarketplaceConnector` + `Capability`, `WooClient`; `WebhookReceiver`, `ProcessWebhookEvent`; `OrderSync::syncOne/syncRecent($since)`, `OrderIngestor`, `StatusMapper`; `InventorySyncPlanner::plan(force)`, `PushInventoryJob`, `AllocationCalculator` (đã có mode percent/fixed), `StockReader::sellable`; `ListingService`, `SyncListingsJob`; `PriceSync`, `PushPricesJob`, `MarketplacePriceBoard`; `SyncErrorLog`, `SyncRun`, `SyncCursor`; `SystemMonitor`; `AuditLogger`; `PermissionRegistry`; `TenantContext::run`.

## 17. File dự kiến thay đổi
- Sửa nhỏ: `routes/console.php` (B1, lịch mới), `config/horizon.php` (tách supervisor `sync-long` timeout 900, `retry_after` > timeout) (B2), `ListingService.php` (B3, B8), `WebhookReceiver.php` (B4), `OrderSync.php` (B5, B6), `ReconcileOrdersCommand.php` → dispatch `ReconcileShopJob` (B10), `SyncLogController.php`, `MarketplaceAccountController.php` (chạy nền), `AccountService.php` (audit, re-plan), `InventorySyncPlanner.php` (đọc allocation), `ConnectorException.php` (+code), `SyncErrorLog.php`, `PermissionRegistry.php`.
- Mới: `Services/SyncActivityQuery.php`, `Services/SyncDashboard.php`, `Services/ReconciliationService.php`, `Jobs/ReconcileShopJob.php`, `Jobs/RunReconciliationJob.php`, `Console/RecoverSyncCommand.php` (`ecso:sync:recover`), `Console/PruneSyncLogsCommand.php`, `Domain/SyncErrorCode.php`, `Models/MarketplaceAllocation.php`, `Models/SyncReconciliation*.php`, `Http/Controllers/SyncCenterController.php`, `pages/marketplaces/SyncCenter*.vue` (tách component theo tab), test tương ứng.

## 18. Migration dự kiến
1. `add_sync_center_columns` (cột §14.1, index; xóa payload webhook cũ: `UPDATE webhook_events SET payload = NULL`).
2. `create_marketplace_allocations_table`.
3. `create_sync_reconciliations_tables`.
4. `add_sync_center_permissions` — `sync.view`, `sync.operate` (retry/đồng bộ thủ công/hủy), `sync.configure`, `sync.resolve` (xung đột, đối soát), `sync.logs.export`; gán mặc định owner/manager như quyền marketplace hiện có.
Tất cả chỉ thêm cột/bảng, không đổi dữ liệu nghiệp vụ, không khóa bảng lớn lâu (bảng order không đụng).

## 19. Phân loại ưu tiên
- **P0**: B1.
- **P1**: B2, B3, B4, B10; recovery tác vụ kẹt; cảnh báo reconcile trễ; phân bổ tồn theo kênh (rủi ro bán vượt); dashboard + hàng đợi hợp nhất.
- **P2**: B5–B9, B11, audit settings; đối soát; xung đột; quyền chi tiết; xuất log; dọn log; rate limiter chủ động; khung làm mới token (bật khi có Shopee/TikTok).

## 20. Kế hoạch kiểm thử
Chạy trên DB test + Fake/FakeWooStore, **không gọi website/sàn thật, không chạy trên production**.

| Kịch bản | Có sẵn | Thêm |
|---|---|---|
| Webhook trùng / sai thứ tự | ✓ | correlation ID xuyên suốt |
| API timeout / 429 / 5xx | một phần | phân loại đúng mã lỗi, backoff có jitter, giới hạn lần |
| Token hết hạn | một phần (tồn) | đơn cũng chuyển shop sang expired (B6) |
| Worker crash / job treo | — | sync_run "running" quá hạn → failed; webhook received >15' → xếp lại (B5, B11) |
| Mutex scheduler | — | mutex có hạn, lệnh chạy lại sau hạn (B1) |
| Job dài > retry_after | — | SyncListingsJob trên supervisor riêng không chạy đôi (B2) |
| Dead letter + retry thủ công có quyền | lỗi đơn | outbox/webhook/giá; người không có `sync.operate` bị 403 |
| Đơn trùng / hai worker cùng đơn | ✓ (1.000 đơn) | — |
| Hai đơn cùng SKU | ✓ | — |
| Mapping thiếu | ✓ | bỏ map hủy outbox (B3) |
| Tồn lệch | — | phát hiện marketplace_stock ≠ last_pushed_qty |
| Giá lệch | ✓ (duyệt giá) | đối soát giá |
| Phân bổ theo kênh | — | 2 shop cùng SKU công bố khác nhau; buffer; max; không cộng dồn về kho |
| Tenant isolation | ✓ arch | API Sync Center tenant A không thấy B; webhook_events lọc tenant |
| Đồng bộ ban đầu / đồng bộ lại khoảng | một phần | resync khoảng ngày không tạo trùng, không đổi tồn 2 lần |
| Mất mạng & khôi phục | một phần | circuit mở rồi đóng, outbox đẩy bù đúng giá trị mới nhất |
| PII | — | webhook không lưu payload; log không chứa token/secret |

## 21. Lộ trình triển khai (sau khi được duyệt)
- **Vòng 0 — Sửa lỗi nền (không UI, không cần mockup):** B1, B2, B3, B4 (+ dọn payload cũ), B10 (reconcile mỗi shop thành job, giữ nguyên OrderSync), `ecso:sync:recover`, cảnh báo reconcile trễ trong `ecso:monitor`. 1 PR, test hồi quy toàn bộ.
- **Vòng 1 — Nền quan sát:** mã lỗi chuẩn, correlation ID, cột mới, `SyncActivityQuery`, `SyncDashboard`, quyền `sync.*`, API. B5, B6.
- **Vòng 2 — UI (mockup trước):** Tổng quan, Trạng thái kết nối, Hàng đợi, Nhật ký, Lỗi cần xử lý; dashboard admin.
- **Vòng 3 — Tồn theo kênh (mockup trước):** `marketplace_allocations`, Planner đọc phân bổ, cảnh báo tồn lệch, tab Cấu hình.
- **Vòng 4 — Đối soát & Xung đột (mockup trước):** đối soát đơn/tồn/giá theo shop + khoảng, xem trước và xử lý có quyền, lịch sử.
- **Vòng 5 — Hoàn thiện:** B7, B8, B9, B11, xuất log, dọn log, rate limiter theo shop, khung làm mới token (UNKNOWN cho tới khi có credential sàn).

Mỗi vòng: 1 PR, CI xanh mới merge, gửi lệnh `sudo ecso-update main`. Các file ở §13 mức "Cao" sẽ được báo cho phiên Woo trước khi sửa.
