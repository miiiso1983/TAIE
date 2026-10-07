import React from 'react';
import { Button } from '@fluentui/react-components';
import { Notice, PageHeading, QueryState, RecordTable, SectionCard, useQuery } from './components';
const states={upgrade_required:'ينتظر ترقية النظام',records_present:'توجد سجلات — يلزم فحص الجودة',no_records:'لا توجد سجلات مستوردة',review_required:'راجع الفترة والأدلة'};
export function AdvisorSetup({can,navigate}) {
 const q=useQuery('/advisor/setup'),d=q.data?.data;
 return <div className="stack"><PageHeading title="إعداد مستشار الشركة" description="خطوات عملية لتجهيز بيانات الشركة ومراجعة الحسابات والتوصيات."/><QueryState query={q}>{d&&<><Notice>{d.notice}</Notice><SectionCard title="مجموعات البيانات والمتابعة"><RecordTable caption="حالة الإعداد للشركة الحالية" rows={d.steps.map(x=>({...x,uuid:x.key}))} columns={[{key:'label',label:'الخطوة'},{key:'status',label:'الحالة',render:x=>states[x.status]},{key:'count',label:'السجلات'},{key:'open',label:'الإجراء',render:x=>can(x.permission)&&<Button disabled={x.status==='upgrade_required'} onClick={()=>navigate(x.page)}>فتح القسم</Button>}]}/></SectionCard><SectionCard title="المراجعة قبل التشغيل"><ol>{d.operational_steps.map((x,i)=><li key={i}>{x}</li>)}</ol></SectionCard></>}</QueryState></div>;
}
