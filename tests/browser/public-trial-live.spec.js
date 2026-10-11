import { test, expect } from '@playwright/test';

test('real trial registration provisions a company and authenticates without mocked APIs', async ({page,baseURL}) => {
    test.skip(process.env.TEAMI_LIVE_TRIAL_TEST !== '1', 'Requires the isolated CI database and seeded permission catalog.');
    expect(['localhost','127.0.0.1']).toContain(new URL(baseURL).hostname);
    const errors=[];page.on('pageerror',error=>errors.push(error.message));
    const choose=async(name,value)=>{const field=page.getByRole('combobox',{name,exact:true});await field.click();await field.fill('');await page.locator(`[role=option][data-option-value="${value}"]`).click();};
    await page.goto('/register');
    await expect(page.getByRole('heading',{name:'عرّفنا بشركتك',exact:true})).toBeVisible();
    await page.getByLabel('اسم الشركة',{exact:false}).fill('شركة اختبار التكامل');
    await page.getByLabel('الاسم القانوني للشركة').fill('شركة اختبار التكامل القانونية');
    await choose('نشاط الشركة','technology');await choose('حجم الفريق','1-5');
    await page.getByLabel('المدينة').fill('بغداد');await page.getByLabel('عنوان الشركة').fill('عنوان اختبار CI — لا يمثل شركة إنتاجية');
    await page.getByRole('button',{name:'متابعة',exact:true}).click();
    await page.getByLabel('الاسم الكامل للمسؤول').fill('مسؤول التكامل');await page.getByLabel('المسمى الوظيفي').fill('مدير الاختبار');
    await page.getByLabel('بريد المسؤول').fill(`ci-trial-${Date.now()}@example.test`);await page.getByLabel('رقم الهاتف الدولي').fill('+9647709876543');
    await page.locator('input[name=password]').fill('Fixture-Password42!');await page.getByLabel('تأكيد كلمة المرور').fill('Fixture-Password42!');
    await page.getByRole('button',{name:'متابعة',exact:true}).click();
    await page.getByRole('checkbox',{name:/أؤكد صحة المعلومات/}).check();await page.getByRole('button',{name:'إنشاء الحساب وبدء التجربة',exact:true}).click();
    await expect(page).toHaveURL(/\/app$/);await expect(page.getByRole('heading',{name:'أهلاً مسؤول',exact:true})).toBeVisible();
    await expect(page.getByRole('complementary',{name:'حالة التجربة المجانية'})).toContainText('25 من 25');
    const result=await page.evaluate(async()=>{
        const headers={Authorization:`Bearer ${localStorage.getItem('teami_token')}`,'X-Tenant':localStorage.getItem('teami_tenant'),Accept:'application/json'};
        const trial=await fetch('/api/billing/trial',{headers}),owner=await fetch('/api/platform/context',{headers}),forbidden=await fetch('/api/platform/tenants',{headers});
        return {trial:await trial.json(),owner:await owner.json(),platformStatus:forbidden.status};
    });
    expect(result.trial.data.remaining).toBe(25);expect(result.trial.data.state).toBe('active');expect(result.owner.data.platform_owner).toBe(false);expect(result.platformStatus).toBe(403);expect(errors).toEqual([]);
    await page.screenshot({path:'test-results/public-site/real-trial-workspace.png',fullPage:true,animations:'disabled'});
});
