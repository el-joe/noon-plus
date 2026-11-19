<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory as C;

/**
 * Emails to marketers and creators (Marketer panel). Recipient:
 * MarketerProfile.user_id.
 */
class MarketerEmails extends CatalogueSection
{
    public static function actor(): string
    {
        return 'marketer';
    }

    protected static function defaultRecipients(): string
    {
        return 'Marketer (MarketerProfile.user_id)';
    }

    public static function events(): array
    {
        $earnings = ['View earnings', 'عرض الأرباح'];

        return [
            'marketer.campaign_invitation' => self::e(C::Opportunities, 'Campaign invitation', 'Campaigns Invitations::dispatch', ['campaign', 'brand', 'commission', 'respond_by'],
                ['{{ brand }} invited you to "{{ campaign }}"', "Hi {{ name }},\n\n{{ brand }} invited you to promote \"{{ campaign }}\" for {{ commission }}. Respond by {{ respond_by }}."],
                ['دعاك {{ brand }} إلى "{{ campaign }}"', "مرحباً {{ name }}،\n\nدعاك {{ brand }} للترويج لحملة \"{{ campaign }}\" مقابل {{ commission }}. يرجى الرد قبل {{ respond_by }}."],
                ['button' => ['Open invitation', 'فتح الدعوة']]),
            'marketer.conversion' => self::e(C::Transactional, 'New conversion', 'Conversions::record', ['campaign', 'amount', 'order_reference'],
                ['New sale through your link: {{ amount }}', "Hi {{ name }},\n\nYour link for \"{{ campaign }}\" earned {{ amount }} on order {{ order_reference }}. It becomes available after the return window."],
                ['عملية بيع جديدة عبر رابطك: {{ amount }}', "مرحباً {{ name }}،\n\nحقق رابطك لحملة \"{{ campaign }}\" مبلغ {{ amount }} من الطلب {{ order_reference }}. يصبح متاحاً بعد انتهاء فترة الإرجاع."],
                ['button' => $earnings]),
            'marketer.earnings_available' => self::e(C::Transactional, 'Earnings available', 'StateMoved MarketerConversion → released', ['amount', 'campaign'],
                ['{{ amount }} is ready to withdraw', "Hi {{ name }},\n\n{{ amount }} from \"{{ campaign }}\" is now available. Request a withdrawal any time."],
                ['{{ amount }} جاهز للسحب', "مرحباً {{ name }}،\n\nأصبح مبلغ {{ amount }} من \"{{ campaign }}\" متاحاً. اطلب السحب في أي وقت."],
                ['button' => ['Withdraw', 'سحب']]),
            'marketer.withdrawal_approved' => self::e(C::Transactional, 'Withdrawal approved', 'StateMoved MarketerWithdrawal → approved', ['withdrawal_number', 'amount'],
                ['Withdrawal {{ withdrawal_number }} approved', "Hi {{ name }},\n\nYour withdrawal {{ withdrawal_number }} of {{ amount }} is approved and will be paid shortly."],
                ['تمت الموافقة على طلب السحب {{ withdrawal_number }}', "مرحباً {{ name }}،\n\nتمت الموافقة على طلب السحب {{ withdrawal_number }} بمبلغ {{ amount }} وسيتم الدفع قريباً."],
                ['button' => $earnings]),
            'marketer.withdrawal_paid' => self::e(C::Transactional, 'Payout paid', 'StateMoved MarketerWithdrawal → processed', ['withdrawal_number', 'amount', 'reference'],
                ['Payout sent: {{ amount }}', "Hi {{ name }},\n\nWe paid {{ amount }} for withdrawal {{ withdrawal_number }}. Reference: {{ reference }}."],
                ['تم إرسال الدفعة: {{ amount }}', "مرحباً {{ name }}،\n\nدفعنا {{ amount }} لطلب السحب {{ withdrawal_number }}. المرجع: {{ reference }}."],
                ['button' => $earnings]),
            'marketer.withdrawal_rejected' => self::e(C::Transactional, 'Withdrawal rejected', 'StateMoved MarketerWithdrawal → rejected', ['withdrawal_number', 'amount', 'reason'],
                ['Withdrawal {{ withdrawal_number }} was not approved', "Hi {{ name }},\n\nYour withdrawal {{ withdrawal_number }} of {{ amount }} was not approved.\nReason: {{ reason }}\n\nThe amount is back in your available balance."],
                ['لم تتم الموافقة على طلب السحب {{ withdrawal_number }}', "مرحباً {{ name }}،\n\nلم تتم الموافقة على طلب السحب {{ withdrawal_number }} بمبلغ {{ amount }}.\nالسبب: {{ reason }}\n\nعاد المبلغ إلى رصيدك المتاح."],
                ['button' => $earnings]),
            'marketer.celebrity_report' => self::e(C::Transactional, 'Celebrity monthly report (PDF)', 'CelebrityReports::email (monthly on the 1st)', ['month', 'earnings'],
                ['Your Nawy report for {{ month }}', "Hi {{ name }},\n\nYour monthly performance report for {{ month }} is attached. Earnings this month: {{ earnings }}."],
                ['تقريرك من ناوي لشهر {{ month }}', "مرحباً {{ name }}،\n\nأرفقنا تقرير أدائك الشهري لشهر {{ month }}. أرباح هذا الشهر: {{ earnings }}."],
                ['status' => 'existing', 'attachment' => 'Celebrity report PDF']),
            'marketer.flash_sale_invite' => self::e(C::Opportunities, 'Flash-sale invitation (creator)', 'FlashSaleMarketerInvites::invite', ['sale', 'starts'],
                ['Promote the {{ sale }} flash sale', "Hi {{ name }},\n\nYou are invited to promote the {{ sale }} flash sale starting {{ starts }} and earn on every sale."],
                ['روّج للتخفيضات السريعة {{ sale }}', "مرحباً {{ name }}،\n\nأنت مدعو للترويج للتخفيضات السريعة {{ sale }} التي تبدأ في {{ starts }} واكسب من كل عملية بيع."],
                ['button' => ['Open invitation', 'فتح الدعوة']]),
        ];
    }
}
