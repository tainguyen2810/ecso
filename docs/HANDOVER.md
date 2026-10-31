# Handover

> Cập nhật sau mỗi phase. Lần cập nhật: Phase 0, 2026-10-31.

## Trạng thái hiện tại

- Phase hoàn thành: **Phase 0 (tài liệu)**. Chưa có code. Repository: `tainguyen2810/ecso`.
- Tài liệu nằm tại `docs/` của repo.

## Việc chủ dự án cần làm

1. ~~Tạo repository GitHub~~ Đã có: `tainguyen2810/ecso` (2026-10-31).
2. Đăng ký tài khoản developer: Shopee Open Platform, TikTok Shop Partner Center (VN), Lazada Open Platform, Tiki Open Platform. Tạo app loại phục vụ nhiều seller.
3. Chuẩn bị test shop/sandbox cho từng sàn.
4. Quyết định: cổng thanh toán, tên miền, nhà cung cấp email.

## Thông tin vận hành

Chưa có môi trường. Xem DEPLOYMENT-AAPANEL.md khi triển khai.

## Credentials cần (không gửi qua chat, nhập vào `.env` trên server/CI secrets)

| Biến | Dùng cho | Phase |
|---|---|---|
| `SHOPEE_PARTNER_ID`, `SHOPEE_PARTNER_KEY` | Shopee | 4 |
| `TIKTOK_APP_KEY`, `TIKTOK_APP_SECRET` | TikTok Shop | 5 |
| `LAZADA_APP_KEY`, `LAZADA_APP_SECRET` | Lazada | 7 |
| `TIKI_CLIENT_ID`, `TIKI_CLIENT_SECRET` | Tiki | 7 |
| Payment gateway keys | Billing | 6 |
