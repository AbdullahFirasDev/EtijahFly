# EtijahFly — منصة B2B لتوزيع وحجز تذاكر الطيران

منصة متكاملة لتوزيع خدمات حجز الطيران على شركات ومكاتب السفر (B2B / White-Label):
بحث وحجز مبدئي وإصدار تذاكر عبر واجهة مزودين موحدة، حسابات مالية بقيد مزدوج لكل شركة،
عمولات وهوامش قابلة للتخصيص، مستندات PDF بعلامة الشركة، ولوحتا تحكم منفصلتان.

| المكوّن | التقنية | المسار | المنفذ (تطوير) |
|---|---|---|---|
| API | Laravel 13 · PHP 8.4 · Sanctum · Spatie Permission · Horizon | `apps/backend` | 8000 |
| بوابة الشركات | Nuxt 4 · Vue 3 · Nuxt UI 4 · TypeScript | `apps/partner-portal` | 3000 |
| لوحة الإدارة | Next.js 16 · React 19 · Tailwind 4 · TypeScript | `apps/admin-portal` | 3001 |
| العقود المشتركة | OpenAPI (Scramble) + أنواع TypeScript مولّدة | `packages/api-contracts` | — |
| قاعدة البيانات | PostgreSQL 17 (Supabase في الإنتاج) | — | 54322 محلياً |
| Queue / Cache | Redis + Laravel Queue/Horizon | — | 6379 |

> **تنبيه مهم:** مزود الحجز المربوط حالياً هو **مزود محاكاة (Mock)** لأغراض التطوير والاختبار فقط.
> كل عروضه وحجوزاته وتذاكره مُعلَّمة كبيانات تجريبية في الواجهات والمستندات (علامة مائية
> `TEST - NOT VALID FOR TRAVEL`). **لا يوجد بعد مزود حجز/إصدار حقيقي**؛ يلزم عقد وبيانات اعتماد
> مع GDS أو مجمّع NDC أو موحِّد (consolidator) — انظر [docs/providers.md](docs/providers.md).
> Airlines API (omkar.cloud) مربوط فعلياً لكنه يوفّر **بيانات شركات الطيران فقط** وليس رحلات أو أسعاراً.

## هيكل المستودع

```text
EtijahFly/
├── apps/
│   ├── backend/          Laravel API (app/Domain/* = الوحدات)
│   ├── partner-portal/   Nuxt: بوابة الشركات (BFF في server/)
│   └── admin-portal/     Next.js: الإدارة الداخلية (BFF في src/app/api)
├── packages/api-contracts/  openapi.json + src/schema.d.ts + أدوات مشتركة
├── infrastructure/       docker-compose، Dockerfiles، nginx، سكربتات التشغيل المحلي
├── docs/                 المعمارية، المالية، الأمان، المزودون، النشر، الاختبارات
└── .env.example
```

## التشغيل المحلي (Windows)

المتطلبات: Node.js ≥ 22.12 (مثبت لديك 26). سلسلة الأدوات المحمولة (PHP 8.4 بإضافاتها، PostgreSQL 17،
Redis) موجودة في `.tools/` (مستثناة من Git) ولا تمس إعدادات XAMPP.

```powershell
# 1) تحميل الأدوات في جلسة PowerShell وتشغيل PostgreSQL و Redis
. .\infrastructure\local\dev-env.ps1
.\infrastructure\local\services.ps1 start

# 2) الباك إند
cd apps\backend
copy .env.example .env      # ثم ضع AIRLINES_API_KEY و DEV_SEED_PASSWORD
php ..\..\.tools\php\composer.phar install
php artisan key:generate
php artisan migrate --seed   # بيانات تجريبية (local/testing فقط)
php artisan serve            # http://localhost:8000  (توثيق API: /docs/api)
php artisan queue:work       # نافذة أخرى: المستندات والإشعارات
php artisan schedule:work    # نافذة أخرى: انتهاء الحجوزات المبدئية

# 3) الواجهات (من جذر المستودع)
npm install
npm run dev:partner          # http://localhost:3000/login?agency=rafidain
npm run dev:admin            # http://localhost:3001/login
```

### الحسابات التجريبية (بعد `db:seed` فقط، كلمة المرور = `DEV_SEED_PASSWORD`)

