# Quy chuẩn giao diện ECSO

Chốt ngày 03/11/2026 cùng màn Tổng quan mới (tham khảo bố cục Tổng quan của Sapo). Màn hình khác khi tinh chỉnh thì theo tài liệu này.

## Nguyên tắc

1. **Nền xám nhạt, thẻ trắng, sidebar xanh đậm.** Trang `bg-background`, mỗi khối là `Card` trắng viền mảnh, bo `rounded-lg`.
2. **Màu nhạt theo bảng Tailwind.** Nền tô màu dùng tông 50 (`bg-blue-50`), icon tông 500, con số tông 600. Không dùng màu đặc cho mảng lớn.
3. **Màu phải có nghĩa.** Icon mặc định đơn sắc (nét lucide, `stroke-width` 1.75). Chỉ tô màu khi màu nói lên điều gì:

   | Tone | Dùng cho | Ví dụ |
   |---|---|---|
   | `blue` | Tiền, số trung tính, mục đang chọn | Doanh thu, hàng đợi đơn |
   | `green` | Tốt, mới, hoàn tất | Đơn mới, "Đang hoạt động" |
   | `amber` | Cần chú ý | Quá hạn giao, hết hàng |
   | `red` | Có vấn đề | Đơn hủy, dưới tồn an toàn, "Cần kiểm tra" |
   | `slate` | Số đếm thường | Tổng tồn kho |

   Một dải nhiều mục cùng loại (ví dụ các trạng thái đơn) dùng **một** tông, chỉ mục cảnh báo đổi màu.
4. **Màu theo kênh bán** chỉ dùng trong biểu đồ và ô chữ cái gian hàng: Shopee cam, TikTok xám đậm, Lazada chàm, Tiki xanh trời, kênh riêng xanh ngọc (tông 300–400).
5. Tiền `1.234.000 ₫` (`formatMoney`), số `formatNumber`, ngày giờ `formatDateTime`. Số trong bảng/thẻ thêm `tabular-nums`.

## Token màu (`resources/css/app.css`)

- Chung: `background`, `foreground`, `card`, `muted`, `muted-foreground`, `border`, `primary`, `success`, `warning`, `destructive`.
- Sidebar: `sidebar` (nền), `sidebar-foreground` (chữ), `sidebar-muted` (chữ phụ), `sidebar-hover`, `sidebar-border`, `sidebar-active` (mục đang chọn, xanh dương).
- Kênh bán: `channel-shopee`, `channel-tiktok`, `channel-lazada`, `channel-tiki`, `channel-manual`, `channel-other`. Trong code lấy qua `channelColor(key)` (`resources/js/shared/lib/tones.ts`).
- Tone: `toneClasses[tone].bubble | value | soft` trong `tones.ts`. Không viết lại bộ class màu ở từng màn.

## Thành phần dùng chung (`resources/js/shared/ui`)

| Thành phần | Dùng khi |
|---|---|
| `Card` | Mọi khối nội dung. `caps` = tiêu đề in hoa đậm kiểu dashboard ("KẾT QUẢ KINH DOANH HÔM NAY"); slot `actions` cho link/bộ lọc bên phải tiêu đề. |
| `StatTile` | Ô chỉ số: icon tròn + nhãn + số lớn + dòng phụ (slot). Có `to` thì bấm được. Đặt nhiều ô trong `grid divide-x`. |
| `IconBubble` | Icon trong vòng tròn nền nhạt theo tone (hàng đợi, ô chỉ số). |
| `charts/StackedBarChart` | Cột chồng theo ngày, mỗi chuỗi một màu; có chú thích, trục tiền rút gọn ("10 tr"), tooltip số chính xác. |
| `charts/DonutChart` | Tỷ trọng (doanh thu theo sàn) kèm chú thích %. |
| `MarketplaceLogo` | Logo sàn (`public/images/marketplaces/<sàn>.webp`: shopee, tiktok, lazada, tiki); kênh riêng hiện icon cửa hàng. Dùng ở mọi chỗ hiện tên sàn/gian hàng. |
| `Badge` | Trạng thái (tone `success`, `warning`, `danger`, `muted`, `default`). |
| `EmptyState` | Khối chưa có dữ liệu, kèm hành động tiếp theo. |

Mẫu hay dùng (viết trực tiếp bằng Tailwind, không cần component riêng):

- **Hộp thông tin tô nhạt** (Thông tin kho): `rounded-lg px-4 py-3` + `toneClasses[tone].soft`, số dùng `toneClasses[tone].value`, mũi tên `ChevronRight` bên phải.
- **Danh sách xếp hạng** (Top sản phẩm): số thứ tự trong vòng tròn `size-6`, top 3 `bg-blue-100 text-blue-600`, còn lại `bg-slate-100 text-slate-500`.
- **Dòng thời gian** (Đơn hàng gần đây): chấm `bg-blue-400 ring-4 ring-blue-50`, đường nối nét đứt `border-blue-200`.

## Bố cục

- Trang: `PageHeader` (tiêu đề + mô tả, nút/bộ lọc bên phải), rồi các `Card` cách nhau `gap-5`.
- Tổng quan: lưới hai cột `xl:grid-cols-[minmax(0,1fr)_320px]`; cột phải cho khối phụ (tỷ trọng, gian hàng, hoạt động). Dưới `xl` thành một cột; mobile mọi khối xếp dọc.
- Sidebar: nhóm menu in hoa đậm, ngăn bằng đường kẻ `border-sidebar-border`; mục đang chọn nền `sidebar-active` chữ trắng.
