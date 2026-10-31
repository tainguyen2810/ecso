# Báo cáo Phase 2 — Catalog & Inventory

Ngày: 2026-10-31. Nhánh: `phase-2-catalog-inventory`. Trạng thái: **xong phần code và test, chờ chủ dự án review trước Phase 3.**

## 1. Đã hoàn thành

**Sản phẩm (module Catalog)**
- Sản phẩm, biến thể (SKU), mã vạch, thuộc tính (Màu, Size…), giá tham khảo, khối lượng, danh mục (có cha/con), thương hiệu, ảnh (tối đa 9 ảnh/sản phẩm, kiểm tra nội dung file thật, tên file ngẫu nhiên trong thư mục riêng của tenant).
- SKU duy nhất trong một doanh nghiệp, không phân biệt hoa thường, tính cả SKU đã xóa. Hai doanh nghiệp khác nhau dùng trùng SKU được. Mã vạch cũng duy nhất.
- Kiểm tra SKU trùng ngay khi gõ trên form; tạo nhanh biến thể theo thuộc tính (Màu × Size).
- Không xóa được SKU còn tồn hoặc đang được giữ cho đơn.
- Import CSV/XLSX: kiểm tra toàn bộ file trước, báo lỗi theo từng dòng, **có lỗi thì không ghi dòng nào**. Các dòng cùng `product_key` là biến thể của một sản phẩm; **không bao giờ tự ghép theo tên**. SKU đã có thì cập nhật (khớp chính xác SKU). Tồn đầu kỳ (`opening_qty`) chỉ áp dụng cho SKU mới, nên import lại cùng file không cộng kho lần hai.
- Export CSV/XLSX kèm tồn, file mẫu import.

**Kho (module Inventory)**
- Nhiều kho; tenant mới tự có kho `MAIN`. Số kho bị giới hạn theo gói (Free 1, Starter 2, Pro 5, Business không giới hạn) qua entitlement, không theo tên gói.
- `InventoryService` là nơi duy nhất được ghi tồn (có test kiến trúc chặn). Mọi thao tác: transaction, khóa dòng theo thứ tự cố định, idempotency key, ghi sổ kho bất biến kèm tồn sau phát sinh, sự kiện `AvailableStockChanged` sau commit (Phase 4 dùng để đẩy tồn lên sàn).
- Giữ tồn (reservation): giữ tối đa số khả dụng, phần thiếu ghi `shortage`, không bao giờ giữ vượt tồn. Gọi lặp cùng đơn hoặc cùng key không giữ thêm. Nhả giữ và xuất kho chỉ chạy một lần.
- Phiếu nhập kho (số RCV-000001…), giá vốn bình quân gia quyền tự tính.
- Phiếu điều chỉnh (ADJ-…): nhập theo số đếm thực tế hoặc chênh lệch ±; chỉ thay đổi tồn khi được duyệt; số đếm được tính lại dưới khóa lúc duyệt; từ chối bắt buộc ghi lý do; không cho âm kho.
- Phiếu chuyển kho (TRF-…): nháp → xuất kho (trừ kho nguồn) → nhận hàng (cộng kho đích); hủy khi đang chuyển thì trả hàng về kho nguồn. Không xuất phần hàng đang giữ cho đơn khác.
- Tồn an toàn theo SKU × kho: không chặn giữ hàng cho đơn đã có, chỉ giảm số "có thể bán" (số sẽ đẩy lên sàn).
- Thẻ kho: toàn bộ phát sinh của một SKU, lọc theo kho, loại; tồn từng kho.
- `php artisan ecso:inventory:verify`: kiểm tra số dư = tổng sổ kho = tổng giữ hàng; chỉ đọc; chạy tự động 02:30 hằng ngày.
- `AllocationCalculator` (đẩy toàn bộ / theo % / số cố định) đã viết và test; bảng cấu hình theo shop làm ở Phase 4 khi có tài khoản sàn.

**Giao diện:** Sản phẩm (danh sách, mở rộng biến thể, chọn hàng loạt, form, import có xem trước lỗi), Kho hàng (thẻ chỉ số, tồn kho theo kho/trạng thái, sửa tồn an toàn tại chỗ, thẻ kho, 3 loại phiếu với form và trang chi tiết có tiến trình, danh sách kho), Tổng quan có KPI "SKU sắp hết hàng" thật. Ô chọn SKU dùng được với máy quét mã vạch (quét xong Enter là thêm dòng).

## 2. File chính đã tạo/sửa

