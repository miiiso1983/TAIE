import React, { useEffect, useRef, useState } from 'react';
import {
    Avatar, Badge, Button, Card, Checkbox, Dialog, DialogActions, DialogBody, DialogContent,
    DialogSurface, DialogTitle, Field, Input, MessageBar, MessageBarBody,
    Combobox, Option, Spinner, Table, TableBody, TableCell, TableCellLayout,
    TableHeader, TableHeaderCell, TableRow, Textarea,
} from '@fluentui/react-components';
import { Dismiss24Regular, DocumentSearch24Regular, ArrowClockwise24Regular } from '@fluentui/react-icons';
import { api, requestKey } from './api';

const searchKey = text => String(text).normalize('NFKD').replace(/[\u064B-\u065F\u0670\u0640]/g, '').replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').toLocaleLowerCase().trim();
const optionLabel = node => React.Children.toArray(node).map(x => React.isValidElement(x) ? optionLabel(x.props.children) : String(x)).join('');
// Preserve canonical values while searching translated option labels.
export function SearchableSelect({ children, value = '', onChange, required, disabled, name, ...props }) {
    const options = React.Children.toArray(children).filter(x => React.isValidElement(x) && x.type === 'option').map(x => ({ value: String(x.props.value ?? ''), label: optionLabel(x.props.children), disabled: x.props.disabled }));
    const selected = options.find(x => x.value === String(value));
    const [query, setQuery] = useState(selected?.label ?? ''), [searching, setSearching] = useState(false);
    const [open, setOpen] = useState(false);
    const input = useRef(null);
    useEffect(() => { if (!searching) setQuery(selected?.label ?? ''); }, [value, selected?.label, searching]);
    useEffect(() => { input.current?.setCustomValidity(required && (!selected || selected.value === '' || searching) ? 'اختر قيمة من القائمة.' : ''); }, [required, selected?.value, searching]);
    const filtered = searching ? options.filter(x => searchKey(x.label).includes(searchKey(query))) : options;
    return <Combobox {...props} open={open} onOpenChange={(_, data) => setOpen(data.open)} className={`searchable-select ${props.className ?? ''}`} value={query} selectedOptions={selected && selected.value !== '' ? [selected.value] : []} required={required} disabled={disabled} name={name} input={{ ref: input }} placeholder={options.find(x => x.value === '')?.label ?? 'ابحث واختر…'}
        onChange={event => { setQuery(event.target.value); setSearching(true); setOpen(true); }}
        onOptionSelect={(_, data) => { if (data.optionValue === undefined) return; setOpen(false); setSearching(false); const next = options.find(x => x.value === data.optionValue); setQuery(next?.label ?? ''); onChange?.({ target: { value: data.optionValue ?? '', name } }); }}
        onBlur={() => { setOpen(false); setSearching(false); setQuery(selected?.label ?? ''); }}>
        {filtered.map(x => <Option key={x.value} value={x.value} text={x.label} data-option-value={x.value} disabled={x.disabled}>{x.label}</Option>)}
        {!filtered.length && <Option disabled value="__no_results">لا توجد نتائج</Option>}
    </Combobox>;
}
const Select = SearchableSelect;