| البوابة | البريد | الدور |
|---|---|---|
| الإدارة | `admin@etijahfly.test` | Super Admin |
| الإدارة | `staff@etijahfly.test` | Platform Staff |
| الإدارة | `finance@etijahfly.test` | Platform Finance (اعتماد الدفعات والتسويات) |
| الشركات | `owner@rafidain.test` · `owner@babylon.test` | مالك/مدير الشركة |
| الشركات | `agent@rafidain.test` · `agent@babylon.test` | موظف حجوزات |
| الشركات | `accountant@rafidain.test` · `accountant@babylon.test` | محاسب |

الشركة الأولى (الرافدين) بعملة IQD، والثانية (بابل) بعملة USD. سعر الصرف المزروع (1 USD = 1310 IQD)
**قيمة تطوير فقط** ويجب ضبط السعر المعتمد من لوحة الإدارة.

لإنشاء أول مدير في الإنتاج: `php artisan platform:create-super-admin you@company.com`

## الأوامر الأساسية

| الأمر | الوظيفة |
|---|---|
| `php vendor/bin/pest` | اختبارات الباك إند (PostgreSQL حقيقي) |
| `RUN_EXTERNAL_TESTS=true php vendor/bin/pest --testsuite=External` | اختبار الاتصال الحقيقي بـ Airlines API (يستهلك طلبين من الحصة) |
| `php vendor/bin/pint` · `php vendor/bin/phpstan analyse` | التنسيق والتحليل الساكن (Larastan) |
| `php artisan scramble:export` ثم `npm run contracts:generate` | تحديث OpenAPI وأنواع TypeScript |
| `npm run lint` · `npm run typecheck` · `npm run test` · `npm run build` | فحوص وبناء الواجهتين |
| `.\infrastructure\local\e2e.ps1` | اختبارات E2E (Playwright) على قاعدة بيانات `etijahfly_e2e` منفصلة |
| `php artisan airlines:sync --iata=IA --iata=FZ` | استيراد بيانات شركات طيران من Airlines API |
| `php artisan platform:harden-db` | إعادة تطبيق RLS ومشغلات منع التعديل (بعد أي migration جديدة) |
| `php artisan platform:storage-setup` | إنشاء حاويات Supabase Storage الخاصة |

## التوثيق

- [المعمارية والوحدات وتدفق الحجز](docs/architecture.md)
- [توثيق الـ API وقواعد الطلبات](docs/api.md) — OpenAPI كامل في `packages/api-contracts/openapi.json`
- [النظام المالي: القيود والعمولات والعملات](docs/finance.md)
- [المزودون وإضافة مزود حجز حقيقي](docs/providers.md)
- [الأمان والصلاحيات وعزل الشركات](docs/security.md)
- [النشر على Supabase و Docker والنسخ الاحتياطي](docs/deployment.md)
- [الاختبارات ونتائج التشغيل الفعلية](docs/testing.md)

## ما يحتاج بيانات اعتماد أو قرار خارجي

| البند | الحالة |
|---|---|
| مزود حجز وإصدار تذاكر حقيقي (GDS/NDC/Consolidator) | **غير مربوط** — الواجهة `FlightProviderInterface` جاهزة |
| Supabase (قاعدة البيانات والتخزين) | يحتاج `DB_URL` و `SUPABASE_URL` و `SUPABASE_SERVICE_ROLE_KEY` — جُرّب محلياً على PostgreSQL 17 |
| بوابة دفع إلكتروني | غير مربوطة — الدفعات حالياً تحويل/نقد مع اعتماد يدوي (`PaymentGatewayInterface` جاهزة) |
| البريد الإلكتروني (دعوات، إعادة تعيين، إشعارات) | `MAIL_MAILER=log` محلياً — يلزم SMTP/مزود بريد في الإنتاج |
| Airlines API | **مربوط ومختبر** بالمفتاح الحقيقي (الخطة المجانية 100 طلب/شهر) |

> ⚠️ ملف `readme.txt` في جذر المجلد يحتوي مفتاح Airlines API بنص واضح. نُقل المفتاح إلى
> `apps/backend/.env` (مستثنى من Git) وأُضيف `readme.txt` إلى `.gitignore`؛ يُنصح بحذف الملف
> وتدوير المفتاح إن كان قد شورك.