- `app/Modules/Catalog/` (mới): Models (Category, Brand, Product, ProductVariant, ProductImage), Services (ProductService, ProductImporter, ProductExporter), Http (ProductController, TaxonomyController, ProductTransferController, ProductImageController, ProductRequest, Resources), Policies/ProductPolicy, Routes.
- `app/Modules/Inventory/` (mới): Models (Warehouse, StockBalance, StockMovement, StockReservation, InventoryAdjustment(+Line), StockTransfer(+Line), StockReceipt(+Line)), Services (InventoryService, InventoryDocumentService, WarehouseService, StockVerifier), Domain/AllocationCalculator, Support (DocumentNumber, OperationResult), Events/AvailableStockChanged, Listeners/CreateDefaultWarehouse, Console/VerifyInventoryCommand, Exceptions, Policies, Http, Routes.
- Sửa: `PermissionRegistry` (thêm `inventory.receive`), `TenantService` (phát sự kiện `TenantCreated`), `ModuleServiceProvider`, `routes/console.php`, `DemoSeeder`, `phpunit.xml`, `.github/workflows/ci.yml`, `composer.json` (openspout, larastan), `phpstan.neon`.
- Frontend: `resources/js/apps/tenant/pages/products/*`, `pages/inventory/*`, `components/{SkuPicker,Tabs,Pagination}.vue`, router, navigation, Dashboard, `shared/types.ts`, `shared/lib/{api,utils}.ts`, `shared/ui/Dialog.vue` (thêm kích thước).
- Test: `tests/Feature/Inventory/*`, `tests/Feature/Catalog/ProductApiTest.php`, `tests/Concurrency/*`, `tests/Unit/AllocationCalculatorTest.php`, `tests/Arch/InventoryArchTest.php`, `e2e/inventory.spec.ts`, Vitest `SkuPicker.spec.ts`, `labels.spec.ts`.

## 3. Migrations

- `2026_10_31_200000_create_catalog_tables`: categories, brands, products, product_variants, product_images.
- `2026_10_31_200100_create_inventory_tables`: warehouses, stock_balances, stock_movements, stock_reservations, inventory_adjustments(+lines), stock_transfers(+lines), stock_receipts(+lines), document_sequences.

Chỉ thêm bảng mới, không sửa bảng Phase 1. Kho mặc định chỉ tự tạo cho tenant mới; nếu đã có tenant thật trước khi deploy Phase 2 thì cần tạo kho một lần (hiện chưa có tenant production).

## 4. API mới (`/api/v1`, cần đăng nhập + header `X-Tenant`)

| Nhóm | Endpoint | Quyền |
|---|---|---|
| Danh mục, thương hiệu | `GET/POST categories`, `PATCH/DELETE categories/{id}`, tương tự `brands` | products.view / products.manage |
| Sản phẩm | `GET/POST products`, `GET/PUT/DELETE products/{id}`, `POST products/bulk-status`, `GET products/sku-check`, `GET variants` | products.view / products.manage |
| Import/Export | `POST products/import` (`dry_run`, `warehouse_id`), `GET products/export?format=csv|xlsx`, `GET products/import-template` | products.manage (+ inventory.receive cho tồn đầu kỳ) / products.view |
| Ảnh | `POST products/{id}/images`, `DELETE products/{id}/images/{image}` | products.manage |
| Kho | `GET/POST warehouses`, `PATCH/DELETE warehouses/{id}` | inventory.view / settings.manage |
| Tồn | `GET inventory/stock`, `GET inventory/summary`, `GET inventory/variants/{id}/card`, `PUT inventory/variants/{id}/safety-stock` | inventory.view / inventory.adjust.approve |
| Nhập kho | `GET/POST inventory/receipts`, `GET inventory/receipts/{id}` | inventory.view / inventory.receive |
| Điều chỉnh | `GET/POST inventory/adjustments`, `GET …/{id}`, `POST …/{id}/approve|reject|cancel` | inventory.adjust / inventory.adjust.approve (người tạo được hủy phiếu của mình) |
| Chuyển kho | `GET/POST inventory/transfers`, `GET …/{id}`, `POST …/{id}/dispatch|receive|cancel` | inventory.transfer |

Lỗi nghiệp vụ trả mã rõ ràng: `INSUFFICIENT_STOCK` (422, kèm SKU, số cần, số khả dụng), `INVALID_STOCK_OPERATION` (422), `PLAN_LIMIT_EXCEEDED` (403).

## 5. Giao diện

18 ảnh chụp tại `docs/screenshots/phase-2/` (ảnh tổng hợp `00-tong-hop.png`): danh sách sản phẩm, form sản phẩm, import (lỗi và hợp lệ), tồn kho, thẻ kho, phiếu nhập (form, chi tiết), phiếu điều chỉnh (form, danh sách, chờ duyệt, đã duyệt), chuyển kho (đang chuyển, đã nhận, form), danh sách kho, tổng quan, tồn kho trên điện thoại.

