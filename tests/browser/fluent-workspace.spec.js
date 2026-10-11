import { test, expect } from '@playwright/test';

const ids = {
    employee: '11111111-1111-4111-8111-111111111111', department: '22222222-2222-4222-8222-222222222222',
    position: '33333333-3333-4333-8333-333333333333', type: '44444444-4444-4444-8444-444444444444',
    leave: '55555555-5555-4555-8555-555555555555', customer: '66666666-6666-4666-8666-666666666666',
};
const allPermissions = ['hr.employee.read', 'hr.employee.create', 'hr.employee.update', 'hr.department.read', 'hr.position.read', 'hr.leave.read', 'hr.leave.create', 'hr.leave.manage', 'hr.leave.approve', 'hr.payroll.read', 'data.sensitive.read', 'sales.customer.read', 'sales.customer.create', 'sales.opportunity.read', 'ai.knowledge.read', 'ai.ask', 'billing.subscription.read', 'billing.usage.read'];
async function fixture(page, { signedIn = true, permissions = allPermissions, mfa = false, failUpdateOnce = false, platformOwner = false, publicTrial = false, failSignupOnce = false } = {}) {
    const writes = [], errors = [];
    const department = { uuid: ids.department, name: 'المبيعات' }, position = { uuid: ids.position, title: 'مدير المبيعات', department };
    const employee = { uuid: ids.employee, employee_number: 'EMP-1', first_name: 'موظف', last_name: 'تجريبي', work_email: 'employee@example.test', employment_status: 'active', employment_type: 'full_time', hired_at: '2026-01-01', department, position };
    const employees = [employee], customers = [{ uuid: ids.customer, customer_number: 'C-1', name: 'شركة الاختبار', type: 'organization', status: 'active', country_code: 'IQ', email: 'contact@example.test' }];
    let leaves = [];
    const publicPlans=[{uuid:'77777777-7777-4777-8777-777777777777',name:'خطة الأعمال',currency:'USD',unit_amount:2500,interval:'month',limits:{users:10,ai_requests_per_month:100},is_public:true}];
    const publicCatalog={plans:publicPlans,activities:[{value:'general',label:'عام'},{value:'technology',label:'البرمجيات والتكنولوجيا'}],countries:[{value:'IQ',label:'العراق'},{value:'SA',label:'السعودية'}],company_sizes:[{value:'1-5',label:'1–5 موظفين'},{value:'6-20',label:'6–20 موظفًا'}],trial:{days:4,ai_requests_total:25,users:5},registration_enabled:true,ai_available:false};
    let trialCreated=false;
    const platformPlans = [], platformSubscriptions = [];
    const platformTenants = [{uuid:'platform-company',name:'شركة منصة تجريبية',slug:'fixture-company',status:'active',users_count:1}];
    const type = { uuid: ids.type, code: 'ANNUAL', name: 'سنوية', status: 'active', requires_approval: true, annual_allowance_days: '20.00' };
    const types = [type];
    page.on('pageerror', e => errors.push(e.message));
    const externalReference={key:'cipd-performance',title:'إدارة الأداء وتطوير الفريق',publisher:'CIPD',url:'https://www.cipd.org/en/knowledge/factsheets/performance-factsheet/',category:'team',summary:'مرجع لتحسين مراجعات الأداء وتطوير الفريق.',reviewed_at:'2026-10-07',recommended:true,status:'not_added'};
    let businessActivity='general', coverageConfirmed=false;
    const integrationConnections=[], integrationBatches=[];
    const reportRows=[], reportSchedules=[], reconciliationRows=[];
    const reportSnapshot={kind:'sales',notes:['التغطية غير مؤكدة؛ طابق المصدر قبل القرار.'],totals:{USD:{net_sales_minor:'10000',collected_minor:'7000',balance_minor:'3000',overdue_balance_minor:'3000',credit_minor:'0'}},previous:{from:'2026-08-02',to:'2026-08-31',totals:{USD:{net_sales_minor:'8000',collected_minor:'6000'}}},evidence:[{uuid:'I1',external_id:'I1',source_system:'primary',employee_name:'موظف تجريبي',currency:'USD',net_sales_minor:'10000',collected_minor:'7000'}]};
    const bonusPolicy = {uuid:'policy-1',name:'سياسة الشركة التجريبية',version:1,currency:'USD',mode:'enforced',valid_from:'2026-01-01',valid_to:'2026-12-31',rules:{minimum_sales_percent:'100',minimum_collection_percent:'80',maximum_monthly_base_percent:'10'}};
    const bonusRequests=[];
    const snapshot={policy:bonusPolicy,employee:{name:'موظف تجريبي'},result:'objection',next_action:'راجع المصادر قبل القرار؛ لا ينفذ الطلب صرفاً.',reasons:[{code:'collection_threshold',level:'objection',message:'نسبة التحصيل 70.00%؛ السياسة تتطلب 80%.'},{code:'coverage_unconfirmed',level:'missing',message:'اكتمال بيانات الشركة غير مؤكد.'}],metrics:{net_sales_minor:'10000',collected_minor:'7000'},target:{amount_minor:'10000'},cap_minor:'1000',evidence:[{uuid:'I1',external_id:'I1',source_system:'primary',currency:'USD',sales_minor:'10000',returns_minor:'0',collected_minor:'7000'}]};
    await page.route('**/api/**', async route => {
        const request = route.request(), u = new URL(request.url()), path = u.pathname.replace('/api', '');
        const fulfill = (body, status = 200) => route.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) });
        if (path === '/advisor/imports/inspect' && request.method() === 'POST') return fulfill({data:{sheets:[{index:0,name:'شركة',columns:['رمز العميل','اسم العميل','ملاحظة']}],targets:['customer_number','name','email']}});
        if (path === '/advisor/imports/preview' && request.method() === 'POST') {
            writes.push({path,method:'POST',body:request.postData(),contentType:request.headers()['content-type']});
            return fulfill({data:{uuid:'import-batch',kind:'customers',source_system:'primary',status:'validated',row_count:1,error_count:0,errors:[]}});
        }
        if (path === '/advisor/data/reconciliations/detailed' && request.method() === 'POST') {
            writes.push({path,body:request.postData(),contentType:request.headers()['content-type'],key:request.headers()['idempotency-key']});
            const actual={invoices:{count:1,amount_minor:'10000'},receipts:{count:0,amount_minor:'0'},returns:{count:0,amount_minor:'0'}};
            const x={uuid:'reconciliation-1',method:'record_matching',source_system:'primary',from:'2026-09-01',to:'2026-09-30',as_of:'2026-10-06',currency:'USD',status:'mismatched',current_status:'mismatched',evidence_reference:'Independent-Report',review_note:'راجعت تقرير المصدر المستقل',snapshot:{expected:actual,actual,record_counts:{matched:0,missing_in_teami:0,unexpected_in_teami:0,changed_fields:1},differences:{invoices:{count:0,amount_minor:'0'},receipts:{count:0,amount_minor:'0'},returns:{count:0,amount_minor:'0'}}},current:{readiness:{invoices_without_area:0,invoices_without_lines:0,invoices_with_line_difference:0}}};
            reconciliationRows.push(x);return fulfill({data:x},201);
        }
        if (path === '/public/catalog') return fulfill({data:publicCatalog});
        if (path === '/platform/context') return fulfill({data:{platform_owner:platformOwner,user:{name:'مالك المنصة',email:'platform@example.test'}}});
        if (path === '/platform/plans' && request.method() === 'GET') return fulfill({data:platformPlans});
        if (path === '/platform/subscriptions' && request.method() === 'GET') return fulfill({data:platformSubscriptions,current_page:1,last_page:1,total:platformSubscriptions.length});
        if (path === '/platform/tenants' && request.method() === 'GET') return fulfill({data:platformTenants,current_page:1,last_page:1,total:platformTenants.length});
        if (request.method() !== 'GET') {
            const data = request.postDataJSON(); writes.push({ path, data, method: request.method(), key: request.headers()['idempotency-key'] });
            if (path === '/public/trials') {if(failSignupOnce){failSignupOnce=false;return fulfill({message:'تعذّر الاتصال مؤقتًا'},503);}trialCreated=true;return fulfill({data:{tenant:{uuid:'99999999-9999-4999-8999-999999999999',slug:'trial-fixture',name:data.name},trial:{starts_at:new Date().toISOString(),ends_at:new Date(Date.now()+4*86400000).toISOString(),ai_requests_total:25,users:5}}},201);}
            if (path === '/platform/plans') {const plan={uuid:'plan-fixture',...data,limits:{users:Number(data.users),ai_requests_per_month:Number(data.ai_requests_per_month)}};platformPlans.push(plan);return fulfill({data:plan},201);}
            if (path === '/platform/subscriptions') {const sub={uuid:'subscription-fixture',company:platformTenants[0].name,plan:platformPlans[0].name,state:'scheduled',...data};platformSubscriptions.push(sub);return fulfill({data:sub},201);}
            if (path === '/platform/tenants') {const tenant={uuid:'new-platform-company',name:data.name,slug:data.slug,status:'active',users_count:1};platformTenants.unshift(tenant);return fulfill({data:tenant},201);}
            if (path === '/advisor/sales/coverage') {coverageConfirmed=true;return fulfill({data:{uuid:'coverage-1'}},201);}
            if (path === '/advisor/data/reconciliations') {
                const actual={invoices:{count:1,amount_minor:'10000'},receipts:{count:1,amount_minor:'5000'},returns:{count:0,amount_minor:'0'}};
                const readiness={invoices_without_area:0,invoices_without_lines:0,invoices_with_line_difference:0};
                const x={uuid:'reconciliation-1',source_system:data.source_system,from:data.from,to:data.to,as_of:data.as_of,currency:data.currency,status:'matched',current_status:'matched',evidence_reference:data.evidence_reference,review_note:data.review_note,snapshot:{expected:actual,actual,differences:{invoices:{count:0,amount_minor:'0'},receipts:{count:0,amount_minor:'0'},returns:{count:0,amount_minor:'0'}}},current:{readiness}};reconciliationRows.push(x);return fulfill({data:x},201);
            }
            if (path === '/advisor/reports') {reportRows.push({uuid:'report-1',kind:data.kind,period_from:data.from,period_to:data.to,as_of:data.as_of,version:1,created_at:'2026-10-05T12:00:00Z',snapshot:reportSnapshot});return fulfill({data:reportRows[0]});}
            if (path === '/advisor/report-schedules') {reportSchedules.push({uuid:'schedule-1',...data,enabled:true,next_run_at:'2026-10-12T06:00:00Z',last_error_code:null});return fulfill({data:reportSchedules[0]},201);}
            if (path === '/advisor/report-schedules/schedule-1/disable') {reportSchedules[0].enabled=false;return fulfill({data:reportSchedules[0]});}
            if (path === '/advisor/reports/report-1/explain') return fulfill({data:{uuid:'explanation',notice:'تفسير AI قابل للمراجعة.',observations:[{reference:'F2',text:'طابق المصدر قبل اتخاذ قرار.'}],references:[{reference:'F2',label:'اكتمال البيانات',value:'غير مؤكد'}]}});
            if (path === '/advisor/bonus-policies') return fulfill({data:{...bonusPolicy,...data}},201);
            if (path === '/advisor/bonus-requests') {bonusRequests.push({uuid:'bonus-1',employee_name:'موظف تجريبي',amount_minor:'1500',currency:'USD',period_from:data.period_from,period_to:data.period_to,status:'pending',result:'objection',reason:data.reason,snapshot,evaluated_at:'2026-10-05T00:00:00.000000Z',review_token:'a'.repeat(64)});return fulfill({data:bonusRequests[0]},201);}
            if (path.endsWith('/bonus-requests/bonus-1/evaluate')) return fulfill({data:bonusRequests[0]});
            if (path.endsWith('/bonus-requests/bonus-1/decision')) {Object.assign(bonusRequests[0],{status:data.decision==='approve'?'approved':'rejected',decision_reason:data.reason,overridden:data.decision==='approve'});return fulfill({data:bonusRequests[0]});}
            if (path === '/knowledge/business-activity') {businessActivity=data.activity;return fulfill({data});}
            if (path === '/knowledge/external-references/cipd-performance/activate') {externalReference.status='active';return fulfill({data:{origin:'external_reference',status:'queued'}},202);}
            if (path === '/knowledge/external-references/cipd-performance/disable') {externalReference.status='disabled';return fulfill({data:{status:'disabled'}});}
            if (path === '/integrations/connections') { const x={...data,uuid:'connection-1',source_system:'int_test',status:'active',native_adapter_ready:false};integrationConnections.push(x);return fulfill({data:x},201); }
            if (path === '/integrations/connections/connection-1/status') {Object.assign(integrationConnections[0],data);return fulfill({data:integrationConnections[0]});}
            if (path === '/integrations/connections/connection-1/preview') { const x={uuid:'int-batch',kind:data.kind,status:'validated',row_count:data.records.length,error_count:0,errors:[]};integrationBatches.push(x);return fulfill({data:x}); }
            if (path === '/advisor/imports/int-batch/commit') {integrationBatches[0].status='imported';return fulfill({data:integrationBatches[0]});}
            if (path === '/auth/login') {
                if (mfa && !data.mfa_code) return fulfill({ error: { code: 'MFA_REQUIRED', message: 'MFA required' } }, 202);
                return fulfill({ data: { token: 'fixture-token', user: { name: 'مدير الاختبار', email: data.email }, tenant: { uuid: 'tenant', name: 'Teami' } } });
            }
            if (path === '/advisor/imports/import-batch/commit') return fulfill({data:{uuid:'import-batch',kind:'customers',source_system:'primary',status:'imported',row_count:1,error_count:0,errors:[]}});
            if (path === '/auth/logout') return fulfill({ data: {} });
            if (path === '/hr/employees') { const x = { ...data, uuid: 'new-employee', department: data.department_uuid ? department : null, position: data.position_uuid ? position : null }; employees.push(x); return fulfill({ data: x }, 201); }
            if (path === '/hr/employees/' + ids.employee) {
                if (failUpdateOnce) { failUpdateOnce = false; return fulfill({ message: 'تعذّر الاتصال مؤقتاً' }, 503); }
                Object.assign(employee, data);
                if (data.department_uuid !== undefined) employee.department = data.department_uuid ? department : null;
                if (data.position_uuid !== undefined) employee.position = data.position_uuid ? position : null;
                return fulfill({ data: employee });
            }
            if (path === '/sales/customers') { customers.push({ ...data, uuid: 'new-customer' }); return fulfill({ data: customers.at(-1) }, 201); }
            if (path === '/hr/leave-types') { types.push({ ...data, uuid: 'new-type', status: 'active' }); return fulfill({ data: types.at(-1) }, 201); }
            if (path === '/hr/leave-requests') { leaves.push({ uuid: ids.leave, employee, leave_type: type, status: 'pending', ...data }); return fulfill({ data: leaves.at(-1) }, 201); }
            if (path.startsWith('/hr/leave-requests/') && path.endsWith('/decision')) { leaves[0].status = data.decision === 'approve' ? 'approved' : 'rejected'; return fulfill({ data: leaves[0] }); }
            if (path === '/ai/ask') return fulfill({ data: { answer: 'هذه إجابة اختبار من سياسة الشركة.', citations: [{ document_uuid: 'policy', title: 'سياسة الإجازات' }] } });
            return fulfill({ data: {} });
        }
        if (path === '/knowledge/external-references') return fulfill({data:{activity:businessActivity,activities:[{value:'general',label:'عام'},{value:'pharma',label:'الأدوية والمكاتب العلمية'}],references:[externalReference]}});
        if (path === '/integrations/catalog') return fulfill({data:[{key:'maxcon',name:'MaxCon'},{key:'teami_pro',name:'Teami Pro'},{key:'odoo',name:'Odoo'}]});
        if (path === '/integrations/connections') return fulfill({data:{data:integrationConnections,current_page:1,last_page:1}});
        if (path === '/integrations/connections/connection-1/batches') return fulfill({data:{data:integrationBatches,current_page:1,last_page:1}});
        if (path === '/advisor/reports') return fulfill({data:{data:reportRows,current_page:1,last_page:1}});
        if (path === '/advisor/reports/report-1') return fulfill({data:reportRows[0]});
        if (path === '/advisor/report-schedules') return fulfill({data:reportSchedules});
        if (path === '/advisor/bonus-policies') return fulfill({data:{data:[bonusPolicy],current_page:1,last_page:1}});
        if (path === '/advisor/bonus-requests') return fulfill({data:{data:bonusRequests,current_page:1,last_page:1}});
        if (path === '/advisor/bonus-requests/bonus-1') return fulfill({data:bonusRequests[0]});
        if (path === '/advisor/data/sources') return fulfill({data:['primary']});
        if (path === '/advisor/data/reconciliations') return fulfill({data:{data:reconciliationRows,current_page:1,last_page:1}});
        if (path === '/advisor/data/reconciliations/reconciliation-1/differences') return fulfill({data:{data:[{kind:'invoices',external_id:'0001',type:'changed_fields',fields:{customer_number:{source:'C1',imported:'C2'}},source:{currency:'USD'},imported:{currency:'USD'}}],current_page:1,last_page:1,total:1}});
        if (path === '/advisor/data/reconciliations/reconciliation-1') return fulfill({data:reconciliationRows[0]});
        if (path === '/advisor/data/catalog') {
            const kind=u.searchParams.get('kind');
            const rows=kind==='products'?[{uuid:'product',code:'P1',name:'منتج الاختبار',base_unit:'علبة'}]:kind==='areas'?[{uuid:'area',code:'BGH1',province_name:'بغداد',city_name:'الكرادة'}]:kind==='invoice_lines'?[{uuid:'line',source_system:'primary',invoice_external_id:'I1',external_id:'L1',product_code:'P1',quantity:'2.500',base_unit:'علبة',net_amount_minor:'10000',currency:'USD'}]:[];
            return fulfill({data:{data:rows,current_page:1,last_page:1}});
        }
        if (path === '/advisor/sales/economic-comparison') {
            const period = {status:'available',reference_date:'2026-09-01',warnings:[],political_events:[],fx:{observed_usd:'100.00',constant_usd:'100.00',conversion_difference_usd:'0.00',missing_invoice_dates:[],rate_evidence:[]}};
            return fulfill({data:{previous_period:{from:'2026-08-01',to:'2026-08-31'},coverage_confirmed:{current:coverageConfirmed,previous:false},analyses:{official:{current:period,previous:period,comparison:{observed_usd_growth_percent:'0.00',constant_usd_growth_percent:'0.00'}},market:{current:period,previous:period,comparison:{observed_usd_growth_percent:'0.00',constant_usd_growth_percent:'0.00'}}}}});
        }
        if (path === '/advisor/sales/metrics') return fulfill({data:{filters:{from:u.searchParams.get('from'),to:u.searchParams.get('to'),as_of:u.searchParams.get('as_of')},rows:[{employee_uuid:ids.employee,employee_name:'مندوب الاختبار',employee_number:'E1',currency:'USD',invoice_count:1,sales_minor:'10000',collected_minor:'5000',balance_minor:'5000',overdue_balance_minor:'5000',collection_percent:'50.00'}],quality:{invoice_count:1,recorded_receipt_count:2,latest_financial_import_at:null,coverage_confirmed:coverageConfirmed,fingerprint:'a'.repeat(64),coverage:{status:coverageConfirmed?'confirmed':'unconfirmed'},reconciliation:{USD:{invoice_count:1,receipt_count:2,return_count:0,sales_minor:'10000',returns_minor:'0',collected_minor:'5000',balance_minor:'5000',credit_minor:'0',difference_minor:'0',balanced:true}},missing:{last_invoice_date:'2026-09-01',last_receipt_date:'2026-09-15',invoices_without_receipts:0},sources:[],warnings:['اكتمال الملفات غير مؤكد.']}}});
        if (path === '/advisor/sales/invoices') return fulfill({data:[{uuid:'invoice',external_id:'I1',source_system:'primary',invoice_date:'2026-09-01',due_date:'2026-10-01',currency:'USD',sales_minor:'10000',collected_minor:'5000',balance_minor:'5000',receipt_count:2}],meta:{total:1,current_page:1,last_page:1}});
        if (path === '/advisor/imports/schemas') return fulfill({data:[{kind:'customers',columns:['customer_number','name','email'],required:['customer_number','name']}]});
        if (path === '/advisor/imports') return fulfill({data:{data:[],current_page:1,last_page:1}});
        if (path === '/tenant/context') return fulfill({ data: { tenant: { name: 'Teami', uuid: 'tenant' }, user: { uuid: 'user' }, permissions } });
        const paginated = rows => ({ data: rows, meta: { total: rows.length, current_page: 1, last_page: 1 } });
        if (path === '/hr/employees') { const search = u.searchParams.get('search'), status = u.searchParams.get('status'); let rows = employees.filter(x => (!search || `${x.first_name} ${x.last_name} ${x.employee_number}`.includes(search)) && (!status || x.employment_status === status)); return fulfill(paginated(rows)); }
        if (path === '/hr/employees/' + ids.employee) return fulfill({ data: employee });
        if (path === '/hr/departments') return fulfill({ data: [department] });
        if (path === '/hr/positions') return fulfill({ data: [position] });
        if (path === '/hr/leave-types') return fulfill({ data: types });
        if (path === '/hr/leave-requests') return fulfill(paginated(leaves.filter(x => !u.searchParams.get('status') || x.status === u.searchParams.get('status'))));
        if (path === '/hr/leave-balances') return fulfill({ data: [{ leave_type_name: 'سنوية', allocated_days: '20.00', used_days: '2.00', pending_days: '0.00', available_days: '18.00' }] });
        if (path === '/sales/customers') return fulfill(paginated(customers));
        if (path === '/sales/opportunities') return fulfill(paginated([{ uuid: 'opportunity', name: 'فرصة تجريبية', customer: customers[0], stage: { name: 'التفاوض' }, amount: '5000', currency_code: 'USD', status: 'open', expected_close_on: '2026-12-01' }]));
        if (path === '/hr/payroll/runs') return fulfill(paginated([{ uuid: 'payroll', name: 'رواتب أكتوبر', period_starts_on: '2026-10-01', period_ends_on: '2026-10-31', employee_count: 1, currency_code: 'IQD', status: 'draft' }]));
        if (path === '/knowledge/sources') return fulfill({ data: [{ uuid: 'source', name: 'سياسات الشركة', documents_count: 1, status: 'active' }] });
        if (path.endsWith('/contacts')) return fulfill(paginated([]));
        if (path === '/billing/plans' || path === '/sales/pipelines') return fulfill({data: []});
        if (path === '/identity/members') return fulfill(paginated([]));
        if (path === '/identity/permissions') return fulfill({data: permissions.map(name => ({name}))});
        if (path === '/billing/invoices') return fulfill({data:{data:[{uuid:'invoice',number:'TM-TEST',plan_name:'Launch',currency:'USD',amount_minor:2500,period_start:'2026-10-01',period_end:'2026-11-01',status:'open'}],current_page:1,last_page:1}});
        if (path === '/billing/payments') return fulfill(paginated([]));
        if (path === '/billing/trial') return fulfill({data:trialCreated||publicTrial?{state:'active',ends_at:new Date(Date.now()+4*86400000).toISOString(),remaining:25,used:0,limit:25,ai_available:false}:null});
        if (path === '/billing/subscription') return fulfill({ data: null });
        if (path === '/billing/usage') return fulfill({ data: { totals: { 'ai.tokens': 120 } } });
        return fulfill({ message: 'Unexpected fixture route: ' + path }, 404);
    });
    if (signedIn) await page.addInitScript(() => {
        localStorage.setItem('teami_token', 'fixture-token'); localStorage.setItem('teami_tenant', 'teami'); localStorage.setItem('teami_user', JSON.stringify({ name: 'مدير الاختبار', email: 'manager@example.test' }));
    });
    await page.goto(signedIn?'/':'/login');
    return { writes, errors };
}
async function choose(page, control, value) {
    const input = control.and(page.getByRole('combobox'));
    await input.click(); await input.fill('');
    await page.locator('[role=option][data-option-value='+JSON.stringify(String(value))+']').click();
}
async function go(page, key) {
    await page.locator('.desktop-navigation [data-page="' + key + '"]').click();
}

