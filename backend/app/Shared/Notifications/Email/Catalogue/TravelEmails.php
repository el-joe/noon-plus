<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory as C;

/**
 * Emails to travel agencies (Travel panel). Recipient: the agency owner.
 */
class TravelEmails extends CatalogueSection
{
    public static function actor(): string
    {
        return 'travel';
    }

    protected static function defaultRecipients(): string
    {
        return 'Agency owner (Vendor.owner_user_id)';
    }

    public static function events(): array
    {
        $promo = ['Open promotions', 'فتح الترويجات'];

        return [
            'travel.booking_request' => self::e(C::Transactional, 'New booking request', 'TravelBookings::notifySubmitted (request / submit)', ['booking_number', 'trip', 'travellers', 'date', 'contact'],
                ['New booking request {{ booking_number }}', "Hi {{ name }},\n\n{{ contact }} asked to book \"{{ trip }}\" on {{ date }} for {{ travellers }} traveller(s). Confirm or decline it from the Travel panel — payment is arranged with the traveller directly."],
                ['طلب حجز جديد {{ booking_number }}', "مرحباً {{ name }}،\n\nطلب {{ contact }} حجز \"{{ trip }}\" بتاريخ {{ date }} لعدد {{ travellers }} مسافر. أكّد الطلب أو ارفضه من لوحة السفر، ويتم ترتيب الدفع مع المسافر مباشرة."],
                ['status' => 'existing', 'button' => ['Open booking', 'فتح الحجز']]),
            'travel.inquiry_received' => self::e(C::Transactional, 'New trip question', 'TravelInquiries::ask', ['trip', 'from', 'question'],
                ['New question about {{ trip }}', "Hi {{ name }},\n\n{{ from }} asked about \"{{ trip }}\":\n\n{{ question }}"],
                ['سؤال جديد عن {{ trip }}', "مرحباً {{ name }}،\n\nسأل {{ from }} عن \"{{ trip }}\":\n\n{{ question }}"],
                ['status' => 'existing', 'button' => ['Reply', 'الرد']]),
            'travel.promotion_active' => self::e(C::Transactional, 'Promotion paid & active', 'StateMoved TravelPromotion → active (TravelPromotions::paid)', ['promotion', 'trip', 'ends'],
                ['Your promotion for {{ trip }} is live', "Hi {{ name }},\n\nPayment received — your {{ promotion }} promotion for \"{{ trip }}\" is live until {{ ends }}."],
                ['ترويجك لـ {{ trip }} فعّال الآن', "مرحباً {{ name }}،\n\nتم استلام الدفعة، وترويج {{ promotion }} لرحلة \"{{ trip }}\" فعّال حتى {{ ends }}."],
                ['button' => $promo]),
            'travel.promotion_expiring' => self::e(C::Reminders, 'Promotion expiring', 'Scheduler nawy:emails:promotion-expiry — active promotion ending within 3 days', ['promotion', 'trip', 'ends'],
                ['Your promotion for {{ trip }} ends on {{ ends }}', "Hi {{ name }},\n\nYour {{ promotion }} promotion for \"{{ trip }}\" ends on {{ ends }}. Extend it to stay at the top of results."],
                ['ينتهي ترويجك لـ {{ trip }} في {{ ends }}', "مرحباً {{ name }}،\n\nينتهي ترويج {{ promotion }} لرحلة \"{{ trip }}\" في {{ ends }}. مدّده لتبقى في أعلى النتائج."],
                ['button' => $promo]),
            'travel.promotion_expired' => self::e(C::Transactional, 'Promotion expired', 'StateMoved TravelPromotion → expired', ['promotion', 'trip'],
                ['Your promotion for {{ trip }} has ended', "Hi {{ name }},\n\nYour {{ promotion }} promotion for \"{{ trip }}\" has ended. Buy a new one any time."],
                ['انتهى ترويجك لـ {{ trip }}', "مرحباً {{ name }}،\n\nانتهى ترويج {{ promotion }} لرحلة \"{{ trip }}\". يمكنك شراء ترويج جديد في أي وقت."],
                ['button' => $promo]),
            'travel.promotion_failed' => self::e(C::Transactional, 'Promotion payment failed', 'StateMoved TravelPromotion → cancelled (payment not completed)', ['promotion', 'trip', 'reason'],
                ['Your promotion for {{ trip }} was not activated', "Hi {{ name }},\n\nThe payment for the {{ promotion }} promotion of \"{{ trip }}\" was not completed ({{ reason }}). Try again from the panel."],
                ['لم يتم تفعيل ترويجك لـ {{ trip }}', "مرحباً {{ name }}،\n\nلم تكتمل دفعة ترويج {{ promotion }} لرحلة \"{{ trip }}\" ({{ reason }}). حاول مرة أخرى من اللوحة."],
                ['button' => $promo]),
            'travel.package_approved' => self::e(C::Transactional, 'Package approved', 'StateMoved TravelPackage → active', ['trip'],
                ['"{{ trip }}" is approved and live', "Hi {{ name }},\n\nYour package \"{{ trip }}\" was approved and is now visible to travellers."],
                ['تمت الموافقة على "{{ trip }}" وهي منشورة', "مرحباً {{ name }}،\n\nتمت الموافقة على باقتك \"{{ trip }}\" وأصبحت ظاهرة للمسافرين."],
                ['button' => ['Open package', 'فتح الباقة']]),
            'travel.package_rejected' => self::e(C::Transactional, 'Package rejected', 'StateMoved TravelPackage → rejected', ['trip', 'reason'],
                ['"{{ trip }}" was not approved', "Hi {{ name }},\n\nYour package \"{{ trip }}\" was not approved.\nReason: {{ reason }}\n\nEdit it and submit again."],
                ['لم تتم الموافقة على "{{ trip }}"', "مرحباً {{ name }}،\n\nلم تتم الموافقة على باقتك \"{{ trip }}\".\nالسبب: {{ reason }}\n\nعدّلها وأرسلها مرة أخرى."],
                ['button' => ['Edit package', 'تعديل الباقة']]),
        ];
    }
}
