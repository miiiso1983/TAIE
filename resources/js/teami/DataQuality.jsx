import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { api } from './api';
import { FormDialog, Notice, Pager, QueryState, RecordTable, SearchableSelect, SectionCard, useQuery } from './components';
import { exactMoney } from './Analytics';
import { rowsOf } from './Launch';

const states = { matched: 'المجاميع متطابقة', mismatched: 'توجد فروقات', stale: 'تغيرت البيانات — أعد المصالحة' };
const kinds = { invoices: 'الفواتير قبل المرتجعات', receipts: 'تحصيلات الفواتير المحددة', returns: 'مرتجعات الفواتير المحددة' };
export function DataQuality({ can, notify, filters }) {
    const [page, setPage] = useState(1), [selected, setSelected] = useState(null), [create, setCreate] = useState(false), [detailed, setDetailed] = useState(false);
    const sources = useQuery('/advisor/data/sources'), history = useQuery('/advisor/data/reconciliations?page=' + page), detail = useQuery(selected ? '/advisor/data/reconciliations/' + selected : null);
    const choices = (Array.isArray(sources.data?.data) ? sources.data.data : []).map(x => ({ value: x, label: x }));
    const report = detail.data?.data, snap = report?.snapshot;
    const fields = [
        { name: 'source_system', label: 'مصدر البيانات للمصالحة', required: true, options: choices, placeholder: 'ابحث واختر المصدر' },
        { name: 'currency', label: 'عملة المصالحة', required: true, options: [{ value: 'USD', label: 'USD' }, { value: 'IQD', label: 'IQD' }], placeholder: 'اختر العملة' },
        { name: 'from', label: 'فواتير المصالحة من', type: 'date', required: true }, { name: 'to', label: 'فواتير المصالحة إلى', type: 'date', required: true }, { name: 'as_of', label: 'الحركات حتى', type: 'date', required: true },
        ...[['invoice', 'الفواتير'], ['receipt', 'التحصيلات'], ['return', 'المرتجعات']].flatMap(([key, label]) => [
            { name: key + '_count', label: 'عدد ' + label + ' في المصدر', type: 'number', min: 0, max: 50000, step: 1, required: true },
            { name: key + '_amount', label: 'إجمالي ' + label + ' في المصدر', required: true, maxLength: 24, hint: 'مبلغ عشري دون فواصل؛ أدخل صفر عند عدم وجود حركات. استخدم نفس نطاق الفواتير وتاريخ القياس.' },
        ]),
        { name: 'evidence_reference', label: 'مرجع تقرير المصدر', required: true, maxLength: 255, hint: 'اسم أو رقم تقرير المصدر الذي راجعته؛ لا يُرفع ملف في هذه الخطوة.' },
        { name: 'review_note', label: 'ملاحظة مراجعة المصدر', type: 'textarea', wide: true, required: true, maxLength: 1000, hint: '12 حرفاً على الأقل. يُحفظ النص مشفراً.' },
        { name: 'acknowledge', label: 'تأكيد نطاق المقارنة', required: true, boolean: true, placeholder: 'اختر التأكيد', options: [{ value: 'true', label: 'راجعت المصدر بنفس نطاق الفواتير والعملة وتاريخ القياس' }] },
    ];
    const detailedFields = [...fields.filter(f => !/^(invoice|receipt|return)_(count|amount)$/.test(f.name)).map(f => f.name === 'evidence_reference' ? { ...f, hint: 'اسم أو رقم التقرير المستقل الذي رفعت سجلاته.' } : f), { name: 'file', label: 'ملف سجلات المصدر', type: 'file', accept: '.csv,.xlsx', required: true, wide: true, hint: 'CSV UTF-8 أو XLSX بقيم ثابتة. حتى 1000 سجل و1MiB؛ الورقة الأولى. حافظ على الأصفار في بداية المعرفات.' }];
    if (sources.error?.status === 503) return <SectionCard title="مصالحة البيانات مع المصدر"><Notice intent="warning">مطابقة المصدر متاحة بعد إكمال ترقية النظام.</Notice></SectionCard>;
    return <SectionCard title="مصالحة البيانات مع المصدر" action={can('advisor.data.review') && <div className="toolbar"><Button disabled={!choices.length} onClick={() => setCreate(true)}>مصالحة جديدة</Button><Button appearance="primary" disabled={!choices.length} onClick={() => setDetailed(true)}>مطابقة السجلات</Button></div>}>
        <Button appearance="subtle" onClick={downloadEvidenceTemplate}>تنزيل قالب مطابقة السجلات</Button>
        <Notice>المقارنة تخص فواتير المصدر خلال الفترة، وتحصلاتها ومرتجعاتها حتى تاريخ القياس، بما فيها الحركات بعد نهاية الفترة. تطابق الأعداد والمبالغ وحده لا يثبت تطابق كل سجل أو اكتمال بيانات الشركة، ولا يعتمد مكافأة.</Notice>
        <QueryState query={sources}>{!choices.length && <p className="muted">استورد الفواتير أولاً لتظهر مصادر المقارنة.</p>}</QueryState>
        <QueryState query={history}><RecordTable caption="سجل المصالحة" minWidth={850} rows={rowsOf(history.data)} columns={[{ key: 'source_system', label: 'المصدر' }, { key: 'from', label: 'من' }, { key: 'to', label: 'إلى' }, { key: 'as_of', label: 'الحركات حتى' }, { key: 'currency', label: 'العملة' }, { key: 'status', label: 'نتيجة وقت الحفظ', render: x => x.method === 'record_matching' && x.status === 'matched' ? 'السجلات متطابقة' : states[x.status] }, { key: 'open', label: 'التفاصيل', render: x => <Button onClick={() => { setSelected(x.uuid); detail.refresh(); }}>مراجعة الفروقات</Button> }]} /><Pager meta={history.data?.data} setPage={setPage} /></QueryState>
        {selected && <div className="stack"><Button onClick={() => setSelected(null)}>إغلاق المصالحة</Button><QueryState query={detail}>{snap && <>
            <Notice intent={report.current_status === 'matched' ? 'success' : 'warning'}>{report.method === 'record_matching' && report.current_status === 'matched' ? 'السجلات متطابقة' : states[report.current_status]}. يعرض الجدول نسخة المقارنة المحفوظة؛ أعد المصالحة عند تغير البيانات.</Notice>
            {report.method === 'record_matching' && <RecordDifferences key={report.uuid} report={report} />}
            <p>{report.evidence_reference} · {report.review_note}</p>
            <RecordTable caption="فروقات المصدر والسجلات المستوردة" minWidth={950} rows={Object.entries(kinds).map(([key, label]) => ({ uuid: key, label, expected: snap.expected[key], actual: snap.actual[key], difference: snap.differences[key] }))} columns={[
                { key: 'label', label: 'النوع' }, { key: 'expected_count', label: 'عدد المصدر', render: x => x.expected.count }, { key: 'actual_count', label: 'عدد المستورد', render: x => x.actual.count }, { key: 'count_difference', label: 'فرق العدد', render: x => x.difference.count },
                { key: 'expected_amount', label: 'مبلغ المصدر', render: x => exactMoney(x.expected.amount_minor, report.currency) }, { key: 'actual_amount', label: 'مبلغ المستورد', render: x => exactMoney(x.actual.amount_minor, report.currency) }, { key: 'amount_difference', label: 'فرق المبلغ', render: x => exactMoney(x.difference.amount_minor, report.currency) },
            ]} />
            <p className="muted">جاهزية البيانات الحالية ضمن النطاق: {report.current.readiness.invoices_without_area} فاتورة بلا منطقة محفوظة، {report.current.readiness.invoices_without_lines} فاتورة بلا سطور، {report.current.readiness.invoices_with_line_difference} فاتورة لا تطابق سطورها مبلغها. هذه مؤشرات تجهيز بيانات؛ لم تُنفذ توقعات المنتجات بعد.</p>
        </>}</QueryState></div>}
        {detailed && <FormDialog title="مطابقة سجلات تقرير المصدر" description="ارفع تقريراً مستقلاً يضم جميع فواتير الفترة وتحصلاتها ومرتجعاتها حتى تاريخ القياس. تُحفظ أدلة المقارنة مشفرة، دون تعديل سجلات الشركة. تطابق التقرير لا يؤكد تلقائياً اكتمال بيانات الشركة." fields={detailedFields} initial={{ from: filters.from, to: filters.to, as_of: filters.as_of, currency: filters.currency || '' }} save={(data, key) => { const form = new FormData(); Object.entries(data).forEach(([name, value]) => form.append(name, typeof value === 'boolean' ? (value ? '1' : '0') : value)); return api('/advisor/data/reconciliations/detailed', { method: 'POST', data: form, key }); }} onClose={() => setDetailed(false)} onSaved={message => { setDetailed(false); history.refresh(); notify(message); }} />}
        {create && <FormDialog title="مقارنة مجاميع تقرير المصدر" description="انسخ أعداد ومبالغ تقرير المصدر المستقل، بنفس نطاق الفواتير والعملة وتاريخ القياس. لا تستخدم الأرقام المعروضة في Teami لإثبات تطابق المصدر." fields={fields} initial={{ from: filters.from, to: filters.to, as_of: filters.as_of, currency: filters.currency || '' }} save={(data, key) => api('/advisor/data/reconciliations', { method: 'POST', data, key })} onClose={() => setCreate(false)} onSaved={message => { setCreate(false); history.refresh(); notify(message); }} />}
    </SectionCard>;
}

