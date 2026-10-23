# المزودون والتكاملات

## الوضع الحالي

| المزود | النوع | الحالة |
|---|---|---|
| `mock` — `MockFlightProvider` | حجز (محاكاة) | للتطوير والاختبار فقط. معطّل تلقائياً في `APP_ENV=production` ما لم يُفعَّل صراحة |
| `airlines_api` — `AirlinesInfoProvider` | بيانات شركات الطيران | **مربوط بالمفتاح الحقيقي ومختبر**. لا يوفر رحلات أو أسعاراً أو حجزاً |
| مزود حجز حقيقي | GDS / NDC / Consolidator | **غير مربوط** — يحتاج عقداً وبيانات اعتماد |

## واجهة `FlightProviderInterface`

`apps/backend/app/Domain/Providers/Booking/FlightProviderInterface.php`

| الدالة | القدرة | الوصف |
|---|---|---|
| `capabilities()` | — | ما يدعمه المزود: `search, price_check, hold, retrieve, ticketing, cancel, refund, round_trip, multi_city` |
| `search(SearchCriteria)` | search | عروض مع المقاطع والأمتعة وشروط السعر وصلاحية العرض |
| `priceCheck(offerId)` | price_check | إعادة التسعير والتحقق من التوفر |
| `createBooking(BookingRequest)` | hold (اختياري) | مع `hold`: حجز مبدئي بلا دفع مع مهلة؛ بدونه: شراء فوري **بعد** خصم الشركة |
| `retrieveBooking(id)` / `findByClientReference(ref)` | retrieve | استرجاع الحالة و PNR والتذاكر، والمطابقة بعد الانقطاع |
| `issueTickets(id)` | ticketing | إصدار التذاكر الإلكترونية |
| `cancelBooking(id)` | cancel | إلغاء قبل الإصدار |
| `refund(id)` | refund | استرداد بعد الإصدار؛ يجب أن يكون **متساوي الأثر** (idempotent) عند التكرار |

**دلالات الأخطاء الإلزامية:**
- `ProviderTimeoutException` = النتيجة **غير معروفة** (ربما نُفذت). النظام لا يفترض نجاحاً ولا فشلاً، بل يتحقق لاحقاً.
- أي `ProviderException` آخر = المزود **لم ينفذ** العملية (يُعكس الخصم إن وجد).
- `ProviderRejectedException` رفض تجاري، `ProviderUnavailableException` / `ProviderRateLimitException` قابلة لإعادة المحاولة،
  `ProviderAuthenticationException` خطأ اعتماد.

كل مزود يُغلَّف تلقائياً بـ `InstrumentedFlightProvider` الذي يفرض القدرات ويسجّل كل طلب في `provider_api_logs`
(العملية، النجاح، المدة، رمز الخطأ — دون ترويسات أو مفاتيح).

## إضافة مزود حجز حقيقي

1. أنشئ صنفاً يطبّق `FlightProviderInterface` (مثل `App\Domain\Providers\Booking\AmadeusProvider`) يحوّل ردود المزود إلى
   كائنات `Data\*`، ويرسل `clientReference` (رقم حجزنا) للمطابقة.
2. سجّله في `config/providers.php` تحت `booking` مع بيانات الاعتماد من متغيرات البيئة:
   ```php
   'amadeus' => ['class' => AmadeusProvider::class, 'name' => 'Amadeus', 'enabled' => (bool) env('AMADEUS_ENABLED', false),
                 'sandbox' => (bool) env('AMADEUS_SANDBOX', true),
                 'credentials' => ['key' => env('AMADEUS_API_KEY'), 'secret' => env('AMADEUS_API_SECRET')]],
   ```
3. أضف سجلاً في `flight_providers` (أو امتداداً لـ `ProviderSeeder`) بـ `kind=booking` و`is_sandbox` المناسب.
4. فعّله من لوحة الإدارة ← المزودون، واستخدم "فحص الاتصال".
5. اكتب اختبارات بـ `Http::fake()` لكل حالة (نجاح، رفض، مهلة، تغير سعر) على غرار `tests/Feature/Providers`.

لا يحتاج باقي النظام (البحث، الحجز، المالية، المستندات، الواجهات) إلى أي تعديل.

## مزود المحاكاة (Mock)

- عروض حتمية حسب معايير البحث، بأرقام رحلات 9000–9999، و PNR عشوائي، وأرقام تذاكر بالبادئة غير المخصصة `000`.
- كل العروض `is_test=true`؛ تظهر لافتة "بيانات تجريبية" في البوابة، وعلامة مائية `TEST - NOT VALID FOR TRAVEL` في المستندات.
- سيناريوهات قابلة للاختيار من لوحة الإدارة (المزودون ← سيناريو المحاكاة) لاختبار مسارات الفشل:
  `normal, no_results, search_error, auth_error, price_change, unavailable, hold_rejected, hold_timeout,
  ticketing_failed, ticketing_timeout, ticketing_pending, refund_pending, cancel_failed`.

## Airlines API (omkar.cloud)

- `GET https://airlines-api.omkar.cloud/airlines?iata=|icao=|name=` بترويسة `API-Key` من `AIRLINES_API_KEY`.
- الرد الفعلي **مصفوفة** (يدعم الكود الكائن المفرد أيضاً). الحقول: الاسم، الدولة، سنة التأسيس، المطار الرئيسي، IATA، ICAO، الأسطول.
- حماية الحصة (الخطة المجانية 100 طلب/شهر): تخزين مؤقت 30 يوماً، حد داخلي `AIRLINES_API_MONTHLY_BUDGET` (90)،
  قراءة ترويسة `X-RateLimit-Requests-Remaining`، **لا** استدعاء أثناء البحث (أسماء الشركات من الدليل المحلي فقط).
- الأخطاء: 401/403 ← خطأ اعتماد بلا إعادة محاولة؛ 429 ← تجاوز الحد بلا إعادة محاولة؛ 5xx/انقطاع ← إعادة محاولة واحدة.
- الاستيراد: لوحة الإدارة ← المزودون ← دليل شركات الطيران، أو `php artisan airlines:sync --iata=IA`.
