# WhatsApp Restaurant CRM - Laravel API

واجهة REST API كاملة لإدارة العملاء والمجموعات والحملات التسويقية ومحادثات WhatsApp وقواعد الأتمتة.

- Base URL: `/api/v1`
- Authentication: Laravel Sanctum Bearer Token
- Content Type: `application/json`
- Pagination: Laravel pagination
- Webhook: Meta WhatsApp Cloud API

## 1. المتطلبات

- PHP 8.3 أو أحدث
- Composer
- قاعدة بيانات مدعومة: MySQL/MariaDB أو SQLite
- Node.js وNPM فقط عند تشغيل واجهة الواجهة الأمامية
- حساب WhatsApp Cloud API وMeta App من أجل الإرسال الحقيقي
- Redis أو database queue لمعالجة حملات الإرسال

على Windows استخدم المسارات التالية داخل PowerShell:

```powershell
Set-Location "E:\restaurant-crm\backend"
php -v
composer -V
```

## 2. التثبيت المحلي

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

الإعداد الفعلي في بيئة الجهاز الحالية يعمل على MySQL:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3307
DB_DATABASE=restaurant_crm
DB_USERNAME=root
DB_PASSWORD=
QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
```

بعد تعديل قاعدة البيانات نفذ:

```powershell
php artisan migrate --seed
```

حساب الاختبار الذي ينشئه `DatabaseSeeder`:

```text
Email: test@example.com
Password: password
Role: admin
```

## 3. تشغيل Jobs

بدء الحملة ينشئ Jobs من جدول `campaign_recipients`. شغل العامل دائمًا في بيئة التشغيل:

```powershell
php artisan queue:work --queue=default
```

للحالة المحلية يمكن تشغيله بهذه الطريقة أثناء التطوير:

```powershell
php artisan queue:work
```

في الإنتاج استخدم Supervisor أو أي مدير عمليات.

## 4. المصادقة والتوكن

### تسجيل الدخول

```http
POST /api/v1/auth/login
Content-Type: application/json
```

```json
{
  "email": "test@example.com",
  "password": "password"
}
```

الاستجابة:

```json
{
  "token": "1|plainTextSanctumToken",
  "user": {
    "id": 1,
    "name": "Test User",
    "email": "test@example.com",
    "roles": []
  }
}
```

احفظ `token` وأرسلها في جميع الطلبات المحمية:

```http
Authorization: Bearer 1|plainTextSanctumToken
Accept: application/json
```

مثال cURL:

```bash
curl "http://127.0.0.1:8000/api/v1/auth/me" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer YOUR_TOKEN"
```

### Auth Endpoints

| Method | Endpoint | Authentication | الوصف |
|---|---|---|---|
| POST | `/api/v1/auth/login` | لا | تسجيل الدخول وإنشاء توكن |
| POST | `/api/v1/auth/logout` | نعم | حذف التوكن الحالي |
| GET | `/api/v1/auth/me` | نعم | عرض بيانات المستخدم والأدوار |
| POST | `/api/v1/auth/refresh` | نعم | حذف التوكن الحالي وإنشاء توكن جديد |

تسجيل الخروج يلغي التوكن الحالي فقط، وليس كل توكنات المستخدم.

## 5. تنسيق الاستجابات والأخطاء

نجاح القوائم يكون عبر Laravel pagination:

```json
{
  "current_page": 1,
  "data": [],
  "per_page": 15,
  "total": 0,
  "last_page": 1
}
```

أمثلة الأكواد:

| Status | المعنى |
|---|---|
| 200 | نجاح |
| 201 | تم إنشاء السجل |
| 401 | توكن غير صالح أو غير مرسل |
| 422 | خطأ تحقق من البيانات |
| 404 | السجل غير موجود |
| 502 | فشل الاستدعاء إلى Meta |

خطأ التحقق يكون غالبًا بهذا الشكل:

```json
{
  "message": "The phone field is required.",
  "errors": {
    "phone": [
      "The phone field is required."
    ]
  }
}
```

## 6. توحيد أرقام الهواتف

كل رقم عميل يخزن بصيغة دولية تشبه E.164، مع افتراض السعودية افتراضيًا.

أمثلة التحويل:

| المدخل | القيمة المخزنة |
|---|---|
| `0501234567` | `+966501234567` |
| `966501234567` | `+966501234567` |
| `+966501234567` | `+966501234567` |
| `00966501234567` | `+966501234567` |

رقم الهاتف يكون فريدًا في جدول `customers`. عند تكراره أثناء الاستيراد يتم تحديث العميل بدل إنشاء نسخة جديدة.

## 7. Customers API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/customers` | عرض العملاء مع البحث والترقيم |
| POST | `/api/v1/customers` | إضافة عميل |
| GET | `/api/v1/customers/{customer}` | عرض عميل ومجموعاته وسجلات الموافقة |
| PUT/PATCH | `/api/v1/customers/{customer}` | تعديل عميل |
| DELETE | `/api/v1/customers/{customer}` | أرشفة العميل |
| GET | `/api/v1/customers/{customer}/messages` | سجل رسائل WhatsApp للعميل |
| POST | `/api/v1/customers/import` | استيراد CSV/TXT |
| GET | `/api/v1/customers/export` | تصدير CSV |

