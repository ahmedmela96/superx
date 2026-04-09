# SUPERX ERP (PHP + SQLite)

نظام ERP متكامل لشركة الشحن **SUPERX** يشمل:

- لوحة إدارة كاملة (المستخدمين، الصلاحيات، الشحنات، المرتجعات).
- لوحة التاجر (إنشاء شحنات، متابعة الحالة وبيانات العملاء).
- واجهة المندوب (تحديث: تسليم / فشل / تأجيل / مرتجع).
- واجهة العميل لتتبع الشحنة.
- قاعدة بيانات فعلية (SQLite) بدل بيانات واجهة فقط.
- صلاحيات متعددة بحسب الدور (admin / merchant / courier / customer).
- API تتبع شحنة (`?page=api-track&tracking=...`).
- تكامل API واتساب عبر متغيرات البيئة.

## المتطلبات

- PHP 8.1+
- امتداد SQLite مفعّل
- (اختياري) cURL + مزود WhatsApp API

## التشغيل المحلي

```bash
php -S 0.0.0.0:8000
```

ثم افتح:

- `http://localhost:8000/?page=login`
- `http://localhost:8000/?page=track`

## حسابات تجريبية

- admin@superx.local / admin123
- merchant@superx.local / merchant123
- courier@superx.local / courier123
- customer@superx.local / customer123

## إعداد API الواتساب

قبل التشغيل، عرّف المتغيرات:

```bash
export SUPERX_WHATSAPP_API_URL="https://your-provider.example/send"
export SUPERX_WHATSAPP_TOKEN="your_token"
```

عند تحديث حالة الشحنة سيتم محاولة إرسال رسالة واتساب للعميل تلقائياً.
