import { SearchableSelect as Select } from './components';
import React, { useRef, useState } from 'react';
import { Button, Field, Input, } from '@fluentui/react-components';
import { api, requestKey } from './api';
import { Notice, PageHeading, QueryState, RecordTable, SectionCard, useQuery } from './components';
import { rowsOf } from './Launch';

const labels={employees:'الموظفون',customers:'العملاء',invoices:'فواتير المبيعات',receipts:'التحصيلات',returns:'مرتجعات المبيعات',targets:'أهداف المندوبين',compensation:'التعويضات السنوية'};
const states={validated:'جاهزة للاعتماد',invalid:'تحتاج تصحيحاً',imported:'معتمدة',expired:'انتهت المعاينة'};
export function Imports({notify}){
    const schemas=useQuery('/advisor/imports/schemas'), history=useQuery('/advisor/imports');
    const [kind,setKind]=useState(''),[source,setSource]=useState('primary'),[file,setFile]=useState(null),[batch,setBatch]=useState(null),[busy,setBusy]=useState(false),[error,setError]=useState(''),[inspection,setInspection]=useState(null),[sheet,setSheet]=useState('0'),[mapping,setMapping]=useState({});
    const inFlight=useRef(false), input=useRef(null), keys=useRef({});
    const selected=rowsOf(schemas.data).find(x=>x.kind===kind);
    function clear(){setInspection(null);setMapping({});setSheet('0');setBatch(null);setError('');setFile(null);if(input.current)input.current.value='';}
    function template(){const blob=new Blob(['\uFEFF'+selected.columns.join(',')+'\r\n'],{type:'text/csv;charset=utf-8'}),url=URL.createObjectURL(blob),a=document.createElement('a');a.href=url;a.download=`teami-${kind}-template.csv`;a.click();setTimeout(()=>URL.revokeObjectURL(url),1000);}
    function chooseSheet(index,detail=inspection){setSheet(String(index));setBatch(null);const head=detail.sheets.find(x=>String(x.index)===String(index))?.columns??[];setMapping(Object.fromEntries(selected.columns.map(target=>[target,head.find(c=>c===target)??''])));}
    async function inspect(){if(inFlight.current||!file)return;inFlight.current=true;setBusy(true);setError('');setBatch(null);
        try{const body=new FormData();body.append('kind',kind);body.append('file',file);const result=await api('/advisor/imports/inspect',{method:'POST',data:body});setInspection(result.data);chooseSheet(result.data.sheets[0].index,result.data);}
        catch(e){setError(e.message);}finally{inFlight.current=false;setBusy(false);}
    }
    async function preview(event){event.preventDefault();if(inFlight.current)return;inFlight.current=true;setBusy(true);setError('');setBatch(null);
        try{const body=new FormData();body.append('kind',kind);body.append('source_system',source);body.append('file',file);if(inspection){body.append('sheet',sheet);body.append('mapping',JSON.stringify(mapping));}const result=await api('/advisor/imports/preview',{method:'POST',data:body});setBatch(result.data);history.refresh();}
        catch(e){setError(e.message);}finally{inFlight.current=false;setBusy(false);}
    }
    async function commit(){if(inFlight.current||batch?.status!=='validated')return;inFlight.current=true;setBusy(true);setError('');
        try{keys.current[batch.uuid]??=requestKey();const result=await api(`/advisor/imports/${batch.uuid}/commit`,{method:'POST',data:{},key:keys.current[batch.uuid]});setBatch(result.data);history.refresh();notify('تم اعتماد الدفعة بنجاح');}
        catch(e){setError(e.message);}finally{inFlight.current=false;setBusy(false);}
    }
    return <div className="stack"><PageHeading title="بيانات الشركة" description="الخطوة الأولى لتجهيز بيانات مستشار المبيعات والموارد البشرية."/>
        <Notice>استورد الموظفين والعملاء أولاً، ثم الفواتير والتحصيلات والمرتجعات والأهداف. التعويض السنوي منفصل عن المكافأة ويُستورد بفترات غير متداخلة. الفواتير هنا بيانات للتحليل؛ اعتمادها لا يصدر فاتورة قانونية ولا ينفّذ دفعة أو قرار HR.</Notice>
        <SectionCard title="رفع ومعاينة"><QueryState query={schemas}><form className="stack" onSubmit={preview}>
            <div className="form-grid"><Field label="نوع البيانات" required><Select required disabled={busy} value={kind} onChange={e=>{setKind(e.target.value);clear();}}><option value="">اختر نوعاً</option>{rowsOf(schemas.data).map(x=><option value={x.kind} key={x.kind}>{labels[x.kind]}</option>)}</Select></Field>
            <Field label="رمز نظام المصدر" required hint="رمز ثابت مثل primary أو maxcon. نفس الرمز للفواتير وتحصيلاتها؛ لا تغيّره لإعادة إدخال السجلات."><Input value={source} required maxLength={40} pattern="[a-z0-9][a-z0-9_-]*" disabled={busy} dir="ltr" onChange={(_,d)=>{setSource(d.value);setBatch(null);}}/></Field></div>
            <Field label={{children:"ملف CSV أو Excel",htmlFor:"company-import-file"}} required hint="CSV UTF-8 أو XLSX، حتى 1000 سجل و1MiB. Excel بقيم ثابتة دون صيغ أو روابط خارجية؛ PDF وXLS غير مدعومين."><input id="company-import-file" ref={input} name="file" type="file" accept=".csv,.xlsx,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required disabled={busy||!kind} onChange={e=>{setFile(e.target.files?.[0]??null);setInspection(null);setMapping({});setBatch(null);setError('');}}/></Field>
            {selected&&<p className="muted">حقول البيانات: <span dir="ltr">{selected.columns.join(', ')}</span>. أرقام الموظفين والعملاء والفواتير ثابتة؛ المبالغ دون فواصل آلاف، والتاريخ YYYY-MM-DD.</p>}
            {inspection&&<div className="stack"><Field label="ورقة Excel"><Select disabled={busy} value={sheet} onChange={e=>chooseSheet(e.target.value)}>{inspection.sheets.map(x=><option key={x.index} value={x.index}>{x.name}</option>)}</Select></Field><div className="form-grid">{selected.columns.map(target=><Field key={target} label={`ربط ${target}`} required={(selected.required??selected.columns).includes(target)}><Select disabled={busy} value={mapping[target]??''} onChange={e=>{setMapping(old=>({...old,[target]:e.target.value}));setBatch(null);}}><option value="">{(selected.required??selected.columns).includes(target)?'اختر عموداً':'غير متوفر — اترك فارغاً'}</option>{(inspection.sheets.find(x=>String(x.index)===sheet)?.columns??[]).map(column=><option key={column} value={column}>{column}</option>)}</Select></Field>)}</div><p className="muted">الأعمدة الإضافية لا تُستورد. الحقول الاختيارية يمكن تركها فارغة؛ لا تربط العمود بأكثر من حقل. خزّن معرّفات Excel كنص إذا فيها أصفار بادئة.</p></div>}
            <div className="actions"><Button type="button" disabled={!selected||busy} onClick={template}>تحميل القالب</Button><Button type="button" disabled={!selected||!file||busy} onClick={inspect}>فحص الأعمدة وربطها</Button><Button type="submit" appearance="primary" disabled={!selected||!file||busy||(file?.name.toLowerCase().endsWith('.xlsx')&&!inspection)}>{busy?'جارٍ المعالجة…':'معاينة الملف'}</Button></div>
        </form></QueryState></SectionCard>
        {error&&<Notice intent="error">{error}</Notice>}
        {batch&&<SectionCard title="نتيجة المعاينة"><p>{labels[batch.kind]} · {batch.row_count} سجل · {states[batch.status]} · {batch.error_count} خطأ</p>
            {batch.status==='validated'&&<><Notice>لم تُضف السجلات بعد. الاعتماد يضيف الدفعة كاملة دون استبدال بيانات موجودة. تُعاد مراجعة البيانات عند الاعتماد؛ المعاينة صالحة سبعة أيام.</Notice><Button appearance="primary" disabled={busy} onClick={commit}>اعتماد الدفعة كاملة</Button></>}
            {batch.status==='imported'&&<Notice intent="success">هذه الدفعة معتمدة. إعادة رفع نفس الملف لا تضيف سجلات أخرى.</Notice>}
            {!!batch.errors?.length&&<><RecordTable caption="أخطاء الملف" rows={batch.errors.map((x,i)=>({...x,uuid:String(i)}))} columns={[{key:'line',label:'رقم السجل'},{key:'field',label:'العمود'},{key:'message',label:'المشكلة'}]}/><p className="muted">تظهر أول 100 مشكلة. صحّح الملف وأعد معاينته؛ لا تُعتمد دفعة تحتوي أخطاء.</p></>}
        </SectionCard>}
        <SectionCard title="آخر دفعات الاستيراد"><QueryState query={history}><RecordTable caption="سجل الاستيراد" rows={rowsOf(history.data)} columns={[{key:'kind',label:'البيانات',render:x=>labels[x.kind]},{key:'source_system',label:'المصدر'},{key:'row_count',label:'السجلات'},{key:'status',label:'الحالة',render:x=>states[x.status]},{key:'committed_at',label:'الاعتماد',render:x=>x.committed_at?.slice(0,10)??'—'}]}/></QueryState><p className="muted">آخر 20 دفعة. لا تُرسل ملفات الاستيراد إلى مزوّد AI في هذه المرحلة. يُحذف محتوى المعاينة بعد الاعتماد أو انتهاء مدتها.</p></SectionCard>
    </div>;
}