test('Fluent RTL login, MFA and theme persistence', async ({ page }) => {
    const { errors } = await fixture(page, { signedIn: false, mfa: true });
    await expect(page.locator('.fui-FluentProvider')).toHaveAttribute('dir', 'rtl');
    if (process.env.TEAMI_CAPTURE_PREVIEW) await page.screenshot({ path: '/tmp/teami-fluent-login.png', fullPage: true, animations: 'disabled' });
    await page.getByLabel('معرّف الشركة').fill('teami');
    await page.getByLabel('البريد الإلكتروني').fill('manager@example.test');
    await page.getByLabel('كلمة المرور').fill('Fixture-only-passphrase');
    await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
    await page.getByLabel('رمز المصادقة الثنائية').fill('123456');
    await page.getByRole('button', { name: 'تسجيل الدخول', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    await page.getByRole('button', { name: 'الوضع الداكن', exact: true }).click();
    await expect(page.locator('.teami-provider')).toHaveAttribute('data-theme', 'dark');
    if (process.env.TEAMI_CAPTURE_PREVIEW) await page.screenshot({ path: '/tmp/teami-fluent-dark.png', fullPage: true, animations: 'disabled' });
    await page.reload(); await expect(page.locator('.teami-provider')).toHaveAttribute('data-theme', 'dark');
    await page.locator('.account-button').click();
    await page.getByRole('menuitem', { name: 'تسجيل الخروج', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'تسجيل الدخول', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});

test('Employee creation, edit, details and customer creation retain API semantics', async ({ page }) => {
    const { writes, errors } = await fixture(page, { permissions: [...allPermissions, 'advisor.report.read', 'advisor.bonus.read', 'hr.compensation.read', 'advisor.sales.read', 'data.import.manage'] });
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    if (process.env.TEAMI_CAPTURE_PREVIEW) { await page.evaluate(() => document.fonts.ready); await page.screenshot({ path: '/workspace/scratch/79c980763a1d/deliverables/Teami-Redesign-Desktop.png', fullPage: true, animations: 'disabled' }); }
    await go(page, 'employees'); await expect(page.getByText('مدير المبيعات', { exact: true })).toBeVisible();
    await page.locator('.skip-link').focus(); await page.keyboard.press('Enter');
    await expect(page.locator('#main-content')).toBeFocused();
    await expect(page).toHaveURL(/#employees$/);
    await expect(page.getByRole('button', { name: 'إضافة موظف', exact: true })).toBeEnabled();
    if (process.env.TEAMI_CAPTURE_PREVIEW) await page.screenshot({ path: '/tmp/teami-fluent-employees.png', fullPage: true, animations: 'disabled' });
    await page.getByRole('button', { name: 'تعديل', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('اسم العائلة').fill('محدّث');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.getByText('موظف محدّث', { exact: true })).toBeVisible();
    expect(writes.find(x => x.method === 'PATCH').key).toBeTruthy();
    await page.getByRole('button', { name: 'التفاصيل', exact: true }).click();
    await expect(page.getByRole('dialog').getByText('employee@example.test')).toBeVisible();
    await page.getByRole('dialog').getByRole('button', { name: 'إغلاق', exact: true }).click();
    await page.getByRole('button', { name: 'إضافة موظف', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('الرقم الوظيفي').fill('EMP-2');
    await dialog.getByLabel('الاسم الأول').fill('موظف');
    await dialog.getByLabel('اسم العائلة').fill('جديد');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.getByText('موظف جديد', { exact: true })).toBeVisible();
    await go(page, 'customers'); await page.getByRole('button', { name: 'إضافة عميل', exact: true }).click();
    dialog = page.getByRole('dialog');
    await dialog.getByLabel('رقم العميل').fill('C-2');
    await dialog.getByLabel('اسم العميل').fill('عميل جديد');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.getByText('عميل جديد', { exact: true })).toBeVisible();
    expect(writes.find(x => x.path === '/sales/customers').key).toBeTruthy();
    expect(errors).toEqual([]);
});

test('Leave submission, decision and balance lookup work through Fluent dialogs', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    await go(page, 'leave'); await page.getByRole('button', { name: 'طلب إجازة', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await choose(page, dialog.getByLabel('الموظف'), ids.employee);
    await choose(page, dialog.getByLabel('نوع الإجازة'), ids.type);
    await dialog.getByLabel('من').fill('2026-10-05'); await dialog.getByLabel('إلى').fill('2026-10-06');
    await dialog.getByLabel('أيام العمل المطلوبة').fill('2');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await page.getByRole('button', { name: 'موافقة', exact: true }).click();
    dialog = page.getByRole('dialog'); await dialog.getByLabel('ملاحظة القرار').fill('تمت المراجعة');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.getByText('موافق عليه', { exact: true })).toBeVisible();
    await page.getByRole('tab', { name: 'الأرصدة', exact: true }).click();
    await choose(page, page.getByLabel('الموظف'), ids.employee);
    await page.getByRole('button', { name: 'عرض الرصيد', exact: true }).click(); await expect(page.getByText('18.00', { exact: true })).toBeVisible();
    expect(writes.find(x => x.path.endsWith('/decision')).data).toEqual({ note: 'تمت المراجعة', decision: 'approve' });
    expect(errors).toEqual([]);
});

test('Read-only permissions, all read screens and AI answer rendering', async ({ page }) => {
    const { writes, errors } = await fixture(page, { permissions: allPermissions.filter(x => !x.endsWith('.create') && !x.endsWith('.update') && !x.endsWith('.approve') && !x.endsWith('.manage')) });
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    await go(page, 'employees'); await expect(page.getByRole('button', { name: 'إضافة موظف', exact: true })).toHaveCount(0); await expect(page.getByRole('button', { name: 'تعديل', exact: true })).toHaveCount(0);
    for (const [key, heading] of [['opportunities', 'الفرص التجارية'], ['payroll', 'الرواتب'], ['knowledge', 'المعرفة والمساعد'], ['billing', 'الخطة والاستخدام']]) { await go(page, key); await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible(); }
    await go(page, 'knowledge'); await page.getByLabel('سؤالك').fill('ما هي سياسة الإجازات؟'); await page.getByRole('button', { name: 'اسأل المساعد', exact: true }).click();
    await expect(page.getByText('هذه إجابة اختبار من سياسة الشركة.', { exact: true })).toBeVisible(); await expect(page.getByText('سياسة الإجازات', { exact: true })).toBeVisible();
    expect(writes.find(x => x.path === '/ai/ask').key).toBeTruthy(); expect(errors).toEqual([]);
});

test('Mobile drawer and forms stay within the viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    const { errors } = await fixture(page);
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    if (process.env.TEAMI_CAPTURE_PREVIEW) { await page.evaluate(() => document.fonts.ready); await page.screenshot({ path: '/workspace/scratch/79c980763a1d/deliverables/Teami-Redesign-Mobile.png', fullPage: true, animations: 'disabled' }); }
    await page.getByRole('button', { name: 'فتح القائمة', exact: true }).click();
    await page.locator('.fui-DrawerBody [data-page="employees"]').click();
    await expect(page.getByRole('heading', { name: 'الموظفون', exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'إضافة موظف', exact: true }).click(); await expect(page.getByRole('dialog')).toBeVisible();
    const box = await page.getByRole('dialog').boundingBox(); expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(391);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy(); expect(errors).toEqual([]);
});


test('Leave type creation and balance allocation preserve numbers and boolean policy', async ({ page }) => {
    const { writes, errors } = await fixture(page);
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    await go(page, 'leave'); await page.getByRole('tab', { name: 'أنواع الإجازات', exact: true }).click();
    await page.getByRole('button', { name: 'إضافة نوع', exact: true }).click();
    let dialog = page.getByRole('dialog');
    await dialog.getByLabel('الرمز').fill('SICK'); await dialog.getByLabel('الاسم').fill('مرضية');
    await dialog.getByLabel('الأيام السنوية').fill('14'); await choose(page, dialog.getByLabel('سياسة الموافقة'), 'false');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(page.getByText('مرضية', { exact: true })).toBeVisible();
    expect(writes.find(x => x.path === '/hr/leave-types').data.requires_approval).toBe(false);
    await page.getByRole('tab', { name: 'الأرصدة', exact: true }).click();
    await page.getByRole('button', { name: 'تخصيص رصيد', exact: true }).click();
    dialog = page.getByRole('dialog');
    await choose(page, dialog.getByLabel('الموظف'), ids.employee); await choose(page, dialog.getByLabel('نوع الإجازة'), ids.type);
    await dialog.getByLabel('السنة').fill('2026'); await dialog.getByLabel('الأيام المخصصة').fill('22');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(writes.find(x => x.path === '/hr/leave-balances').data).toEqual({ employee_uuid: ids.employee, leave_type_uuid: ids.type, year: '2026', allocated_days: '22' });
    expect(errors).toEqual([]);
});

test('Failed saves retain form data and reuse the idempotency key when retried', async ({ page }) => {
    const { writes, errors } = await fixture(page, { failUpdateOnce: true });
    await expect(page.getByRole('heading', { name: 'أهلاً مدير' })).toBeVisible();
    await go(page, 'employees'); await page.getByRole('button', { name: 'تعديل', exact: true }).click();
    const dialog = page.getByRole('dialog'); await dialog.getByLabel('اسم العائلة').fill('بعد المحاولة');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click();
    await expect(dialog.getByText('تعذّر الاتصال مؤقتاً', { exact: true })).toBeVisible();
    await expect(dialog.getByLabel('اسم العائلة')).toHaveValue('بعد المحاولة');
    await dialog.getByRole('button', { name: 'حفظ', exact: true }).click(); await expect(page.getByText('موظف بعد المحاولة', { exact: true })).toBeVisible();
    const attempts = writes.filter(x => x.method === 'PATCH'); expect(attempts).toHaveLength(2); expect(attempts[0].key).toBe(attempts[1].key); expect(errors).toEqual([]);
});


test('Launch billing report and operator plan preserve exact money and grant boundaries', async ({page}) => {
    const permissions=[...allPermissions,'platform.billing.manage','billing.invoice.read','billing.payment.report'];
    const {writes,errors}=await fixture(page,{permissions});
    await go(page,'billing');
    await page.getByRole('button',{name:'إبلاغ عن تحويل',exact:true}).click();
    await page.getByLabel('مرجع التحويل').fill('BANK-42');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(writes.find(x=>x.path==='/billing/invoices/invoice/payments')).toMatchObject({data:{reference:'BANK-42',amount_minor:2500}});
    await page.getByRole('button',{name:'إضافة خطة',exact:true}).click();
    await page.getByLabel('رمز الخطة').fill('launch');
    await page.getByLabel('اسم الخطة').fill('خطة الإطلاق');
    await choose(page, page.getByLabel('العملة'), 'USD');
    await page.getByLabel('سعر الاشتراك').fill('25.37');
    await choose(page, page.getByLabel('مدة الاشتراك'), 'month');
    await page.getByLabel('حد المستخدمين').fill('8');
    await page.getByLabel('أسئلة المساعد شهرياً').fill('100');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(writes.find(x=>x.path==='/billing/plans')).toMatchObject({data:{unit_amount:2537,limits:{users:8,ai_requests_per_month:100}}});
    expect(errors).toEqual([]);
});

test('New membership dialog submits chosen permissions and a unique request key', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'identity.membership.manage']});
    await page.locator('.account-button').click();
    await page.getByRole('menuitem',{name:'المستخدمون والصلاحيات',exact:true}).click();
    await page.getByRole('button',{name:'إضافة مستخدم',exact:true}).click();
    await page.getByLabel('اسم المستخدم').fill('عضو جديد');
    await page.getByLabel('البريد الإلكتروني').fill('new@example.test');
    await page.getByLabel('كلمة مرور أولية').fill('Fixture-Strong-42!');
    await page.getByRole('checkbox',{name:'hr.employee.read',exact:true}).check();
    await page.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    const write=writes.find(x=>x.path==='/identity/members');
    expect(write.data.permissions).toEqual(['hr.employee.read']);expect(write.key).toBeTruthy();expect(errors).toEqual([]);
});

test('Operations contact form links the selected customer and preserves boolean input', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'sales.contact.read','sales.contact.write']});
    await go(page,'operations');
    await page.getByRole('tab',{name:'جهات الاتصال',exact:true}).click();
    await choose(page, page.getByLabel('العميل'), ids.customer);
    await page.getByRole('button',{name:'إضافة',exact:true}).click();
    await page.getByLabel('الاسم الأول').fill('جهة');
    await page.getByLabel('اسم العائلة').fill('اختبار');
    await choose(page, page.getByLabel('جهة اتصال رئيسية'), 'true');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(writes.find(x=>x.path===`/sales/customers/${ids.customer}/contacts`).data.is_primary).toBe(true);
    expect(errors).toEqual([]);
});


