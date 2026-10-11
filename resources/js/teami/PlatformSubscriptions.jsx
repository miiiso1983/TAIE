import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { api } from './api';
import { money } from './Launch';
import { FormDialog, Notice, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';

const states = { active: 'فعال', trialing: 'تجريبي', scheduled: 'مجدول', expired: 'منتهٍ', pending: 'بانتظار الدفع', canceled: 'ملغى', cancelled: 'ملغى' };
const baghdadToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Baghdad', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
const subscriptionEnd = (start, interval) => {
    const [year,month,day]=start.split('-').map(Number), target=new Date(Date.UTC(year,month-1+(interval==='year'?12:1),1));
    const lastDay=new Date(Date.UTC(target.getUTCFullYear(),target.getUTCMonth()+1,0)).getUTCDate();
    target.setUTCDate(Math.min(day,lastDay));target.setUTCDate(target.getUTCDate()-1);
    return target.toISOString().slice(0,10);
};
const dateKey = (year, month, day) => `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;

export function PlatformSubscriptions() {
    const [month, setMonth] = useState(baghdadToday().slice(0, 7)), [page, setPage] = useState(1);
    const [dialog, setDialog] = useState(null), [success, setSuccess] = useState('');
    const subscriptions = useQuery(`/platform/subscriptions?month=${month}&page=${page}`), plans = useQuery('/platform/plans');
    const rows = subscriptions.data?.data ?? [], [year, index] = month.split('-').map(Number), monthIndex = index - 1;
    const shift = direction => { const date = new Date(Date.UTC(year, monthIndex + direction, 1)); setMonth(dateKey(date.getUTCFullYear(), date.getUTCMonth(), 1).slice(0, 7)); setPage(1); };
    const monthTitle = new Intl.DateTimeFormat('ar-IQ', { year: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(Date.UTC(year, monthIndex, 1)));
    const closeSaved = () => { setDialog(null); setSuccess('تم حفظ الاشتراك؛ يبدأ التفعيل وينتهي تلقائيًا حسب التواريخ المحددة.'); subscriptions.refresh(); };
    const planOptions = (plans.data?.data ?? []).map(p => ({ value: p.uuid, label: `${p.name} — ${money(p.unit_amount,p.currency)} — ${p.limits?.users ?? 0} مستخدم` }));
    return <div className="stack">
        <Notice>حدد الشركة والخطة ثم مدة الاشتراك. يبدأ التفعيل عند بداية اليوم المحدد وينتهي بعد آخر يوم، بتوقيت بغداد. التفعيل اليدوي قرار إداري مسجل ولا يسجل فاتورة مدفوعة.</Notice>
        {success && <Notice intent="success">{success}</Notice>}
        <SectionCard title="تقويم اشتراكات الشركات" action={<div className="actions"><Button onClick={() => setDialog({ kind: 'plan' })}>إضافة خطة</Button><Button appearance="primary" onClick={() => setDialog({ kind: 'subscription', starts_on: baghdadToday() })}>تفعيل اشتراك</Button></div>}>
            <div className="actions subscription-month"><Button onClick={() => shift(-1)} aria-label="الشهر السابق">السابق</Button><strong aria-live="polite">{monthTitle}</strong><Button onClick={() => shift(1)} aria-label="الشهر التالي">التالي</Button><Button onClick={() => {setMonth(baghdadToday().slice(0,7));setPage(1);}}>الشهر الحالي</Button></div>
            <QueryState query={subscriptions}>
                <div className="subscription-calendar" role="group" aria-label={`تقويم ${monthTitle}`}>
                    {['الأحد','الاثنين','الثلاثاء','الأربعاء','الخميس','الجمعة','السبت'].map(day=><strong className="calendar-weekday" key={day}>{day}</strong>)}
                    {Array.from({length:new Date(Date.UTC(year,monthIndex,1)).getUTCDay()},(_,i)=><div key={`blank-${i}`} aria-hidden="true" />)}
                    {Array.from({length:new Date(Date.UTC(year,monthIndex+1,0)).getUTCDate()},(_,i)=>{
                        const date=dateKey(year,monthIndex,i+1), events=rows.flatMap(row=>[...(row.starts_on===date?[{label:'بداية',row}]:[]),...(row.ends_on===date?[{label:'نهاية',row}]:[])]);
                        return <div className={`calendar-day ${date===baghdadToday()?'calendar-today':''}`} key={date}><Button appearance="subtle" aria-label={`تفعيل اشتراك يبدأ ${date}`} onClick={()=>setDialog({kind:'subscription',starts_on:date})}>{i+1}</Button>{events.map(event=><span className={`calendar-event ${event.label==='نهاية'?'calendar-expiry':''}`} key={`${event.row.uuid}-${event.label}`} title={`${event.row.company} — ${event.row.plan}`} >{event.label}: {event.row.company}</span>)}</div>;
                    })}
                </div>
                <RecordTable caption="اشتراكات الشهر" rows={rows} columns={[
                    {key:'company',label:'الشركة'},{key:'plan',label:'الخطة'},
                    {key:'starts_on',label:'بداية الاشتراك'},{key:'ends_on',label:'آخر يوم',render:x=>x.ends_on??'غير محدد'},
                    {key:'state',label:'الحالة',render:x=>states[x.state]??x.state},
                ]} />
                <Pager meta={subscriptions.data} setPage={setPage} />
                {(subscriptions.data?.last_page??1)>1 && <Notice>أحداث التقويم تعرض اشتراكات الصفحة الحالية. تنقل بين الصفحات لمراجعة بقية الشركات.</Notice>}
            </QueryState>
        </SectionCard>
        <SectionCard title="خطط الاشتراك"><QueryState query={plans}><RecordTable caption="الخطط المتاحة" rows={plans.data?.data??[]} columns={[
            {key:'name',label:'الخطة'},{key:'interval',label:'دورة الفوترة',render:x=>x.interval==='year'?'سنوية':'شهرية'},
            {key:'unit_amount',label:'السعر',render:x=>money(x.unit_amount,x.currency)},
            {key:'users',label:'حد المستخدمين',render:x=>x.limits?.users},
            {key:'ai',label:'طلبات الذكاء شهريًا',render:x=>x.limits?.ai_requests_per_month},
        ]}/></QueryState></SectionCard>
        {dialog?.kind==='subscription' && <FormDialog key={dialog.starts_on} title="تفعيل اشتراك شركة" description="يُحسب آخر يوم تلقائيًا حسب دورة الخطة ويمكن تعديله وفق الاتفاق. يمكنك جدولة تجديد يبدأ بعد انتهاء الفترة الحالية. لا تُسمح الفترات المتداخلة." initial={{starts_on:dialog.starts_on}} deriveValues={(values,field)=>{const plan=(plans.data?.data??[]).find(p=>p.uuid===values.plan_uuid); return plan&&values.starts_on&&['plan_uuid','starts_on'].includes(field)?{...values,ends_on:subscriptionEnd(values.starts_on,plan.interval)}:values;}} fields={[
            {name:'tenant_uuid',label:'الشركة',required:true,remote:{path:'/platform/tenants',label:x=>x.name}},
            {name:'plan_uuid',label:'خطة الاشتراك',required:true,placeholder:'ابحث واختر الخطة',options:planOptions},
            {name:'starts_on',label:'تاريخ البداية',type:'date',required:true},
            {name:'ends_on',label:'آخر يوم في الاشتراك',type:'date',required:true},
            {name:'reason',label:'سبب التفعيل أو مرجع الاتفاق',type:'textarea',required:true,maxLength:500},
        ]} save={(data,key)=>api('/platform/subscriptions',{method:'POST',data,key})} onClose={()=>setDialog(null)} onSaved={closeSaved}/ >}
        {dialog?.kind==='plan' && <FormDialog title="إضافة خطة اشتراك" description="أدخل السعر بعملة الخطة. حدود الخطة تطبق على جميع الشركات المشتركة بها. التفعيل يتيح استخدام النظام خلال المدة التي تحددها." fields={[
            {name:'name',label:'اسم الخطة',required:true,maxLength:120}, {name:'code',label:'رمز الخطة بالإنجليزية',required:true,maxLength:40,hint:'أحرف صغيرة وأرقام وشرطة أو شرطة سفلية'},
            {name:'currency',label:'العملة',required:true,options:[{value:'USD',label:'دولار أمريكي'},{value:'IQD',label:'دينار عراقي'}],default:'USD'},
            {name:'price',label:'سعر الاشتراك',required:true,hint:'مثال: 25.00 دولار أو 25000 دينار. استخدم النقطة للفاصلة العشرية.'},
            {name:'interval',label:'دورة الفوترة',required:true,default:'month',options:[{value:'month',label:'شهرية'},{value:'year',label:'سنوية'}]},
            {name:'users',label:'حد المستخدمين',type:'number',required:true,min:1,step:1},
            {name:'ai_requests_per_month',label:'طلبات الذكاء الاصطناعي شهريًا',type:'number',required:true,min:0,step:1},
        ]} save={(data,key)=>{const {price,...rest}=data, digits=data.currency==='IQD'?3:2; if(!/^\d+(\.\d+)?$/.test(price)||((price.split('.')[1]??'').length>digits))throw new Error('أدخل سعرًا صحيحًا؛ الدولار يدعم منزلتين عشريتين والدينار ثلاثًا.'); const [whole,fraction='']=price.split('.');return api('/platform/plans',{method:'POST',key,data:{...rest,unit_amount:Number(whole+fraction.padEnd(digits,'0'))}});}} onClose={()=>setDialog(null)} onSaved={()=>{setDialog(null);plans.refresh();setSuccess('تمت إضافة الخطة وأصبحت متاحة لتفعيل الاشتراكات.');}} />}
    </div>;
}
