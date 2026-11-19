<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory as C;

/**
 * Emails to delivery agents and carrier companies. Document events are
 * shared with sellers ({@see SellerEmails}).
 */
class DeliveryEmails extends CatalogueSection
{
    public static function actor(): string
    {
        return 'delivery';
    }

    protected static function defaultRecipients(): string
    {
        return 'Delivery agent (DeliveryAgent.user_id)';
    }

    public static function events(): array
    {
        $wallet = ['Open earnings', 'فتح الأرباح'];

        return [
            'delivery.agent_credentials' => self::e(C::Security, 'Delivery agent account created', 'DeliveryAgents::create', ['company'],
                ['Your Nawy delivery account', "Hi {{ name }},\n\n{{ company }} created your Nawy delivery account. Sign in to the delivery app with this email address and the temporary password below."],
                ['حساب التوصيل في ناوي', "مرحباً {{ name }}،\n\nأنشأت {{ company }} حساب التوصيل الخاص بك في ناوي. سجّل الدخول إلى تطبيق التوصيل بهذا البريد الإلكتروني وكلمة المرور المؤقتة أدناه."],
                ['status' => 'existing', 'sensitive' => true]),
            'delivery.assignment' => self::e(C::Transactional, 'New delivery assignment', 'DeliveryDispatch::assign / reassign', ['shipment_number', 'area', 'deadline', 'cod_amount'],
                ['New delivery: {{ shipment_number }}', "Hi {{ name }},\n\nYou were assigned shipment {{ shipment_number }} in {{ area }}. Deliver by {{ deadline }}.{{ cod_amount }}"],
                ['توصيل جديد: {{ shipment_number }}', "مرحباً {{ name }}،\n\nتم إسناد الشحنة {{ shipment_number }} في {{ area }} إليك. سلّمها قبل {{ deadline }}.{{ cod_amount }}"],
                ['button' => ['Open assignment', 'فتح المهمة'], 'channels' => ['mail']]),
            'delivery.payslip_issued' => self::e(C::Transactional, 'Payslip issued (PDF attached)', 'AgentPay::issue (daily)', ['payslip_number', 'period', 'amount'],
                ['Payslip {{ payslip_number }}: {{ amount }}', "Hi {{ name }},\n\nYour payslip {{ payslip_number }} for {{ period }} is attached. Net pay: {{ amount }}."],
                ['قسيمة الراتب {{ payslip_number }}: {{ amount }}', "مرحباً {{ name }}،\n\nأرفقنا قسيمة راتبك {{ payslip_number }} عن {{ period }}. صافي الأجر: {{ amount }}."],
                ['recipients' => 'Delivery agent (and the carrier owner for carrier agents)', 'attachment' => 'Payslip PDF (PayslipDocument::pdf)', 'button' => $wallet]),
            'delivery.withdrawal_approved' => self::e(C::Transactional, 'Withdrawal approved', 'StateMoved DeliveryWithdrawal → approved', ['withdrawal_number', 'amount'],
                ['Withdrawal {{ withdrawal_number }} approved', "Hi {{ name }},\n\nYour withdrawal {{ withdrawal_number }} of {{ amount }} is approved."],
                ['تمت الموافقة على طلب السحب {{ withdrawal_number }}', "مرحباً {{ name }}،\n\nتمت الموافقة على طلب السحب {{ withdrawal_number }} بمبلغ {{ amount }}."],
                ['recipients' => 'Requesting agent / carrier user', 'button' => $wallet]),
            'delivery.withdrawal_paid' => self::e(C::Transactional, 'Withdrawal paid', 'StateMoved DeliveryWithdrawal → paid', ['withdrawal_number', 'amount', 'reference'],
                ['Withdrawal {{ withdrawal_number }} paid', "Hi {{ name }},\n\nWe paid {{ amount }} for withdrawal {{ withdrawal_number }}. Reference: {{ reference }}."],
                ['تم دفع طلب السحب {{ withdrawal_number }}', "مرحباً {{ name }}،\n\nدفعنا {{ amount }} لطلب السحب {{ withdrawal_number }}. المرجع: {{ reference }}."],
                ['recipients' => 'Requesting agent / carrier user', 'button' => $wallet]),
            'delivery.withdrawal_rejected' => self::e(C::Transactional, 'Withdrawal rejected', 'StateMoved DeliveryWithdrawal → rejected', ['withdrawal_number', 'amount', 'reason'],
                ['Withdrawal {{ withdrawal_number }} was not approved', "Hi {{ name }},\n\nYour withdrawal {{ withdrawal_number }} of {{ amount }} was not approved.\nReason: {{ reason }}"],
                ['لم تتم الموافقة على طلب السحب {{ withdrawal_number }}', "مرحباً {{ name }}،\n\nلم تتم الموافقة على طلب السحب {{ withdrawal_number }} بمبلغ {{ amount }}.\nالسبب: {{ reason }}"],
                ['recipients' => 'Requesting agent / carrier user', 'button' => $wallet]),
        ];
    }
}