test('Company CSV upload previews before explicit commit with multipart and request key', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'data.import.manage','data.sensitive.read']});
    await page.locator('.account-button').click();
    await page.getByRole('menuitem',{name:'بيانات الشركة',exact:true}).click();
    await choose(page, page.getByLabel('نوع البيانات'), 'customers');
    await page.getByLabel('ملف CSV').setInputFiles({name:'customers.csv',mimeType:'text/csv',buffer:Buffer.from('customer_number,name,email\nC2,Test,\n')});
    await page.getByRole('button',{name:'معاينة الملف',exact:true}).click();
    await expect(page.getByRole('button',{name:'اعتماد الدفعة كاملة',exact:true})).toBeVisible();
    if(process.env.TEAMI_CAPTURE_PREVIEW) await page.screenshot({path:'/tmp/teami-advisor-imports.png',fullPage:true,animations:'disabled'});
    expect(writes.filter(x=>x.path.endsWith('/commit'))).toHaveLength(0);
    const preview=writes.find(x=>x.path==='/advisor/imports/preview');
    expect(preview.contentType).toContain('multipart/form-data; boundary=');expect(preview.body).toContain('customer_number,name,email');
    await page.getByRole('button',{name:'اعتماد الدفعة كاملة',exact:true}).click();
    await expect(page.getByText('هذه الدفعة معتمدة.',{exact:false})).toBeVisible();
    expect(writes.find(x=>x.path.endsWith('/commit')).key).toBeTruthy();expect(errors).toEqual([]);
});


