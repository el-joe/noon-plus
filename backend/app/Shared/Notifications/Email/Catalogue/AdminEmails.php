<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory as C;

/**
 * Operations alerts for admins. Only admins who hold the event's
 * permission (in the event's country, or super admins) and opted in on
 * their email preferences receive them. Admin security mail (new device,
 * two-step changes) uses the shared `auth.*` events.
 */
class AdminEmails extends CatalogueSection
{
    public static function actor(): string
    {
        return 'admin';
    }

    protected static function defaultRecipients(): string
    {
        return 'Opted-in admins holding the permission';
    }

    public static function events(): array
    {
        $open = ['Open in admin', 'فتح في لوحة الإدارة'];

        return [
            'admin.partner_application' => self::e(C::AdminAlerts, 'New partner application', 'StateMoved PartnerApplication → submitted', ['application_number', 'type', 'company'],
                ['New {{ type }} application {{ application_number }}', 'A new {{ type }} application {{ application_number }} from {{ company }} is waiting for review.'],
                ['طلب {{ type }} جديد {{ application_number }}', 'طلب {{ type }} جديد {{ application_number }} من {{ company }} بانتظار المراجعة.'],
                ['permission' => 'applications.view', 'button' => $open]),
            'admin.advertise_inquiry' => self::e(C::AdminAlerts, 'New advertise inquiry', 'AdvertiseInquiries::submit', ['company', 'contact'],
                ['New advertising inquiry from {{ company }}', '{{ contact }} from {{ company }} asked about advertising on Nawy.'],
                ['استفسار إعلاني جديد من {{ company }}', 'استفسر {{ contact }} من {{ company }} عن الإعلان على ناوي.'],
                ['status' => 'existing', 'permission' => 'applications.view', 'button' => $open]),
            'admin.payment_under_review' => self::e(C::AdminAlerts, 'Payment under review', 'PaymentAttempts::applyOutcome → UnderReview', ['payment_reference', 'order_number', 'amount', 'reason'],
                ['Payment {{ payment_reference }} needs review', 'Payment {{ payment_reference }} for order {{ order_number }} ({{ amount }}) is under review: {{ reason }}.'],
                ['الدفعة {{ payment_reference }} تحتاج إلى مراجعة', 'الدفعة {{ payment_reference }} للطلب {{ order_number }} ({{ amount }}) قيد المراجعة: {{ reason }}.'],
                ['permission' => 'payments.review', 'button' => $open]),
            'admin.dispute_opened' => self::e(C::AdminAlerts, 'Dispute opened', 'Disputes::open', ['dispute_number', 'order_number', 'reason'],
                ['Dispute {{ dispute_number }} opened', 'Dispute {{ dispute_number }} was opened on order {{ order_number }}: {{ reason }}.'],
                ['تم فتح النزاع {{ dispute_number }}', 'تم فتح النزاع {{ dispute_number }} على الطلب {{ order_number }}: {{ reason }}.'],
                ['permission' => 'disputes.manage', 'button' => $open]),
            'admin.job_failed' => self::e(C::AdminAlerts, 'Queue job / scheduled task failed', 'Queue::failing and ScheduledTaskFailed (one alert per job class per hour)', ['job', 'queue', 'error'],
                ['Job failed: {{ job }}', "The job {{ job }} on queue {{ queue }} failed:\n\n{{ error }}"],
                ['فشلت المهمة: {{ job }}', "فشلت المهمة {{ job }} على قائمة الانتظار {{ queue }}:\n\n{{ error }}"],
                ['permission' => 'system_health.view', 'button' => ['Open system health', 'فتح صحة النظام']]),
            'admin.migration_quarantine' => self::e(C::AdminAlerts, 'Data migration quarantine', 'MigrationJobs::execute finished with rows quarantined', ['run', 'count'],
                ['{{ count }} legacy rows quarantined in {{ run }}', 'The data migration run {{ run }} put {{ count }} rows in quarantine. Review them in Data migration → Quarantine queue.'],
                ['تم حجز {{ count }} صفاً قديماً في {{ run }}', 'وضعت عملية ترحيل البيانات {{ run }} عدد {{ count }} صفاً في الحجز. راجعها من ترحيل البيانات ← قائمة الحجز.'],
                ['permission' => 'data_migration.manage', 'button' => $open]),
            'admin.migration_failed' => self::e(C::AdminAlerts, 'Data migration job failed', 'MigrationJobs::execute → failed', ['run', 'error'],
                ['Data migration {{ run }} failed', "The data migration job {{ run }} failed:\n\n{{ error }}"],
                ['فشلت عملية ترحيل البيانات {{ run }}', "فشلت مهمة ترحيل البيانات {{ run }}:\n\n{{ error }}"],
                ['permission' => 'data_migration.manage', 'button' => $open]),
            'admin.reconcile_mismatch' => self::e(C::AdminAlerts, 'Reconciliation mismatch', 'nawy:legacy:reconcile finds differences', ['scope', 'count'],
                ['Reconciliation found {{ count }} differences', 'Reconciling {{ scope }} found {{ count }} differences between the legacy and new data.'],
                ['وجدت المطابقة {{ count }} اختلافات', 'وجدت مطابقة {{ scope }} عدد {{ count }} اختلافات بين البيانات القديمة والجديدة.'],
                ['permission' => 'data_migration.manage', 'button' => $open]),
            'admin.warehouse_capacity' => self::e(C::AdminAlerts, 'Warehouse capacity low / over capacity', 'StorageBilling::snapshot — used ≥ 90 % of capacity', ['warehouse', 'used_percent'],
                ['Warehouse {{ warehouse }} is at {{ used_percent }}% capacity', 'Warehouse {{ warehouse }} is using {{ used_percent }}% of its capacity. Plan removals or new space.'],
                ['المستودع {{ warehouse }} عند {{ used_percent }}% من سعته', 'يستخدم المستودع {{ warehouse }} نسبة {{ used_percent }}% من سعته. خطط لعمليات سحب أو مساحة جديدة.'],
                ['permission' => 'fbn.configure', 'button' => $open]),
            'admin.email_bounces' => self::e(C::AdminAlerts, 'Email complaints / bounce spike', 'Email webhook — complaint received', ['event', 'type', 'recipient'],
                ['Email {{ type }} on {{ event }}', 'A {{ type }} was reported for {{ recipient }} on the {{ event }} email. The address is now suppressed.'],
                ['{{ type }} على رسالة {{ event }}', 'تم الإبلاغ عن {{ type }} للعنوان {{ recipient }} على رسالة {{ event }}. تم إيقاف المراسلة لهذا العنوان.'],
                ['permission' => 'system_health.view', 'button' => ['Open email log', 'فتح سجل البريد']]),
        ];
    }
}
