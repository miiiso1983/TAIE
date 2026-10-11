import { Landing, Privacy, TrialRegistration, TrialBanner } from './PublicSite';
import { PlatformWorkspace } from './Platform';
import {Brand, BrandBlocks} from './Brand';
import { CompanyRules } from './CompanyRules';
import { SourceControl } from './SourceControl';
import { AdvisorSetup } from './AdvisorSetup';
import { Decisions } from './Decisions';
import { Integrations } from './Integrations';
import { Reports } from './Reports';
import { Bonus } from './Bonus';
import { Analytics } from './Analytics';
import { Imports } from './Imports';
import React, { useEffect, useState } from 'react';
import {
    Avatar, Badge, Button, Divider, DrawerBody, DrawerHeader, DrawerHeaderTitle,
    Field, FluentProvider, Input, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger,
    OverlayDrawer, Spinner, Toast, ToastBody, Toaster, ToastTitle, Tooltip,
    useId, useToastController, createDarkTheme, createLightTheme,
} from '@fluentui/react-components';
import {
    Home24Regular, People24Regular, CalendarLtr24Regular, Wallet24Regular, Building24Regular,
    TargetArrow24Regular, BookOpen24Regular, Sparkle24Regular, Payment24Regular,
    Navigation24Regular, Dismiss24Regular, WeatherMoon24Regular, WeatherSunny24Regular,
    SignOut24Regular, ArrowLeft24Regular, ShieldCheckmark24Regular, ChevronDown20Regular,
} from '@fluentui/react-icons';
import { api, clearSession } from './api';
import { FormDialog, Notice } from './components';
import { Employees, Leave } from './HR';
import { Customers, Home, Knowledge } from './Pages';

import { Administration, Operations, AccountSecurity, BillingWorkspace as Billing, PayrollWorkspace as Payroll, OpportunityWorkspace as Opportunities } from './Launch';
// TAIE identity: indigo and night lead; violet actions, sky accents, AI spark reserved for AI.
const taieBrand = {
 10:'#080014',20:'#14003A',30:'#210063',40:'#2C008B',50:'#3700B3',60:'#4600CB',70:'#5400DC',80:'#6200EE',
 90:'#7534F1',100:'#8852F4',110:'#9B70F6',120:'#AE8CF8',130:'#C1A9FA',140:'#D4C6FC',150:'#E7E1FD',160:'#F6F3FF',
};
const shared = {borderRadiusSmall:'6px',borderRadiusMedium:'8px',borderRadiusLarge:'12px',borderRadiusXLarge:'16px',shadow2:'none',shadow4:'none',shadow8:'0 8px 28px rgba(20,0,58,.12)',shadow16:'0 16px 48px rgba(20,0,58,.18)'};
const lightTheme = {
 ...createLightTheme(taieBrand),...shared,
 colorNeutralBackground1:'#FFFFFF',colorNeutralBackground2:'#F6F5FB',colorNeutralBackground3:'#EEEBF6',
 colorNeutralForeground1:'#14003A',colorNeutralForeground2:'#4C4266',colorNeutralForeground3:'#696178',
 colorNeutralStroke1:'#B7AEC9',colorNeutralStroke2:'#E6E6E6',
 colorBrandBackground:'#6200EE',colorBrandBackgroundHover:'#5000C7',colorBrandBackgroundPressed:'#3700B3',
 colorBrandBackground2:'#EEE8FF',colorBrandBackground2Hover:'#E5D9FF',colorBrandBackground2Pressed:'#D4C6FC',
 colorBrandForeground1:'#3700B3',colorBrandForeground2:'#3700B3',colorBrandForegroundLink:'#6200EE',colorBrandForegroundLinkHover:'#3700B3',
 colorBrandStroke1:'#6200EE',colorBrandStroke2:'#C1A9FA',colorNeutralForegroundOnBrand:'#FFFFFF',
 colorCompoundBrandBackground:'#6200EE',colorCompoundBrandBackgroundHover:'#5000C7',colorCompoundBrandBackgroundPressed:'#3700B3',
 colorCompoundBrandForeground1:'#6200EE',colorCompoundBrandForeground1Hover:'#3700B3',
};
const darkTheme = {
 ...createDarkTheme(taieBrand),...shared,
 colorNeutralBackground1:'#211045',colorNeutralBackground2:'#14003A',colorNeutralBackground3:'#2B1851',
 colorNeutralForeground1:'#FFFFFF',colorNeutralForeground2:'#E0D9ED',colorNeutralForeground3:'#B8AECF',
 colorNeutralStroke1:'#80719E',colorNeutralStroke2:'#46365F',
 colorBrandBackground:'#6200EE',colorBrandBackgroundHover:'#7534F1',colorBrandBackgroundPressed:'#5000C7',
 colorBrandBackground2:'#33205C',colorBrandBackground2Hover:'#46306F',colorBrandBackground2Pressed:'#513978',
 colorBrandForeground1:'#54BBFC',colorBrandForeground2:'#D4C6FC',colorBrandForegroundLink:'#54BBFC',colorBrandForegroundLinkHover:'#9DD8FF',
 colorBrandStroke1:'#AE8CF8',colorBrandStroke2:'#80719E',colorNeutralForegroundOnBrand:'#FFFFFF',
 colorCompoundBrandBackground:'#6200EE',colorCompoundBrandBackgroundHover:'#7534F1',colorCompoundBrandBackgroundPressed:'#5000C7',
 colorCompoundBrandForeground1:'#C1A9FA',colorCompoundBrandForeground1Hover:'#D4C6FC',
};

