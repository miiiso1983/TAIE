export function requestKey() {
    return crypto.randomUUID?.() ?? Array.from(crypto.getRandomValues(new Uint8Array(16)), x => x.toString(16).padStart(2, '0')).join('');
}

export async function api(path, { method = 'GET', data, key, signal, authenticated = true } = {}) {
    const token = localStorage.getItem('teami_token');
    const tenant = localStorage.getItem('teami_tenant');
    const response = await fetch(`/api${path}`, {
        method, signal,
        headers: {
            Accept: 'application/json', ...(data instanceof FormData ? {} : { 'Content-Type': 'application/json' }),
            ...(authenticated ? { Authorization: `Bearer ${token}`, 'X-Tenant': tenant } : {}),
            ...(key ? { 'Idempotency-Key': key } : {}),
        },
        ...(data !== undefined ? { body: data instanceof FormData ? data : JSON.stringify(data) } : {}),
    });
    let body = {};
    try { body = await response.json(); } catch { /* Non-JSON failures still show their HTTP status. */ }
    if (!response.ok) {
        const validation = Object.values(body.errors ?? body.error?.details ?? {}).flat().filter(x => typeof x === 'string');
        const error = new Error(validation.length ? validation.join(' · ') : body.error?.message ?? body.message ?? `تعذر تنفيذ الطلب (${response.status})`);
        error.status = response.status;
        if (authenticated && response.status === 401) window.dispatchEvent(new Event('teami:unauthorized'));
        throw error;
    }
    if (method === 'POST' && (path === '/ai/ask' || /^\/advisor\/reports\/[^/]+\/explain$/.test(path))) window.dispatchEvent(new Event('teami:ai-usage-changed'));
    return body;
}

export function clearSession() {
    for (const key of ['teami_token', 'teami_tenant', 'teami_user']) localStorage.removeItem(key);
}
