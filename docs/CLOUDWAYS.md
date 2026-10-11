# نشر Teami على GitHub وCloudways

## الحالة الحالية

المشروع المحلي على فرع main؛ لا يوجد remote معيّن بعد. ملفات .env وstorage وقواعد SQLite والحزم المحلية مستبعدة من Git. القالب deploy/production.env.example لا يحتوي أسرارًا حقيقية. لا تُنقل قاعدة الجهاز أو مفاتيحه إلى المستودع.

## GitHub

أنشئ مستودعًا فارغًا (الخاص مناسب للكود المؤسسي)، وحدّد رابطه وحساب المالك قبل الرفع. بعد تجهيز المصادقة عبر SSH أو مدير بيانات الاعتماد المحلي:

```sh
git remote add origin git@github.com:OWNER/REPOSITORY.git
git push -u origin main
```

لا تستخدم force push. إذا كان المستودع يحتوي ملفات أو تاريخًا، افحصه قبل دمجه. لا تضع token في عنوان remote ولا ترسله في المحادثة. يوجد workflow في .github/workflows/backend-integration.yml لفحص MySQL/Redis والواجهة؛ نجاح الفحوصات على GitHub يجب التحقق منه بعد الرفع.

## Cloudways

1. استخدم تطبيق Laravel منفصلًا أولًا، أو حدّد بدقة التطبيق الموجود المراد تحديثه. اضبط PHP 8.4 مع bcmath وpdo_mysql وmbstring وxml وzip وdom وxmlreader وfileinfo وRedis عند استخدام phpredis. الحد المكتوب في composer.json لا يكفي؛ قفل OpenSpout يتطلب PHP 8.3/8.4/8.5.
2. من Deployment via Git ولّد مفتاح النشر وأضف المفتاح العام إلى GitHub كـdeploy key للقراءة فقط. اختر عنوان SSH للمستودع وفرع main. لا تشارك المفتاح الخاص.
3. حدّد موضع الكود بحسب مسار التطبيق الفعلي، واجعل Document Root يشير إلى مجلد public الخاص بهذا المشروع، وليس جذر المستودع. يجب ألا يكون .env متاحًا عبر الويب. لا تفترض أن مسار /srv/teami في القوالب يطابق Cloudways.
4. جهّز .env على الخادم من deploy/production.env.example، مع APP_ENV=production وAPP_DEBUG=false وAPP_URL الصحيح وقاعدة MySQL الخاصة بالتطبيق. عيّن البريد وRedis وAI وفق الخدمات المتاحة. ولّد APP_KEY وAUDIT_HMAC_KEY مرة واحدة للتطبيق الجديد، واحتفظ بالمفتاحين الأصليين عند تحديث بيانات موجودة.
5. ثبّت الحزم وابنِ الواجهة داخل مجلد المشروع:

```sh
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build
```

يتطلب البناء Node 22.12+ ضمن سلسلة 22، أو إصدارًا يوافق package.json. إذا لم يتوفر Node على الخادم، ابنِ نسخة مطابقة للcommit محليًا وارفع public/build فقط كأصول الإصدار؛ لا تضف .env أو vendor المحلي أو قاعدة الجهاز.

6. قبل تطبيق المخطط على قاعدة موجودة، خذ نسخة احتياطية وتحقق من الاسترجاع. راجع migrations المعلقة. ثم شغّل migrate --force وDatabaseSeeder وteami:permissions:sync-owners وفق docs/LAUNCH.md. لا تستخدم migrate:fresh على الخادم. deploy/release.sh يتطلب نسخة احتياطية موثّقة وينفّذ هذه التغييرات؛ لا تشغّله عشوائيًا على التطبيق الحي.
7. فعّل HTTPS والدومين الصحيح، ثم php artisan optimize. جهّز الشركة وحساب المالك بالطريقة التفاعلية في docs/LAUNCH.md؛ لا تزرع حسابات اختبار أو كلمات مرور ثابتة.
8. أضف cron كل دقيقة لتشغيل schedule:run باستخدام PHP الموافق لإصدار التطبيق ومسار artisan الفعلي. جهّز worker دائمًا عبر إعداد Supervisor المتاح على Cloudways للطوابير critical,ai,analytics,default، مع timeout=120 وretry_after=180 وإعادة تشغيل worker بعد كل إصدار. تحقّق من العامل والمجدول عمليًا، لا من وجود القالب فقط.
9. نفّذ php artisan teami:launch:check --runtime --ai، واختبر /up والدخول والبريد وعزل الشركات والأصول والخطوط. نفّذ قبول MySQL/Redis وAI والنسخ الاحتياطي بحسب دليل الإطلاق قبل استقبال المستخدمين.

## مصادر الإعداد

- [Cloudways: نشر Laravel](https://support.cloudways.com/en/articles/5128779-how-to-deploy-laravel-project-on-cloudways-server)
- [Cloudways: Supervisor](https://support.cloudways.com/en/articles/5127434-how-to-configure-laravel-supervisord-on-cloudways)
- [Cloudways: Cron](https://support.cloudways.com/en/articles/5124057-how-to-create-cron-jobs-on-cloudways)

## ما يلزم لاستكمال التنفيذ

رابط المستودع، مصادقة GitHub على الجهاز، اسم/معرّف تطبيق Cloudways وعنوان الخادم ومستخدم SSH والدومين. لا تُرسل كلمات مرور أو مفاتيح خاصة في المحادثة؛ استخدم تسجيل الدخول أو إعداد المفتاح على جهازك. هذه الوثيقة تجهيز للنشر ولا تثبت حدوث رفع أو نشر.
