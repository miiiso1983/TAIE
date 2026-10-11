import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { PlatformSubscriptions } from './PlatformSubscriptions';
import { Brand } from './Brand';
import { api } from './api';
import { FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';

export function PlatformWorkspace({ context, themeMode, toggleTheme, logout }) {
    const [tab, setTab] = useState('companies');
    const [page, setPage] = useState(1), [creating, setCreating] = useState(false), [created, setCreated] = useState(null), [profileCompany,setProfileCompany]=useState(null);
    const query = useQuery(`/platform/tenants?page=${page}`);
    return <div className="platform-shell">
        <a className="skip-link" href="#main-content">الانتقال إلى المحتوى</a>
        <header className="topbar"><Brand small /><strong>إدارة المنصة</strong><div className="actions"><Button as="a" href="/welcome" target="_blank" rel="noopener noreferrer">الصفحة التعريفية</Button><span className="platform-account">{context.user?.name}</span><Button onClick={toggleTheme}>{themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}</Button><Button onClick={logout}>تسجيل الخروج</Button></div></header>
        <main id="main-content" className="content stack" tabIndex={-1}>
            <div className="actions"><Button appearance={tab==='companies'?'primary':'secondary'} onClick={()=>setTab('companies')}>الشركات</Button><Button appearance={tab==='subscriptions'?'primary':'secondary'} onClick={()=>setTab('subscriptions')}>الاشتراكات والتقويم</Button></div>
            {tab==='subscriptions'?<><PageHeading title="إدارة اشتراكات الشركات" description="إدارة الخطط ومواعيد التفعيل والتجديد من تقويم واحد."/><PlatformSubscriptions/></>:<>
            <PageHeading title="شركات المستأجرين" description="أنت في لوحة مالك الموقع. أنشئ شركة مستقلة وحساب مالكها، ثم سلّم بيانات الدخول للشخص المسؤول عنها." />
            <Notice>لكل شركة مستخدموها وبياناتها المعزولة. مالك الشركة يدير شركته؛ إنشاء شركات جديدة متاح لمالك المنصة فقط.</Notice>
            {created && <Notice intent="success">تم إنشاء {created.name}. معرّف الشركة للدخول: <bdi>{created.uuid}</bdi>. يدخل مالكها بالبريد وكلمة المرور اللذين حددتهما. تهيئة الاشتراك مستقلة عن إنشاء الحساب.</Notice>}
            <SectionCard title="الشركات المسجلة" action={<Button appearance="primary" onClick={() => setCreating(true)}>إنشاء مستأجر جديد</Button>}>
                <QueryState query={query}><RecordTable caption="شركات المستأجرين" rows={query.data?.data ?? []} columns={[
                    {key:'name',label:'الشركة'}, {key:'slug',label:'الرمز'},
                    {key:'uuid',label:'معرّف الشركة للدخول',render:x=><bdi>{x.uuid}</bdi>},
                    {key:'registration',label:'معلومات الشركة',render:x=><Button onClick={()=>setProfileCompany(x)}>عرض بيانات التسجيل</Button>},
                    {key:'users_count',label:'المستخدمون'}, {key:'status',label:'الحالة',render:x=>x.status==='active'?'نشطة':'غير نشطة'},
                ]} /><Pager meta={query.data} setPage={setPage} /></QueryState>
            </SectionCard>
            {profileCompany&&<CompanyRegistration company={profileCompany} onClose={()=>setProfileCompany(null)}/>}
            {creating && <FormDialog title="إنشاء شركة وحساب مالكها" description="اختر كلمة مرور أولية قوية وسلمها للمالك عبر وسيلة آمنة. لا يمنح هذا الحساب إدارة المنصة ولا اشتراكًا مدفوعًا تلقائيًا." fields={[
                {name:'name',label:'اسم الشركة',required:true,maxLength:255},
                {name:'slug',label:'رمز الشركة بالإنجليزية',required:true,maxLength:80,hint:'أحرف إنجليزية صغيرة وأرقام وشرطة، مثل company-one'},
                {name:'owner_name',label:'اسم مالك الشركة',required:true,maxLength:255},
                {name:'owner_email',label:'بريد مالك الشركة',type:'email',required:true,maxLength:255},
                {name:'password',label:'كلمة المرور الأولية',type:'password',required:true,maxLength:128,hint:'12 محرفًا على الأقل مع أحرف كبيرة وصغيرة ورقم ورمز'},
                {name:'password_confirmation',label:'تأكيد كلمة المرور',type:'password',required:true,maxLength:128},
            ]} save={async (data,key)=>{const r=await api('/platform/tenants',{method:'POST',data,key});setCreated(r.data);return r;}} onClose={()=>setCreating(false)} onSaved={()=>{setCreating(false);query.refresh();}} />}
            </>}
        </main>
    </div>;
}

function CompanyRegistration({company,onClose}) {
    const query=useQuery(`/platform/tenants/${encodeURIComponent(company.uuid)}/registration`), profile=query.data?.data?.profile;
    const labels={name:'اسم الشركة',legal_name:'الاسم القانوني',business_activity:'النشاط',country_code:'البلد',city:'المدينة',address:'العنوان',company_size:'حجم الفريق',website:'الموقع',owner_name:'المسؤول',job_title:'المسمى الوظيفي',owner_email:'البريد',phone:'الهاتف',preferred_plan_uuid:'الخطة المفضلة'};
    return <SectionCard title={`بيانات التسجيل — ${company.name}`} action={<Button onClick={onClose}>إغلاق</Button>}><QueryState query={query}>{profile?<><Notice>هذه معلومات قدمها المسجل. البريد ورقم الهاتف لم يُتحقق منهما تلقائيًا.</Notice><dl className="detail-grid">{Object.entries(labels).map(([key,label])=><div key={key}><dt>{label}</dt><dd><bdi>{profile[key]||'—'}</bdi></dd></div>)}</dl></>:<Notice>هذه الشركة أُنشئت من إدارة المنصة ولا يوجد لها نموذج تسجيل ذاتي.</Notice>}</QueryState></SectionCard>;
}