### GET العملاء

```http
GET /api/v1/customers?search=050&status=active&per_page=20&page=1
```

المعاملات الاختيارية:

| Parameter | النوع | الوصف |
|---|---|---|
| `search` | string | بحث بالاسم أو الهاتف أو البريد |
| `status` | string | تصفية بالحالة |
| `per_page` | integer | عدد الصفوف الافتراضي 15 |
| `page` | integer | رقم الصفحة |

### POST عميل

```json
{
  "name": "عبدالله",
  "phone": "0501234567",
  "email": "abdullah@example.com",
  "city": "الرياض",
  "status": "active",
  "marketing_consent": true
}
```

حقول الطلب:

| Field | Required | الوصف |
|---|---|---|
| `name` | نعم | اسم العميل |
| `phone` | نعم | الهاتف بصفة دولية أو عربية سعودية |
| `email` | لا | بريد إلكتروني صالح |
| `city` | لا | المدينة |
| `status` | لا | حالة العميل، الافتراضي `active` |
| `marketing_consent` | لا | إنشاء موافقة تسويق عند true |

### تعديل عميل

```http
PUT /api/v1/customers/1
```

```json
{
  "name": "عبدالله محمد",
  "city": "جدة",
  "status": "active"
}
```

### الحذف والأرشفة

`DELETE` ينفذ Soft Delete، ولذلك لا يتحذف سجل الموافقة ولا السجل نهائيًا من قاعدة البيانات.

### استيراد CSV/TXT

```http
POST /api/v1/customers/import
Content-Type: multipart/form-data
```

مثال cURL:

```bash
curl "http://127.0.0.1:8000/api/v1/customers/import" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -F "file=@customers.csv"
```

رؤوس الملف المدعومة:

```csv
name,phone,email,city,status,consent
عبدالله,0501234567,abdullah@example.com,الرياض,active,yes
سارة,0551234567,sarah@example.com,جدة,active,no
```

قيم الموافقة المقبولة للموافقة:

```text
1, true, yes, نعم
```

قيم إلغاء الموافقة المقبولة:

```text
0, false, no, لا
```

الاستجابة تحتوي:

```json
{
  "id": 1,
  "file_name": "customers.csv",
  "total_rows": 2,
  "imported_rows": 2,
  "failed_rows": 0,
  "status": "completed"
}
```

النسخة الأولى تدعم CSV وTXT فقط، وليس XLSX. لتحميل Excel استخدم حفظ الملف بصيغة CSV UTF-8. دعم `.xlsx` يمكن إضافته لاحقًا عبر PhpSpreadsheet؛ ولم يُعد جزءًا من حالة التنفيذ الحالية.