const navigation = [
    { key: 'rules', label: 'محاكاة السياسات', icon: ShieldCheckmark24Regular, permissions: ['data.sensitive.read','advisor.rule.manage','advisor.rule.approve'], all: false, menu: true },
    { key: 'sources', label: 'مراجعة المصادر', icon: ShieldCheckmark24Regular, permissions: ['advisor.sales.read','data.sensitive.read'], all: true, menu: true },
    { key: 'setup', label: 'إعداد المستشار', icon: ShieldCheckmark24Regular, permissions: ['advisor.sales.read','data.sensitive.read'], all: true, menu: true },
    { key: 'decisions', label: 'مركز القرارات', icon: TargetArrow24Regular, permissions: ['advisor.decision.read','advisor.report.read','data.sensitive.read'], all: true, menu: true },
    { key: 'integrations', label: 'التكاملات', icon: Building24Regular, permissions: ['integration.read', 'data.sensitive.read'], all: true, menu: true },
    { key: 'operations', label: 'عمليات الشركة', icon: People24Regular, permissions: ['hr.department.read','hr.position.read','hr.attendance.read','hr.compensation.read','hr.onboarding.read','hr.performance.read','hr.recruitment.read','hr.employee_relations.read','sales.lead.read','sales.contact.read','sales.activity.read','sales.target.read','sales.forecast.read'] },
    { key: 'reports', label: 'تقارير المستشار', icon: TargetArrow24Regular, permissions: ['advisor.report.read','data.sensitive.read'], all: true, menu: true },
    { key: 'bonus', label: 'السياسات والموافقات', icon: ShieldCheckmark24Regular, permissions: ['advisor.bonus.read','data.sensitive.read','hr.compensation.read'], all: true, menu: true },
    { key: 'analytics', label: 'تحليل الأداء', icon: TargetArrow24Regular, permissions: ['advisor.sales.read','data.sensitive.read'], all: true, menu: true },
    { key: 'imports', label: 'بيانات الشركة', icon: People24Regular, permissions: ['data.import.manage','data.sensitive.read'], all: true, menu: true },
    { key: 'administration', label: 'المستخدمون', icon: People24Regular, permissions: ['identity.membership.manage'], menu: true },
    { key: 'security', label: 'أمان الحساب', icon: ShieldCheckmark24Regular, menu: true },
    { key: 'home', label: 'الرئيسية', icon: Home24Regular, group: 'مساحة العمل' },
    { key: 'employees', label: 'الموظفون', icon: People24Regular, group: 'الموارد البشرية', permissions: ['hr.employee.read'] },
    { key: 'leave', label: 'الإجازات', icon: CalendarLtr24Regular, permissions: ['hr.leave.read'] },
    { key: 'payroll', label: 'الرواتب', icon: Wallet24Regular, permissions: ['hr.payroll.read', 'data.sensitive.read'], all: true },
    { key: 'customers', label: 'العملاء', icon: Building24Regular, group: 'المبيعات', permissions: ['sales.customer.read'] },
    { key: 'opportunities', label: 'الفرص التجارية', icon: TargetArrow24Regular, permissions: ['sales.opportunity.read'] },
    { key: 'knowledge', label: 'المعرفة والمساعد', icon: BookOpen24Regular, group: 'ذكاء الشركة', permissions: ['ai.knowledge.read', 'ai.ask'] },
    { key: 'billing', label: 'الخطة والاستخدام', icon: Payment24Regular, group: 'الإدارة', permissions: ['billing.subscription.read', 'billing.usage.read'] },
];
const currentPage = () => navigation.some(x => x.key === location.hash.slice(1)) ? location.hash.slice(1) : 'home';
function Login({ onAuthenticated }) {
    const [values, setValues] = useState({ tenant: localStorage.getItem('teami_tenant') || '', email: '', password: '', mfa_code: '' });
    const [busy, setBusy] = useState(false), [error, setError] = useState(''), [mfa, setMfa] = useState(false);
    const [recovery, setRecovery] = useState(() => location.pathname.startsWith('/reset-password/') ? 'reset' : null);
    async function login(event) {
        event.preventDefault(); if (busy) return; setBusy(true); setError('');
        try {
            const data = { tenant: values.tenant.trim(), email: values.email.trim(), password: values.password, ...(mfa ? { mfa_code: values.mfa_code.trim() } : {}) };
            const response = await api('/auth/login', { method: 'POST', authenticated: false, data });
            if (response.error?.code === 'MFA_REQUIRED') { setMfa(true); setError('أدخل رمز المصادقة الثنائية لإكمال الدخول.'); return; }
            if (!response.data?.token) throw new Error('تعذر إكمال تسجيل الدخول.');
            localStorage.setItem('teami_token', response.data.token); localStorage.setItem('teami_tenant', data.tenant); localStorage.setItem('teami_user', JSON.stringify(response.data.user));
            onAuthenticated();
        } catch (error) { setError(error.message); } finally { setBusy(false); }
    }
    const set = key => (_, data) => setValues(x => ({ ...x, [key]: data.value }));
    return <><div className="login-layout"><section className="login-story"><Brand /><BrandBlocks/><div className="login-story-copy"><Badge appearance="tint" color="brand">مساحة عمل لفريقك</Badge><h1>ذكاء يقرّب فريقك.<br /><span>وقرارات أوضح لشركتك.</span></h1><p>موارد بشرية، علاقات عملاء ومعرفة الشركة.<br />تجربة عمل أوضح تبدأ من هنا.</p><div className="story-capabilities">{[[People24Regular, 'الموارد البشرية', 'وظائف، إجازات ورواتب'], [TargetArrow24Regular, 'إدارة المبيعات', 'عملاء وفرص تجارية'], [Sparkle24Regular, 'معرفة الشركة', 'مساعد يعتمد على مستنداتك']].map(([Icon, title, text]) => <div className="story-capability" key={title}><span><Icon /></span><div><strong>{title}</strong><small>{text}</small></div></div>)}</div></div><div className="login-assurance"><ShieldCheckmark24Regular />مساحة مستقلة لكل شركة، وصلاحيات لكل دور.</div></section>
        <section className="login-form-side"><form className="login-form" onSubmit={login}><div className="mobile-brand"><Brand /></div><a className="login-back" href="/welcome">العودة إلى الصفحة التعريفية</a><span className="eyebrow">أهلاً بعودتك</span><h2>تسجيل الدخول</h2><p className="muted">ادخل إلى مساحة شركتك في TAIE.</p><div className="stack login-fields"><Field label="معرّف الشركة" required><Input name="tenant" value={values.tenant} onChange={set('tenant')} autoComplete="organization" placeholder="مثال: teami" required disabled={busy} /></Field><Field label="البريد الإلكتروني" required><Input name="email" type="email" value={values.email} onChange={set('email')} autoComplete="username" placeholder="name@company.com" required disabled={busy} /></Field><Field label="كلمة المرور" required><Input name="password" type="password" value={values.password} onChange={set('password')} autoComplete="current-password" required disabled={busy} /></Field>{mfa && <Field label="رمز المصادقة الثنائية" required><Input name="mfa_code" value={values.mfa_code} onChange={set('mfa_code')} autoComplete="one-time-code" required disabled={busy} autoFocus /></Field>}{error && <Notice intent={mfa ? 'info' : 'error'}>{error}</Notice>}<Button appearance="primary" size="large" type="submit" disabled={busy} icon={busy ? <Spinner size="tiny" /> : <ArrowLeft24Regular />} iconPosition="after">{busy ? 'جارٍ التحقق…' : 'تسجيل الدخول'}</Button></div><Button appearance="subtle" type="button" onClick={() => setRecovery('forgot')}>نسيت كلمة المرور؟</Button><a className="login-trial-link" href="/register">ليس لديك حساب؟ ابدأ تجربة مجانية</a><p className="login-footnote"><ShieldCheckmark24Regular />الوصول حسب عضويتك وصلاحياتك في الشركة.</p></form></section>
    </div>{recovery && <FormDialog title={recovery === 'reset' ? 'إعادة تعيين كلمة المرور' : 'استعادة الحساب'} initial={{ email: new URLSearchParams(location.search).get('email') ?? '' }} fields={[
        { name:'email',label:'البريد الإلكتروني',type:'email',required:true },
        ...(recovery==='reset'?[{name:'password',label:'كلمة المرور الجديدة',type:'password',required:true},{name:'password_confirmation',label:'تأكيد كلمة المرور',type:'password',required:true}]:[]),
    ]} onClose={()=>setRecovery(null)} save={data=>api(recovery==='reset'?'/auth/reset-password':'/auth/forgot-password',{method:'POST',authenticated:false,data:{...data,...(recovery==='reset'?{token:decodeURIComponent(location.pathname.split('/').at(-1))}:{})}})} onSaved={()=>{setRecovery(null);if(location.pathname.startsWith('/reset-password/'))history.replaceState(null,'','/');setError('إذا كان الحساب موجوداً ستصل تعليمات الاستعادة إلى بريده. عند إعادة التعيين، سجّل الدخول بكلمة المرور الجديدة.');}} />}</>;
}
function Workspace({ context, themeMode, toggleTheme, logout }) {
    const [page, setPage] = useState(currentPage), [mobileOpen, setMobileOpen] = useState(false);
    const toasterId = useId('teami-toaster'), { dispatchToast } = useToastController(toasterId);
    let user = {}; try { user = JSON.parse(localStorage.getItem('teami_user') || '{}'); } catch { /* Presentation only; authorization is server-owned. */ }
    const company = context.tenant, permissions = context.permissions || [], can = permission => permissions.includes(permission);
    const allowed = item => (item.key!=='rules'||(can('data.sensitive.read')&&(can('advisor.rule.manage')||can('advisor.rule.approve')))) && (!item.permissions || (item.all ? item.permissions.every(can) : item.permissions.some(can)));
    const active = navigation.find(x => x.key === page) || navigation[0];
    const navigate = next => { setPage(next); location.hash = next; setMobileOpen(false); };
    useEffect(() => { const onHash = () => setPage(currentPage()); window.addEventListener('hashchange', onHash); return () => window.removeEventListener('hashchange', onHash); }, []);
    const notify = message => dispatchToast(<Toast><ToastTitle>{message}</ToastTitle><ToastBody>تم تحديث بيانات مساحة العمل.</ToastBody></Toast>, { intent: 'success' });
    const advisorNavigation = navigation.filter(item => ['rules', 'sources', 'setup', 'decisions', 'reports', 'bonus', 'analytics', 'imports', 'integrations'].includes(item.key) && allowed(item));
    const nav = <nav aria-label="التنقل الرئيسي" className="nav-list">{navigation.filter(item => !item.menu).map(item => <React.Fragment key={item.key}>{item.group && <div className="nav-group">{item.group}</div>}<Button data-page={item.key} appearance="subtle" icon={<item.icon />} className={`nav-button ${page === item.key ? 'selected' : ''}`} aria-current={page === item.key ? 'page' : undefined} disabled={!allowed(item)} onClick={() => navigate(item.key)}>{item.label}</Button></React.Fragment>)}</nav>;
    const props = { can, company, user: { ...user, ...context.user }, notify, navigate, logout };
    const pages = { rules: CompanyRules, sources: SourceControl, setup: AdvisorSetup, decisions: Decisions, integrations: Integrations, reports: Reports, bonus: Bonus, analytics: Analytics, imports: Imports, operations: Operations, administration: Administration, security: AccountSecurity, home: Home, employees: Employees, leave: Leave, customers: Customers, opportunities: Opportunities, payroll: Payroll, knowledge: Knowledge, billing: Billing };
    const Page = pages[page] || Home;
    return <div className="workspace"><a className="skip-link" href="#main-content" onClick={event => { event.preventDefault(); document.getElementById('main-content')?.focus(); }}>انتقل إلى المحتوى</a><div className="workspace-main"><header className="topbar"><div className="topbar-brand"><Button className="mobile-menu" appearance="subtle" icon={<Navigation24Regular />} aria-label="فتح القائمة" onClick={() => setMobileOpen(true)} /><Brand small /></div><div className="desktop-navigation">{nav}</div><div className="topbar-actions"><Tooltip content={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'} relationship="label"><Button appearance="subtle" icon={themeMode === 'dark' ? <WeatherSunny24Regular /> : <WeatherMoon24Regular />} aria-label={themeMode === 'dark' ? 'الوضع الفاتح' : 'الوضع الداكن'} onClick={toggleTheme} /></Tooltip><Divider vertical /><Menu><MenuTrigger disableButtonEnhancement><Button appearance="subtle" className="account-button" icon={<ChevronDown20Regular />} iconPosition="after"><Avatar name={user.name || user.email || 'مستخدم'} size={32} color="brand" /><span className="account-name">{user.name || 'حسابي'}</span></Button></MenuTrigger><MenuPopover><div className="account-summary"><strong>{user.name || 'مستخدم'}</strong><span dir="ltr">{user.email || ''}</span><span>{company.name}</span></div><MenuList>{can('integration.read') && can('data.sensitive.read') && <MenuItem onClick={() => navigate('integrations')}>التكاملات</MenuItem>}{can('advisor.report.read') && can('data.sensitive.read') && <MenuItem onClick={() => navigate('reports')}>تقارير المستشار</MenuItem>}{can('advisor.bonus.read') && can('data.sensitive.read') && can('hr.compensation.read') && <MenuItem onClick={() => navigate('bonus')}>السياسات والموافقات</MenuItem>}{can('advisor.sales.read') && can('data.sensitive.read') && <MenuItem onClick={() => navigate('analytics')}>تحليل الأداء</MenuItem>}{can('data.import.manage') && can('data.sensitive.read') && <MenuItem onClick={() => navigate('imports')}>بيانات الشركة</MenuItem>}{can('identity.membership.manage') && <MenuItem onClick={() => navigate('administration')}>المستخدمون والصلاحيات</MenuItem>}<MenuItem onClick={() => navigate('security')}>أمان الحساب</MenuItem><MenuItem icon={<SignOut24Regular />} onClick={logout}>تسجيل الخروج</MenuItem></MenuList></MenuPopover></Menu></div></header>
            <div className="workspace-strip"><div className="workspace-context"><span className="workspace-monogram" aria-hidden="true">{company.name?.slice(0, 1)}</span><span>{company.name}</span><span className="context-divider">/</span><strong>{active.label}</strong></div><nav aria-label="أدوات المستشار" className="advisor-navigation">{advisorNavigation.map(item => <Button key={item.key} appearance="subtle" className={`advisor-nav ${page === item.key ? 'selected' : ''}`} aria-current={page === item.key ? 'page' : undefined} icon={<item.icon />} onClick={() => navigate(item.key)}>{item.label}</Button>)}</nav></div>
            <main id="main-content" tabIndex={-1} className="content" key={page}><TrialBanner enabled={can('billing.subscription.read')}/>{allowed(active) ? <Page {...props} /> : <Notice intent="warning">لا يتضمن دورك صلاحية الوصول إلى هذا القسم.</Notice>}</main>
        </div><OverlayDrawer open={mobileOpen} position="start" onOpenChange={(_, d) => setMobileOpen(d.open)}><DrawerHeader><DrawerHeaderTitle action={<Button appearance="subtle" icon={<Dismiss24Regular />} aria-label="إغلاق القائمة" onClick={() => setMobileOpen(false)} />}><Brand small /></DrawerHeaderTitle></DrawerHeader><DrawerBody><div className="drawer-company">{company.name}</div>{nav}<div className="nav-group">المستشار والإدارة</div>{navigation.filter(item => item.menu && allowed(item)).map(item => <Button key={item.key} data-page={item.key} className="nav-button" appearance="subtle" icon={<item.icon />} onClick={() => navigate(item.key)}>{item.label}</Button>)}</DrawerBody></OverlayDrawer><Toaster toasterId={toasterId} position="bottom-end" />
    </div>;
}
export default function App() {
    const [themeMode, setThemeMode] = useState(() => localStorage.getItem('teami_theme') === 'dark' ? 'dark' : 'light');
    const [context, setContext] = useState(null), [booting, setBooting] = useState(!!localStorage.getItem('teami_token')), [bootError, setBootError] = useState(''), [attempt, setAttempt] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        if (!localStorage.getItem('teami_token')) { setBooting(false); return; }
        setBooting(true); setBootError('');
        api('/platform/context', { signal: controller.signal }).then(r => r.data?.platform_owner ? r : api('/tenant/context', { signal: controller.signal })).then(r => { if (!controller.signal.aborted) setContext(r.data); }).catch(error => { if (!controller.signal.aborted && error.status !== 401) setBootError(error.message); }).finally(() => { if (!controller.signal.aborted) setBooting(false); });
        return () => controller.abort();
    }, [attempt]);
    useEffect(() => { const expired = () => { clearSession(); setContext(null); setBootError(''); }; window.addEventListener('teami:unauthorized', expired); return () => window.removeEventListener('teami:unauthorized', expired); }, []);
    async function logout() { try { await api('/auth/logout', { method: 'POST' }); } catch { /* Local logout remains available if the server cannot be reached. */ } clearSession(); setContext(null); setBootError(''); location.hash = ''; }
    const toggleTheme = () => setThemeMode(mode => { const next = mode === 'dark' ? 'light' : 'dark'; localStorage.setItem('teami_theme', next); return next; });
    const publicPage = location.pathname === '/privacy' ? 'privacy' : location.pathname === '/register' ? 'register' : location.pathname === '/welcome' || (!context && !localStorage.getItem('teami_token') && location.pathname === '/') ? 'landing' : null;
    useEffect(() => {document.title = publicPage ? 'TAIE | شركتك أوضح. فريقك أقرب.' : 'TAIE | مساحة العمل';}, [publicPage,context]);
    const theme = { ...(themeMode === 'dark' ? darkTheme : lightTheme), fontFamilyBase: '"Almarai", "Segoe UI", sans-serif', fontFamilyNumeric: '"Almarai", sans-serif' };
    return <FluentProvider theme={theme} dir="rtl" className="teami-provider" data-theme={themeMode}>
        {publicPage === 'landing' ? <Landing authenticated={!!context}/> : publicPage === 'privacy' ? <Privacy/> : publicPage === 'register' ? <TrialRegistration onAuthenticated={() => setAttempt(x=>x+1)}/> : booting ? <div className="boot-screen"><Brand /><Spinner label="جارٍ فتح مساحة العمل…" /></div> : bootError ? <div className="boot-screen"><Brand /><Notice intent="error">{bootError}</Notice><div className="actions"><Button appearance="primary" onClick={() => setAttempt(x => x + 1)}>إعادة المحاولة</Button><Button onClick={logout}>تسجيل الخروج</Button></div></div> : context ? (context.platform_owner ? <PlatformWorkspace context={context} themeMode={themeMode} toggleTheme={toggleTheme} logout={logout} /> : <Workspace context={context} themeMode={themeMode} toggleTheme={toggleTheme} logout={logout} />) : <Login onAuthenticated={() => setAttempt(x => x + 1)} />}
    </FluentProvider>;
}
