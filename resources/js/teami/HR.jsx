import { SearchableSelect as Select } from './components';
import React, { useEffect, useState } from 'react';
import { Avatar, Button, Card, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, Field, Input, Tab, TabList } from '@fluentui/react-components';
import { Add24Regular, Search24Regular, Edit20Regular, Person24Regular, Checkmark20Regular, Dismiss20Regular } from '@fluentui/react-icons';
import { api } from './api';
import { employeeName, FormDialog, Notice, PageHeading, Pager, PersonCell, QueryState, RecordTable, SectionCard, Status, useQuery } from './components';

const employmentStatuses = ['active', 'on_leave', 'terminated'].map((value, i) => ({ value, label: ['نشط', 'في إجازة', 'منتهي الخدمة'][i] }));
const employmentTypes = ['full_time', 'part_time', 'contractor', 'temporary'].map((value, i) => ({ value, label: ['دوام كامل', 'دوام جزئي', 'متعاقد', 'مؤقت'][i] }));

function EmployeeDetails({ uuid, close }) {
    const q = useQuery(`/hr/employees/${uuid}`), x = q.data?.data;
    return <Dialog open onOpenChange={(_, d) => { if (!d.open) close(); }}><DialogSurface><DialogBody><DialogTitle>ملف الموظف</DialogTitle><DialogContent><QueryState query={q}>{x && <div className="stack">
        <div className="profile-heading"><Avatar name={employeeName(x)} size={64} color="colorful" /><div><h2>{employeeName(x)}</h2><p className="muted">{x.position?.title || 'لم تحدد الوظيفة'}</p></div><Status value={x.employment_status} /></div>
        <dl className="detail-grid">{[['الرقم الوظيفي', x.employee_number], ['القسم', x.department?.name], ['البريد الوظيفي', x.work_email], ['تاريخ التعيين', x.hired_at], ['نوع التوظيف', employmentTypes.find(t => t.value === x.employment_type)?.label]].map(([label, value]) => <div key={label}><dt>{label}</dt><dd>{value || '—'}</dd></div>)}</dl>
    </div>}</QueryState></DialogContent><DialogActions><Button onClick={close}>إغلاق</Button></DialogActions></DialogBody></DialogSurface></Dialog>;
}