const catalogNames = { products: 'المنتجات', areas: 'المحافظات والمدن المسجلة', customer_locations: 'مواقع العملاء', invoice_lines: 'سطور الفواتير' };
export function BusinessCatalog() {
    const [kind, setKind] = useState('products'), [page, setPage] = useState(1);
    const q = useQuery(`/advisor/data/catalog?kind=${kind}&page=${page}`);
    const columns = {
        products: [{ key: 'code', label: 'رمز المنتج' }, { key: 'name', label: 'المنتج' }, { key: 'base_unit', label: 'الوحدة الأساسية' }],
        areas: [{ key: 'code', label: 'رمز المنطقة' }, { key: 'province_name', label: 'المحافظة' }, { key: 'city_name', label: 'المدينة' }],
        customer_locations: [{ key: 'customer_number', label: 'رقم العميل' }, { key: 'name', label: 'العميل' }, { key: 'area_code', label: 'رمز المنطقة' }, { key: 'province_name', label: 'المحافظة' }, { key: 'city_name', label: 'المدينة' }],
        invoice_lines: [{ key: 'source_system', label: 'المصدر' }, { key: 'invoice_external_id', label: 'الفاتورة' }, { key: 'external_id', label: 'رمز السطر' }, { key: 'product_code', label: 'المنتج' }, { key: 'quantity', label: 'الكمية' }, { key: 'base_unit', label: 'الوحدة' }, { key: 'amount', label: 'صافي السطر', render: x => exactMoney(x.net_amount_minor, x.currency) }],
    };
    return <SectionCard title="بيانات المنتجات والمناطق"><SearchableSelect aria-label="عرض بيانات المنتجات والمناطق" value={kind} onChange={e => { setKind(e.target.value); setPage(1); }}>{Object.entries(catalogNames).map(([key, label]) => <option key={key} value={key}>{label}</option>)}</SearchableSelect>
        <QueryState query={q}><RecordTable caption={catalogNames[kind]} rows={rowsOf(q.data)} columns={columns[kind]} minWidth={kind === 'invoice_lines' ? 950 : 600} /><Pager meta={q.data?.data} setPage={setPage} /></QueryState>
        <p className="muted">تُضاف هذه البيانات من «بيانات الشركة». المناطق أسماء وأكواد تعتمدها الشركة داخل العراق، وليست قاعدة حدود إدارية أو بيانات سكان رسمية. موقع الفاتورة يُحفظ عند استيرادها؛ ربط عميل لاحقاً لا يغيّر الفواتير السابقة.</p>
    </SectionCard>;
}

