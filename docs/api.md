# توثيق الـ API

- **المرجع الكامل (OpenAPI 3.1)**: `packages/api-contracts/openapi.json` — يُولَّد من الكود بواسطة Scramble
  (`php artisan scramble:export`). واجهة تفاعلية على `http://localhost:8000/docs/api` خارج الإنتاج.
- **أنواع TypeScript**: `npm run contracts:generate` يولّد `packages/api-contracts/src/schema.d.ts` وتستخدمه البوابتان.

## القواعد العامة

| البند | القيمة |
|---|---|
| المسار الأساسي | `/api/v1` |
| المصادقة | `Authorization: Bearer <token>` (Sanctum) — التوكن مرتبط ببوابة: `portal:partner` أو `portal:admin` |
| مدة الجلسة | مطلقة `AUTH_TOKEN_TTL_MINUTES` (480) + خمول `AUTH_TOKEN_IDLE_MINUTES` (120) |
| اللغة | `Accept-Language: ar|en` (العربية افتراضياً) — رسائل الأخطاء مترجمة |
| منع التكرار | `Idempotency-Key` (8–120 حرفاً) إلزامي لـ: إنشاء الحجز، الإصدار، الاسترداد، تسجيل الدفعة |
| معرّف الطلب | `X-Request-Id` يُعاد في كل استجابة ويُحفظ في سجل التدقيق وسجل المزودين |
| تحديد المعدل | API 240/دقيقة للمستخدم، الدخول 5/دقيقة لكل بريد و10 لكل IP، البحث 30/دقيقة |

### شكل الأخطاء

```json
{ "message": "تغيّر سعر الرحلة. يرجى مراجعة السعر الجديد وتأكيده.", "code": "PRICE_CHANGED",
  "details": { "expected_total": {"amount": "274.05", "currency": "USD"}, "current_total": {"amount": "284.05", "currency": "USD"} } }
```

أخطاء التحقق: `422` مع `code: VALIDATION_FAILED` و`errors: {field: [messages]}`.
أكواد شائعة: `INSUFFICIENT_CREDIT`, `ACCOUNT_FROZEN`, `OFFER_EXPIRED`, `OFFER_UNAVAILABLE`, `HOLD_EXPIRED`,
`INVALID_STATUS_TRANSITION`, `USE_REFUND`, `TICKETING_IN_PROGRESS`, `NOT_TICKETED`, `FOUR_EYES_REQUIRED`,
`IDEMPOTENCY_KEY_REUSED`, `REQUEST_IN_PROGRESS`, `EXCHANGE_RATE_MISSING`, `WRONG_PORTAL`, `TENANT_SUSPENDED`.

### إعادة الطلب بنفس المفتاح

| الحالة | النتيجة |
|---|---|
| نفس المفتاح + نفس المحتوى، الطلب الأول نجح | تُعاد الاستجابة المحفوظة مع `Idempotent-Replayed: true` |
| نفس المفتاح + محتوى مختلف | `422 IDEMPOTENCY_KEY_REUSED` |
| الطلب الأول ما زال قيد التنفيذ | `409 REQUEST_IN_PROGRESS` |
| الطلب الأول فشل (غير 2xx) | لا يُحفظ؛ يمكن إعادة المحاولة بنفس المفتاح |

## ملخص المسارات

### عامة
`POST auth/login` · `POST auth/forgot-password` · `POST auth/reset-password` · `GET invitations/{token}` ·
`POST invitations/{token}/accept` · `GET public/branding?host=&slug=` · `GET public/tenants/{slug}/logo` · `GET health`

### لأي مستخدم موثّق
`GET auth/me` · `POST auth/logout` · `GET auth/sessions` · `DELETE auth/sessions/{id}` · `PUT auth/password` ·
`GET notifications` · `POST notifications/{id}/read` · `POST notifications/read-all` · `GET reference/airports?q=` · `GET reference/airlines?q=`

### بوابة الشركات `partner/*` (معزولة حسب الشركة)
| المجال | المسارات |
|---|---|
| لوحة | `GET dashboard` |
| البحث | `POST flights/search` · `GET flights/offers/{id}` · `POST flights/offers/{id}/price-check` |
| الحجوزات | `GET bookings` · `POST bookings`* · `GET bookings/{id}` · `POST bookings/{id}/issue`* · `POST bookings/{id}/cancel` · `POST bookings/{id}/refund`* |
| المستندات | `POST bookings/{id}/documents/{booking_confirmation|e_ticket}` · `GET bookings/{id}/documents/{doc}/url` · `GET …/file` |
| المسافرون | `apiResource travelers` |
| المالية | `GET finance/account` · `GET finance/statement?format=csv` · `GET|POST* finance/payments` · `GET finance/invoices` · `GET finance/invoices/{id}/file` · `GET finance/commissions` |
| التقارير | `GET reports/summary` |
| الفريق | `GET team/users` · `POST|GET team/invitations` · `DELETE team/invitations/{id}` · `PUT team/users/{id}/roles|status` · `GET|POST team/roles` · `PUT|DELETE team/roles/{id}` · `GET team/permissions` |
| العلامة | `GET|PUT settings/branding` · `POST settings/branding/logo` |

\* يتطلب `Idempotency-Key`.

### الإدارة `admin/*` (صلاحية لكل مسار)
الشركات (`tenants`, `suspend`, `activate`, `credit-limit`, `account-status`) · مستخدمو المنصة والأدوار ·
المزودون (`providers`, `health-check`, `airlines`, `airlines/import`) · قواعد التسعير وأسعار الصرف (`pricing-rules`, `preview`, `exchange-rates`) ·
الحجوزات (`bookings`, `verify-ticketing`, `cancel`, المستندات) والاستردادات (`refunds/{id}/complete|reject`) ·
المالية (`finance/accounts`, `statement`, `transactions/{id}`, `payments/{id}/approve|reject|proof`, `adjustments`, `invoices`) ·
التقارير (`summary`, `commissions`, `receivables`) · `audit-logs` · `system/health|provider-logs|failed-jobs`.

### الحقول المالية حسب الجمهور
بوابة الشركات ترى: `price.total`، `price.commission`، `price.agency_net`، والتسوية بعملة حسابها.
مسارات الإدارة فقط ترى `internal`: تكلفة المورد، الهامش، ربح المنصة، القواعد المطبقة، معرّف المزود.