export function Employees({ can, notify }) {
    const [filters, setFilters] = useState({ search: '', status: '' }), [draft, setDraft] = useState(filters), [page, setPage] = useState(1);
    const [editing, setEditing] = useState(null), [detail, setDetail] = useState(null);
    const q = useQuery('/hr/employees?' + new URLSearchParams({ ...filters, page, per_page: 25 }));
    const departments = useQuery(can('hr.department.read') ? '/hr/departments' : null);
    const positions = useQuery(can('hr.position.read') ? '/hr/positions' : null);
    const fields = [
        { name: 'employee_number', label: 'الرقم الوظيفي', required: true, maxLength: 60 },
        { name: 'first_name', label: 'الاسم الأول', required: true, maxLength: 120 },
        { name: 'last_name', label: 'اسم العائلة', required: true, maxLength: 120 },
        { name: 'work_email', label: 'البريد الوظيفي', type: 'email', nullable: true, maxLength: 255 },
        { name: 'employment_type', label: 'نوع التوظيف', options: employmentTypes, default: 'full_time', required: true },
        { name: 'employment_status', label: 'الحالة الوظيفية', options: employmentStatuses, default: 'active', required: true },
        { name: 'hired_at', label: 'تاريخ التعيين', type: 'date', nullable: true },
        ...(departments.data ? [{ name: 'department_uuid', label: 'القسم', nullable: true, clearOnChange: ['position_uuid'], options: departments.data.data.map(x => ({ value: x.uuid, label: x.name })) }] : []),
        ...(positions.data ? [{ name: 'position_uuid', label: 'الوظيفة', nullable: true, options: values => positions.data.data.filter(x => (x.department?.uuid ?? '') === (values.department_uuid ?? editing?.department?.uuid ?? '')).map(x => ({ value: x.uuid, label: x.title })) }] : []),
    ];
    return <div className="stack"><PageHeading eyebrow="الموارد البشرية" title="الموظفون" description="ملفات فريقك، وظائفهم وبيانات التوظيف في مكان واحد." action={can('hr.employee.create') && <Button appearance="primary" icon={<Add24Regular />} disabled={departments.loading || positions.loading} onClick={() => setEditing({})}>إضافة موظف</Button>} />
        <Card className="data-card"><form className="filter-bar" onSubmit={e => { e.preventDefault(); setFilters(draft); setPage(1); }}>
            <Field label="البحث"><Input name="search" contentBefore={<Search24Regular />} placeholder="اسم الموظف، الرقم أو البريد…" value={draft.search} maxLength={120} onChange={(_, d) => setDraft(x => ({ ...x, search: d.value }))} /></Field>
            <Field label="الحالة"><Select name="status" value={draft.status} onChange={e => setDraft(x => ({ ...x, status: e.target.value }))}><option value="">جميع الحالات</option>{employmentStatuses.map(x => <option key={x.value} value={x.value}>{x.label}</option>)}</Select></Field>
            <Button type="submit">بحث</Button>
            <span className="filter-total">{q.data?.meta?.total !== undefined ? `${q.data.meta.total} موظف` : ''}</span>
        </form><QueryState query={q}><RecordTable caption="دليل الموظفين" rows={q.data?.data} columns={[
            { key: 'name', label: 'الموظف والوظيفة', render: x => <PersonCell employee={x} /> },
            { key: 'employee_number', label: 'الرقم الوظيفي' },
            { key: 'department', label: 'القسم', render: x => x.department?.name || '—' },
            { key: 'employment_status', label: 'الحالة', render: x => <Status value={x.employment_status} /> },
            { key: 'hired_at', label: 'تاريخ التعيين' },
            { key: 'actions', label: 'الإجراءات', render: x => <div className="actions"><Button size="small" appearance="subtle" icon={<Person24Regular />} onClick={() => setDetail(x.uuid)}>التفاصيل</Button>{can('hr.employee.update') && <Button size="small" appearance="subtle" icon={<Edit20Regular />} disabled={departments.loading || positions.loading} onClick={() => setEditing(x)}>تعديل</Button>}</div> },
        ]} empty={{ title: 'لم نعثر على موظفين', text: filters.search || filters.status ? 'جرّب تغيير البحث أو الحالة.' : 'أضف أول موظف لبدء إدارة فريقك.' }} /><Pager meta={q.data?.meta} setPage={setPage} /></QueryState></Card>
        {editing && <FormDialog title={editing.uuid ? 'تعديل الموظف' : 'إضافة موظف'} fields={fields} initial={{ ...editing, department_uuid: editing.department?.uuid, position_uuid: editing.position?.uuid }} description="تغيير الحالة إلى منتهي الخدمة يحدّث سجل التوظيف. صلاحية دخول المستخدم تُدار بصورة منفصلة." onClose={() => setEditing(null)} save={(data, key) => api('/hr/employees' + (editing.uuid ? `/${editing.uuid}` : ''), { method: editing.uuid ? 'PATCH' : 'POST', data, key })} onSaved={message => { setEditing(null); q.refresh(); notify(message); }} />}
        {detail && <EmployeeDetails uuid={detail} close={() => setDetail(null)} />}
    </div>;
}