test('Excel inspection requires explicit column mapping before preview and commit', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'data.import.manage','data.sensitive.read']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'بيانات الشركة',exact:true}).click();
    await choose(page, page.getByLabel('نوع البيانات'), 'customers');
    await page.getByLabel('ملف CSV أو Excel').setInputFiles({name:'company.xlsx',mimeType:'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',buffer:Buffer.from('fixture: XLSX parsing covered by PHP integration tests')});
    await expect(page.getByRole('button',{name:'معاينة الملف',exact:true})).toBeDisabled();
    await page.getByRole('button',{name:'فحص الأعمدة وربطها',exact:true}).click();
    await choose(page, page.getByLabel('ربط customer_number'), 'رمز العميل');await choose(page, page.getByLabel('ربط name',{exact:false}), 'اسم العميل');
    await page.getByRole('button',{name:'معاينة الملف',exact:true}).click();
    await expect(page.getByRole('button',{name:'اعتماد الدفعة كاملة',exact:true})).toBeVisible();
    const body=writes.find(x=>x.path==='/advisor/imports/preview').body;expect(body).toContain('"customer_number":"رمز العميل"');expect(body).toContain('"email":""');expect(errors).toEqual([]);
});

test('Advisor metrics show scope warnings and invoice evidence without writing business data', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'advisor.sales.read','data.sensitive.read']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'تحليل الأداء',exact:true}).click();
    await expect(page.getByRole('heading',{name:'تحليل المبيعات والتحصيل',exact:true})).toBeVisible();
    await expect(page.getByText('اكتمال الملفات غير مؤكد.',{exact:true})).toBeVisible();
    await expect(page.getByText('50.00%',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'الفواتير الداعمة',exact:true}).click();await expect(page.getByText('I1',{exact:true})).toBeVisible();
    expect(writes).toHaveLength(0);expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW)await page.screenshot({path:'/tmp/teami-sales-metrics.png',fullPage:true,animations:'disabled'});
});

