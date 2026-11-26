# تشغيل Evolution من غير Docker

> المشروع ده بقى من غير Docker خالص. ملف `docker-compose.evolution.yml` اتشال من الريبو.

## الفكرة في سطر

Laravel بيكلم Evolution على `http://127.0.0.1:8080` عن طريق HTTP
(`config/whatsapp.php`: `evolution_url`)، سواء كان Evolution شغال في
كونتينر Docker أو شغال من الكود مباشرة بـ Node.js. فمفيش أي تغيير
مطلوب في كود Laravel عشان نشتغل من غير Docker — التغيير كله في
طريقة تشغيل Evolution نفسه.

## المتطلبات

- Node.js 20 أو أعلى (`node --version`)
- Git
- MySQL شغالة على `127.0.0.1:3307` (نفس اللي Laravel شغال عليها)
- مفتاح `EVOLUTION_API_KEY` متظبط في ملف `.env` بتاع Laravel
  (لازم يبقى نفس المفتاح اللي Evolution هيشتغل بيه، وإلا Laravel
  هيرجع 401 ومش هيطلع QR)

## التشغيل بأمر واحد (Windows)

من PowerShell داخل مجلد الباك اند:

```powershell
.\scripts\start-evolution.ps1
```

السكربت بيعمل كل حاجة لوحده:

1. يتأكد إن Node.js 20+ و Git موجودين.
2. لو مجلد `evolution-api` مش موجود جنب مجلد الباك اند، يعمل
   `git clone` من https://github.com/evolution-foundation/evolution-api
3. يعمل `npm install` و `npm run build` لو لسه متعملوش.
4. يظبط ملف `.env` بتاع Evolution: `SERVER_PORT=8080` و
   `AUTHENTICATION_API_KEY` بنفس قيمة `EVOLUTION_API_KEY` اللي في
   `.env` بتاع Laravel، و `DATABASE_PROVIDER=mysql` على نفس MySQL
   اللي Laravel شغال عليها (`127.0.0.1:3307`) بقاعدة بيانات اسمها
   `evolution` (السكربت بينشئها وجداولها لو مش موجودة).
5. يشغّل Evolution على `http://127.0.0.1:8080`.

سيب نافذة PowerShell دي مفتوحة طول ما انت عايز الواتساب شغال —
لو قفلتها Evolution بيقف.

## التشغيل يدوي (لو السكربت مش مناسبك)

```powershell
git clone https://github.com/evolution-foundation/evolution-api.git
cd evolution-api
npm install
Copy-Item .env.example .env
```

وظبّط في `.env`:

```env
SERVER_PORT=8080
AUTHENTICATION_API_KEY=<نفس مفتاح EVOLUTION_API_KEY في .env بتاع Laravel بالظبط>
DATABASE_PROVIDER=mysql
DATABASE_CONNECTION_URI=mysql://root:@127.0.0.1:3307/evolution
DATABASE_ENABLED=true
```

وأنشئ قاعدة البيانات والجداول (على نفس MySQL اللي Laravel شغال عليها):

```powershell
php ..\backend\artisan tinker --execute "DB::statement('CREATE DATABASE IF NOT EXISTS evolution');"
npm run db:generate
Copy-Item prisma\mysql-migrations\* prisma\migrations -Recurse -Force
npx prisma migrate deploy --schema prisma\mysql-schema.prisma
npm run build
npm run start:prod
```

(`db:generate` بيولّد Prisma client اللي الـ build محتاجه،
ولو متعملش الـ build بيفشل بأخطاء `has no exported member`.
ولازم قاعدة بيانات حقيقية — حتى مع `DATABASE_ENABLED=false` السيرفر
بيحاول يتصل بقاعدة البيانات عند التشغيل وبيقع بخطأ `P1001` لو مش موجودة.)

## التأكد إنه شغال

1. افتح `http://127.0.0.1:8080` في المتصفح — لازم يرد عليك.
2. شغّل Laravel (`php artisan serve`) والفرونت (`npm run dev`).
3. من صفحة WhatsAppConnect في الفرونت اطلب الـ QR وامسحه **برقم
   تجريبي الأول، مش رقم المطعم**.
4. بعد الاتصال، جرّب رسالة تجريبية من Postman أو من الفرونت.

## قاعدة البيانات

Evolution بيتصل بقاعدة البيانات عند التشغيل حتى لو
`DATABASE_ENABLED=false` — قيمة وهمية في `DATABASE_CONNECTION_URI`
بتوقع السيرفر بخطأ `P1001` (مش قادر يوصل لقاعدة البيانات).
عشان كده السكربت بيستخدم MySQL اللي Laravel شغال عليها أصلاً
(`127.0.0.1:3307`) وينشئ قاعدة بيانات `evolution` وجداولها
(`prisma migrate deploy`) قبل التشغيل.

## ملاحظات

- الإيقاع زي ما هو: 40 رسالة/يوم، 30–60 ثانية بين الرسايل، وقفة
  10 دقايق كل 20 رسالة، من 12:00 لـ 21:00 بتوقيت السعودية —
  يعني الـ 600 عميل في حوالي 15 يوم.
- لو الاتصال وقع، الحملات بتقف فوراً والباقي يتأجل لتاني يوم 09:00
  وبيتكمل يدوي بـ Resume.
- الـ Webhook بتاع Evolution بقى على `http://127.0.0.1:8000/api/v1/webhooks/evolution`
  (بعد ما كان `host.docker.internal` بتاع Docker).