function useEmployeeDirectory(enabled) {
    const [state, setState] = useState({ loading: enabled, data: [], error: null });
    useEffect(() => {
        if (!enabled) return;
        const controller = new AbortController();
        (async () => {
            const records = []; let page = 1, last = 1;
            do { const response = await api(`/hr/employees?per_page=100&page=${page}`, { signal: controller.signal }); records.push(...response.data); last = response.meta.last_page; page++; } while (page <= last);
            if (!controller.signal.aborted) setState({ loading: false, data: records, error: null });
        })().catch(error => { if (!controller.signal.aborted) setState({ loading: false, data: [], error }); });
        return () => controller.abort();
    }, [enabled]);
    return state;
}
function Balances({ employees, types, can, open }) {
    const [employee, setEmployee] = useState(''), [year, setYear] = useState(String(new Date().getFullYear())), [path, setPath] = useState(null);
    const q = useQuery(path);
    return <SectionCard title="أرصدة الإجازات" action={can('hr.leave.manage') && can('hr.employee.read') && <Button appearance="primary" icon={<Add24Regular />} onClick={() => open('balance')}>تخصيص رصيد</Button>}>
        <p className="muted">الرصيد المتاح يساوي المخصص بعد استبعاد الأيام المستخدمة والطلبات المعلّقة.</p>
        {can('hr.employee.read') ? <><form className="filter-bar" onSubmit={e => { e.preventDefault(); const next = '/hr/leave-balances?' + new URLSearchParams({ employee_uuid: employee, year }); if (next === path) q.refresh(); else setPath(next); }}>
            <Field label="الموظف" required><Select name="balance_employee" value={employee} required onChange={e => setEmployee(e.target.value)}><option value="">اختر الموظف</option>{employees.map(x => <option key={x.uuid} value={x.uuid}>{employeeName(x)}</option>)}</Select></Field>
            <Field label="السنة" required><Input name="balance_year" type="number" min="2000" max="2200" required value={year} onChange={(_, d) => setYear(d.value)} /></Field><Button type="submit">عرض الرصيد</Button>
        </form>{path ? <QueryState query={q}><RecordTable rows={q.data?.data} caption="أرصدة الإجازات" columns={[
            { key: 'leave_type_name', label: 'نوع الإجازة' }, { key: 'allocated_days', label: 'المخصص' }, { key: 'used_days', label: 'المستخدم' }, { key: 'pending_days', label: 'المعلّق' }, { key: 'available_days', label: 'المتاح', render: x => <strong className="brand-text">{x.available_days}</strong> },
        ]} empty={{ title: 'لا يوجد رصيد لهذه السنة', text: 'يمكن للمسؤول تخصيص الرصيد من هذه الشاشة.' }} /></QueryState> : <div className="lookup-placeholder">اختر موظفاً وسنة لعرض الأرصدة.</div>}</> : <Notice>عرض قائمة الموظفين يحتاج صلاحية قراءة ملفات الموظفين.</Notice>}
    </SectionCard>;
}
export function Leave({ can, notify }) {
    const [tab, setTab] = useState('requests'), [status, setStatus] = useState(''), [page, setPage] = useState(1), [modal, setModal] = useState(null), [balanceRevision, setBalanceRevision] = useState(0);
    const q = useQuery('/hr/leave-requests?' + new URLSearchParams({ page, per_page: 25, status }));
    const types = useQuery('/hr/leave-types'), directory = useEmployeeDirectory(can('hr.employee.read'));
    const open = kind => setModal({ kind });
    const employeeField = { name: 'employee_uuid', label: 'الموظف', required: true, placeholder: 'اختر الموظف', options: directory.data.map(x => ({ value: x.uuid, label: employeeName(x) })) };
    const typeField = { name: 'leave_type_uuid', label: 'نوع الإجازة', required: true, placeholder: 'اختر النوع', options: (types.data?.data ?? []).filter(x => x.status === 'active').map(x => ({ value: x.uuid, label: x.name })) };
    let form;
    if (modal?.kind === 'request') form = { title: 'طلب إجازة جديد', fields: [employeeField, typeField, { name: 'starts_on', label: 'من', type: 'date', required: true }, { name: 'ends_on', label: 'إلى', type: 'date', required: true }, { name: 'requested_days', label: 'أيام العمل المطلوبة', type: 'number', step: '0.01', min: '0.01', max: '365', required: true }, { name: 'reason', label: 'السبب', type: 'textarea', maxLength: 4000, wide: true }], path: '/hr/leave-requests', method: 'POST' };
    if (modal?.kind === 'type') form = { title: 'إضافة نوع إجازة', fields: [{ name: 'code', label: 'الرمز', maxLength: 40, required: true }, { name: 'name', label: 'الاسم', maxLength: 160, required: true }, { name: 'annual_allowance_days', label: 'الأيام السنوية', type: 'number', step: '0.01', min: '0', max: '365', nullable: true }, { name: 'requires_approval', label: 'سياسة الموافقة', boolean: true, required: true, default: 'true', options: [{ value: 'true', label: 'تحتاج موافقة' }, { value: 'false', label: 'موافقة تلقائية' }] }], path: '/hr/leave-types', method: 'POST' };
    if (modal?.kind === 'balance') form = { title: 'تخصيص رصيد إجازة', fields: [employeeField, typeField, { name: 'year', label: 'السنة', type: 'number', min: '2000', max: '2200', default: String(new Date().getFullYear()), required: true }, { name: 'allocated_days', label: 'الأيام المخصصة', type: 'number', step: '0.01', min: '0', max: '365', required: true }], path: '/hr/leave-balances', method: 'PUT' };
    if (modal?.kind === 'decision') form = { title: modal.decision === 'approve' ? 'الموافقة على طلب الإجازة' : 'رفض طلب الإجازة', fields: [{ name: 'note', label: 'ملاحظة القرار', type: 'textarea', maxLength: 2000, wide: true }], path: `/hr/leave-requests/${modal.uuid}/decision`, method: 'POST', description: 'يجب أن يكون حساب المراجع مرتبطاً بملف موظف نشط.' };
    return <div className="stack"><PageHeading eyebrow="الموارد البشرية" title="الإجازات" description="طلبات واضحة، موافقات منظّمة وأرصدة دقيقة لفريقك." action={can('hr.leave.create') && can('hr.employee.read') && <Button appearance="primary" icon={<Add24Regular />} disabled={directory.loading || types.loading || !!directory.error || !!types.error} onClick={() => open('request')}>طلب إجازة</Button>} />
        <TabList selectedValue={tab} onTabSelect={(_, d) => setTab(d.value)}><Tab value="requests">الطلبات</Tab><Tab value="balances">الأرصدة</Tab><Tab value="types">أنواع الإجازات</Tab></TabList>
        {directory.error && <Notice intent="error">{directory.error.message}</Notice>}
        {tab === 'requests' && <Card className="data-card"><div className="filter-bar"><Field label="الحالة"><Select name="leave_status" value={status} onChange={e => { setStatus(e.target.value); setPage(1); }}><option value="">جميع الطلبات</option>{['pending', 'approved', 'rejected', 'cancelled'].map((x, i) => <option key={x} value={x}>{['قيد المراجعة', 'موافق عليها', 'مرفوضة', 'ملغاة'][i]}</option>)}</Select></Field><span className="filter-total">{q.data?.meta?.total !== undefined ? `${q.data.meta.total} طلب` : ''}</span></div><QueryState query={q}><RecordTable rows={q.data?.data} caption="طلبات الإجازة" columns={[
            { key: 'employee', label: 'الموظف', render: x => <TablePerson employee={x.employee} /> }, { key: 'leave_type', label: 'النوع', render: x => x.leave_type.name },
            { key: 'dates', label: 'الفترة', render: x => <span className="date-range">{x.starts_on} — {x.ends_on}</span> }, { key: 'requested_days', label: 'الأيام' }, { key: 'status', label: 'الحالة', render: x => <Status value={x.status} /> },
            { key: 'actions', label: 'القرار', render: x => x.status === 'pending' && can('hr.leave.approve') ? <div className="actions"><Button size="small" appearance="subtle" icon={<Checkmark20Regular />} onClick={() => setModal({ kind: 'decision', uuid: x.uuid, decision: 'approve' })}>موافقة</Button><Button size="small" appearance="subtle" icon={<Dismiss20Regular />} onClick={() => setModal({ kind: 'decision', uuid: x.uuid, decision: 'reject' })}>رفض</Button></div> : '—' },
        ]} empty={{ title: 'لا توجد طلبات في هذه القائمة', text: 'ستظهر الطلبات الجديدة هنا ليتابعها الفريق.' }} /><Pager meta={q.data?.meta} setPage={setPage} /></QueryState></Card>}
        {tab === 'balances' && <Balances key={balanceRevision} employees={directory.data} types={types.data?.data} can={can} open={open} />}
        {tab === 'types' && <SectionCard title="أنواع الإجازات" action={can('hr.leave.manage') && <Button appearance="primary" icon={<Add24Regular />} onClick={() => open('type')}>إضافة نوع</Button>}><QueryState query={types}><RecordTable caption="أنواع الإجازات" rows={types.data?.data} columns={[{ key: 'name', label: 'الاسم' }, { key: 'code', label: 'الرمز' }, { key: 'annual_allowance_days', label: 'الأيام السنوية' }, { key: 'approval', label: 'الموافقة', render: x => x.requires_approval ? 'تحتاج موافقة' : 'تلقائية' }, { key: 'status', label: 'الحالة', render: x => <Status value={x.status} /> }]} empty={{ title: 'لم تُضف أنواع الإجازات', text: 'أضف الأنواع ثم خصّص الأرصدة للموظفين.' }} /></QueryState></SectionCard>}
        {form && <FormDialog {...form} onClose={() => setModal(null)} save={(data, key) => api(form.path, { method: form.method, key, data: modal.kind === 'decision' ? { ...data, decision: modal.decision } : data })} onSaved={message => { setModal(null); q.refresh(); types.refresh(); setBalanceRevision(x => x + 1); notify(message); }} />}
    </div>;
}
function TablePerson({ employee }) { return <div className="person-inline"><Avatar name={employeeName(employee)} size={28} color="colorful" /><strong>{employeeName(employee)}</strong></div>; }