const differenceNames = { missing_in_teami: 'مفقود في Teami', unexpected_in_teami: 'زائد في Teami', changed_fields: 'حقول مختلفة', matched: 'متطابق' };
const fieldNames = { invoice_external_id: 'الفاتورة المرتبطة', record_date: 'التاريخ', due_date: 'الاستحقاق', customer_number: 'العميل', employee_number: 'الموظف', currency: 'العملة', amount_minor: 'المبلغ' };
function RecordDifferences({ report }) {
    const [page, setPage] = useState(1), [type, setType] = useState('');
    const q = useQuery(`/advisor/data/reconciliations/${report.uuid}/differences?page=${page}${type ? '&type=' + type : ''}`);
    return <div className="stack">
        <p>{Object.entries(report.snapshot.record_counts).map(([key, value]) => `${differenceNames[key]}: ${value}`).join(' · ')}</p>
        <Notice>هذه فروقات السجلات وقت الحفظ. المجاميع أدناه تخص نطاق الفواتير والعملة فقط؛ تُفحص أيضاً معرّفات المصدر خارج النطاق لاكتشاف تغير التاريخ أو العملة أو الفاتورة المرتبطة.</Notice>
        <SearchableSelect aria-label="نوع فروقات السجلات" value={type} onChange={e => { setType(e.target.value); setPage(1); }}><option value="">جميع الفروقات</option>{Object.entries(differenceNames).filter(([key]) => key !== 'matched').map(([key, label]) => <option key={key} value={key}>{label}</option>)}</SearchableSelect>
        <QueryState query={q}><RecordTable caption="مطابقة السجلات المحفوظة" minWidth={850} rows={rowsOf(q.data).map(x => ({ ...x, uuid: x.kind + '|' + x.external_id }))} columns={[
            { key: 'kind', label: 'النوع', render: x => kinds[x.kind] }, { key: 'external_id', label: 'معرّف المصدر' }, { key: 'type', label: 'الفارق', render: x => differenceNames[x.type] },
            { key: 'fields', label: 'المصدر ← المستورد', render: x => x.type === 'changed_fields' ? <ul>{Object.entries(x.fields).map(([name, values]) => <li key={name}>{fieldNames[name]}: <bdi>{name === 'amount_minor' ? exactMoney(values.source, x.source.currency) : values.source ?? '—'}</bdi> ← <bdi>{name === 'amount_minor' ? exactMoney(values.imported, x.imported.currency) : values.imported ?? '—'}</bdi></li>)}</ul> : <span>{(x.source ?? x.imported).record_date} · {exactMoney((x.source ?? x.imported).amount_minor, (x.source ?? x.imported).currency)}</span> },
        ]} /><Pager meta={q.data?.data} setPage={setPage} /></QueryState>
    </div>;
}
function downloadEvidenceTemplate() {
    const text = 'kind,external_id,invoice_external_id,record_date,due_date,customer_number,employee_number,currency,amount\n';
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv;charset=utf-8' }));
    const link = document.createElement('a'); link.href = url; link.download = 'reconciliation-evidence.csv'; link.click(); URL.revokeObjectURL(url);
}

