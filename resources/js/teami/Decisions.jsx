import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { api } from './api';
import { rowsOf } from './Launch';
import { FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';
const labels={proposed:'مقترح',accepted:'معتمد',in_progress:'قيد التنفيذ',completed:'مكتمل',rejected:'مرفوض',cancelled:'ملغى'};
const next={proposed:['accepted','rejected'],accepted:['in_progress','cancelled'],in_progress:['completed','cancelled']};
export function Decisions({can,notify}) {
 const [page,setPage]=useState(1),[selected,setSelected]=useState(null),[creating,setCreating]=useState(false),[transition,setTransition]=useState(null);
 const list=useQuery('/advisor/decisions?page='+page),detail=useQuery(selected?'/advisor/decisions/'+selected:null),item=detail.data?.data;
 const refresh=()=>{setCreating(false);setTransition(null);list.refresh();detail.refresh();notify('تم حفظ القرار وسجل المتابعة');};
 return <div className="stack"><PageHeading title="مركز قرارات المدير" description="إجراء مرتبط بتقرير أو توقع محفوظ، ومسؤول وموعد وأدلة متابعة ونتيجة قابلة للمراجعة."/>
 <Notice>تسجيل القرار ومتابعته داخل التطبيق. النتيجة المدخلة بشرية، ولا يثبت اختلافها عن خط البداية أن الإجراء سبب التغيير.</Notice>
 <SectionCard title="القرارات والإجراءات" action={can('advisor.decision.manage')&&<Button appearance="primary" onClick={()=>setCreating(true)}>إجراء جديد</Button>}><QueryState query={list}><RecordTable caption="قرارات الشركة" rows={rowsOf(list.data)} columns={[{key:'title',label:'الإجراء',render:x=>x.details.title},{key:'status',label:'الحالة',render:x=>labels[x.status]},{key:'owner_name',label:'المسؤول'},{key:'due_date',label:'الموعد',render:x=>x.due_date+(x.overdue?' · متأخر':'')},{key:'open',label:'التفاصيل',render:x=><Button onClick={()=>setSelected(x.uuid)}>متابعة الإجراء</Button>}]}/><Pager meta={list.data?.data} setPage={setPage}/></QueryState></SectionCard>
 {item&&<SectionCard title={item.details.title} action={<Button onClick={()=>setSelected(null)}>إغلاق</Button>}><p>{item.details.rationale}</p>{item.details.report&&<p>مرجع التقرير: <bdi>{item.details.report.uuid}</bdi> · نسخة {item.details.report.version} · {item.details.report.period_from} إلى {item.details.report.period_to}</p>}{item.details.forecast&&<><p>مرجع التوقع: <bdi>{item.details.forecast.uuid}</bdi> · {item.details.forecast.product.code} · {item.details.forecast.area.code}</p><Notice>قرار مرتبط بنسخة توقع تجريبية محفوظة؛ قد تختلف البيانات الحالية. القياس بأجزاء ألف من وحدة {item.details.forecast.product.unit}.</Notice></>}<p>المقياس: {item.details.metric} · الوحدة: {item.details.unit} · خط البداية: {item.details.baseline} · المستهدف: {item.details.target}</p>
 {item.details.outcome&&<><p>النتيجة: {item.details.outcome.actual} · التغير: {item.details.outcome.delta} · الفرق عن الهدف: {item.details.outcome.target_gap}</p><p>دليل النتيجة: {item.details.outcome.evidence_reference}</p></>}
 <RecordTable caption="سجل المتابعة" rows={item.events.map(x=>({...x,uuid:String(x.version)}))} columns={[{key:'version',label:'الإصدار'},{key:'status',label:'الحالة',render:x=>labels[x.status]},{key:'note',label:'سبب الإجراء',render:x=>x.evidence_encrypted.note},{key:'created_at',label:'الوقت'}]}/>
 {can('advisor.decision.manage')&&(next[item.status]??[]).map(status=><Button key={status} onClick={()=>setTransition(status)}>{labels[status]}</Button>)}
 </SectionCard>}
 {creating&&<FormDialog title="إنشاء إجراء من تقرير" fields={[
 {name:'evidence_kind',label:'نوع الدليل',required:true,default:'report',options:[{value:'report',label:'تقرير محفوظ'},...(can('advisor.forecast.run')?[{value:'forecast',label:'توقع تجريبي محفوظ'}]:[])]},{name:'evidence_uuid',label:'معرّف الدليل المحفوظ',required:true},{name:'title',label:'الإجراء',required:true,maxLength:200},{name:'rationale',label:'سبب التوصية وحدود البيانات',type:'textarea',required:true,minLength:12,maxLength:2000,wide:true},
 {name:'owner_id',label:'المسؤول',required:true,remote:{path:'/advisor/decisions/owners',label:x=>x.name}},
 {name:'due_date',label:'موعد التنفيذ',type:'date',required:true}, {name:'metric',label:'مقياس المتابعة',required:true,maxLength:100},
 {name:'unit',label:'الوحدة',required:true,options:[{value:'quantity_milli',label:'أجزاء ألف من وحدة المنتج — للتوقعات'},{value:'count',label:'عدد'},{value:'percent',label:'نسبة صحيحة'},{value:'USD_minor',label:'سنت USD'},{value:'IQD_minor',label:'فلس IQD'}]},
 {name:'baseline',label:'خط البداية',type:'number',required:true,step:1},{name:'target',label:'المستهدف',type:'number',required:true,step:1}
 ]} save={(data,key)=>{const {evidence_kind,evidence_uuid,...body}=data;return api('/advisor/decisions',{method:'POST',data:{...body,[evidence_kind==='forecast'?'forecast_uuid':'report_uuid']:evidence_uuid},key});}} onClose={()=>setCreating(false)} onSaved={refresh}/>}
 {transition&&item&&<FormDialog title={labels[transition]} fields={[{name:'note',label:'السبب وأدلة التنفيذ',type:'textarea',required:true,minLength:12,maxLength:2000},...(transition==='completed'?[{name:'actual',label:'النتيجة بنفس وحدة خط البداية',type:'number',required:true,step:1},{name:'evidence_reference',label:'مرجع التحقق من النتيجة',required:true,maxLength:255}]:[])]} save={(data,key)=>api('/advisor/decisions/'+item.uuid+'/transition',{method:'POST',data:{...data,status:transition,version:item.version},key})} onClose={()=>setTransition(null)} onSaved={refresh}/>}
 </div>;
}