test('Bonus workspace creates policy and request then explains objections before a documented decision', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'hr.compensation.read','advisor.bonus.read','advisor.bonus.submit','advisor.bonus.approve','advisor.policy.manage']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'السياسات والموافقات',exact:true}).click();
    await expect(page.getByRole('heading',{name:'السياسات ومراجعة المكافآت',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'إنشاء نسخة سياسة',exact:true}).click();
    await choose(page, page.getByRole('combobox',{name:'العملة',exact:true}), 'USD');await choose(page, page.getByRole('combobox',{name:'نمط التدخل',exact:true}), 'enforced');await page.getByLabel('اسم السياسة').fill('سياسة جديدة');await page.getByLabel('تسري من').fill('2026-01-01');await page.getByLabel('تسري إلى').fill('2026-12-31');
    await page.getByLabel('أدنى تحقيق لهدف صافي المبيعات %').fill('100');await page.getByLabel('أدنى نسبة تحصيل %').fill('80');await page.getByLabel('سقف المكافأة كنسبة من أساس شهر %').fill('10');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button',{name:'طلب مكافأة',exact:true}).click();await choose(page, page.getByRole('combobox',{name:'الموظف',exact:true}), ids.employee);await choose(page, page.getByRole('combobox',{name:'نسخة السياسة',exact:true}), 'policy-1');
    await page.getByLabel('بداية فترة المكافأة').fill('2026-09-01');await page.getByLabel('نهاية الفترة').fill('2026-09-30');await page.getByLabel('الحركات المسجلة حتى').fill('2026-10-05');await page.getByLabel('المبلغ بعملة السياسة').fill('15.00');await page.getByRole('textbox',{name:'سبب الطلب',exact:true}).fill('طلب تجريبي يستند إلى مراجعة المبيعات');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button',{name:'الأسباب والأدلة',exact:true}).click();await expect(page.getByText('نسبة التحصيل 70.00%؛ السياسة تتطلب 80%.',{exact:true})).toBeVisible();await expect(page.getByText('I1',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'قرار المدير',exact:true}).click();await choose(page, page.getByRole('combobox',{name:'القرار',exact:true}), 'reject');await page.getByLabel('سبب القرار وكيف عالجت الاعتراض أو نقص البيانات').fill('تأجيل المكافأة حتى استكمال بيانات التحصيل');await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    expect(writes.find(x=>x.path==='/advisor/bonus-policies').data).toMatchObject({currency:'USD',mode:'enforced',minimum_sales_percent:'100',minimum_collection_percent:'80',maximum_monthly_base_percent:'10'});const decision=writes.find(x=>x.path.endsWith('/bonus-1/decision'));expect(decision.data.review_token).toBe('a'.repeat(64));expect(decision.data.reviewed_at).toContain('2026-10-05');expect(decision.key).toBeTruthy();expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW){await expect(page.getByText('تأجيل المكافأة حتى استكمال بيانات التحصيل',{exact:false})).toBeVisible();await page.evaluate(async()=>{await document.fonts.ready;window.scrollTo(0,0);await Promise.all(document.getAnimations().filter(x=>x.effect?.getComputedTiming().iterations!==Infinity).map(x=>x.finished.catch(()=>{})));await new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve)));});await page.screenshot({path:'/tmp/teami-bonus-review.png',fullPage:true});}
});