### تصدير CSV

```bash
curl "http://127.0.0.1:8000/api/v1/customers/export" \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -o customers.csv
```

## 8. Groups API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/groups` | عرض المجموعات وعدد العملاء |
| POST | `/api/v1/groups` | إنشاء مجموعة |
| GET | `/api/v1/groups/{group}` | عرض مجموعة والعملاء |
| PUT/PATCH | `/api/v1/groups/{group}` | تعديل مجموعة |
| DELETE | `/api/v1/groups/{group}` | حذف مجموعة |
| POST | `/api/v1/groups/{group}/customers` | إسناد عملاء للمجموعة |

إنشاء مجموعة:

```json
{
  "name": "عملاء الرياض",
  "description": "عملاء المنطقة الوسطى"
}
```

إضافة عملاء:

```http
POST /api/v1/groups/1/customers
```

```json
{
  "customer_ids": [1, 2, 3]
}
```

عملية الإضافة لا تنشئ تكرارات بسبب مفتاح الربط المركب.

## 9. Campaigns API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/campaigns` | عرض الحملات |
| POST | `/api/v1/campaigns` | إنشاء حملة |
| GET | `/api/v1/campaigns/{campaign}` | تفاصيل الحملة والمستلمين |
| PUT/PATCH | `/api/v1/campaigns/{campaign}` | تعديل الحملة |
| DELETE | `/api/v1/campaigns/{campaign}` | حذف الحملة |
| POST | `/api/v1/campaigns/{campaign}/preview` | معاينة الجمهور المؤهل |
| POST | `/api/v1/campaigns/{campaign}/test` | إرسال تجربة لرقم محدد |
| POST | `/api/v1/campaigns/{campaign}/start` | بدء الحملة |
| POST | `/api/v1/campaigns/{campaign}/pause` | إيقاف الحملة مؤقتًا |
| POST | `/api/v1/campaigns/{campaign}/resume` | استكمال المستلمين المعلقين |
| GET | `/api/v1/campaigns/{campaign}/report` | تقرير عددي للحملة |

### إنشاء حملة

```json
{
  "name": "عرض العيد",
  "template_id": 1,
  "scheduled_at": "2026-11-24 18:00:00",
  "filters": {
    "city": "الرياض",
    "group_ids": [1, 2]
  }
}
```

حالات الحملة:

```text
Draft -> Running -> Paused -> Running -> Completed
```

الحالات المعلنة في النظام:

- `Draft`
- `Scheduled`
- `Running`
- `Paused`
- `Completed`
- `Failed`

الحملة تتحول إلى `Completed` عند اكتمال مستلميها بعد تنفيذ Jobs. الحملة المجدولة تُخزن بحالة `Scheduled` ويتم تشغيلها تلقائيًا بواسطة Laravel Scheduler.

### قواعد اختيار المستلمين

الجمهور المؤهل:

- عميل نشط موجود في الجدول.
- لديه سجل `marketing` موافقًا عليه.
- سجل الموافقة لا يحتوي `revoked_at`.
- يطابق فلتر المدينة إن كان موجودًا.
- يطابق فلتر المجموعات إن كان موجودًا.

المواطنون غير الموافقين أو الملغية موافقاتهم لن يُرسل لهم.

### معاينة الحملة

```http
POST /api/v1/campaigns/1/preview
```

```json
{
  "campaign_id": 1,
  "eligible_count": 25,
  "customers": []
}
```

### إرسال تجربة

```json
{
  "phone": "0501234567"
}
```

### بدء الحملة

```http
POST /api/v1/campaigns/1/start
```

لا يبدأ التنفيذ في هذه الـ endpoint فورًا إذا كان `QUEUE_CONNECTION=sync`؛ لكنه ينشأ وينفذ Job مباشرة في بيئة التطوير. في الإنتاج استخدم database/Redis queue ثم شغل Queue Worker.

