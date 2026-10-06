import React, { useState } from 'react';
import { Button, Field, Textarea } from '@fluentui/react-components';
import { Add24Regular, PlugConnected24Regular } from '@fluentui/react-icons';
import { api, requestKey } from './api';
import { FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';

export function Integrations({ can, notify, navigate }) {
    const [page, setPage] = useState(1), [create, setCreate] = useState(false), [selected, setSelected] = useState(null);
    const [payload, setPayload] = useState(''), [preview, setPreview] = useState(null), [busy, setBusy] = useState(false), [error, setError] = useState('');
    const catalog = useQuery('/integrations/catalog'), connections = useQuery(`/integrations/connections?page=${page}`);
    const batches = useQuery(selected ? `/integrations/connections/${selected.uuid}/batches` : null);
    const canSync = can('integration.sync') && can('data.import.manage');
    const names = Object.fromEntries((catalog.data?.data ?? []).map(x => [x.key, x.name]));
    function select(x) { setSelected(x); setPayload(''); setPreview(null); setError(''); }
    async function run(action) {
        if (busy) return; setBusy(true); setError('');
        try {
            if (action === 'preview') {
                const data = JSON.parse(payload);
                const r = await api(`/integrations/connections/${selected.uuid}/preview`, { method: 'POST', data, key: requestKey() });
                setPreview(r.data); batches.refresh();
            } else if (action === 'commit') {
                await api(`/advisor/imports/${preview.uuid}/commit`, { method: 'POST', key: requestKey() });
                setPreview(null); batches.refresh(); notify('تم اعتماد بيانات المصدر');
            } else {
                const r = await api(`/integrations/connections/${selected.uuid}/status`, { method: 'PATCH', data: { status: selected.status === 'active' ? 'disabled' : 'active' }, key: requestKey() });
                setSelected(r.data); setPreview(null); connections.refresh(); notify('تم تحديث حالة المصدر');
            }
        } catch (e) { setError(e instanceof SyntaxError ? 'صيغة JSON غير صحيحة؛ راجع ملف البيانات.' : e.message); }
        finally { setBusy(false); }
    }
    return <div className="stack"><PageHeading eyebrow="الإدارة" title="التكاملات" description="مصادر بيانات شركتك، في مساحة واحدة ومن دون تكرار السجلات." action={can('integration.manage') && <Button appearance="primary" icon={<Add24Regular />} onClick={() => setCreate(true)}>تعريف مصدر</Button>} />
        <Notice>يتوفر استقبال البيانات بصيغة موحّدة بعد تحويلها من البرنامج المصدر. الربط المباشر مع API الخاص بكل برنامج يحتاج محوّلاً يُعد حسب الإصدار؛ تعريف المصدر هنا لا يفعّل اتصالاً خارجياً تلقائياً.</Notice>
        <SectionCard title="مصادر البيانات" action={<PlugConnected24Regular />}><QueryState query={connections}><RecordTable caption="مصادر التكامل" rows={connections.data?.data?.data} columns={[
            { key: 'name', label: 'المصدر' }, { key: 'provider', label: 'البرنامج', render: x => names[x.provider] || x.provider },
            { key: 'status', label: 'الاستقبال', render: x => x.status === 'active' ? 'مفعّل' : 'متوقف' },
            { key: 'transport', label: 'طريقة الربط', render: () => 'API موحّد — يحتاج محوّل' },
            { key: 'actions', label: 'الإجراءات', render: x => <Button disabled={busy} onClick={() => select(x)}>التفاصيل والدفعات</Button> },
        ]} empty={{ title: 'اربط بيانات شركتك', text: 'عرّف Teami Pro أو MaxCon أو برنامجاً آخر كمصدر مستقل.' }} /><Pager meta={connections.data?.data} setPage={setPage} /></QueryState></SectionCard>
        {selected && <SectionCard title={selected.name} action={can('integration.manage') && <Button disabled={busy} onClick={() => run('status')}>{selected.status === 'active' ? 'إيقاف الاستقبال' : 'تفعيل الاستقبال'}</Button>}>
            <p className="muted">تُفحص الدفعة قبل اعتمادها. الموظفون والعملاء يُطابقون بأرقامهم داخل الشركة؛ الفواتير والتحصيل والمرتجعات تُطابق بمعرّفها داخل المصدر.</p>
            {canSync && <><Field label="بيانات الدفعة بصيغة JSON" hint="استخدم schema_version وkind وrecords وفق دليل التكامل. المبالغ نصوص عشرية، ولا تُرسل كلمات مرور أو مفاتيح API هنا."><Textarea value={payload} disabled={busy || selected.status !== 'active'} resize="vertical" onChange={(_, d) => { setPayload(d.value); setPreview(null); }} /></Field><Button disabled={busy || !payload || selected.status !== 'active'} onClick={() => run('preview')}>فحص الدفعة</Button></>}
            {error && <Notice intent="error">{error}</Notice>}{preview && <><Notice intent={preview.error_count ? 'warning' : 'info'}>{preview.status === 'imported' ? 'هذه الدفعة مستوردة سابقاً.' : `${preview.row_count} سجل · ${preview.error_count} خطأ. راجع النتائج قبل الاعتماد.`}</Notice>{preview.errors?.length > 0 && <pre className="integration-errors" dir="ltr">{JSON.stringify(preview.errors, null, 2)}</pre>}{preview.status === 'validated' && <Button appearance="primary" disabled={busy || selected.status !== 'active'} onClick={() => run('commit')}>اعتماد الدفعة</Button>}</>}
            <QueryState query={batches}><RecordTable caption="دفعات التكامل" rows={batches.data?.data?.data} columns={[{ key: 'kind', label: 'البيانات' }, { key: 'status', label: 'الحالة' }, { key: 'row_count', label: 'السجلات' }, { key: 'error_count', label: 'الأخطاء' }, { key: 'committed_at', label: 'وقت الاعتماد' }]} empty={{ title: 'لا توجد دفعات', text: 'ستظهر الدفعات هنا عند استقبال البيانات من هذا المصدر.' }} /></QueryState>
        </SectionCard>}
        {can('data.import.manage') && <Button appearance="subtle" onClick={() => navigate('imports')}>استيراد ملفات CSV أو Excel بدلاً من API</Button>}
        {create && <FormDialog title="تعريف مصدر بيانات" fields={[{ name: 'name', label: 'اسم المصدر', required: true, maxLength: 120 }, { name: 'provider', label: 'البرنامج', required: true, placeholder: 'اختر البرنامج', options: (catalog.data?.data ?? []).map(x => ({ value: x.key, label: x.name })) }]} onClose={() => setCreate(false)} save={(data, key) => api('/integrations/connections', { method: 'POST', data, key })} onSaved={m => { setCreate(false); connections.refresh(); notify(m); }} />}
    </div>;
}
