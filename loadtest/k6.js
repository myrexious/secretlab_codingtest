// Run: K6_KEY=<load-testing api key> k6 run loadtest/k6.js
// 50% write, 50% read. Rate steps up every 2 min. Aborts at p95 > 1s or errors > 1%.
import http from 'k6/http';
import { check } from 'k6';

const BASE = 'https://secretlab.kreio.tech';
const H = { 'X-API-Key': __ENV.K6_KEY, 'Content-Type': 'application/json' };
const KEYS = 200; // key pool: reads always hit a seeded key
const key = (i) => `loadtest:${i}`;

const steps = [50, 100, 200, 400, 800, 1200, 1600]; // requests per second
export const options = {
  scenarios: {
    ramp: {
      executor: 'ramping-arrival-rate',
      startRate: 10, timeUnit: '1s',
      preAllocatedVUs: 200, maxVUs: 2000,
      stages: steps.flatMap((r) => [{ target: r, duration: '10s' }, { target: r, duration: '110s' }]),
    },
  },
  thresholds: {
    http_req_duration: [{ threshold: 'p(95)<1000', abortOnFail: true, delayAbortEval: '30s' }],
    http_req_failed: [{ threshold: 'rate<0.01', abortOnFail: true, delayAbortEval: '30s' }],
  },
};

export function setup() {
  const pairs = Array.from({ length: KEYS }, (_, i) => ({ key: key(i), value: { seed: i } }));
  for (let i = 0; i < KEYS; i += 50) {
    const r = http.post(`${BASE}/kv-value/bulk-create`, JSON.stringify({ pairs: pairs.slice(i, i + 50) }), { headers: H });
    if (r.status >= 300) throw new Error(`seed failed: ${r.status} ${r.body}`);
  }
}

export default function () {
  const k = key(Math.floor(Math.random() * KEYS));
  const r = Math.random() < 0.5
    ? http.post(`${BASE}/kv-value/data`, JSON.stringify({ key: k, value: { t: Date.now() } }), { headers: H })
    : http.get(`${BASE}/kv-value/data/${k}`, { headers: H });
  check(r, { ok: (x) => x.status < 300 });
}