export const statuses = {
    queued: 'بانتظار الفهرسة', indexing: 'تجري الفهرسة', indexed: 'مفهرس', failed: 'تعثر', expired: 'منتهي', canceled: 'ملغى', suspended: 'موقوف', void: 'ملغاة', completed: 'مكتمل', needs_revision: 'يحتاج تعديلاً', applied: 'تقديم', screening: 'فرز', interview: 'مقابلة', offered: 'عرض', hired: 'تعيين', active: 'نشط', on_leave: 'في إجازة', terminated: 'منتهي الخدمة', pending: 'قيد المراجعة',
    approved: 'موافق عليه', rejected: 'مرفوض', cancelled: 'ملغى', draft: 'مسودة', review: 'قيد المراجعة',
    paid: 'مدفوع', open: 'مفتوحة', won: 'مكتملة بنجاح', lost: 'مفقودة', trialing: 'تجريبي', inactive: 'غير نشط',
};
export const employeeName = x => `${x.first_name} ${x.last_name}`;
export function Status({ value }) {
    const color = ['active', 'approved', 'won', 'paid'].includes(value) ? 'success' : ['pending', 'review', 'draft'].includes(value) ? 'warning' : ['terminated', 'rejected', 'lost'].includes(value) ? 'danger' : 'informative';
    return <Badge className={`record-status status-${color}`} appearance="tint" color="subtle">{statuses[value] ?? value ?? 'غير محدد'}</Badge>;
}
export function Notice({ children, intent = 'info' }) {
    return <MessageBar intent={intent}><MessageBarBody>{children}</MessageBarBody></MessageBar>;
}
export function Empty({ title = 'لا توجد سجلات بعد', text = 'ستظهر البيانات هنا عند إضافتها.', action }) {
    return <div className="empty-state"><DocumentSearch24Regular /><h3>{title}</h3><p>{text}</p>{action}</div>;
}
export function PageHeading({ title, description, action, eyebrow }) {
    return <header className="page-heading"><div>{eyebrow && <span className="eyebrow">{eyebrow}</span>}<h1>{title}</h1><p>{description}</p></div>{action}</header>;
}
export function useQuery(path) {
    const [state, setState] = useState({ loading: !!path, data: null, error: null });
    const [revision, setRevision] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        if (!path) { setState({ loading: false, data: null, error: null }); return; }
        setState({ loading: true, data: null, error: null });
        api(path, { signal: controller.signal }).then(data => {
            if (!controller.signal.aborted) setState({ loading: false, data, error: null });
        }).catch(error => {
            if (!controller.signal.aborted) setState({ loading: false, data: null, error });
        });
        return () => controller.abort();
    }, [path, revision]);
    return { ...state, refresh: () => setRevision(x => x + 1) };
}
export function QueryState({ query, children }) {
    if (query.loading) return <div className="loading-state"><Spinner label="جارٍ تحميل البيانات…" /></div>;
    if (query.error) return <div className="stack"><Notice intent="error">{query.error.message}</Notice><Button icon={<ArrowClockwise24Regular />} onClick={query.refresh}>إعادة المحاولة</Button></div>;
    return children;
}
export function RecordTable({ columns, rows = [], empty, caption, minWidth }) {
    if (!rows.length) return <Empty {...empty} />;
    return <div className="table-scroll" role="region" aria-label={caption ?? 'جدول السجلات'} tabIndex={0}>
        <Table style={minWidth ? { minWidth, tableLayout: 'auto' } : undefined} aria-label={caption ?? 'جدول السجلات'} size="medium">
            <TableHeader><TableRow>{columns.map(c => <TableHeaderCell key={c.key}>{c.label}</TableHeaderCell>)}</TableRow></TableHeader>
            <TableBody>{rows.map((row, i) => <TableRow key={row.uuid ?? i}>{columns.map(c => <TableCell key={c.key}>{c.render ? c.render(row) : row[c.key] ?? '—'}</TableCell>)}</TableRow>)}</TableBody>
        </Table>
    </div>;
}
export function PersonCell({ employee, compact = false }) {
    return <TableCellLayout media={<Avatar name={employeeName(employee)} size={compact ? 28 : 32} color="neutral" />}>
        <div className="person-name">{employeeName(employee)}</div><div className="caption">{employee.position?.title || 'لم تحدد الوظيفة'}</div>
    </TableCellLayout>;
}
export function Pager({ meta, setPage }) {
    if (!meta) return null;
    return <div className="pagination"><span className="caption">صفحة {meta.current_page} من {meta.last_page}{meta.total !== undefined && ` · ${meta.total} سجل`}</span><div className="actions"><Button disabled={meta.current_page <= 1} onClick={() => setPage(meta.current_page - 1)}>السابق</Button><Button disabled={meta.current_page >= meta.last_page} onClick={() => setPage(meta.current_page + 1)}>التالي</Button></div></div>;
}
export function FormDialog({ title, fields, initial = {}, save, onClose, onSaved, success, description }) {
    const [values, setValues] = useState(() => Object.fromEntries(fields.map(f => [f.name, initial[f.name] ?? f.default ?? ''])));
    const [busy, setBusy] = useState(false), [error, setError] = useState('');
    const inFlight = useRef(false), lastAttempt = useRef({ signature: '', key: '' });
    const change = (field, value) => setValues(previous => ({ ...previous, [field.name]: value, ...Object.fromEntries((field.clearOnChange ?? []).map(name => [name, ''])) }));
    async function submit(event) {
        event.preventDefault();
        if (inFlight.current) return;
        const data = Object.fromEntries(fields.map(f => [f.name, values[f.name] === '' ? (f.nullable ? null : undefined) : f.boolean ? values[f.name] === 'true' : values[f.name]]).filter(([, value]) => value !== undefined));
        const signature = JSON.stringify(data);
        if (lastAttempt.current.signature !== signature) lastAttempt.current = { signature, key: requestKey() };
        inFlight.current = true; setBusy(true); setError('');
        try { await save(data, lastAttempt.current.key); onSaved(success ?? 'تم الحفظ بنجاح'); }
        catch (error) { setError(error.message); }
        finally { inFlight.current = false; setBusy(false); }
    }
    return <Dialog open onOpenChange={(_, data) => { if (!data.open && !inFlight.current) onClose(); }}>
        <DialogSurface className="form-dialog"><form onSubmit={submit}>
            <DialogBody><DialogTitle action={<Button appearance="subtle" icon={<Dismiss24Regular />} aria-label="إغلاق" disabled={busy} onClick={onClose} />}>{title}</DialogTitle>
                <DialogContent>{description && <p className="muted form-description">{description}</p>}<div className="form-grid">{fields.map(f => {
                    const common = { name: f.name, value: values[f.name], required: !!f.required, disabled: busy || f.disabled };
                    const choices = typeof f.options === 'function' ? f.options(values) : f.options;
                    if (f.type === 'permissions') return <fieldset key={f.name} className="permission-fieldset span-two"><legend>{f.label}</legend><div className="permission-options">{(choices ?? []).map(o => <Checkbox key={o.value} label={o.label} checked={(values[f.name] ?? []).includes(o.value)} disabled={busy} onChange={(_, d) => change(f, d.checked ? [...(values[f.name] ?? []), o.value] : (values[f.name] ?? []).filter(x => x !== o.value))} />)}</div></fieldset>;
                    return <Field key={f.name} label={f.label} required={f.required} hint={f.hint} className={f.wide ? 'span-two' : ''}>
                        {f.type === 'permissions' ? <div className="permission-options">{(choices ?? []).map(o => <Checkbox key={o.value} label={o.label} checked={(values[f.name] ?? []).includes(o.value)} disabled={busy} onChange={(_, d) => change(f, d.checked ? [...(values[f.name] ?? []), o.value] : (values[f.name] ?? []).filter(x => x !== o.value))} />)}</div> : choices ? <Select {...common} onChange={e => change(f, e.target.value)}>{!f.required || f.placeholder ? <option value="">{f.placeholder ?? 'بدون تحديد'}</option> : null}{choices.map(o => <option key={o.value} value={o.value}>{o.label}</option>)}</Select> : f.type === 'textarea' ? <Textarea {...common} maxLength={f.maxLength} resize="vertical" onChange={(_, d) => change(f, d.value)} /> : <Input {...common} type={f.type ?? 'text'} min={f.min} max={f.max} step={f.step} maxLength={f.maxLength} onChange={(_, d) => change(f, d.value)} />}
                    </Field>;
                })}</div>{error && <div className="form-message"><Notice intent="error">{error}</Notice></div>}</DialogContent>
                <DialogActions><Button disabled={busy} onClick={onClose}>إلغاء</Button><Button appearance="primary" type="submit" disabled={busy} icon={busy ? <Spinner size="tiny" /> : undefined}>{busy ? 'جارٍ الحفظ…' : 'حفظ'}</Button></DialogActions>
            </DialogBody>
        </form></DialogSurface>
    </Dialog>;
}
export function SectionCard({ title, action, children, className = '' }) {
    return <Card className={`section-card ${className}`}><div className="section-heading"><h2>{title}</h2>{action}</div>{children}</Card>;
}
