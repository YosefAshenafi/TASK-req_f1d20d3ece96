/**
 * Campus Portal — API client
 * All requests go through BASE_URL (nginx proxy → ThinkPHP backend).
 */
const API = (() => {
  const BASE = '/api';

  function token() {
    return localStorage.getItem('campus_token') || '';
  }

  function headers(extra = {}) {
    const h = { 'Content-Type': 'application/json', ...extra };
    if (token()) h['Authorization'] = 'Bearer ' + token();
    return h;
  }

  async function request(method, path, body) {
    const opts = { method, headers: headers() };
    if (body !== undefined) opts.body = JSON.stringify(body);
    const res = await fetch(BASE + path, opts);
    const text = await res.text();
    let data;
    try { data = JSON.parse(text); } catch { data = { code: res.status, msg: text }; }
    if (!res.ok) throw Object.assign(new Error(data.msg || 'Request failed'), { status: res.status, data });
    return data;
  }

  return {
    get:    (path)        => request('GET',    path),
    post:   (path, body)  => request('POST',   path, body),
    put:    (path, body)  => request('PUT',    path, body),
    patch:  (path, body)  => request('PATCH',  path, body),
    delete: (path)        => request('DELETE', path),

    // File upload (multipart — no JSON Content-Type)
    upload(path, formData) {
      return fetch(BASE + path, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + token() },
        body: formData,
      }).then(r => r.json());
    },
  };
})();
