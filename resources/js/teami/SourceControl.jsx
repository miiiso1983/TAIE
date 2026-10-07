import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { api } from './api';
import { FormDialog, Notice, PageHeading, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';
const kinds={invoices:'الفواتير',receipts:'التحصيلات',returns:'المرتجعات',products:'المنتجات',invoice_lines:'سطور الفواتير',stock_snapshots:'أرصدة المخزون',product_costs:'تكلفة الوحدة',stockouts:'الانقطاع',return_lines:'سطور المرتجعات'};
export function SourceControl({can,notify}) {
 const [page,setPage]=useState(1),[linkPage,setLinkPage]=useState(1),[dialog,setDialog]=useState(null),[revoking,setRevoking]=useState(null);
 const authorities=useQuery('/advisor/source-control/authorities?page='+page),links=useQuery('/advisor/source-control/identities?page='+linkPage),sources=useQuery('/advisor/source-control/sources');
 const rows=q=>q.data?.data?.data??[];
 const review=[{name:'review_note',label:'دليل المراجعة وسبب الاختيار',type:'textarea',required:true,minLength:12,maxLength:1000},{name:'acknowledge',label:'إقرار المراجع',required:true,boolean:true,placeholder:'اختر الإقرار',options:[{value:'true',label:'راجعت المصدر والربط وأتحمل مسؤولية صحة هذا النطاق'}]}];
 const saved=()=>{setDialog(null);setRevoking(null);authorities.refresh();links.refresh();notify('تم حفظ مراجعة المصادر');};
 return <div className="stack"><PageHeading title="مصدر الحقيقة وربط الهويات" description="اختيار مصدر موثق لكل مجموعة وربط رموز الأنظمة بهوية الشركة بعد مراجعة بشرية."/><Notice>السجل لا يدمج فواتير ولا يغيّر قيمها. التوقعات تحترم مصدر الحقيقة المختار للفواتير؛ التصحيح يحفظ الربط السابق وسبب إيقافه.</Notice>
 <SectionCard title="مصادر مستوردة"><QueryState query={sources}><RecordTable caption="المصدر والنوع" rows={(sources.data?.data??[]).map(x=>({...x,uuid:x.source_system+':'+x.kind}))} columns={[{key:'source_system',label:'النظام'},{key:'kind',label:'البيانات',render:x=>kinds[x.kind]}]}/></QueryState></SectionCard>
 <SectionCard title="إصدارات مصدر الحقيقة" action={can('advisor.source.manage')&&<Button onClick={()=>setDialog('authority')}>مراجعة مصدر الحقيقة</Button>}><QueryState query={authorities}><RecordTable caption="مراجعات المصادر" rows={rows(authorities)} columns={[{key:'kind',label:'النوع',render:x=>kinds[x.kind]},{key:'source_system',label:'المصدر'},{key:'version',label:'النسخة'},{key:'uuid',label:'مرجع الإصدار'},{key:'review_note_encrypted',label:'الدليل'}]}/><Pager meta={authorities.data?.data} setPage={setPage}/></QueryState></SectionCard>
 <SectionCard title="روابط الهوية وتاريخ التصحيح" action={can('advisor.source.manage')&&<Button onClick={()=>setDialog('identity')}>ربط معرّف</Button>}><QueryState query={links}><RecordTable caption="روابط الأنظمة" rows={rows(links)} columns={[{key:'kind',label:'النوع'},{key:'source_system',label:'المصدر'},{key:'external_id_encrypted',label:'معرّف المصدر'},{key:'canonical_uuid',label:'هوية الشركة'},{key:'version',label:'الإصدار'},{key:'status',label:'الحالة',render:x=>x.status==='active'?'نشط':'متوقف'},{key:'uuid',label:'مرجع الربط'},{key:'stop',label:'التصحيح',render:x=>can('advisor.source.manage')&&x.status==='active'&&<Button onClick={()=>setRevoking(x.uuid)}>إيقاف مع توثيق</Button>}]}/><Pager meta={links.data?.data} setPage={setLinkPage}/></QueryState></SectionCard>
 {dialog&&<FormDialog title={dialog==='authority'?'مراجعة مصدر الحقيقة':'ربط معرّف نظام'} fields={[
 {name:'kind',label:'النوع',required:true,options:Object.entries(dialog==='authority'?kinds:{products:'منتج',invoices:'فاتورة',...(can('sales.customer.read')?{customers:'عميل'}:{}),...(can('hr.employee.read')?{employees:'موظف'}:{})}).map(([value,label])=>({value,label}))},
 {name:'source_system',label:'كود المصدر المستورد',required:true,maxLength:40},{name:'previous_uuid',label:'معرّف الإصدار السابق — عند التغيير',nullable:true},
 ...(dialog==='identity'?[{name:'external_id',label:'معرّف المصدر',required:true,maxLength:100},{name:'canonical_uuid',label:'UUID السجل الموجود في الشركة',required:true}]:[]),...review
 ]} save={(data,key)=>api('/advisor/source-control/'+(dialog==='authority'?'authorities':'identities'),{method:'POST',data,key})} onClose={()=>setDialog(null)} onSaved={saved}/>}
 {revoking&&<FormDialog title="إيقاف الربط السابق قبل تصحيحه" fields={review} save={(data,key)=>api('/advisor/source-control/identities/'+revoking+'/revoke',{method:'POST',data,key})} onClose={()=>setRevoking(null)} onSaved={saved}/>}
 </div>;
}