حالات مستلم الحملة:

- `Pending`
- `Sent`
- `Failed`

حالات رسالة WhatsApp:

- `Pending`
- `Sent`
- `Delivered`
- `Read`
- `Failed`

معرف الرسالة الأساسي هو `wamid` الذي يرد به Meta.

## 10. WhatsApp API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/whatsapp/status` | حالة الربط والحساب النشط |
| GET | `/api/v1/whatsapp/templates` | عرض القوالب المحلية |
| POST | `/api/v1/whatsapp/templates/sync` | مزامنة القوالب من Meta |
| POST | `/api/v1/whatsapp/send` | إرسال نص فردي |

إرسال نص فردي:

```json
{
  "phone": "0501234567",
  "body": "مرحبًا بك في مطعمنا"
}
```

الاستجابة تتضمن سجلًا من `whatsapp_messages` بما فيه `wamid` إن نجح الإرسال من Meta.

### إعداد حساب WhatsApp

احفظ الحساب النشط في جدول `whatsapp_accounts`. لا تحفظ Access Token كنص مكشوف؛ الـ Model يحفظ الحقل المشفر تلقائيًا باستخدام Laravel encrypted cast.

مثال إعداد من Tinker:

```powershell
php artisan tinker --execute 'App\Models\WhatsAppAccount::updateOrCreate(["phone_number_id" => "META_PHONE_NUMBER_ID"], ["name" => "Main WhatsApp", "phone_number_id" => "META_PHONE_NUMBER_ID", "access_token" => "META_ACCESS_TOKEN", "business_account_id" => "META_BUSINESS_ACCOUNT_ID", "webhook_verify_token" => "YOUR_VERIFY_TOKEN", "app_secret" => "META_APP_SECRET", "is_active" => true]);'
```

`.env.example` يحتوي المتغيرات التالية:

```env
WHATSAPP_GRAPH_VERSION=v21.0
WHATSAPP_PHONE_NUMBER_ID=
WHATSAPP_ACCESS_TOKEN=
WHATSAPP_BUSINESS_ACCOUNT_ID=
WHATSAPP_WEBHOOK_VERIFY_TOKEN=
WHATSAPP_APP_SECRET=
```

`WHATSAPP_GRAPH_VERSION` يُستخدم فعليًا في استدعاءات Graph API.
بيانات الحساب الفعلي تُقرأ حاليًا من جدول `whatsapp_accounts`.

## 11. Conversations API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/whatsapp/conversations` | عرض المحادثات |
| GET | `/api/v1/whatsapp/conversations/{conversation}` | تفاصيل محادثة ورسائلها |
| POST | `/api/v1/whatsapp/conversations/{conversation}/reply` | رد موظف |
| POST | `/api/v1/whatsapp/conversations/{conversation}/assign` | تعيين موظف للمحادثة |
| POST | `/api/v1/whatsapp/conversations/{conversation}/close` | إغلاق المحادثة |

تصفية المحادثات:

```http
GET /api/v1/whatsapp/conversations?status=open&per_page=20
```

حالات المحادثة المستعملة حاليًا:

- `open`
- `closed`

رد على عميل:

```json
{
  "body": "تم تأكيد طلبك"
}
```

تعيين موظف:

```json
{
  "user_id": 1
}
```

تغلق المحادثة باستخدام POST بلا body:

```http
POST /api/v1/whatsapp/conversations/1/close
```

## 12. WhatsApp Webhooks

### التحقق عند إعداد Meta

```http
GET /api/v1/webhooks/whatsapp?hub.mode=subscribe&hub.verify_token=YOUR_VERIFY_TOKEN&hub.challenge=123456
```

في Meta Developer Console استخدم:

```text
Callback URL: https://YOUR-DOMAIN/api/v1/webhooks/whatsapp
Verify Token: YOUR_VERIFY_TOKEN
```

