import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { Brand } from './Brand';
import { api } from './api';
import { FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';

export function PlatformWorkspace({ context, themeMode, toggleTheme, logout }) {
    const [page, setPage] = useState(1), [creating, setCreating] = useState(false), [created, setCreated] = useState(null);
    const query = useQuery(`/platform/tenants?page=${page}`);
    return <div className="platform-shell">
        <a className="skip-link" href="#main-content">الانتقال إلى المحتوى</a>
        <header className="topbar"><Brand small /><strong>إدارة المنصة</strong><div className="actions"><span className="platform-account">{context.user?.name}</span><Button onClick={toggleTheme}>{themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'}</Button><Button onClick={logout}>تسجيل الخروج</Button></div></header>
        <main id="main-content" className="content stack" tabIndex={-1}>
            <PageHeading title="شركات المستأجرين" description="أنت في لوحة مالك الموقع. أنشئ شركة مستقلة وحساب مالكها، ثم سلّم بيانات الدخول للشخص المسؤول عنها." />
            <Notice>لكل شركة مستخدموها وبياناتها المعزولة. مالك الشركة يدير شركته؛ إنشاء شركات جديدة متاح لمالك المنصة فقط.</Notice>
            {created && <Notice intent="success">تم إنشاء {created.name}. معرّف الشركة للدخول: <bdi>{created.uuid}</bdi>. يدخل مالكها بالبريد وكلمة المرور اللذين حددتهما. تهيئة الاشتراك مستقلة عن إنشاء الحساب.</Notice>}
            <SectionCard title="الشركات المسجلة" action={<Button appearance="primary" onClick={() => setCreating(true)}>إنشاء مستأجر جديد</Button>}>
                <QueryState query={query}><RecordTable caption="شركات المستأجرين" rows={query.data?.data ?? []} columns={[
                    {key:'name',label:'الشركة'}, {key:'slug',label:'الرمز'},
                    {key:'uuid',label:'معرّف الشركة للدخول',render:x=><bdi>{x.uuid}</bdi>},
                    {key:'users_count',label:'المستخدمون'}, {key:'status',label:'الحالة',render:x=>x.status==='active'?'نشطة':'غير نشطة'},
                ]} /><Pager meta={query.data} setPage={setPage} /></QueryState>
            </SectionCard>
            {creating && <FormDialog title="إنشاء شركة وحساب مالكها" description="اختر كلمة مرور أولية قوية وسلمها للمالك عبر وسيلة آمنة. لا يمنح هذا الحساب إدارة المنصة ولا اشتراكًا مدفوعًا تلقائيًا." fields={[
                {name:'name',label:'اسم الشركة',required:true,maxLength:255},
                {name:'slug',label:'رمز الشركة بالإنجليزية',required:true,maxLength:80,hint:'أحرف إنجليزية صغيرة وأرقام وشرطة، مثل company-one'},
                {name:'owner_name',label:'اسم مالك الشركة',required:true,maxLength:255},
                {name:'owner_email',label:'بريد مالك الشركة',type:'email',required:true,maxLength:255},
                {name:'password',label:'كلمة المرور الأولية',type:'password',required:true,maxLength:128,hint:'12 محرفًا على الأقل مع أحرف كبيرة وصغيرة ورقم ورمز'},
                {name:'password_confirmation',label:'تأكيد كلمة المرور',type:'password',required:true,maxLength:128},
            ]} save={async (data,key)=>{const r=await api('/platform/tenants',{method:'POST',data,key});setCreated(r.data);return r;}} onClose={()=>setCreating(false)} onSaved={()=>{setCreating(false);query.refresh();}} />}
        </main>
    </div>;
}