test('Bonus menu is hidden without compensation and advisor read grants', async ({page}) => {
    await fixture(page);await page.locator('.account-button').click();await expect(page.getByRole('menuitem',{name:'السياسات والموافقات',exact:true})).toHaveCount(0);
});

test('Advisor reports preserve period and schedule and show AI references without taking decisions', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'advisor.report.read','advisor.report.generate','advisor.report.manage','advisor.report.explain','advisor.sales.read']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'تقارير المستشار',exact:true}).click();
    await page.getByRole('button',{name:'توليد تقرير',exact:true}).click();await choose(page, page.getByRole('combobox',{name:'نوع التقرير',exact:true}), 'sales');
    await page.getByRole('textbox',{name:'من',exact:true}).fill('2026-09-01');await page.getByRole('textbox',{name:'إلى',exact:true}).fill('2026-09-30');await page.getByLabel('الحركات حتى').fill('2026-09-30');await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button',{name:'إعداد جدول',exact:true}).click();await choose(page, page.getByRole('combobox',{name:'نوع التقرير',exact:true}), 'sales');await choose(page, page.getByRole('combobox',{name:'التكرار',exact:true}), 'weekly');await page.getByLabel('ساعة التوليد بتوقيت الشركة').fill('9');await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button',{name:'فتح التقرير',exact:true}).click();await expect(page.getByText('التغطية غير مؤكدة؛ طابق المصدر قبل القرار.',{exact:true})).toBeVisible();await page.getByLabel('سؤال المستشار').fill('ماذا أراجع في هذا التقرير؟');await page.getByRole('button',{name:'طلب تفسير التقرير',exact:true}).click();await expect(page.getByText('طابق المصدر قبل اتخاذ قرار.',{exact:false})).toBeVisible();
    await page.getByRole('button',{name:'إيقاف الجدول',exact:true}).click();await expect(page.getByText('متوقف',{exact:true})).toBeVisible();
    expect(writes.find(x=>x.path==='/advisor/reports').data).toMatchObject({kind:'sales',from:'2026-09-01',to:'2026-09-30',as_of:'2026-09-30',new_version:false});expect(writes.find(x=>x.path==='/advisor/report-schedules').data).toMatchObject({frequency:'weekly',local_hour:'9'});expect(writes.find(x=>x.path.endsWith('/explain')).key).toBeTruthy();expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW){await page.evaluate(()=>window.scrollTo(0,0));await page.screenshot({path:'/tmp/teami-advisor-reports.png',fullPage:true,animations:'disabled'});}
});


test('Integration source previews canonical data before commit and can disable reception', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'integration.read','integration.manage','integration.sync','data.import.manage']});
    await page.getByRole('navigation',{name:'أدوات المستشار'}).getByRole('button',{name:'التكاملات',exact:true}).click();
    await expect(page.getByRole('heading',{name:'التكاملات',exact:true})).toBeVisible();
    await page.getByRole('button',{name:'تعريف مصدر',exact:true}).click();
    await page.getByLabel('اسم المصدر').fill('MaxCon بغداد');await choose(page, page.getByRole('combobox',{name:'البرنامج',exact:true}), 'maxcon');
    await page.getByRole('button',{name:'حفظ',exact:true}).click();await expect(page.getByRole('dialog')).toHaveCount(0);
    await page.getByRole('button',{name:'التفاصيل والدفعات',exact:true}).click();
    const data={schema_version:1,kind:'customers',records:[{customer_number:'C1',name:'عميل تجريبي',email:''}]};
    await page.getByLabel('بيانات الدفعة بصيغة JSON').fill(JSON.stringify(data));await page.getByRole('button',{name:'فحص الدفعة',exact:true}).click();
    await expect(page.getByRole('button',{name:'اعتماد الدفعة',exact:true})).toBeVisible();
    expect(writes.some(x=>x.path==='/advisor/imports/int-batch/commit')).toBeFalsy();
    await page.getByRole('button',{name:'اعتماد الدفعة',exact:true}).click();await expect(page.getByRole('button',{name:'اعتماد الدفعة',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'إيقاف الاستقبال',exact:true}).click();await expect(page.getByLabel('بيانات الدفعة بصيغة JSON')).toBeDisabled();
    expect(writes.find(x=>x.path.endsWith('/preview')).data).toEqual(data);expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW){await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:'/tmp/teami-integrations.png',fullPage:true,animations:'disabled'});}
});


test('Searchable choices filter Arabic labels and keyboard selection preserves canonical values', async ({page}) => {
    const {writes,errors}=await fixture(page);
    await go(page,'employees');await page.getByRole('button',{name:'إضافة موظف',exact:true}).click();
    const dialog=page.getByRole('dialog');await dialog.getByLabel('الرقم الوظيفي').fill('SEARCH-1');await dialog.getByLabel('الاسم الأول').fill('اسم');await dialog.getByLabel('اسم العائلة').fill('تجريبي');
    const combo=dialog.getByRole('combobox',{name:'نوع التوظيف',exact:true});await combo.fill('جُزئي');
    await expect(page.getByRole('option',{name:'دوام جزئي',exact:true})).toBeVisible();await expect(page.getByRole('option',{name:'دوام كامل',exact:true})).toHaveCount(0);
    await combo.press('ArrowDown');await combo.press('Enter');await expect(combo).toHaveValue('دوام جزئي');
    await choose(page,dialog.getByRole('combobox',{name:'القسم',exact:true}),ids.department);await choose(page,dialog.getByRole('combobox',{name:'الوظيفة',exact:true}),ids.position);
    await choose(page,dialog.getByRole('combobox',{name:'القسم',exact:true}),'');await expect(dialog.getByRole('combobox',{name:'الوظيفة',exact:true})).toHaveValue('بدون تحديد');
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    expect(writes.find(x=>x.path==='/hr/employees').data).toMatchObject({employment_type:'part_time',department_uuid:null,position_uuid:null});expect(errors).toEqual([]);
});

test('External library selects activity and requires review before enabling a reference', async ({page}) => {
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'ai.knowledge.manage']});
    await go(page,'knowledge');await expect(page.getByRole('heading',{name:'المراجع الخارجية والتطوير'})).toBeVisible();
    await choose(page,page.getByRole('combobox',{name:'نشاط الشركة',exact:true}),'pharma');
    await page.getByRole('button',{name:'إضافة للمساعد',exact:true}).click();const dialog=page.getByRole('dialog');
    await dialog.getByLabel('سبب ملاءمة المرجع لنشاط شركتك').fill('يساعد في تنظيم مراجعات أداء الفريق');
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();expect(writes.some(x=>x.path.endsWith('/activate'))).toBeFalsy();
    await choose(page,dialog.getByRole('combobox'), 'true');await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    await page.getByRole('button',{name:'إيقاف استخدامه',exact:true}).click();await expect(page.getByRole('button',{name:'إعادة الإضافة للمساعد',exact:true})).toBeVisible();
    expect(writes.find(x=>x.path.endsWith('/activate')).data).toMatchObject({acknowledge:true});expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW){await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:'/tmp/teami-external-library.png',fullPage:true,animations:'disabled'});}
});


test('coverage confirmation requires an explicit source match and preserves minor units', async ({page}) => {
    const state=await fixture(page,{permissions:[...allPermissions,'advisor.sales.read','data.import.manage']});
    await page.goto('/');
    await page.locator('.account-button').click();
    await page.getByRole('menuitem',{name:'تحليل الأداء',exact:true}).click();
    await expect(page.getByRole('table',{name:'المصالحة حسب العملة'})).toBeVisible();
    await expect(page.getByRole('button',{name:'مطابقة المصدر وتأكيد التغطية'})).toBeDisabled();
    await page.getByLabel('عملة التقرير').fill('USD');
    await page.getByRole('option',{name:'USD',exact:true}).click();
    await page.getByRole('button',{name:'عرض التحليل',exact:true}).click();
    await page.getByRole('button',{name:'مطابقة المصدر وتأكيد التغطية'}).click();
    const dialog=page.getByRole('dialog');
    for (const [label,value] of [['عدد الفواتير بالمصدر','1'],['عدد التحصيلات بالمصدر','2'],['عدد المرتجعات بالمصدر','0'],['المبيعات بوحدات العملة الصغيرة','10000'],['المرتجعات بوحدات العملة الصغيرة','0'],['التحصيل بوحدات العملة الصغيرة','5000'],['المصدر ونطاق المطابقة وسبب اكتمال البيانات','تمت مطابقة كشف المصدر المستقل لجميع الفواتير المختارة.']]) await dialog.getByLabel(label).fill(value);
    await dialog.getByLabel('إقرار المراجع').fill('طابقت');
    await page.getByRole('option',{name:'طابقت المصدر لهذه الفترة والعملة وأؤكد التغطية'}).click();
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(page.getByText('تغطية مؤكدة بإقرار بشري للفترة والعملة المحددتين.',{exact:true})).toBeVisible();
    const write=state.writes.find(x=>x.path==='/advisor/sales/coverage');
    expect(write.data.expected.sales_minor).toBe('10000');
    expect(write.data.currency).toBe('USD');
    expect(write.data.acknowledge).toBe(true);
    expect(state.errors).toEqual([]);
});