## 6. Test đã chạy (MySQL 8.0, Redis 7, local)

| Bộ | Kết quả |
|---|---|
| Pest (Unit + Feature + Arch) | **142 passed**, 4.799 assertions (Phase 1: 72) |
| Pest `--group=concurrency` (tiến trình PHP riêng, kết nối riêng, dữ liệu commit thật) | **4 passed**, chạy lặp 5 lần đều pass |
| Vitest | **12 passed** |
| Playwright E2E | **12 passed** (7 Phase 1 + 5 Phase 2) |
| Pint | passed |
| vue-tsc | passed |
| `ecso:inventory:verify` trên dữ liệu demo | không sai lệch |

Kịch bản trọng tâm:
- 20 tiến trình cùng giữ 1 SKU tồn 10 ⇒ đúng 10 được giữ, 10 ghi thiếu, `reserved = 10`, sổ kho khớp.
- Cùng một webhook gửi 10 lần đồng thời ⇒ 1 reservation, giữ 3 (không phải 30).
- 16 phiếu khóa 2 SKU theo thứ tự ngược nhau đồng thời ⇒ không deadlock, số dư đúng.
- 12 tiến trình cùng xuất 2 cái khi tồn 5 ⇒ đúng 2 thành công, tồn còn 1, không âm.
- Property-based: 5 seed × 150 thao tác ngẫu nhiên (gồm gọi lặp key và thao tác bị từ chối) ⇒ khớp mô hình, sổ kho khớp.
- Import 10.000 SKU (2.500 sản phẩm) **9,3 giây** (yêu cầu < 2 phút).
- Cách ly tenant cho mọi endpoint mới (404 khi truy cập ULID của tenant khác, không lẫn dữ liệu, `tenant_id` trong payload bị bỏ qua), ma trận quyền theo 5 vai trò.

**Lỗi thật do test phát hiện và đã sửa:** (1) `INSERT IGNORE` trên dòng tồn đã có gây deadlock với giao dịch đang khóa; (2) insert sổ kho lấy khóa khóa ngoại trên SKU gây vòng chờ với phiếu nhập. Cả hai chỉ lộ ra khi chạy đa tiến trình thật. E2E còn bắt được: lưu biến thể không nhập giá bị lỗi 500, máy quét mã vạch nhấn Enter trước khi kết quả tìm kiếm về.

## 7. Lỗi / hạn chế còn lại

- **PHPStan chưa chạy được ở môi trường của tôi** (không tải được gói từ GitHub). Đã thêm vào CI (Larastan level 5); nếu CI báo lỗi tôi sẽ sửa trong PR này.
- Header `Idempotency-Key` từ client chưa làm; chống bấm 2 lần hiện dựa vào khóa trạng thái phiếu và nút bị vô hiệu khi đang gửi. Bấm "Lưu phiếu nhập" 2 lần rất nhanh từ 2 tab vẫn có thể tạo 2 phiếu.
- Người tạo phiếu điều chỉnh được tự duyệt nếu có quyền duyệt (được ghi `self_approved` trong nhật ký). Nếu muốn bắt buộc 2 người, cần quyết định của chủ dự án.
- Import chạy đồng bộ trong request (10.000 dòng ≈ 9 giây, trong giới hạn timeout Nginx 60 giây). File lớn hơn nhiều nên chuyển sang job ở Phase 8.
- Ảnh chụp toàn trang: thanh menu trái chỉ cao bằng màn hình (do chụp full-page), trên trình duyệt thật menu cố định bình thường.
- Combo/bundle, số lô/hạn dùng, serial chưa có (ngoài phạm vi Phase 2).

## 8. Rủi ro

- Phần khó nhất (không bán vượt tồn khi nhiều đơn đồng thời) đã có test đa tiến trình, nhưng mới với 1 máy MySQL local; tải thật sẽ đo ở Phase 8.
- Phase 3 phải dùng đúng `reserve/release/ship` với key xác định theo order item để giữ được bảo đảm này.

## 9. Credentials / phê duyệt cần

Không cần credentials mới cho Phase 2. Vẫn cần như trước cho Phase 4–7: tài khoản developer + test shop Shopee, TikTok Shop, Lazada, Tiki.

## 10. Đề xuất phase tiếp theo

**Phase 3 — Order Engine:** đơn hàng, trạng thái chuẩn hóa, giữ/nhả/xuất kho theo trạng thái, hủy, trả hàng + kiểm hàng + nhập lại, ghi chú/phân công, phiếu đóng gói, webhook inbox, Fake connector, outbox đẩy tồn (tới Fake). Không cần credentials sàn.
