import React, { useState } from 'react';
import { Button, Card } from '@fluentui/react-components';

const steps = [
    { page: 'administration', title: 'جهّز فريقك وصلاحياته', text: 'من المستخدمين، أضف أعضاء فريقك وحدّد دور كل شخص. امنح كل مستخدم الصلاحيات التي يحتاجها للعمل.' },
    { page: 'employees', title: 'أدخل بيانات الموظفين', text: 'أضف الموظفين وبياناتهم الأساسية. أكمل الأقسام والمسميات والحضور من عمليات الشركة حسب صلاحياتك؛ هذه البيانات أساس متابعة الموارد البشرية.' },
    { page: 'customers', title: 'أضف العملاء وفرص البيع', text: 'ابدأ بعميل واحد، ثم افتح الفرص التجارية وأدخل فرصة مرتبطة به بقيمتها ومرحلتها. حدّث المرحلة كلما تقدّم البيع لتصبح المتابعة مفيدة.' },
    { page: 'imports', title: 'جهّز بيانات التحليل', text: 'من بيانات الشركة، استخدم القالب المناسب لاستيراد بيانات المبيعات، وراجع الأخطاء والمطابقة قبل اعتمادها. البيانات المكتملة تحسّن موثوقية التقارير.' },
    { page: 'setup', title: 'اضبط سياق شركتك', text: 'حدّد إعدادات المستشار الملائمة لنشاطك. عند تقييم المبيعات، راجع سياق السوق والظروف السياسية وسعر الدولار الرسمي وسعر السوق، مع تاريخ ومصدر كل معلومة.' },
    { page: 'knowledge', title: 'أضف معرفة الشركة واسأل', text: 'في إدارة المعرفة: أضف مصدرًا، اختره، ثم أضف مستندًا نصيًا مثل سياسة الإجازات. انتظر اكتمال الفهرسة، ثم اسأل: «ما سياسة الإجازات في شركتنا؟». إذا لم تملك صلاحية الإضافة، اطلب تجهيز المستندات من مدير شركتك.' },
    { page: 'reports', title: 'احصل على تقرير قابل للمراجعة', text: 'افتح تقارير المستشار وحدّد الفترة ونوع التقرير. راجع جودة البيانات والنتائج والمصادر؛ نقص البيانات قد يمنع التحليل أو يقلّل دقته. تفسير الذكاء الاصطناعي يساعدك على فهم التقرير.' },
];

export function GettingStarted({ company, user, allowed, navigate, reopened }) {
    const storageKey = `teami_getting_started_v1:${company.uuid}:${user.uuid || user.id || user.email}`;
    const [open, setOpen] = useState(() => {
        if (reopened) return true;
        try { return localStorage.getItem(storageKey) !== 'dismissed'; } catch { return true; }
    });
    const [index, setIndex] = useState(0);
    const available = steps.filter(step => allowed(step.page));
    const sequence = [...available, { title: 'راجع النتيجة وحدّد الخطوة التالية', text: 'بعد ظهور الإجابة، افتح المصادر وتأكد أن المستند يدعمها. جرّب سؤالًا محددًا عن مستندات شركتك، ثم حدّث البيانات وكرر التحليل عند الحاجة. إذا كانت النتيجة ناقصة، راجع الفهرسة والفترة والصلاحيات. عند تعطل AI، تواصل مع مدير الشركة للتحقق من الخدمة والرصيد.', page: allowed('knowledge') ? 'knowledge' : 'home' }];
    const step = sequence[Math.min(index, sequence.length - 1)];
    const dismiss = () => {
        try { localStorage.setItem(storageKey, 'dismissed'); } catch { /* Guidance remains usable when browser storage is unavailable. */ }
        setOpen(false);
    };
    if (!open) return null;
    return <Card className="getting-started" aria-label="دليل البداية">
        <div className="actions"><strong>من أول خطوة إلى أول نتيجة</strong><Button appearance="subtle" onClick={dismiss}>إخفاء الدليل</Button></div>
        <p className="caption">دليل لمساحة {company.name} · يمكنك فتحه مجددًا من قائمة حسابك. تظهر الخطوات المتاحة لصلاحياتك.</p>
        <div aria-live="polite"><span className="caption">الخطوة {index + 1} من {sequence.length}</span><h2>{step.title}</h2><p>{step.text}</p></div>
        <p className="caption">فتح القسم لا يعني إكماله تلقائيًا. طلبات AI الناجحة تُحتسب من رصيد الشركة؛ راجع الخطة والاستخدام لمعرفة المتبقي.</p>
        <div className="actions"><Button appearance="primary" onClick={() => navigate(step.page)}>افتح القسم</Button><Button disabled={index === 0} onClick={() => setIndex(x => x - 1)}>السابق</Button>{index < sequence.length - 1 ? <Button onClick={() => setIndex(x => x + 1)}>التالي</Button> : <Button onClick={dismiss}>إنهاء الدليل</Button>}</div>
    </Card>;
}
