# Testing Strategy

> Phase 0 (2026-10-31): **kế hoạch**. Mỗi phase phải chạy test thật và ghi kết quả vào báo cáo phase.

## 1. Tầng kiểm thử

| Tầng | Công cụ | DB | Mục đích |
|---|---|---|---|
| Unit | Pest | không / in-memory | Quy tắc thuần: công thức tồn, map trạng thái, ký request, backoff |
| Feature/API | Pest + Laravel HTTP | **MySQL 8** (không dùng SQLite vì cần `FOR UPDATE`, JSON, unique giống production) | Endpoint, validation, policy, tenant isolation |
| Concurrency | Pest + `pcntl_fork`/process pool | MySQL 8 | Reservation đồng thời, deadlock, idempotency |
| Integration (mock) | Pest + `Http::fake()` + **Fake connectors** + fixture JSON | MySQL 8 | Luồng đầy đủ webhook → đơn → tồn → outbox → đẩy tồn |
| Contract (sandbox) | Pest group `@sandbox`, **tắt mặc định** | sandbox sàn | Gọi API thật bằng test shop, chỉ chạy tay khi có credentials (`RUN_SANDBOX_TESTS=1`) |
| Frontend unit | Vitest + Vue Test Utils | – | Component, composable, store |
| E2E | Playwright | MySQL test + Fake connector | Luồng người dùng chính, chụp ảnh giao diện gửi kèm báo cáo |
| Architecture | Pest `arch()` | – | Không ghi tồn ngoài InventoryService; model tenant phải dùng trait; module không import model module khác để ghi |

Quy tắc:
- **Không gọi API production trong test tự động.** HTTP client của connector bị chặn ra ngoài khi `APP_ENV=testing` trừ group `@sandbox`.
- Báo cáo phân biệt rõ: "pass với Fake/mock" ≠ "đã xác minh với sàn".
- Fixture payload sàn chỉ được tạo từ tài liệu chính thức/sandbox thật; trước khi có, dùng payload Fake connector tự định nghĩa và ghi chú rõ.

## 2. Danh mục kịch bản bắt buộc

| Nhóm | Kịch bản chính | Phase |
|---|---|---|
| Tenant isolation | User tenant A truy cập mọi endpoint với ULID tenant B ⇒ 404; list không lẫn dữ liệu; job không có tenant ⇒ fail | 1 (+ mỗi phase thêm endpoint) |
| Authentication | Đăng ký, xác minh email, đăng nhập sai nhiều lần bị khóa, quên mật khẩu, token hết hạn | 1 |
| Authorization | Ma trận role × permission × endpoint (dataset), privilege escalation khi gán role | 1 |
| SKU mapping | Map theo external_sku_id/seller_sku chính xác; tên giống nhau không tự map; SKU trùng bị chặn | 2, 4 |
| Concurrent reservations | 20 tiến trình giữ 1 SKU tồn 10 | 2 |
| Duplicate webhooks | Gửi 1 webhook 5 lần ⇒ 1 event xử lý, 1 reservation | 3, 4, 5 |
| Out-of-order events | DELIVERED trước HANDED_OVER; sự kiện cũ đến sau | 3 |
| Token expiration | Access token hết hạn ⇒ refresh 1 lần dù 10 job song song; refresh hỏng ⇒ account expired | 4 |
| API rate limit | Connector nhận 429 ⇒ backoff + jitter, không vượt limit cấu hình | 4 |
| Retry / Timeout | Timeout ⇒ retry tối đa N; lỗi vĩnh viễn ⇒ không retry, cảnh báo | 4 |
| Overselling | Tổng published các sàn không vượt sellable; đơn thiếu hàng ⇒ shortage, không âm kho | 2, 3 |
| Order cancellation | Hủy trước/sau xuất kho; hủy lặp | 3 |
| Returns | Refund không cộng kho; kiểm đạt mới cộng; hàng lỗi không cộng | 3 |
| Stock reconciliation | Lệch ledger phát hiện được; lệch sàn tạo outbox đẩy lại | 2, 4 |
| Failed queue recovery | Job fail vào failed_jobs, retry thủ công từ admin chạy idempotent | 3, 8 |
| Subscription limits | Vượt số shop/user/kho bị chặn đúng mã lỗi; override tenant thắng | 6 |
| Payment idempotency | IPN lặp ⇒ 1 payment; chữ ký sai ⇒ từ chối | 6 |
| Backup/restore | Script backup → restore vào DB trống → kiểm bất biến tồn kho | 8 |
| Security | Webhook chữ ký sai/replay, CSRF, mass assignment tenant_id, token không lộ trong log | 1, 4, 8 |

