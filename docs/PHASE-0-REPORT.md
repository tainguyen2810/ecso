# Báo cáo Phase 0 — Discovery & Architecture

Ngày: 2026-10-31. Trạng thái: **hoàn thành phần tài liệu, chờ xác nhận trước Phase 1.**

## 1. Đã hoàn thành
- Audit: không có repository nào cho dự án trong tài khoản GitHub `tainguyen2810` (9 repo hiện có đều là dự án khác). Theo quy tắc "repo trống thì khởi tạo mới", Phase 1 sẽ khởi tạo dự án Laravel 12 + Vue 3 mới.
- Môi trường làm việc: PHP 8.3.6, Node 22, Redis 7 có sẵn; MySQL 8 sẽ cài cho test ở Phase 1.
- Xác minh API 4 sàn (kết quả ở API-CAPABILITY-MATRIX.md).
- Thiết kế kiến trúc modular monolith, 10 ADR, cấu trúc 18 module, interface connector.
- Thiết kế ~60 bảng, unique constraints chống trùng, ERD Mermaid.
- Thiết kế chi tiết Inventory Engine (ledger, khóa dòng, idempotency, reservation, allocation, outbox, reconcile, giá vốn).
- Thiết kế Order Lifecycle (trạng thái chuẩn hóa, pipeline webhook/polling, hủy, hoàn, sự kiện trùng/sai thứ tự).
- Kế hoạch kiểm thử, hướng dẫn aaPanel, roadmap có phụ thuộc, tiêu chí nghiệm thu, 10 rủi ro.

## 2. File đã tạo
`docs/` : ARCHITECTURE.md, DATABASE.md, ERD.md, API-CAPABILITY-MATRIX.md, INVENTORY-ENGINE.md, ORDER-LIFECYCLE.md, MULTI-TENANCY.md, SECURITY.md, DEPLOYMENT-AAPANEL.md, TESTING.md, ROADMAP.md, CHANGELOG.md, HANDOVER.md, PHASE-0-REPORT.md.

## 3. Database migrations
Chưa có (Phase 0 chỉ thiết kế). Danh sách migration theo phase ở ROADMAP.md.

## 4. API endpoints
Chưa có. Quy ước: REST `/api/v1`, webhook `/webhooks/{marketplace}`.

## 5. Giao diện
Không có (Phase 0 không có UI). Từ Phase 1, mỗi màn hình mới sẽ có ảnh chụp gửi kèm báo cáo.

## 6. Test đã chạy
Không có test nào được chạy vì chưa có code. Không tuyên bố gì về chất lượng code.

## 7. Lỗi còn tồn tại
Không áp dụng.

## 8. Rủi ro lớn nhất
1. **Không đọc được tài liệu chính thức** của Shopee (bị chặn), TikTok Shop và Lazada (cần đăng nhập / không render). Hầu hết capability đang là `UNKNOWN`. Tiki đọc được mục lục: OAuth 2.0, đơn hàng, event queue (pull), **chưa thấy API cập nhật tồn kho**.
2. Phê duyệt app ISV có thể mất nhiều tuần ⇒ cần đăng ký ngay.
3. Oversell do trễ đồng bộ (đã có thiết kế giảm thiểu).

## 9. Cần credentials / phê duyệt
- Tài khoản developer + app + test shop cho 4 sàn (xem HANDOVER.md).
- Hoặc tối thiểu: xuất tài liệu API từ Partner Center (PDF/ảnh) để nâng trạng thái matrix.

## 10. Đề xuất phase tiếp theo
Phase 1 — SaaS Foundation (không phụ thuộc sàn). Cần trước khi bắt đầu:
- Một repository GitHub trống để đẩy code.
- Xác nhận các quyết định kiến trúc (đặc biệt: single DB + tenant_id, Vue SPA + REST, ULID công khai).
