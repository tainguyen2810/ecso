// k6 load test for ECSO (Phase 8). Run against STAGING with the Fake marketplace enabled — never production:
//   k6 run -e BASE_URL=https://staging.example.vn -e EMAIL=owner@demo.test -e PASSWORD=... -e TENANT=<tenant ulid> loadtest/k6-orders.js
// Scenarios:
//   browse  – staff using the order screens (list with filters, detail, dashboard summary)
//   ingest  – marketplace pushing new orders through the real webhook pipeline (Fake marketplace simulator)
// Thresholds encode the targets in docs/LOAD-TEST.md; k6 exits non-zero when they are missed.
import http from 'k6/http';
import { check, group, sleep } from 'k6';

const BASE = __ENV.BASE_URL || 'http://127.0.0.1:8000';
const TENANT = __ENV.TENANT;
const FAKE_ACCOUNT = __ENV.FAKE_ACCOUNT; // ulid of the fake shop account (Kết nối sàn page)

export const options = {
    scenarios: {
        browse: { executor: 'ramping-vus', exec: 'browse', startVUs: 0, stages: [{ duration: '1m', target: 30 }, { duration: '3m', target: 30 }, { duration: '30s', target: 0 }] },
        ingest: { executor: 'constant-arrival-rate', exec: 'ingest', rate: 10, timeUnit: '1s', duration: '4m', preAllocatedVUs: 20, maxVUs: 60, startTime: '30s' },
    },
    thresholds: {
        'http_req_failed{scenario:browse}': ['rate<0.01'],
        'http_req_duration{scenario:browse}': ['p(95)<800'],
        'http_req_failed{scenario:ingest}': ['rate<0.01'],
        'http_req_duration{scenario:ingest}': ['p(95)<1500'],
    },
};

function session() {
    const jar = http.cookieJar();
    http.get(`${BASE}/sanctum/csrf-cookie`);
    const xsrf = decodeURIComponent(jar.cookiesForURL(BASE)['XSRF-TOKEN'][0]);
    const headers = { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrf, 'X-Tenant': TENANT, Referer: `${BASE}/` };
    const res = http.post(`${BASE}/api/v1/auth/login`, JSON.stringify({ email: __ENV.EMAIL, password: __ENV.PASSWORD }), { headers });
    check(res, { 'login ok': (r) => r.status === 200 });
    return headers;
}

let headers = null;

export function browse() {
    headers = headers || session();
    group('orders', () => {
        const list = http.get(`${BASE}/api/v1/orders?tab=to_pack&per_page=50`, { headers, tags: { name: 'orders.list' } });
        check(list, { 'list 200': (r) => r.status === 200 });
        const first = list.status === 200 ? list.json('data.0.id') : null;
        if (first) check(http.get(`${BASE}/api/v1/orders/${first}`, { headers, tags: { name: 'orders.show' } }), { 'detail 200': (r) => r.status === 200 });
        check(http.get(`${BASE}/api/v1/orders/summary`, { headers, tags: { name: 'orders.summary' } }), { 'summary 200': (r) => r.status === 200 });
        check(http.get(`${BASE}/api/v1/inventory/stock?per_page=50`, { headers, tags: { name: 'stock.list' } }), { 'stock 200': (r) => r.status === 200 });
    });
    sleep(1 + Math.random() * 2);
}

export function ingest() {
    headers = headers || session();
    // Creates an order on the Fake marketplace, which sends its signed webhook through the real pipeline.
    const res = http.post(`${BASE}/api/v1/fake-marketplace/${FAKE_ACCOUNT}/orders`, JSON.stringify({ count: 1 }), { headers, tags: { name: 'fake.order' } });
    check(res, { 'order created': (r) => r.status === 201 || r.status === 200 });
}
