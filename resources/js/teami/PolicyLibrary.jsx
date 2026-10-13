import React, { useRef, useState } from 'react';
import { Button, Card, Checkbox, Field, Input, Textarea } from '@fluentui/react-components';
import { api, requestKey } from './api';
import { Notice, PageHeading, QueryState, useQuery } from './components';

export function PolicyLibrary({ can, company, navigate }) {
    const catalog = useQuery('/knowledge/policy-templates');
    const [page, setPage] = useState(1);
    const drafts = useQuery(can('ai.knowledge.manage') ? `/knowledge/policy-drafts?page=${page}` : null);
    const [search, setSearch] = useState(''), [selection, setSelection] = useState(null), [title, setTitle] = useState(''), [body, setBody] = useState('');
    const [confirmed, setConfirmed] = useState(false), [busy, setBusy] = useState(false), [error, setError] = useState(''), [success, setSuccess] = useState('');
    const pending = useRef(null);
    const choose = item => { setSelection(item); setTitle(item.title); setBody(item.body.replaceAll('[اسم الشركة]', company.name)); setConfirmed(false); setError(''); setSuccess(''); pending.current = null; };
    const write = async (path, method, data) => {
        const signature = JSON.stringify([path, method, data]);
        if (pending.current?.signature !== signature) pending.current = { signature, key: requestKey() };
        setBusy(true); setError(''); setSuccess('');
        try {
            const response = await api(path, { method, key: pending.current.key, data });
            choose(response.data); setSuccess(response.data.status === 'approved' ? 'اعتمدت السياسة وأرسلت للفهرسة. تظهر للمساعد بعد اكتمال الفهرسة.' : 'حفظت المسودة؛ لا يستخدمها المساعد حتى اعتمادها.');
            drafts.refresh();
        } catch (e) { setError(e.message); } finally { setBusy(false); }
    };
    const save = () => write(selection.uuid ? `/knowledge/policy-drafts/${selection.uuid}` : '/knowledge/policy-drafts', selection.uuid ? 'PATCH' : 'POST', { title, body, ...(!selection.uuid ? { template_key: selection.key } : {}) });
    const list = drafts.data?.data;
    const immutable = selection?.status === 'approved';
    const saved = selection?.uuid && title === selection.title && body === selection.body;
    return <div className="stack"><PageHeading eyebrow="ابدأ بسياسات واضحة" title="قوالب سياسات الشركة" description="قوالب أصلية قابلة للتخصيص، تساعدك على البدء عندما لا توجد سياسات مكتوبة. المسودة ليست سياسة نافذة، ولا تدخل معرفة TAIE قبل اعتمادها."/>
        <Notice>راجع القالب حسب نشاط الشركة وموقعها والقانون والعقود المعمول بها. المراجع للتعلّم والمراجعة، ولا تعني أن القالب معتمد من الجهة المرجعية.</Notice>
        <Field label="ابحث عن قالب"><Input value={search} onChange={(_, data) => setSearch(data.value)} placeholder="مثلاً: إجازات، عملاء، خصومات…" /></Field>
        <QueryState query={catalog}><div className="policy-grid">{(catalog.data?.data ?? []).filter(item => `${item.title} ${item.category}`.includes(search.trim())).map(item => <Card key={item.key}><span className="caption">{item.category} · الإصدار {item.version}</span><h2>{item.title}</h2><p>{item.notice}</p><Button disabled={busy} onClick={() => choose(item)}>معاينة وتخصيص</Button></Card>)}</div></QueryState>
        {selection && <Card className="stack"><h2>{immutable ? 'سياسة معتمدة' : 'تخصيص القالب ومراجعته'}</h2><Field label="عنوان السياسة"><Input value={title} disabled={busy || immutable || !can('ai.knowledge.manage')} onChange={(_, data) => setTitle(data.value)} /></Field><Field label="نص السياسة"><Textarea value={body} disabled={busy || immutable || !can('ai.knowledge.manage')} onChange={(_, data) => setBody(data.value)} maxLength={30000} resize="vertical" rows={12} /></Field><p className="caption">استبدل الحقول بين الأقواس بمعلومات الشركة؛ لا تعتمد أرقامًا أو استحقاقات دون مراجعة.</p>{(selection.references ?? (catalog.data?.data ?? []).find(x => x.key === selection.template_key)?.references ?? []).map(ref => <a key={ref.url} href={ref.url} target="_blank" rel="noopener noreferrer">{ref.title}</a>)}
        {!immutable && can('ai.knowledge.manage') && <Button appearance="primary" disabled={busy || !title.trim() || body.trim().length < 20} onClick={save}>حفظ المسودة</Button>}
        {!immutable && selection.uuid && can('ai.knowledge.manage') && can('identity.membership.manage') && <><Checkbox checked={confirmed} disabled={busy} onChange={(_, data) => setConfirmed(data.checked)} label="راجعت السياسة والحقول والمتطلبات المحلية، وأعتمدها سياسة داخلية لهذه الشركة."/><Button disabled={busy || !confirmed || !saved || /\[[^\]]+\]/.test(body)} onClick={() => write(`/knowledge/policy-drafts/${selection.uuid}/approve`, 'POST', { confirmed: true })}>اعتماد وإضافة إلى معرفة الشركة</Button>{!saved && <p>احفظ تعديلاتك قبل الاعتماد.</p>}</>}
        {immutable && <><p>حالة فهرسة المستند: {selection.indexing_status === 'indexed' ? 'جاهز للمساعد' : selection.indexing_status === 'failed' ? 'تعثرت الفهرسة؛ راجع إدارة المعرفة لإعادة المحاولة' : 'قيد الفهرسة'}</p><Button onClick={() => navigate('knowledge')}>متابعة المستند في إدارة المعرفة</Button></>}
        {!can('ai.knowledge.manage') && <Notice>يمكنك معاينة القوالب. اطلب من مسؤول المعرفة تخصيصها وحفظها، ومن مسؤول إدارة المستخدمين المخوّل اعتمادها.</Notice>}</Card>}
        {error && <Notice intent="error">{error}</Notice>}{success && <Notice intent="success">{success}</Notice>}
        {can('ai.knowledge.manage') && <Card className="stack"><h2>مسودات وسياسات شركتي</h2><Button disabled={busy} onClick={drafts.refresh}>تحديث الحالة</Button><QueryState query={drafts}>{(list?.data ?? []).map(item => <div className="actions" key={item.uuid}><strong>{item.title}</strong><span>{item.status === 'approved' ? 'معتمدة' : 'مسودة'}</span><Button disabled={busy} onClick={() => choose(item)}>فتح</Button></div>)}</QueryState><div className="actions"><Button disabled={page === 1 || busy} onClick={() => setPage(x => x - 1)}>السابق</Button><Button disabled={page >= (list?.last_page ?? 1) || busy} onClick={() => setPage(x => x + 1)}>التالي</Button></div></Card>}
    </div>;
}