test('bonus employee picker searches the server beyond the initial directory', async ({page}) => {
    const {errors}=await fixture(page,{permissions:[...allPermissions,'hr.compensation.read','advisor.bonus.read','advisor.bonus.submit']});
    const requests=[];
    await page.route('**/api/hr/employees?**', async route=>{
        const url=new URL(route.request().url());requests.push(url.searchParams.get('search'));
        const found=url.searchParams.get('search')==='E101';
        await route.fulfill({status:200,contentType:'application/json',body:JSON.stringify({data:found?[{uuid:ids.employee,employee_number:'E101',first_name:'موظف',last_name:'بعيد'}]:[],meta:{current_page:1,last_page:1}})});
    });
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'السياسات والموافقات',exact:true}).click();
    await page.getByRole('button',{name:'طلب مكافأة',exact:true}).click();
    await page.getByRole('combobox',{name:'الموظف',exact:true}).fill('E101');
    await page.getByRole('option',{name:'موظف بعيد · E101',exact:true}).click();
    await expect(page.getByRole('combobox',{name:'الموظف',exact:true})).toHaveValue('موظف بعيد · E101');
    expect(requests).toContain('E101');
    expect(errors).toEqual([]);
});

test('Data reconciliation requires independent source acknowledgement and displays saved controls',async({page})=>{
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'advisor.sales.read','advisor.data.review']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'تحليل الأداء',exact:true}).click();
    await page.getByRole('button',{name:'مصالحة جديدة',exact:true}).click();const dialog=page.getByRole('dialog');
    await choose(page,dialog.getByRole('combobox',{name:'مصدر البيانات للمصالحة',exact:true}),'primary');
    await choose(page,dialog.getByRole('combobox',{name:'عملة المصالحة',exact:true}),'USD');
    await dialog.getByLabel('فواتير المصالحة من').fill('2026-09-01');await dialog.getByLabel('فواتير المصالحة إلى').fill('2026-09-30');await dialog.getByLabel('الحركات حتى').fill('2026-10-06');
    for(const [label,value] of [['عدد الفواتير في المصدر','1'],['إجمالي الفواتير في المصدر','100.00'],['عدد التحصيلات في المصدر','1'],['إجمالي التحصيلات في المصدر','50.00'],['عدد المرتجعات في المصدر','0'],['إجمالي المرتجعات في المصدر','0']])await dialog.getByRole(label.startsWith('عدد')?'spinbutton':'textbox',{name:label,exact:true}).fill(value);
    await dialog.getByLabel('مرجع تقرير المصدر').fill('ERP-Source-Report');await dialog.getByLabel('ملاحظة مراجعة المصدر').fill('راجعت التقرير المستقل بنفس النطاق');
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();expect(writes.some(x=>x.path==='/advisor/data/reconciliations')).toBeFalsy();
    await choose(page,dialog.getByRole('combobox',{name:'تأكيد نطاق المقارنة',exact:true}),'true');await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    await page.getByRole('button',{name:'مراجعة الفروقات',exact:true}).click();await expect(page.getByRole('table',{name:'فروقات المصدر والسجلات المستوردة'})).toBeVisible();await expect(page.getByText('ERP-Source-Report',{exact:false})).toBeVisible();
    const write=writes.find(x=>x.path==='/advisor/data/reconciliations');expect(write.key).toBeTruthy();expect(write.data).toMatchObject({source_system:'primary',invoice_amount:'100.00',return_count:'0',acknowledge:true});expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW)await page.screenshot({path:'/tmp/teami-quality.png',fullPage:true,animations:'disabled'});
});

test('Read-only analyst can inspect products and regions without creating a reconciliation',async({page})=>{
    const {errors}=await fixture(page,{permissions:[...allPermissions,'advisor.sales.read']});
    await page.setViewportSize({width:390,height:844});await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'تحليل الأداء',exact:true}).click();
    await expect(page.getByRole('button',{name:'مصالحة جديدة',exact:true})).toHaveCount(0);await expect(page.getByText('منتج الاختبار',{exact:true})).toBeVisible();
    await choose(page,page.getByRole('combobox',{name:'عرض بيانات المنتجات والمناطق',exact:true}),'areas');await expect(page.getByText('الكرادة',{exact:true})).toBeVisible();
    await choose(page,page.getByRole('combobox',{name:'عرض بيانات المنتجات والمناطق',exact:true}),'invoice_lines');await expect(page.getByText('2.500',{exact:true})).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth)).toBeTruthy();expect(errors).toEqual([]);
});

test('Detailed reconciliation uploads source records and shows field evidence',async({page})=>{
    const {writes,errors}=await fixture(page,{permissions:[...allPermissions,'advisor.sales.read','advisor.data.review']});
    await page.locator('.account-button').click();await page.getByRole('menuitem',{name:'تحليل الأداء',exact:true}).click();
    await page.getByRole('button',{name:'مطابقة السجلات',exact:true}).click();const dialog=page.getByRole('dialog');
    await choose(page,dialog.getByRole('combobox',{name:'مصدر البيانات للمصالحة',exact:true}),'primary');
    await choose(page,dialog.getByRole('combobox',{name:'عملة المصالحة',exact:true}),'USD');
    await dialog.getByLabel('فواتير المصالحة من').fill('2026-09-01');await dialog.getByLabel('فواتير المصالحة إلى').fill('2026-09-30');await dialog.getByLabel('الحركات حتى').fill('2026-10-06');
    await dialog.getByLabel('مرجع تقرير المصدر').fill('Independent-Report');await dialog.getByLabel('ملاحظة مراجعة المصدر').fill('راجعت تقرير المصدر المستقل');
    await dialog.locator('input[type=file]').setInputFiles({name:'evidence.csv',mimeType:'text/csv',buffer:Buffer.from('kind,external_id,invoice_external_id,record_date,due_date,customer_number,employee_number,currency,amount\ninvoices,0001,,2026-09-01,2026-10-01,C1,E1,USD,100.00\n')});
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();expect(writes.some(x=>x.path.endsWith('/detailed'))).toBeFalsy();
    await choose(page,dialog.getByRole('combobox',{name:'تأكيد نطاق المقارنة',exact:true}),'true');await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    await page.getByRole('button',{name:'مراجعة الفروقات',exact:true}).click();const table=page.getByRole('table',{name:'مطابقة السجلات المحفوظة'});
    await expect(table).toBeVisible();await expect(table.getByText('0001',{exact:true})).toBeVisible();await expect(table.getByText('C2',{exact:true})).toBeVisible();
    await choose(page,page.getByRole('combobox',{name:'نوع فروقات السجلات',exact:true}),'changed_fields');
    const write=writes.find(x=>x.path.endsWith('/detailed'));expect(write.contentType).toContain('multipart/form-data');expect(write.body).toContain('evidence.csv');expect(write.body).toContain('0001');expect(write.key).toBeTruthy();expect(errors).toEqual([]);
    if(process.env.TEAMI_CAPTURE_PREVIEW)await page.screenshot({path:'/tmp/teami-record-reconciliation.png',fullPage:true,animations:'disabled'});
});


test('platform owner creates tenant accounts without exposing tenant workspace', async ({page}) => {
    const state=await fixture(page,{platformOwner:true});
    await page.goto('/#rules');
    await expect(page.getByRole('heading',{name:'شركات المستأجرين',exact:true})).toBeVisible();
    await expect(page.getByRole('button',{name:'الموظفون',exact:true})).toHaveCount(0);
    await page.getByRole('button',{name:'إنشاء مستأجر جديد',exact:true}).click();
    const dialog=page.getByRole('dialog');
    for(const [label,value] of [['اسم الشركة','شركة جديدة'],['رمز الشركة بالإنجليزية','new-company'],['اسم مالك الشركة','مالك جديد'],['بريد مالك الشركة','owner@example.test'],['كلمة المرور الأولية','Fixture-Password42!'],['تأكيد كلمة المرور','Fixture-Password42!']]) await dialog.getByLabel(label).fill(value);
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();
    await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('table',{name:'شركات المستأجرين'}).getByText('شركة جديدة',{exact:true})).toBeVisible();
    await expect(page.getByRole('table',{name:'شركات المستأجرين'}).getByText('new-platform-company',{exact:true})).toBeVisible();
    const write=state.writes.find(x=>x.path==='/platform/tenants');
    expect(write.data.owner_email).toBe('owner@example.test');
    expect(write.key).toBeTruthy();
    expect(state.errors).toEqual([]);
});