يجب أن يكون الـ Verify Token مطابقًا لقيمة الحساب النشط `webhook_verify_token`.

### استقبال الرسائل وحالاتها

```http
POST /api/v1/webhooks/whatsapp
Content-Type: application/json
X-Hub-Signature-256: sha256=...
```

السلوك:

- يتطلب التوقيع `X-Hub-Signature-256` عندما يكون `app_secret` مضافًا للحساب النشط.
- يجب أن يكون توقيع HMAC-SHA256 مطابقًا لجسم الطلب الخام.
- يتم تخزين الحدث في `whatsapp_webhook_events`.
- الرسائل التي تصل للعميل تنشئ أو تحدث العميل والمحادثة.
- حالات الرسائل تحدث `whatsapp_messages.status` حسب `wamid`.
- يتم وضع علامة `processed_at` لمنع معالجة الحدث نفسه مرة أخرى.
- الرد الناجح غالبًا:

```json
{
  "ok": true
}
```

في بيئة الإنتاج لا تستخدم Webhook بدون App Secret.

## 13. Automation API

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/bot/rules` | عرض قواعد الرد |
| POST | `/api/v1/bot/rules` | إنشاء قاعدة رد |
| PUT/PATCH | `/api/v1/bot/rules/{rule}` | تعديل قاعدة رد |
| GET | `/api/v1/bot/menus` | عرض القوائم وخياراتها |
| POST | `/api/v1/bot/menus` | إنشاء قائمة |
| POST | `/api/v1/bot/menus/{menu}/options` | إضافة اختيار للقائمة |

إنشاء قاعدة:

```json
{
  "trigger_type": "keyword",
  "trigger_value": "menu",
  "response": "اختر رقم الطلب",
  "is_active": true
}
```

إنشاء قائمة:

```json
{
  "title": "القائمة الرئيسية",
  "is_active": true
}
```

إضافة اختيار:

```json
{
  "title": "عرض المنتجات",
  "action_type": "message",
  "action_value": "عرض قائمة الطعام"
}
```

محرك البوت يطبق الآن داخل WhatsApp Webhook:

- مطابقة الكلمات حسب `exact` أو احتواء `keyword`.
- حفظ جلسة البوت لكل عميل في `bot_sessions`.
- حفظ القوائم النشطة واختيار الخيارات بواسطة عنوان الاختيار.
- `action_type=message` يرسل ردًا مباشرًا.
- `action_type=transfer` يحول المحادثة لمسار الموظفين ويزيل حالة القائمة النشطة.
- عند غياب مطابقة يتم عرض أول قائمة نشطة إذا كانت موجودة.

## 14. Dashboard, Reports, Users, Settings

### Dashboard

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/dashboard` | ملخص العملاء والحملات والمحادثات وحالات الرسائل |

### Reports

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/reports?from=2026-11-01&to=2026-11-30` | تقرير نطاق زمني للعملاء والحملات والرسائل |
| GET | `/api/v1/reports/campaigns` | تقرير الحملات وأعداد المستلمين والحالات |

### Users and Roles

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/users` | عرض المستخدمين والأدوار |
| POST | `/api/v1/users` | إنشاء مستخدم مع قائمة أدواره |
| GET | `/api/v1/users/{user}` | عرض مستخدم |
| PUT/PATCH | `/api/v1/users/{user}` | تعديل البيانات وكلمة المرور والأدوار |
| DELETE | `/api/v1/users/{user}` | حذف المستخدم والتوكنات المرتبطة به |
| GET | `/api/v1/roles` | عرض الأدوار وعدد المستخدمين |

إنشاء مستخدم:

```json
{
  "name": "موظف الدعم",
  "email": "agent@example.com",
  "password": "StrongPassword123",
  "role_ids": [1]
}
```

### Settings and Canned Replies

