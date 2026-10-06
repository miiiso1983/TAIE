import { ExternalReferences } from './ExternalReferences';
import React, { useRef, useState } from 'react';
import { Avatar, Badge, Button, Card, CardHeader, Divider, Field, Input, Spinner, Textarea } from '@fluentui/react-components';
import {
    Add24Regular, ArrowLeft24Regular, Building24Regular, CalendarLtr24Regular,
    People24Regular, TargetArrow24Regular, Sparkle24Regular, BookOpen24Regular,
    Wallet24Regular, Send24Regular, DocumentText24Regular,
} from '@fluentui/react-icons';
import { api, requestKey } from './api';
import { KnowledgeManager } from './Launch';
import { Empty, employeeName, FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, Status, useQuery } from './components';

function Metric({ label, value, icon, hint, tone = 'blue' }) {
    return <Card className="metric-card"><div className="metric-heading"><span>{label}</span><div className={`metric-icon ${tone}`}>{icon}</div></div><strong className="metric-value">{value}</strong><span className="caption">{hint}</span></Card>;
}
export function Home({ can, company, user, navigate }) {
    const employees = useQuery(can('hr.employee.read') ? '/hr/employees?per_page=5' : null);
    const customers = useQuery(can('sales.customer.read') ? '/sales/customers?per_page=1' : null);
    const opportunities = useQuery(can('sales.opportunity.read') ? '/sales/opportunities?per_page=1' : null);
    const leave = useQuery(can('hr.leave.read') ? '/hr/leave-requests?status=pending&per_page=1' : null);
    const subscription = useQuery(can('billing.subscription.read') ? '/billing/subscription' : null);
    const count = q => q.loading ? '…' : q.error || !q.data ? '—' : q.data.meta?.total ?? q.data.data?.length ?? 0;
    const firstName = user.name?.split(' ')[0] || 'بك';
    return <div className="stack dashboard"><PageHeading eyebrow="نظرة عامة" title={`أهلاً ${firstName}`} description={`إليك ما تحتاجه لإدارة فريق ${company.name} اليوم.`} action={<span className="today"><CalendarLtr24Regular />{new Intl.DateTimeFormat('ar-IQ', { weekday: 'long', month: 'long', day: 'numeric' }).format(new Date())}</span>} />
        <section className="editorial-hero" aria-label="مساحة فريقك"><div className="hero-copy"><span className="hero-kicker">TEAMi / WORK, IN PERSPECTIVE</span><h2>مساحة أهدأ.<br />عمل أكثر وضوحاً.</h2><p>فريقك، علاقاتك ومعرفة شركتك.<br />كل ما تحتاجه ليبدأ يومك بثقة.</p><Button appearance="primary" icon={<ArrowLeft24Regular />} iconPosition="after" disabled={!can('ai.ask') && !can('ai.knowledge.read')} onClick={() => navigate('knowledge')}>اكتشف مساعد Teami</Button></div><div className="hero-caption"><span>فريق مترابط، وقرارات تستند إلى المعرفة.</span></div></section><section className="metric-grid" aria-label="ملخص مساحة العمل"><Metric label="الموظفون" value={count(employees)} icon={<People24Regular />} hint="إجمالي ملفات الفريق" /><Metric label="العملاء" value={count(customers)} icon={<Building24Regular />} tone="teal" hint="ضمن قاعدة العملاء" /><Metric label="الفرص التجارية" value={count(opportunities)} icon={<TargetArrow24Regular />} tone="purple" hint="إجمالي الفرص المسجلة" /><Metric label="إجازات قيد المراجعة" value={count(leave)} icon={<CalendarLtr24Regular />} tone="amber" hint="طلبات تنتظر قراراً" /></section>
        {[employees, customers, opportunities, leave].some(q => q.error) && <Notice>تعذر تحميل بعض المؤشرات. افتح القسم المعني للاطلاع على السبب أو أعد تحميل الصفحة.</Notice>}
        <section className="home-grid"><SectionCard title="دليل الفريق" action={can('hr.employee.read') && <Button appearance="subtle" icon={<ArrowLeft24Regular />} iconPosition="after" onClick={() => navigate('employees')}>عرض الجميع</Button>}>
            {can('hr.employee.read') ? <QueryState query={employees}>{employees.data?.data?.length ? <div className="team-list">{employees.data.data.map(x => <div className="team-row" key={x.uuid}><Avatar name={employeeName(x)} size={40} color="neutral" /><div className="grow"><strong>{employeeName(x)}</strong><span className="caption">{x.position?.title || 'لم تحدد الوظيفة'} · {x.department?.name || x.employee_number}</span></div><Status value={x.employment_status} /></div>)}</div> : <Empty title="لنبدأ بفريقك" text="أضف موظفي شركتك لتظهر ملفاتهم هنا." action={can('hr.employee.create') && <Button onClick={() => navigate('employees')}>إدارة الموظفين</Button>} />}</QueryState> : <Empty title="دليل الفريق" text="لا يتضمن دورك صلاحية قراءة ملفات الموظفين." />}
        </SectionCard><Card className="assistant-card"><div className="assistant-mark"><Sparkle24Regular /></div><Badge appearance="tint" color="brand">مساعد الشركة</Badge><h2>معرفة فريقك، أقرب إليك.</h2><p>اعثر على الإجابات من السياسات والإجراءات الموجودة في مصادر المعرفة المصرّح لك بها.</p><div className="assistant-sample"><BookOpen24Regular /><span>ما هي سياسة الإجازات في شركتنا؟</span></div><Button appearance="primary" icon={<Sparkle24Regular />} disabled={!can('ai.ask') && !can('ai.knowledge.read')} onClick={() => navigate('knowledge')}>افتح مساعد Teami</Button><span className="caption">الإجابات تعتمد على مستندات شركتك المفهرسة.</span></Card></section>
        <SectionCard title="مسارات العمل" action={<span className="caption">ابدأ من المهمة التي تحتاجها</span>}><div className="quick-grid">{[
            ['employees', 'إدارة فريقك', 'ملفات الموظفين والوظائف', <People24Regular />, can('hr.employee.read')],
            ['leave', 'متابعة الإجازات', 'الطلبات والموافقات والأرصدة', <CalendarLtr24Regular />, can('hr.leave.read')],
            ['customers', 'علاقات العملاء', 'قاعدة بيانات عملائك', <Building24Regular />, can('sales.customer.read')],
            ['opportunities', 'الفرص التجارية', 'مراحل البيع والقيم المتوقعة', <TargetArrow24Regular />, can('sales.opportunity.read')],
        ].map(([page, title, text, icon, enabled]) => <Button key={page} appearance="subtle" className="quick-action" disabled={!enabled} onClick={() => navigate(page)}><span className="quick-icon">{icon}</span><span className="quick-label"><strong>{title}</strong><small>{text}</small></span><ArrowLeft24Regular /></Button>)}</div></SectionCard>
        <div className="workspace-foot"><span><span className="status-dot" />مساحة {company.name}</span><span>{subscription.data?.data ? `الخطة: ${subscription.data.data.plan.name}` : 'بياناتك ضمن نطاق شركتك وصلاحياتك'}</span></div>
    </div>;
}
export function Customers({ can, notify }) {
    const [page, setPage] = useState(1), [create, setCreate] = useState(null);
    const q = useQuery(`/sales/customers?per_page=25&page=${page}`);
    const fields = [
        { name: 'customer_number', label: 'رقم العميل', required: true, maxLength: 60 }, { name: 'name', label: 'اسم العميل', required: true, maxLength: 255 },
        { name: 'type', label: 'نوع العميل', required: true, default: 'organization', options: [{ value: 'organization', label: 'شركة / مؤسسة' }, { value: 'individual', label: 'فرد' }] },
        { name: 'industry', label: 'القطاع', maxLength: 120 }, { name: 'email', label: 'البريد الإلكتروني', type: 'email' }, { name: 'phone', label: 'الهاتف', maxLength: 50 },
        { name: 'country_code', label: 'رمز الدولة', maxLength: 2, hint: 'مثال: IQ' }, { name: 'website', label: 'الموقع الإلكتروني', type: 'url' },
    ];
    return <div className="stack"><PageHeading eyebrow="المبيعات" title="العملاء" description="علاقات عملك تبدأ ببيانات منظمة وواضحة." action={can('sales.customer.create') && <Button appearance="primary" icon={<Add24Regular />} onClick={() => setCreate({})}>إضافة عميل</Button>} />
        <Card className="data-card"><div className="list-heading"><h2>دليل العملاء</h2>{q.data?.meta?.total !== undefined && <Badge appearance="tint" color="brand">{q.data.meta.total} عميل</Badge>}</div><QueryState query={q}><RecordTable caption="دليل العملاء" rows={q.data?.data} columns={[
            { key: 'name', label: 'العميل', render: x => <div className="person-inline"><Avatar name={x.name} size={32} color="neutral" /><strong>{x.name}</strong></div> }, { key: 'customer_number', label: 'الرقم' }, { key: 'type', label: 'النوع', render: x => x.type === 'organization' ? 'شركة / مؤسسة' : 'فرد' }, { key: 'industry', label: 'القطاع' }, { key: 'email', label: 'البريد الإلكتروني' }, { key: 'country_code', label: 'الدولة' }, ...(can('sales.customer.update') ? [{key:'actions',label:'الإجراءات',render:x=><Button onClick={()=>setCreate(x)}>تعديل</Button>}] : []),
        ]} empty={{ title: 'قاعدة عملائك تبدأ هنا', text: 'أضف أول عميل لتبدأ تنظيم علاقات المبيعات.' }} /><Pager meta={q.data?.meta} setPage={setPage} /></QueryState></Card>
        {create && <FormDialog title={create.uuid ? "تعديل العميل" : "إضافة عميل"} initial={create} fields={fields} onClose={() => setCreate(false)} save={(data, key) => api('/sales/customers' + (create.uuid ? `/${create.uuid}` : ''), { method: create.uuid ? 'PATCH' : 'POST', data, key })} onSaved={message => { setCreate(false); q.refresh(); notify(message); }} />}
    </div>;
}
export function Opportunities() {
    const [page, setPage] = useState(1), q = useQuery(`/sales/opportunities?per_page=25&page=${page}`);
    return <div className="stack"><PageHeading eyebrow="المبيعات" title="الفرص التجارية" description="تابع مراحل البيع والفرص والقيم المسجلة." /><Card className="data-card"><QueryState query={q}><RecordTable caption="الفرص التجارية" rows={q.data?.data} columns={[
        { key: 'name', label: 'الفرصة', render: x => <strong>{x.name}</strong> }, { key: 'customer', label: 'العميل', render: x => x.customer?.name || '—' }, { key: 'stage', label: 'المرحلة', render: x => x.stage?.name || '—' },
        { key: 'amount', label: 'القيمة', render: x => <span dir="ltr">{Number(x.amount).toLocaleString('en-US')} {x.currency_code}</span> }, { key: 'status', label: 'الحالة', render: x => <Status value={x.status} /> }, { key: 'expected_close_on', label: 'الإغلاق المتوقع' },
    ]} empty={{ title: 'لا توجد فرص مسجلة', text: 'ستظهر هنا الفرص المضافة عبر واجهات النظام.' }} /><Pager meta={q.data?.meta} setPage={setPage} /></QueryState></Card></div>;
}
export function Payroll() {
    const [page, setPage] = useState(1), q = useQuery(`/hr/payroll/runs?per_page=25&page=${page}`);
    return <div className="stack"><PageHeading eyebrow="الموارد البشرية" title="الرواتب" description="اطّلع على مسيرات الرواتب وحالة مراجعتها." /><Notice>بيانات الرواتب حساسة وتُعرض حسب صلاحيات حسابك. هذه الشاشة تعرض المسيرات الموجودة.</Notice><Card className="data-card"><QueryState query={q}><RecordTable caption="مسيرات الرواتب" rows={q.data?.data} columns={[
        { key: 'name', label: 'المسيرة', render: x => <strong>{x.name}</strong> }, { key: 'period', label: 'الفترة', render: x => <span className="date-range">{x.period_starts_on} — {x.period_ends_on}</span> }, { key: 'currency_code', label: 'العملة' }, { key: 'status', label: 'الحالة', render: x => <Status value={x.status} /> }, { key: 'employee_count', label: 'عدد الموظفين' },
    ]} empty={{ title: 'لا توجد مسيرات رواتب', text: 'ستظهر المسيرات هنا بعد إعدادها عبر واجهات النظام.' }} /><Pager meta={q.data?.meta} setPage={setPage} /></QueryState></Card></div>;
}
export function Knowledge({ can, notify }) {
    const sources = useQuery(can('ai.knowledge.read') ? '/knowledge/sources' : null);
    const [question, setQuestion] = useState(''), [answer, setAnswer] = useState(null), [error, setError] = useState(''), [busy, setBusy] = useState(false);
    const pending = useRef(false), attempt = useRef({ question: '', key: '' });
    async function ask(event) {
        event.preventDefault(); if (pending.current) return;
        if (attempt.current.question !== question) attempt.current = { question, key: requestKey() };
        pending.current = true; setBusy(true); setError(''); setAnswer(null);
        try { const r = await api('/ai/ask', { method: 'POST', data: { question }, key: attempt.current.key }); setAnswer(r.data); }
        catch (e) { setError(e.message); }
        finally { pending.current = false; setBusy(false); }
    }
    return <div className="stack"><PageHeading eyebrow="ذكاء الشركة" title="المعرفة والمساعد" description="إجابات مبنية على مصادر المعرفة المصرّح بها داخل شركتك." /><ExternalReferences can={can} notify={notify} onChanged={sources.refresh} /><KnowledgeManager can={can} notify={notify} /><div className="knowledge-grid"><SectionCard title="مصادر المعرفة" action={<BookOpen24Regular />}>
        {can('ai.knowledge.read') ? <QueryState query={sources}><RecordTable caption="مصادر المعرفة" rows={sources.data?.data} columns={[{ key: 'name', label: 'المصدر', render: x => <div className="person-inline"><DocumentText24Regular /><strong>{x.name}</strong></div> }, { key: 'documents_count', label: 'المستندات' }, { key: 'status', label: 'الحالة', render: x => <Status value={x.status} /> }]} empty={{ title: 'المعرفة تبدأ بمستنداتك', text: 'أضف مصادر ومستندات المعرفة عبر API ثم فهرسها لتصبح متاحة للمساعد.' }} /></QueryState> : <Notice>لا يتضمن دورك صلاحية عرض مصادر المعرفة.</Notice>}
    </SectionCard><SectionCard title="مساعد Teami" action={<Sparkle24Regular />}><p className="muted">اسأل عن السياسات والإجراءات والمعلومات الموجودة في المستندات المفهرسة.</p>
        {can('ai.ask') ? <form className="stack" onSubmit={ask}><Field label="سؤالك" required><Textarea name="question" placeholder="مثلاً: ما هي سياسة الإجازات السنوية؟" value={question} maxLength={8000} required disabled={busy} resize="vertical" onChange={(_, d) => setQuestion(d.value)} /></Field><Button appearance="primary" type="submit" disabled={busy} icon={busy ? <Spinner size="tiny" /> : <Send24Regular />}>{busy ? 'جارٍ إعداد الإجابة…' : 'اسأل المساعد'}</Button></form> : <Notice>لا يتضمن دورك صلاحية سؤال المساعد.</Notice>}
        {error && <Notice intent="error">{error}</Notice>}{answer && <div className="ai-answer"><div className="caption">إجابة المساعد</div><p>{answer.answer}</p>{answer.citations?.length > 0 && <><Divider /><h3>المصادر</h3><ol>{answer.citations.map((x, i) => <li key={x.document_uuid + ':' + i}>{x.origin === 'external_reference' ? 'مرجع خارجي: ' : ''}{x.document_title ?? x.title ?? x.document_uuid}{x.url && <a className="reference-link" href={x.url} target="_blank" rel="noopener noreferrer"> · {x.publisher || 'المصدر الأصلي'}</a>}</li>)}</ol></>}</div>}
    </SectionCard></div></div>;
}
export function Billing({ can }) {
    const subscription = useQuery(can('billing.subscription.read') ? '/billing/subscription' : null), usage = useQuery(can('billing.usage.read') ? '/billing/usage' : null);
    const sub = subscription.data?.data, totals = usage.data?.data?.totals ?? {};
    return <div className="stack"><PageHeading eyebrow="مساحة العمل" title="الخطة والاستخدام" description="تفاصيل الاشتراك ومقاييس الاستخدام المتاحة لشركتك." />
        {subscription.error && <Notice intent="error">{subscription.error.message}</Notice>}
        <section className="metric-grid"><Metric label="حالة الاشتراك" value={subscription.loading ? '…' : sub ? <Status value={sub.status} /> : 'غير معيّن'} icon={<Wallet24Regular />} hint="حالة الاشتراك الحالي" /><Metric label="الخطة" value={sub?.plan?.name || '—'} icon={<Building24Regular />} hint={sub?.plan?.code || 'لا توجد خطة معيّنة'} /><Metric label="السعر" value={sub ? `${(sub.plan.unit_amount / 100).toFixed(2)} ${sub.plan.currency}` : '—'} icon={<Wallet24Regular />} hint={sub?.plan?.interval === 'year' ? 'لكل سنة' : 'لكل شهر'} /><Metric label="مقاييس الاستخدام" value={usage.loading ? '…' : usage.data ? Object.keys(totals).length : '—'} icon={<TargetArrow24Regular />} hint="عدد أنواع المقاييس المسجلة" /></section>
        <SectionCard title="تفاصيل الاستخدام">{can('billing.usage.read') ? <QueryState query={usage}><RecordTable caption="تفاصيل الاستخدام" rows={Object.entries(totals).map(([metric, quantity]) => ({ metric, quantity }))} columns={[{ key: 'metric', label: 'المقياس' }, { key: 'quantity', label: 'الكمية' }]} empty={{ title: 'لا توجد مقاييس استخدام مسجلة', text: 'ستظهر الكميات هنا بعد تسجيل الاستخدام.' }} /></QueryState> : <Notice>لا يتضمن دورك صلاحية قراءة الاستخدام.</Notice>}</SectionCard><Notice>إدارة الاشتراك والدفع والفواتير لم تُفعّل بعد. هذه الشاشة لا تنفّذ عمليات تحصيل.</Notice>
    </div>;
}