test('platform owner creates plans and schedules a company subscription from the calendar',async({page})=>{
    const state=await fixture(page,{platformOwner:true});
    await page.getByRole('button',{name:'الاشتراكات والتقويم',exact:true}).click();
    await page.getByRole('button',{name:'إضافة خطة',exact:true}).click();
    const dialog=page.getByRole('dialog');
    for(const [label,value] of [['اسم الخطة','خطة الأعمال'],['رمز الخطة بالإنجليزية','business'],['سعر الاشتراك','25.50'],['حد المستخدمين','20'],['طلبات الذكاء الاصطناعي شهريًا','500']])await dialog.getByLabel(label).fill(value);
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('table',{name:'الخطط المتاحة'}).getByText('خطة الأعمال',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'الشهر التالي',exact:true}).click();
    const firstDate=page.getByRole('button',{name:/تفعيل اشتراك يبدأ/}).first();
    const label=await firstDate.getAttribute('aria-label');await firstDate.click();
    const start=label.split(' ').at(-1);
    await expect(dialog.getByLabel('تاريخ البداية')).toHaveValue(start);
    await choose(page,dialog.getByRole('combobox',{name:'الشركة',exact:true}),'platform-company');
    await choose(page,dialog.getByRole('combobox',{name:'خطة الاشتراك',exact:true}),'plan-fixture');
    await dialog.getByLabel('آخر يوم في الاشتراك').fill(start.slice(0,8)+'28');
    await dialog.getByLabel('سبب التفعيل أو مرجع الاتفاق').fill('اتفاق اشتراك الشركة');
    await dialog.getByRole('button',{name:'حفظ',exact:true}).click();await expect(dialog).toHaveCount(0);
    await expect(page.getByRole('table',{name:'اشتراكات الشهر'}).getByText('مجدول',{exact:true})).toBeVisible();
    expect(state.writes.find(x=>x.path==='/platform/plans').data.unit_amount).toBe(2550);
    expect(state.writes.find(x=>x.path==='/platform/subscriptions').data.starts_on).toBe(start);
    expect(state.writes.find(x=>x.path==='/platform/subscriptions').key).toBeTruthy();
    expect(state.errors).toEqual([]);
});


test('Public landing explains features, published plans and trial on desktop and mobile',async({page})=>{
    const state=await fixture(page,{signedIn:false});await page.goto('/');
    await expect(page.getByRole('heading',{name:'شركتك أوضح. فريقك أقرب.',exact:true})).toBeVisible();
    await expect(page.getByRole('heading',{name:'خطة الأعمال',exact:true})).toBeVisible();
    await expect(page.locator('.public-trial-plan')).toContainText('٢٥ طلب ذكاء اصطناعي إجمالًا');
    await expect(page.locator('.public-availability')).toContainText('قيد التجهيز');
    await page.evaluate(()=>document.fonts.ready);
    await page.screenshot({path:'test-results/public-site/landing-desktop.png',fullPage:true,animations:'disabled'});
    await page.setViewportSize({width:390,height:844});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    await page.screenshot({path:'test-results/public-site/landing-mobile.png',fullPage:true,animations:'disabled'});
    await page.evaluate(()=>localStorage.setItem('teami_theme','dark'));await page.reload();
    await page.screenshot({path:'test-results/public-site/landing-mobile-dark.png',fullPage:true,animations:'disabled'});
    await page.getByText('هل يلزم إدخال بطاقة دفع؟',{exact:true}).click();
    await expect(page.getByText('لا. لا يجمع التسجيل بيانات بطاقات الدفع، ولا تتحول التجربة تلقائيًا إلى اشتراك مدفوع.',{exact:true})).toBeVisible();
    await page.getByRole('link',{name:'جرّب قبل الاشتراك',exact:true}).click();
    await expect(page).toHaveURL(/register\?plan=/);
    await expect(page.getByRole('heading',{name:'عرّفنا بشركتك',exact:true})).toBeVisible();
    expect(state.errors).toEqual([]);
});

async function fillPublicRegistration(page) {
    await page.getByLabel('اسم الشركة',{exact:false}).fill('شركة تجربة');
    await page.getByLabel('الاسم القانوني للشركة').fill('شركة تجربة القانونية');
    await choose(page,page.getByRole('combobox',{name:'نشاط الشركة',exact:true}),'technology');
    await choose(page,page.getByRole('combobox',{name:'حجم الفريق',exact:true}),'6-20');
    await page.getByLabel('المدينة').fill('بغداد');await page.getByLabel('عنوان الشركة').fill('بغداد — عنوان تجريبي 123');
    await page.getByRole('button',{name:'متابعة',exact:true}).click();
    for(const [label,value] of [['الاسم الكامل للمسؤول','مسؤول الشركة'],['المسمى الوظيفي','المدير'],['بريد المسؤول','trial@example.test'],['رقم الهاتف الدولي','+9647701234567'],['كلمة المرور','Fixture-Password42!'],['تأكيد كلمة المرور','Fixture-Password42!']])await (label==='كلمة المرور'?page.locator('input[name=password]'):page.getByLabel(label,{exact:false})).fill(value);
    await page.getByRole('button',{name:'متابعة',exact:true}).click();
}

test('Self registration reviews company details, retries safely and enters a bounded trial',async({page})=>{
    const state=await fixture(page,{signedIn:false,failSignupOnce:true});await page.goto('/register?plan=77777777-7777-4777-8777-777777777777');
    await page.evaluate(()=>document.fonts.ready);await page.screenshot({path:'test-results/public-site/registration-desktop.png',fullPage:true,animations:'disabled'});
    const storyWidth=await page.locator('.registration-story').evaluate(x=>x.getBoundingClientRect().width);
    await fillPublicRegistration(page);
    expect(await page.locator('.registration-story').evaluate(x=>x.getBoundingClientRect().width)).toBeCloseTo(storyWidth,0);
    await expect(page.getByRole('button',{name:'إنشاء الحساب وبدء التجربة',exact:true})).toBeDisabled();
    await expect(page.locator('.registration-review')).toContainText('trial@example.test');
    await expect(page.getByRole('combobox',{name:'خطة مهتم بها بعد التجربة — اختياري',exact:true})).toHaveValue(/خطة الأعمال/);
    await page.getByRole('checkbox',{name:/أؤكد صحة المعلومات/}).check();
    await page.screenshot({path:'test-results/public-site/registration-review.png',fullPage:true,animations:'disabled'});
    await page.getByRole('button',{name:'إنشاء الحساب وبدء التجربة',exact:true}).click();await expect(page.getByText('تعذّر الاتصال مؤقتًا',{exact:true})).toBeVisible();
    await page.getByRole('button',{name:'إنشاء الحساب وبدء التجربة',exact:true}).click();
    await expect(page).toHaveURL(/\/app$/);await expect(page.getByRole('heading',{name:'أهلاً مدير',exact:true})).toBeVisible();
    await expect(page.getByRole('complementary',{name:'حالة التجربة المجانية'})).toContainText('25 من 25');
    const writes=state.writes.filter(x=>x.path==='/public/trials');expect(writes).toHaveLength(2);expect(writes[0].key).toBe(writes[1].key);
    expect(writes[1].data.consent).toBe(true);expect(writes[1].data.business_activity).toBe('technology');expect(writes[1].data.preferred_plan_uuid).toBe('77777777-7777-4777-8777-777777777777');
    expect(await page.evaluate(()=>localStorage.getItem('teami_tenant'))).toBe('99999999-9999-4999-8999-999999999999');expect(state.errors).toEqual([]);
});

test('Public registration and privacy work on mobile without overflow',async({page})=>{
    const state=await fixture(page,{signedIn:false});await page.setViewportSize({width:390,height:844});await page.goto('/register');
    await expect(page.getByRole('heading',{name:'عرّفنا بشركتك',exact:true})).toBeVisible();
    await page.screenshot({path:'test-results/public-site/registration-mobile.png',fullPage:true,animations:'disabled'});
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();
    await fillPublicRegistration(page);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();await page.screenshot({path:'test-results/public-site/registration-review-mobile.png',fullPage:true,animations:'disabled'});await page.getByRole('button',{name:'رجوع',exact:true}).click();
    await expect(page.getByLabel('الاسم الكامل للمسؤول')).toHaveValue('مسؤول الشركة');
    await page.goto('/privacy');await expect(page.getByRole('heading',{name:'الخصوصية وشروط التجربة',exact:true})).toBeVisible();
    expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBeTruthy();expect(state.errors).toEqual([]);
});
