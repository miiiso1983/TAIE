import React, { useState } from 'react';
import { Button } from '@fluentui/react-components';
import { api } from './api';
import { FormDialog, Notice, Pager, QueryState, RecordTable, SectionCard, useQuery } from './components';
export function ProductForecast({can}) {
 const [open,setOpen]=useState(false),[result,setResult]=useState(null),[page,setPage]=useState(1),[selected,setSelected]=useState(null);
 const history=useQuery(can('advisor.forecast.run')?'/advisor/product-forecasts?page='+page:null),saved=useQuery(selected?'/advisor/product-forecasts/'+selected:null);
 const display=selected?saved.data?.data:result;
 if(!can('advisor.forecast.run'))return null;
 return <SectionCard title="توقع المنتج والمنطقة — تجريبي" action={<Button onClick={()=>setOpen(true)}>تحليل 12 أسبوعاً أو أكثر</Button>}>
 <Notice>توقع كمية البيع قبل المرتجعات لأسبوع واحد. يقارن آخر أسبوع بمتوسط أربعة أسابيع، باختبار زمني بأثر رجعي. لا يُستخدم وحده لقرار شراء أو مكافأة.</Notice>
 {display&&<ForecastResult result={display}/>}<QueryState query={saved}/>
 <QueryState query={history}><RecordTable caption="تحليلات التوقع المحفوظة" rows={history.data?.data?.data??[]} columns={[{key:'product_code',label:'المنتج'},{key:'area_code',label:'المنطقة'},{key:'saved_at',label:'وقت الحفظ'},{key:'status',label:'الحالة',render:x=>x.status==='experimental'?'تجريبي':'بيانات غير كافية'},{key:'open',label:'الأدلة',render:x=><Button onClick={()=>setSelected(x.uuid)}>فتح النسخة</Button>}]}/><Pager meta={history.data?.data} setPage={setPage}/></QueryState>
 {open&&<FormDialog title="تحليل توقع منتج ومنطقة" description="اختر فترة من الاثنين إلى الأحد، 12 إلى 104 أسابيع مكتملة. إقرارك يسمح باعتبار الأسبوع بلا مبيعات صفراً ضمن المصدر والنطاق، ولا يؤكد تغطية النظام كله." fields={[
 {name:'product_uuid',label:'المنتج',required:true,remote:{path:'/advisor/data/catalog?kind=products',label:x=>x.code+' · '+x.name}},
 {name:'area_uuid',label:'المنطقة',required:true,remote:{path:'/advisor/data/catalog?kind=areas',label:x=>x.code+' · '+x.province_name+' · '+x.city_name}},
 {name:'source_system',label:'كود مصدر الحقيقة لهذا التحليل',required:true,maxLength:40},{name:'from',label:'من يوم الاثنين',type:'date',required:true},{name:'to',label:'إلى يوم الأحد المكتمل',type:'date',required:true},
 {name:'coverage_note',label:'مرجع تغطية المصدر والوحدة والمنطقة والقيود',required:true,type:'textarea',minLength:12,maxLength:1000},
 {name:'coverage_acknowledge',label:'إقرار التغطية لهذه السلسلة',boolean:true,required:true,placeholder:'اختر الإقرار',options:[{value:'true',label:'راجعت المصدر، والأسابيع دون مبيعات هي صفر فعلي في هذا النطاق'}]}
 ]} save={async(data,key)=>{const response=await api('/advisor/product-forecast',{method:'POST',data,key});setSelected(null);setResult(response.data);history.refresh();return response;}} onClose={()=>setOpen(false)} onSaved={()=>setOpen(false)}/>}
 </SectionCard>;
}

function ForecastResult({result}) { return <div className="stack"><Notice>{result.snapshot_notice}</Notice><p className="caption">مرجع التحليل: <bdi>{result.uuid}</bdi> · {result.saved_at}</p>{result.warnings.map((w,i)=><p className="muted" key={i}>{w}</p>)}<p>{result.product.code} · {result.area.code} · الوحدة: {result.product.unit}</p><p>فواتير ناقصة: {result.readiness.incomplete_invoice_count} · مرتجعات ناقصة: {result.readiness.incomplete_return_count}</p>
 {result.forecast?<><p>التوقع من {result.forecast.from} إلى {result.forecast.to}: {result.forecast.quantity} {result.product.unit}</p><p>نطاق الخطأ الوصفي: {result.forecast.lower} — {result.forecast.upper}</p><p>خطأ مرحلة الاختيار: آخر أسبوع {result.backtest.mae.last_week.toFixed(3)} · متوسط أربعة أسابيع {result.backtest.mae.mean_4_weeks.toFixed(3)}</p>{result.backtest.independent_holdout&&<p>اختبار مستقل في آخر {result.backtest.holdout_weeks} أسابيع: خطأ النموذج المختار {result.backtest.holdout_selected_model_mae.toFixed(3)}. هذه عينة صغيرة، ولا تثبت دقة مستقبلية عامة.</p>}</>:<Notice intent="warning">البيانات غير كافية؛ أكمل مطابقة سطور الفواتير والمرتجعات.</Notice>}
 <RecordTable caption="الأسابيع المستخدمة" rows={result.series.map(x=>({...x,uuid:x.from}))} columns={[{key:'from',label:'بداية الأسبوع'},{key:'observed_lines',label:'السطور المسجلة'},{key:'sold_milli',label:'كمية البيع',render:x=>x.sold_milli/1000},{key:'returned_milli',label:'مرتجع حتى نهاية الفترة',render:x=>x.returned_milli/1000}]}/><p className="caption">بصمة البيانات: <bdi>{result.fingerprint}</bdi></p></div>; }
