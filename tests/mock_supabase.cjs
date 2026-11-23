// ============================================================================
// مخدم Supabase وهمي للاختبارات فقط — يحاكي بالظبط نقاط PostgREST اللي
// طبقة docs/profiles بتستخدمها (قراءة، إنشاء، تحديث بشرط updated_at).
// يشتغل على 127.0.0.1 فقط ولا يتصل بأي خدمة خارجية. البيانات كلها في الذاكرة
// وتضيع مع إغلاق العملية — مستحيل يلمس بيانات حقيقية.
//
// التشغيل: SB_MOCK_PORT=18099 node tests/mock_supabase.cjs
// ============================================================================
'use strict';
const http = require('node:http');

const PORT = Number(process.env.SB_MOCK_PORT || 18099);
let revCounter = 1;
const nextRev = () => '2026-01-01T00:00:' + String(revCounter++).padStart(6, '0') + 'Z';

// name -> {data, updated_at}
const appDocs = new Map();
// id -> {id, email, data, updated_at}
const profiles = new Map();

function send(res, code, body) {
  const s = JSON.stringify(body);
  res.writeHead(code, {'Content-Type': 'application/json'});
  res.end(s);
}

function readBody(req) {
  return new Promise((resolve) => {
    let b = '';
    req.on('data', (c) => { b += c; });
    req.on('end', () => { try { resolve(JSON.parse(b || 'null')); } catch { resolve(null); } });
  });
}

const server = http.createServer(async (req, res) => {
  const u = new URL(req.url, 'http://127.0.0.1');
  const path = u.pathname;
  const q = u.searchParams;

  if (path === '/rest/v1/app_docs') {
    if (req.method === 'GET') {
      const nameEq = q.get('name') || '';
      const m = /^eq\.(.*)$/.exec(nameEq);
      if (!m) return send(res, 200, []);
      const row = appDocs.get(m[1]);
      if (!row) return send(res, 200, []);
      // select=data,updated_at
      return send(res, 200, [{data: row.data, updated_at: row.updated_at}]);
    }
    if (req.method === 'POST') {
      const body = await readBody(req);
      if (!body || typeof body.name !== 'string') return send(res, 400, {message: 'bad row'});
      if (appDocs.has(body.name)) return send(res, 409, {message: 'duplicate', code: '23505'});
      const row = {data: body.data ?? null, updated_at: nextRev()};
      appDocs.set(body.name, row);
      return send(res, 201, [{updated_at: row.updated_at}]);
    }
    if (req.method === 'PATCH') {
      const name = /^eq\.(.*)$/.exec(q.get('name') || '')?.[1];
      const rev = /^eq\.(.*)$/.exec(q.get('updated_at') || '')?.[1];
      const body = await readBody(req);
      const row = name !== undefined ? appDocs.get(name) : undefined;
      // تحكم التزامن: لازم الصف موجود والـ rev يطابق — وإلا 0 صفوف (تعارض)
      if (!row || row.updated_at !== rev) return send(res, 200, []);
      row.data = body && 'data' in body ? body.data : row.data;
      row.updated_at = nextRev();
      return send(res, 200, [{updated_at: row.updated_at}]);
    }
    return send(res, 405, {message: 'method not allowed'});
  }

  if (path === '/rest/v1/profiles') {
    if (req.method === 'GET') {
      let rows = [...profiles.values()];
      const emailEq = /^eq\.(.*)$/.exec(q.get('email') || '')?.[1];
      if (emailEq !== undefined) rows = rows.filter((r) => r.email === emailEq);
      rows.sort((a, b) => a.email.localeCompare(b.email));
      const limit = Number(q.get('limit') || 1000);
      const offset = Number(q.get('offset') || 0);
      rows = rows.slice(offset, offset + limit);
      return send(res, 200, rows.map((r) => ({id: r.id, email: r.email, data: r.data, updated_at: r.updated_at})));
    }
    if (req.method === 'POST') {
      const body = await readBody(req);
      if (!body || !body.id || !body.email) return send(res, 400, {message: 'bad row'});
      if (profiles.has(body.id)) return send(res, 409, {message: 'duplicate', code: '23505'});
      const row = {id: String(body.id), email: String(body.email), data: body.data ?? {}, updated_at: nextRev()};
      profiles.set(row.id, row);
      return send(res, 201, [{id: row.id}]);
    }
    if (req.method === 'PATCH') {
      const id = /^eq\.(.*)$/.exec(q.get('id') || '')?.[1];
      const rev = /^eq\.(.*)$/.exec(q.get('updated_at') || '')?.[1];
      const body = await readBody(req);
      const row = id !== undefined ? profiles.get(id) : undefined;
      if (!row || row.updated_at !== rev) return send(res, 200, []);
      row.data = body && 'data' in body ? body.data : row.data;
      row.updated_at = nextRev();
      return send(res, 200, [{id: row.id}]);
    }
    return send(res, 405, {message: 'method not allowed'});
  }

  return send(res, 404, {message: 'mock: unknown route ' + path});
});

server.listen(PORT, '127.0.0.1', () => {
  console.log('mock supabase on 127.0.0.1:' + PORT);
});