| Method | Endpoint | الوصف |
|---|---|---|
| GET | `/api/v1/settings` | عرض إعدادات النظام |
| PUT/PATCH | `/api/v1/settings` | تحديث/إنشاء إعدادات متعددة |
| GET | `/api/v1/canned-replies` | عرض الردود الجاهزة |
| POST | `/api/v1/canned-replies` | إنشاء رد جاهز |
| PUT/PATCH | `/api/v1/canned-replies/{cannedReply}` | تعديل رد جاهز |
| DELETE | `/api/v1/canned-replies/{cannedReply}` | حذف رد جاهز |

تحديث الإعدادات:

```json
{
  "settings": [
    {"key": "timezone", "value": "Asia/Riyadh"},
    {"key": "default_language", "value": "ar"}
  ]
}
```

## 15. Database Tables

الجداول الرئيسة:

| Table | الاستخدام |
|---|---|
| `users` | مستخدمو النظام |
| `roles` | الأدوار |
| `user_roles` | ربط المستخدم بالأدوار |
| `customers` | العملاء ورقم الهاتف الفريد |
| `customer_groups` | مجموعات العملاء |
| `customer_group_members` | ربط العملاء بالمجموعات |
| `consent_records` | الموافقات والإلغاءات |
| `imports` | سجل استيراد العملاء |
| `campaigns` | الحملات |
| `campaign_recipients` | مستلمو الحملات وحالاتهم |
| `message_templates` | قوالب Meta |
| `whatsapp_accounts` | حسابات WhatsApp وبيانات الربط المشفرة |
| `whatsapp_messages` | الرسائل وwamid وحالة Meta |
| `whatsapp_webhook_events` | أحداث Webhook ومنع التكرار |
| `message_attachments` | مرفقات الرسائل |
| `conversations` | المحادثات |
| `conversation_messages` | رسائل المحادثة |
| `conversation_assignments` | سجل تعيين المحادثات |
| `canned_replies` | الردود الجاهزة |
| `bot_rules` | قواعد الأتمتة |
| `bot_menus` | القوائم |
| `bot_menu_options` | خيارات القوائم |
| `bot_sessions` | جلسات وحالة القوائم التلقائية للعميل |
| `canned_replies` | الردود الجاهزة |
| `system_settings` | إعدادات النظام |
| `activity_logs` | سجل العمليات |
| `jobs` | Queue jobs |
| `job_batches` | مجموعات Jobs |
| `failed_jobs` | Jobs الفاشلة |

## 16. الهاتف والموافقة والأمان

- رقم الهاتف Unique بعد تحويله للصيغة الدولية.
- الموافقة تسجل ولا تُحذف عند الإلغاء؛ يتم تعيين `revoked_at`.
- الحملات لا ترسل إلا للعملاء الموافقين غير الملغاة موافقتهم.
- Access Token في `whatsapp_accounts.access_token` مشفر تلقائيًا.
- App Secret مشفر أيضًا باستخدام encrypted cast.
- جميع Webhooks تتحقق من التوقيع في حالة وجود App Secret.
- حدث Webhook لا يعاد معالجته مرتين باستخدام event_id/processed_at.
- جميع endpoints الإدارية محمية بـ Sanctum Bearer Token باستثناء Login وWhatsApp GET/POST Webhook.

## 17. أوامر مفيدة

عرض كل Routes:

```powershell
php artisan route:list --path=api/v1
```

تشغيل migrations:

```powershell
php artisan migrate
```

إعادة بناء قاعدة الاختبار:

```powershell
php artisan migrate:fresh --seed
```

تشغيل الاختبارات:

```powershell
php artisan test
```

تنسيق ملفات PHP:

```powershell
.\vendor\bin\pint.bat
```

عرض حالة Queue Jobs:

```powershell
php artisan queue:work
```

تشغيل مجدول Laravel للحملات المجدولة:

```powershell
php artisan schedule:work
```