## 3. Chạy test

```bash
# Backend: phpunit.xml trỏ DB_DATABASE=ecso_test trên MySQL 8 (user/pass lấy từ .env)
./vendor/bin/pest                       # Unit + Feature + Arch
./vendor/bin/pint --test                # code style

# Frontend
npm run typecheck                       # vue-tsc
npm test                                # Vitest

# E2E (Playwright) – cần server chạy với dữ liệu demo, ảnh chụp lưu ở docs/screenshots/phase-N/
php artisan migrate:fresh --seed && php artisan db:seed --class=DemoSeeder
php artisan serve --port=8000 &
npx playwright test

# Trình cài đặt trên web: chạy trên một bản sao chưa cài (không .env) với một DB trống, ảnh ở docs/screenshots/install/
INSTALL_DB_DATABASE=ecso_wizard INSTALL_DB_USERNAME=root INSTALL_DB_PASSWORD=root scripts/e2e-install.sh

# Phase 2: test đa tiến trình (mỗi worker là 1 tiến trình PHP riêng, kết nối MySQL riêng, dữ liệu commit thật).
# Bị loại khỏi lần chạy mặc định vì dùng DatabaseTruncation; CI chạy riêng một bước.
php artisan test --group=concurrency
# Từ Phase 4: RUN_SANDBOX_TESTS=1 ... --group=sandbox
```

Tài khoản demo (chỉ môi trường local/E2E, DemoSeeder từ chối chạy trên production): `owner@demo.test`, `staff@demo.test`, `admin@demo.test`, mật khẩu `password1`.

CI (GitHub Actions): service MySQL 8 + Redis, chạy lint (Pint, ESLint), PHPStan level 6+, Pest, Vitest, Playwright headless.

## 4. Tiêu chí "xong" của mỗi phase

- Toàn bộ test của phase pass trên MySQL 8, log kết quả đính kèm báo cáo.
- Không giảm coverage của module Inventory/Orders (mục tiêu ≥ 85% dòng cho 2 module này).
- Có ảnh chụp giao diện (Playwright screenshot) cho mọi màn hình mới.

## 5. Kết quả theo phase

| Phase | Pest | Concurrency | Vitest | Playwright |
|---|---|---|---|---|
| 1 | 72 | – | 8 | 7 |
| 2 | 142 (gồm property-based 5 seed × 150 thao tác, import 10.000 SKU) | 4 kịch bản | 12 | 12 (5 mới) |
| 4 | 192 (9 mới: ủy quyền shop, state một lần, chống chiếm shop, giới hạn shop) | – | 16 | 20 |
| 5 | 195 (3 mới) | – | 16 | 21 |
| 6 | 212 (17 mới: hóa đơn, idempotency thanh toán, chuyển thiếu/thừa, nâng gói trừ tiền, hạ gói bị chặn/hẹn, dùng thử 1 lần, gia hạn/quá hạn/về Free, IPN giả lập ký HMAC, đếm đơn, tách tenant, admin MRR, tài chính, báo cáo, gating `reports.advanced`) | – | 19 | 23 |
| 7 | 218 (6 mới: Lazada/Tiki UNKNOWN, từ chối kết nối, từ chối webhook) | – | 19 | 24 |
| 8 | 226 (8 mới: TOTP theo RFC 6238, bắt buộc cài/nhập 2FA, chống dùng lại mã, mã khôi phục dùng 1 lần, header bảo mật/CSP/HSTS, che log, giám sát + cảnh báo) | 5 | 19 | 26 (2FA cài đặt + từ chối khi chưa qua 2FA, giám sát) |
| 3 | 183 (41 mới: pipeline đơn, webhook trùng/sai thứ tự, hủy, trả hàng, đẩy tồn, API) | 5 kịch bản (mới: 1.000 đơn / 50 SKU / 20 tiến trình, webhook gửi trùng) | 16 | 18 (6 mới) |
| Wizard cài đặt | 234 (8 mới: chuyển hướng khi chưa cài, mã cài đặt bắt buộc và không lộ, thử kết nối DB/Redis, cài đặt ghi `.env` production và tự khóa, từ chối DB đã có admin, `.env` giữ đúng ký tự đặc biệt, CLI ghi khóa) | 5 | 22 | 26 + 1 (cài thật trên bản sao chưa cài, rồi đăng nhập tới màn hình 2FA) |