export function InventoryCatalog() {
 const [kind,setKind]=useState('stock_snapshots'),[page,setPage]=useState(1),q=useQuery('/advisor/data/inventory?kind='+kind+'&page='+page);
 const names={stock_snapshots:'أرصدة المخزون',product_costs:'تكلفة الوحدة',stockouts:'فترات الانقطاع',return_lines:'كميات المرتجعات'};
 return <SectionCard title="مدخلات المخزون والتكلفة والمرتجعات"><Notice>سجلات تحليلية مستوردة بوحدة المنتج الأساسية. لا تحرّك المخزون ولا تنشئ قيداً مالياً، ولا تثبت اكتمال حركة المخزون.</Notice><SearchableSelect value={kind} onChange={e=>{setKind(e.target.value);setPage(1);}} aria-label="نوع مدخلات المخزون">{Object.entries(names).map(([v,label])=><option key={v} value={v}>{label}</option>)}</SearchableSelect><QueryState query={q}><RecordTable caption={names[kind]} rows={rowsOf(q.data)} columns={[{key:'external_id',label:'رقم المصدر'},{key:'source_system',label:'النظام'},{key:'product_code',label:'المنتج'},{key:'area_code',label:'المنطقة'},{key:'observed_on',label:'التاريخ',render:x=>x.observed_on?.slice(0,10)},{key:'end_date',label:'نهاية الانقطاع',render:x=>x.end_date?.slice(0,10)??'—'},{key:'quantity',label:'الكمية',render:x=>x.quantity===null?'—':x.quantity+' '+x.base_unit},{key:'amount_minor',label:'المبلغ',render:x=>x.amount_minor===null?'—':exactMoney(x.amount_minor,x.currency)}]}/><Pager meta={q.data?.data} setPage={setPage}/></QueryState></SectionCard>;
}

export function GeographyContext() {
 const [code,setCode]=useState(''),q=useQuery('/advisor/data/geography'),d=q.data?.data;
 return <SectionCard title="السياق السكاني — مصدر مؤرخ"><QueryState query={q}>{d&&<><Notice>{d.notice}</Notice><p>سنة القياس: {d.measurement_year} · تحقق من المصدر: {d.verified_at} · <a href={d.url} target="_blank" rel="noopener noreferrer">الجدول الرسمي</a></p><SearchableSelect aria-label="المحافظة في المصدر السكاني" value={code} onChange={e=>setCode(e.target.value)}><option value="">جميع المحافظات الواردة في الجدول</option>{d.rows.map(x=><option key={x.dataset_code} value={x.dataset_code}>{x.name}</option>)}</SearchableSelect><RecordTable caption="تقديرات السكان 2023 على مستوى المحافظة" rows={d.rows.filter(x=>!code||x.dataset_code===code).map(x=>({...x,uuid:x.dataset_code}))} columns={[{key:'name',label:'المحافظة'},{key:'year',label:'السنة'},{key:'population',label:'تقدير السكان',render:x=>x.population.toLocaleString('ar-IQ')}]}/></>}</QueryState></SectionCard>;
}
