# النشر والتشغيل

## 1. Supabase

### قاعدة البيانات
1. أنشئ مشروع Supabase (PostgreSQL 15+؛ جُرّب النظام محلياً على 17).
2. من *Project Settings → Database* استخدم **Session pooler** (المنفذ 5432، يدعم IPv4) أو الاتصال المباشر:
   ```env
   DB_CONNECTION=pgsql
   DB_URL=postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:5432/postgres?sslmode=require
   ```
   لا تستخدم **Transaction pooler (6543)** للتطبيق: النظام يعتمد على أقفال الصفوف داخل المعاملات وعلى العبارات المحضّرة.
3. نفّذ:
   ```bash
   php artisan migrate --force
   php artisan db:seed --class=PermissionSeeder --force
   php artisan db:seed --class=ProviderSeeder --force
   php artisan db:seed --class=AirportSeeder --force
   php artisan db:seed --class=AirlineSeeder --force
   php artisan platform:create-super-admin admin@your-company.com
   php artisan platform:harden-db      # يتحقق أن كل الجداول عليها RLS
   ```
   آخر migration (`harden_postgres`) يفعّل RLS على كل الجداول ويسحب صلاحيات `anon` و`authenticated`، فلا يمكن قراءة
   أي بيانات عبر Supabase Data API حتى لو تسرب مفتاح `anon`. يتصل Laravel بالمستخدم المالك للجداول فلا يتأثر.
   **بعد أي migration تضيف جداول جديدة شغّل `platform:harden-db`** (الاختبارات تفشل إن وُجد جدول بلا RLS).
4. لا تُشغّل `DevelopmentSeeder` في الإنتاج (يرفض العمل خارج `local/testing`).

### التخزين
```env
FILE_STORAGE_DRIVER=supabase
SUPABASE_URL=https://<ref>.supabase.co
SUPABASE_SERVICE_ROLE_KEY=<service-role key>   # في بيئة الخادم فقط
```
ثم `php artisan platform:storage-setup` لإنشاء الحاويات الخاصة `documents` و`branding` و`payment-proofs`
مع أنواع وأحجام مسموحة. الملفات تُقدّم فقط عبر الـ API بعد التحقق من الصلاحية (روابط موقّعة لمدة `FILE_SIGNED_URL_TTL_SECONDS`).

## 2. متغيرات البيئة الأساسية للإنتاج

| المتغير | القيمة |
|---|---|
| `APP_ENV=production`, `APP_DEBUG=false`, `APP_KEY` | مولّد بـ `php artisan key:generate --show` |
| `APP_URL` | العنوان الداخلي للـ API |
| `PARTNER_PORTAL_URL`, `ADMIN_PORTAL_URL` | لروابط البريد (الدعوات وإعادة التعيين) |
| `CORS_ALLOWED_ORIGINS` | أصول البوابتين فقط |
| `TRUSTED_PROXIES` | عناوين خوادم البوابتين/موزع الحمل |
| `REDIS_HOST/PASSWORD`, `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis` | |
| `MAIL_*` | مزود بريد حقيقي (SMTP/SES/…) |
| `AIRLINES_API_KEY`, `AIRLINES_API_MONTHLY_BUDGET` | |
| `MOCK_FLIGHT_PROVIDER_ENABLED=false` | (الافتراضي في الإنتاج) |
| بوابة الشركات: `NUXT_API_BASE`, `NUXT_COOKIE_SECURE=true` | |
| لوحة الإدارة: `API_BASE_URL` | الكوكي `Secure` تلقائياً في الإنتاج ⇒ يلزم HTTPS |

## 3. Docker

```bash
cp infrastructure/.env.production.example infrastructure/.env.production   # املأ القيم
docker compose -f infrastructure/docker-compose.yml --env-file infrastructure/.env.production up -d --build
docker compose -f infrastructure/docker-compose.yml exec api php artisan migrate --force
```

الخدمات: `api` (php-fpm)، `horizon` (عمّال الطوابير)، `scheduler` (`schedule:work` — انتهاء الحجوزات المبدئية كل دقيقة)،
`redis`، `partner` (Nuxt)، `admin` (Next standalone)، `web` (nginx). الـ API غير منشور للعامة: البوابتان تصلان إليه داخلياً على `web:8080`.

- **TLS**: أنهِ TLS في nginx أو في موزع الحمل أمامه؛ فعّل HSTS (يرسله الـ API تلقائياً في الإنتاج).
- **النطاقات المخصصة للشركات (White-Label)**: وجّه نطاق الشركة (CNAME) إلى بوابة الشركات، واضبط `custom_domain` للشركة
  من لوحة الإدارة؛ صفحة الدخول تتعرف على الشركة من اسم المضيف. أصدر شهادة TLS لكل نطاق (مثلاً Caddy/Let's Encrypt).
- **لوحة الإدارة**: قيّد الوصول بشبكة خاصة/VPN أو قائمة IP في nginx.
- **Horizon**: العمّال يعملون في خدمة `horizon`. لوحة `/horizon` تعتمد جلسات الويب التي لا يستخدمها هذا الـ API،
  لذلك تبقى **مغلقة في الإنتاج** (بوابة `viewHorizon` ترفض الجميع). راقب الطوابير من لوحة الإدارة ← حالة النظام،
  أو افتح اللوحة مؤقتاً عبر VPN مع مصادقة أساسية في nginx إن احتجت.

## 4. المراقبة

- لوحة الإدارة ← حالة النظام: قاعدة البيانات، Redis، الطابور والمهام الفاشلة، التخزين، نبضة المجدول، أخطاء المزودين خلال 24 ساعة.
- `GET /api/v1/health` (خفيف) و`/up` لفحوص موزع الحمل.
- السجلات بصيغة stderr في الحاويات مع `request_id` لكل طلب.

## 5. النسخ الاحتياطي والاستعادة

| الطبقة | الإجراء |
|---|---|
| Supabase | نسخ يومية تلقائية (خطط مدفوعة) + **Point-in-Time Recovery** موصى به للبيانات المالية |
| نسخة مستقلة | `infrastructure/scripts/backup.sh` يومياً (`pg_dump -Fc`) إلى تخزين خارجي مشفّر، احتفاظ 30 يوماً |
| التخزين | تفعيل نسخ حاويات Supabase Storage أو مزامنة دورية لـ `documents` |
| الأسرار | خزنة أسرار (Vault/1Password/SSM)، وليس في Git |

الاستعادة: `pg_restore --clean --no-owner -d "$DB_URL" backup.dump` ثم `php artisan platform:harden-db`.
اختبر الاستعادة شهرياً على بيئة منفصلة. لاحظ أن جداول القيود غير قابلة للتعديل؛ الاستعادة تكون للقاعدة كاملة.

## 6. التحديثات

```bash
php artisan down --render="errors::503"
php artisan migrate --force && php artisan platform:harden-db
php artisan db:seed --class=PermissionSeeder --force     # مزامنة كتالوج الصلاحيات
php artisan config:cache route:cache event:cache
php artisan horizon:terminate                             # يعيد تشغيل العمّال بالكود الجديد
php artisan up
```
