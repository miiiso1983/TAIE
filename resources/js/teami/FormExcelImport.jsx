import React, { useRef, useState } from 'react';
import { Button, Checkbox, Field } from '@fluentui/react-components';
import { api, requestKey } from './api';
import { Notice, SearchableSelect } from './components';

export function FormExcelImport({ fields, initial = {}, deriveValues, save, onSaved, onBusyChange }) {
    const [file, setFile] = useState(null), [table, setTable] = useState(null), [sheet, setSheet] = useState('0'), [mapping, setMapping] = useState({});
    const [busy, setBusy] = useState(false), [error, setError] = useState(''), [confirmed, setConfirmed] = useState(false), [results, setResults] = useState({});
    const keys = useRef({}), completed = useRef(new Set()), inFlight = useRef(false);
    const lock = value => { setBusy(value); onBusyChange(value); };
    const reset = () => { setTable(null); setMapping({}); setConfirmed(false); setResults({}); keys.current = {}; completed.current = new Set(); setError(''); };
    async function inspect(event) {
        event.preventDefault(); if (!file || inFlight.current) return; inFlight.current = true; lock(true); setError('');
        try {
            const data = new FormData(); data.append('file', file); data.append('sheet', sheet);
            const response = await api('/forms/import-preview', { method: 'POST', data });
            reset(); setTable(response.data); setMapping(Object.fromEntries(fields.map(f => [f.name, response.data.header.find(x => x === f.name || x === f.label) ?? ''])));
        } catch (e) { setError(e.message); } finally { inFlight.current = false; lock(false); }
    }
    const rows = (table?.rows ?? []).map(row => {
        let values = Object.fromEntries(fields.map(f => [f.name, mapping[f.name] ? row.cells[table.header.indexOf(mapping[f.name])] : initial[f.name] ?? f.default ?? '']));
        if (deriveValues) for (const f of fields) values = deriveValues(values, f.name);
        const issues = [], data = {};
        for (const f of fields) {
            let value = values[f.name]; const choices = typeof f.options === 'function' ? f.options(values) : f.options;
            if (value !== '' && choices) {
                const match = choices.find(o => String(o.value) === String(value) || o.label === value);
                if (match) value = match.value; else issues.push(`${f.label}: اختر قيمة من الخيارات المتاحة`);
                values[f.name] = value;
            }
            if (f.required && (value === '' || value === null || value === undefined)) issues.push(`${f.label}: مطلوب`);
            if (f.type === 'email' && value !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) issues.push(`${f.label}: بريد غير صالح`);
            if (f.type === 'date' && value !== '' && (!/^\d{4}-\d{2}-\d{2}$/.test(value) || Number.isNaN(Date.parse(value)) || new Date(value).toISOString().slice(0, 10) !== value)) issues.push(`${f.label}: استخدم تاريخ YYYY-MM-DD صالحًا`);
            if (f.maxLength && String(value).length > f.maxLength) issues.push(`${f.label}: النص أطول من الحد`);
            if (f.type === 'number' && value !== '' && (!Number.isFinite(Number(value)) || (f.min !== undefined && Number(value) < Number(f.min)) || (f.max !== undefined && Number(value) > Number(f.max)))) issues.push(`${f.label}: رقم خارج الحدود`);
            if (value !== '' && value !== null && value !== undefined) data[f.name] = f.boolean ? value === 'true' : value;
            else if (f.nullable) data[f.name] = null;
        }
        return { line: row.line, data, issues };
    });
    async function template() {
        if (inFlight.current) return; inFlight.current = true; lock(true); setError('');
        try {
            const response = await fetch('/api/forms/import-template', { method: 'POST', headers: { Authorization: `Bearer ${localStorage.getItem('teami_token')}`, 'X-Tenant': localStorage.getItem('teami_tenant'), 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify({ columns: fields.map(f => f.name) }) });
            if (!response.ok) throw new Error('تعذر تحميل القالب؛ تحقق من اتصالك وصلاحية الجلسة.');
            const url = URL.createObjectURL(await response.blob()), a = document.createElement('a'); a.href = url; a.download = 'TAIE-addition-template.xlsx'; a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch (e) { setError(e.message); } finally { inFlight.current = false; lock(false); }
    }
    async function commit() {
        if (inFlight.current || !confirmed || rows.some(r => r.issues.length)) return;
        inFlight.current = true; lock(true); setError('');
        try {
            for (const row of rows) {
                if (completed.current.has(row.line)) continue;
                keys.current[row.line] ??= requestKey();
                try { await save(row.data, keys.current[row.line]); completed.current.add(row.line); setResults(old => ({ ...old, [row.line]: 'تم الحفظ' })); }
                catch (e) { setResults(old => ({ ...old, [row.line]: e.message })); setError(`توقف الحفظ عند الصف ${row.line}: ${e.message}. صحح الملف عند الحاجة؛ أو أعد المحاولة بنفس الملف إذا كان الخطأ مؤقتًا.`); return; }
            }
            onSaved(`تمت إضافة ${completed.current.size} سجلًا من الملف.`);
        } finally { inFlight.current = false; lock(false); }
    }
    return <div className="stack"><p>حمّل قالب XLSX وافتحه في Excel، أو ارفع ملف XLSX واربط أعمدته. لا يكتب فحص الملف أي سجلات. الحفظ النهائي يطبّق فحوصات النظام على كل صف.</p><Button type="button" disabled={busy} onClick={template}>تحميل قالب Excel</Button>
        <Field label="ملف Excel أو CSV" hint="XLSX أو CSV UTF-8، حتى 100 صف و1MiB، بقيم ثابتة دون صيغ أو روابط خارجية."><input aria-label="ملف Excel أو CSV" type="file" accept=".xlsx,.csv" disabled={busy || completed.current.size > 0} onChange={e => { setFile(e.target.files?.[0] ?? null); setSheet('0'); reset(); }} /></Field>
        {table?.sheets.length > 1 && <Field label="ورقة الملف"><SearchableSelect disabled={busy || completed.current.size > 0} value={sheet} onChange={e => { setSheet(e.target.value); setTable(null); setConfirmed(false); }} >{table.sheets.map(x => <option key={x.index} value={String(x.index)}>{x.name}</option>)}</SearchableSelect></Field>}
        <Button type="button" disabled={!file || busy || completed.current.size > 0} onClick={inspect}>فحص الملف ومعاينة الصفوف</Button>
        {table && <><div className="form-grid">{fields.map(f => <Field key={f.name} label={f.label} required={!!f.required}><SearchableSelect disabled={busy || completed.current.size > 0} value={mapping[f.name] ?? ''} onChange={e => { setMapping(old => ({ ...old, [f.name]: e.target.value })); setConfirmed(false); }}><option value="">غير مربوط</option>{table.header.map(column => <option key={column} value={column}>{column}</option>)}</SearchableSelect></Field>)}</div><div className="excel-preview"><table><caption>معاينة {rows.length} صفًا</caption><thead><tr><th>الصف</th><th>البيانات</th><th>الحالة</th></tr></thead><tbody>{rows.map(row => <tr key={row.line}><td>{row.line}</td><td>{Object.entries(row.data).map(([key, value]) => `${fields.find(f => f.name === key)?.label}: ${value}`).join('، ')}</td><td>{results[row.line] || row.issues.join('، ') || 'جاهز للفحص النهائي عند الحفظ'}</td></tr>)}</tbody></table></div>
        <Checkbox disabled={busy} checked={confirmed} onChange={(_, d) => setConfirmed(d.checked)} label="راجعت الصفوف وأوافق على إضافتها. قد تتوقف الدفعة بعد حفظ بعض الصفوف إذا رفض النظام صفًا لاحقًا."/><Button type="button" appearance="primary" disabled={busy || !confirmed || !rows.length || rows.some(r => r.issues.length)} onClick={commit}>اعتماد وإضافة الصفوف</Button>{completed.current.size > 0 && <Notice>حُفظ {completed.current.size} صفًا. إعادة المحاولة هنا تتجاوز الصفوف المحفوظة. إذا أعدت فتح النافذة أو رفعت ملفًا جديدًا، احذف الصفوف المحفوظة من الملف أولًا.</Notice>}</>}
        {error && <Notice intent="error">{error}</Notice>}</div>;
}
