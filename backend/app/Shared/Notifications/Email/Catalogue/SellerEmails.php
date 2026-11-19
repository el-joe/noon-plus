<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory as C;

/**
 * Emails to sellers / partners (Seller Center): applications, team,
 * contracts, orders, FBN, payouts, subscriptions, requests, ads, invites
 * and documents. Recipient: the store owner unless stated.
 */
class SellerEmails extends CatalogueSection
{
    public static function actor(): string
    {
        return 'seller';
    }

    protected static function defaultRecipients(): string
    {
        return 'Store owner (Vendor.owner_user_id)';
    }

    public static function events(): array
    {
        $order = ['Open order', 'فتح الطلب'];
        $center = ['Open Seller Center', 'فتح مركز البائعين'];

        return [
            // Applications & team
            'partner.application_email_code' => self::e(C::Security, 'Partner application email code', 'ApplicationWizard email verification', ['code', 'minutes'],
                ['Your Nawy application code', "Hi {{ name }},\n\nUse this code to verify your email for your Nawy partner application. It expires in {{ minutes }} minutes."],
                ['رمز طلب الشراكة مع ناوي', "مرحباً {{ name }}،\n\nاستخدم هذا الرمز لتأكيد بريدك الإلكتروني في طلب الشراكة مع ناوي. تنتهي صلاحيته خلال {{ minutes }} دقيقة."],
                ['status' => 'existing', 'sensitive' => true, 'recipients' => 'Applicant email']),
            'partner.application_submitted' => self::e(C::Transactional, 'Partner application received', 'ApplicationWizard::submit / PartnerApplicationController', ['application_number', 'type'],
                ['We received your application {{ application_number }}', "Hi {{ name }},\n\nThank you for applying to join Nawy as a {{ type }}. Our team reviews applications within three business days and will email you the outcome."],
                ['استلمنا طلبك {{ application_number }}', "مرحباً {{ name }}،\n\nشكراً لتقديمك طلب الانضمام إلى ناوي بصفة {{ type }}. يراجع فريقنا الطلبات خلال ثلاثة أيام عمل وسنرسل لك النتيجة بالبريد الإلكتروني."],
                ['status' => 'existing', 'recipients' => 'Applicant email', 'button' => ['Application status', 'حالة الطلب']]),
            'partner.application_approved' => self::e(C::Transactional, 'Partner application approved', 'ApplicationReview::approve', ['application_number', 'type'],
                ['Your Nawy application is approved', "Hi {{ name }},\n\nCongratulations — your application {{ application_number }} is approved. Your {{ type }} account is ready; sign in to finish setting it up."],
                ['تمت الموافقة على طلبك في ناوي', "مرحباً {{ name }}،\n\nتهانينا، تمت الموافقة على طلبك {{ application_number }}. حسابك بصفة {{ type }} جاهز، سجّل الدخول لإكمال إعداده."],
                ['status' => 'existing', 'recipients' => 'Applicant email', 'button' => ['Sign in', 'تسجيل الدخول']]),
            'partner.application_rejected' => self::e(C::Transactional, 'Partner application rejected', 'ApplicationReview::reject', ['application_number', 'reason'],
                ['Update on your Nawy application {{ application_number }}', "Hi {{ name }},\n\nWe are sorry — we cannot approve application {{ application_number }} at this time.\nReason: {{ reason }}"],
                ['تحديث بشأن طلبك في ناوي {{ application_number }}', "مرحباً {{ name }}،\n\nنأسف، لا يمكننا الموافقة على الطلب {{ application_number }} في الوقت الحالي.\nالسبب: {{ reason }}"],
                ['status' => 'existing', 'recipients' => 'Applicant email']),
            'partner.application_changes_requested' => self::e(C::Transactional, 'Partner application needs more information', 'ApplicationReview::requestChanges', ['application_number', 'changes'],
                ['Your application {{ application_number }} needs a few changes', "Hi {{ name }},\n\nWe reviewed application {{ application_number }} and need a few changes before we can approve it:\n\n{{ changes }}"],
                ['يحتاج طلبك {{ application_number }} إلى بعض التعديلات', "مرحباً {{ name }}،\n\nراجعنا الطلب {{ application_number }} ونحتاج إلى بعض التعديلات قبل الموافقة عليه:\n\n{{ changes }}"],
                ['status' => 'existing', 'recipients' => 'Applicant email', 'button' => ['Update application', 'تحديث الطلب']]),
            'partner.welcome' => self::e(C::Transactional, 'Partner account created (welcome)', 'TenantProvisioner after approval', ['panel'],
                ['Welcome to Nawy {{ panel }}', "Hi {{ name }},\n\nYour Nawy {{ panel }} account is ready. Sign in with this email address as described below."],
                ['مرحباً بك في {{ panel }} من ناوي', "مرحباً {{ name }}،\n\nحسابك في {{ panel }} من ناوي جاهز. سجّل الدخول بهذا البريد الإلكتروني كما هو موضّح أدناه."],
                ['status' => 'existing', 'sensitive' => true, 'recipients' => 'New partner user', 'button' => ['Sign in', 'تسجيل الدخول']]),
            'team.invitation' => self::e(C::Transactional, 'Team invitation', 'TeamInvitations::invite (every panel with teams)', ['inviter', 'team', 'role', 'expires'],
                ['You are invited to join {{ team }}', "Hi {{ name }},\n\n{{ inviter }} invited you to join {{ team }} as {{ role }}. Nothing changes on your account unless you accept. The invitation expires on {{ expires }}."],
                ['أنت مدعو للانضمام إلى {{ team }}', "مرحباً {{ name }}،\n\nدعاك {{ inviter }} للانضمام إلى {{ team }} بدور {{ role }}. لا يتغير شيء في حسابك ما لم تقبل الدعوة. تنتهي صلاحية الدعوة في {{ expires }}."],
                ['status' => 'existing', 'sensitive' => true, 'recipients' => 'Invitee email', 'button' => ['Accept invitation', 'قبول الدعوة']]),
            'panel.password_reset' => self::e(C::Security, 'Panel password reset link', 'Panel forgot-password', ['minutes'],
                ['Reset your Nawy panel password', "Hi {{ name }},\n\nUse the link below within {{ minutes }} minutes to choose a new password. If you did not ask for it, ignore this email."],
                ['إعادة تعيين كلمة مرور لوحة ناوي', "مرحباً {{ name }}،\n\nاستخدم الرابط أدناه خلال {{ minutes }} دقيقة لاختيار كلمة مرور جديدة. إذا لم تطلب ذلك فتجاهل هذه الرسالة."],
                ['status' => 'existing', 'sensitive' => true, 'recipients' => 'Panel user', 'button' => ['Reset password', 'إعادة تعيين كلمة المرور']]),
            'contract.update_to_sign' => self::e(C::Transactional, 'Agreement update to sign', 'ContractPublisher::publish (new version that needs signing / notice period)', ['contract', 'version', 'effective'],
                ['Please review the updated {{ contract }}', "Hi {{ name }},\n\nWe published version {{ version }} of the {{ contract }}. It takes effect on {{ effective }}. Please review and sign it in your panel before then."],
                ['يرجى مراجعة {{ contract }} المحدّثة', "مرحباً {{ name }}،\n\nنشرنا الإصدار {{ version }} من {{ contract }} ويسري في {{ effective }}. يرجى مراجعتها وتوقيعها من لوحتك قبل ذلك التاريخ."],
                ['recipients' => 'Every signer of the previous version (vendor, marketer, agency owners)', 'button' => ['Review and sign', 'المراجعة والتوقيع']]),

            // Orders
            'seller.order_new' => self::e(C::Transactional, 'New order (per sub-order)', 'Order placed (COD) or paid (card / BNPL / wallet) — one email per sub-order to its store', ['shipment_number', 'items', 'total', 'confirm_by'],
                ['New order {{ shipment_number }}', "Hi {{ name }},\n\nYou have a new order {{ shipment_number }} ({{ total }}): {{ items }}.\n\nPlease confirm it by {{ confirm_by }}."],
                ['طلب جديد {{ shipment_number }}', "مرحباً {{ name }}،\n\nلديك طلب جديد {{ shipment_number }} ({{ total }}): {{ items }}.\n\nيرجى تأكيده قبل {{ confirm_by }}."],
                ['button' => $order, 'channels' => ['mail']]),
            'seller.order_cancelled' => self::e(C::Transactional, 'Order cancelled by the customer', 'StateMoved SubOrder → cancelled (actor customer, admin or system)', ['shipment_number', 'cancelled_by', 'reason'],
                ['Order {{ shipment_number }} was cancelled', "Hi {{ name }},\n\nOrder {{ shipment_number }} was cancelled by {{ cancelled_by }}.\nReason: {{ reason }}\n\nDo not ship it."],
                ['تم إلغاء الطلب {{ shipment_number }}', "مرحباً {{ name }}،\n\nتم إلغاء الطلب {{ shipment_number }} بواسطة {{ cancelled_by }}.\nالسبب: {{ reason }}\n\nلا تقم بشحنه."],
                ['button' => $order]),
            'seller.sla_unconfirmed' => self::e(C::Reminders, 'SLA reminder: order not confirmed', 'Scheduler nawy:emails:seller-sla — sub-order placed > 24 h and still unconfirmed', ['shipment_number', 'hours'],
                ['Reminder: confirm order {{ shipment_number }}', "Hi {{ name }},\n\nOrder {{ shipment_number }} has waited {{ hours }} hours for your confirmation. Late confirmations affect your seller rating and may cancel the order."],
                ['تذكير: أكّد الطلب {{ shipment_number }}', "مرحباً {{ name }}،\n\nينتظر الطلب {{ shipment_number }} تأكيدك منذ {{ hours }} ساعة. يؤثر التأخير على تقييمك كبائع وقد يؤدي إلى إلغاء الطلب."],
                ['button' => $order]),
            'seller.sla_not_shipped' => self::e(C::Reminders, 'SLA reminder: order not shipped', 'Scheduler nawy:emails:seller-sla — confirmed / packed sub-order past its handling time', ['shipment_number', 'days'],
                ['Reminder: ship order {{ shipment_number }}', "Hi {{ name }},\n\nOrder {{ shipment_number }} was confirmed {{ days }} day(s) ago and has not shipped yet. Please hand it to the carrier today."],
                ['تذكير: اشحن الطلب {{ shipment_number }}', "مرحباً {{ name }}،\n\nتم تأكيد الطلب {{ shipment_number }} منذ {{ days }} يوم ولم يُشحن بعد. يرجى تسليمه لشركة الشحن اليوم."],
                ['button' => $order]),
            'seller.return_requested' => self::e(C::Transactional, 'Return request', 'ReturnRequests::create', ['return_number', 'shipment_number', 'reason', 'respond_by'],
                ['Return request {{ return_number }}', "Hi {{ name }},\n\nA customer asked to return items from {{ shipment_number }}.\nReason: {{ reason }}\n\nPlease respond by {{ respond_by }}."],
                ['طلب إرجاع {{ return_number }}', "مرحباً {{ name }}،\n\nطلب عميل إرجاع منتجات من {{ shipment_number }}.\nالسبب: {{ reason }}\n\nيرجى الرد قبل {{ respond_by }}."],
                ['button' => ['Open return', 'فتح الإرجاع']]),
            'seller.low_stock' => self::e(C::Reminders, 'Low stock', 'InventoryLedger::move — stock falls to the low-stock threshold (once per listing per day)', ['product', 'sku', 'quantity'],
                ['Low stock: {{ product }}', "Hi {{ name }},\n\nOnly {{ quantity }} left of {{ product }} (SKU {{ sku }}). Restock soon to avoid missing sales."],
                ['مخزون منخفض: {{ product }}', "مرحباً {{ name }}،\n\nتبقى {{ quantity }} فقط من {{ product }} (رمز {{ sku }}). أعد التخزين قريباً كي لا تفوتك المبيعات."],
                ['button' => ['Update stock', 'تحديث المخزون']]),

            // FBN
            'fbn.inbound_reminder' => self::e(C::Reminders, 'FBN inbound reminder', 'InboundReminders::send (weekly) — draft / submitted inbound shipments not sent yet', ['inbound_number', 'status'],
                ['Inbound shipment {{ inbound_number }} is still {{ status }}', "Hi {{ name }},\n\nYour inbound shipment {{ inbound_number }} to the Nawy warehouse is still {{ status }}. Send it so your stock goes live."],
                ['شحنة التوريد {{ inbound_number }} لا تزال {{ status }}', "مرحباً {{ name }}،\n\nشحنة التوريد {{ inbound_number }} إلى مستودع ناوي لا تزال {{ status }}. أرسلها ليصبح مخزونك متاحاً للبيع."],
                ['button' => ['Open inbound', 'فتح شحنة التوريد']]),
            'fbn.inbound_received' => self::e(C::Transactional, 'FBN inbound received', 'InboundShipments::finishReceiving (no discrepancy)', ['inbound_number', 'received'],
                ['Inbound {{ inbound_number }} received', "Hi {{ name }},\n\nWe received inbound {{ inbound_number }}: {{ received }} units are now available for sale."],
                ['تم استلام شحنة التوريد {{ inbound_number }}', "مرحباً {{ name }}،\n\nاستلمنا شحنة التوريد {{ inbound_number }}: أصبحت {{ received }} وحدة متاحة للبيع."],
                ['button' => ['Open inbound', 'فتح شحنة التوريد']]),
            'fbn.inbound_discrepancy' => self::e(C::Transactional, 'FBN inbound discrepancy', 'InboundShipments::finishReceiving with short or damaged units', ['inbound_number', 'received', 'short', 'damaged'],
                ['Discrepancy on inbound {{ inbound_number }}', "Hi {{ name }},\n\nWe finished receiving {{ inbound_number }}: {{ received }} units received, {{ short }} short and {{ damaged }} damaged. Review the details and contact us within 7 days if you disagree."],
                ['اختلاف في شحنة التوريد {{ inbound_number }}', "مرحباً {{ name }}،\n\nأنهينا استلام {{ inbound_number }}: تم استلام {{ received }} وحدة، ونقص {{ short }}، وتلف {{ damaged }}. راجع التفاصيل وتواصل معنا خلال 7 أيام إذا كان لديك اعتراض."],
                ['button' => ['Open inbound', 'فتح شحنة التوريد']]),
            'fbn.removal_completed' => self::e(C::Transactional, 'FBN removal done', 'StateMoved RemovalOrder → completed', ['removal_number', 'units'],
                ['Removal {{ removal_number }} completed', "Hi {{ name }},\n\nRemoval order {{ removal_number }} is complete: {{ units }} units left the Nawy warehouse."],
                ['اكتمل طلب السحب {{ removal_number }}', "مرحباً {{ name }}،\n\nاكتمل طلب السحب {{ removal_number }}: غادرت {{ units }} وحدة مستودع ناوي."],
                ['button' => $center]),
            'fbn.storage_invoice' => self::e(C::Transactional, 'FBN storage invoice', 'StorageBilling::invoice (monthly)', ['invoice_number', 'period', 'amount'],
                ['Storage invoice {{ invoice_number }}: {{ amount }}', "Hi {{ name }},\n\nYour Nawy warehouse storage invoice {{ invoice_number }} for {{ period }} is {{ amount }}. It will be deducted from your next payout."],
                ['فاتورة التخزين {{ invoice_number }}: {{ amount }}', "مرحباً {{ name }}،\n\nفاتورة التخزين في مستودع ناوي {{ invoice_number }} عن {{ period }} بمبلغ {{ amount }}، وستُخصم من دفعتك القادمة."],
                ['button' => ['View invoice', 'عرض الفاتورة']]),

            // Payouts
            'payout.statement' => self::e(C::Transactional, 'Payout statement', 'Payouts::generateFor', ['payout_number', 'period', 'amount'],
                ['Payout statement {{ payout_number }}: {{ amount }}', "Hi {{ name }},\n\nYour payout statement {{ payout_number }} for {{ period }} is ready. Net amount: {{ amount }}."],
                ['كشف الدفعة {{ payout_number }}: {{ amount }}', "مرحباً {{ name }}،\n\nكشف دفعتك {{ payout_number }} عن {{ period }} جاهز. المبلغ الصافي: {{ amount }}."],
                ['button' => ['View statement', 'عرض الكشف']]),
            'payout.approved' => self::e(C::Transactional, 'Payout approved', 'StateMoved VendorPayout → approved', ['payout_number', 'amount'],
                ['Payout {{ payout_number }} approved', "Hi {{ name }},\n\nPayout {{ payout_number }} of {{ amount }} is approved and will be transferred shortly."],
                ['تمت الموافقة على الدفعة {{ payout_number }}', "مرحباً {{ name }}،\n\nتمت الموافقة على الدفعة {{ payout_number }} بمبلغ {{ amount }} وسيتم تحويلها قريباً."],
                ['button' => ['View payout', 'عرض الدفعة']]),
            'payout.paid' => self::e(C::Transactional, 'Payout paid', 'StateMoved VendorPayout → completed', ['payout_number', 'amount', 'reference'],
                ['Payout {{ payout_number }} paid: {{ amount }}', "Hi {{ name }},\n\nWe transferred {{ amount }} for payout {{ payout_number }}. Bank reference: {{ reference }}."],
                ['تم تحويل الدفعة {{ payout_number }}: {{ amount }}', "مرحباً {{ name }}،\n\nحوّلنا {{ amount }} للدفعة {{ payout_number }}. المرجع البنكي: {{ reference }}."],
                ['button' => ['View payout', 'عرض الدفعة']]),
            'payout.failed' => self::e(C::Transactional, 'Payout failed / on hold', 'StateMoved VendorPayout → failed / on_hold', ['payout_number', 'amount', 'reason'],
                ['Action needed on payout {{ payout_number }}', "Hi {{ name }},\n\nPayout {{ payout_number }} of {{ amount }} could not be completed.\nReason: {{ reason }}\n\nCheck your bank details in the panel."],
                ['إجراء مطلوب بشأن الدفعة {{ payout_number }}', "مرحباً {{ name }}،\n\nتعذّر إتمام الدفعة {{ payout_number }} بمبلغ {{ amount }}.\nالسبب: {{ reason }}\n\nتحقق من بياناتك البنكية في اللوحة."],
                ['button' => ['View payout', 'عرض الدفعة']]),

            // Subscriptions
            'subscription.invoice' => self::e(C::Transactional, 'Subscription invoice issued', 'VendorSubscriptions issued', ['plan', 'invoice_number', 'amount', 'due'],
                ['Subscription invoice {{ invoice_number }}', "Hi {{ name }},\n\nYour {{ plan }} subscription invoice {{ invoice_number }} for {{ amount }} is due on {{ due }}. The PDF is attached."],
                ['فاتورة الاشتراك {{ invoice_number }}', "مرحباً {{ name }}،\n\nتستحق فاتورة اشتراك {{ plan }} رقم {{ invoice_number }} بمبلغ {{ amount }} في {{ due }}. أرفقنا ملف PDF."],
                ['status' => 'existing', 'attachment' => 'Subscription invoice PDF', 'button' => ['Pay invoice', 'دفع الفاتورة']]),
            'subscription.auto_charged' => self::e(C::Transactional, 'Subscription renewed (auto-charge)', 'VendorSubscriptions auto_charged', ['plan', 'invoice_number', 'amount'],
                ['Your {{ plan }} subscription renewed', "Hi {{ name }},\n\nWe charged {{ amount }} for invoice {{ invoice_number }} and renewed your {{ plan }} subscription."],
                ['تم تجديد اشتراك {{ plan }}', "مرحباً {{ name }}،\n\nخصمنا {{ amount }} للفاتورة {{ invoice_number }} وجددنا اشتراكك في {{ plan }}."],
                ['status' => 'existing', 'button' => $center]),
            'subscription.auto_charge_failed' => self::e(C::Transactional, 'Subscription auto-charge failed', 'VendorSubscriptions auto_charge_failed', ['plan', 'invoice_number', 'amount'],
                ['We could not charge your {{ plan }} subscription', "Hi {{ name }},\n\nThe automatic charge of {{ amount }} for invoice {{ invoice_number }} failed. Pay it from the panel to keep your plan."],
                ['تعذّر تحصيل اشتراك {{ plan }}', "مرحباً {{ name }}،\n\nفشل الخصم التلقائي بمبلغ {{ amount }} للفاتورة {{ invoice_number }}. ادفعها من اللوحة للاحتفاظ بخطتك."],
                ['status' => 'existing', 'button' => ['Pay invoice', 'دفع الفاتورة']]),
            'subscription.past_due' => self::e(C::Transactional, 'Subscription past due / final notice', 'VendorSubscriptions past_due and final_notice', ['plan', 'invoice_number', 'amount', 'deadline'],
                ['Your {{ plan }} subscription is past due', "Hi {{ name }},\n\nInvoice {{ invoice_number }} ({{ amount }}) is past due. Pay it by {{ deadline }} or your plan features will stop."],
                ['اشتراك {{ plan }} متأخر السداد', "مرحباً {{ name }}،\n\nالفاتورة {{ invoice_number }} ({{ amount }}) متأخرة السداد. ادفعها قبل {{ deadline }} وإلا ستتوقف مزايا خطتك."],
                ['status' => 'existing', 'button' => ['Pay invoice', 'دفع الفاتورة']]),
            'subscription.expired' => self::e(C::Transactional, 'Subscription expired', 'VendorSubscriptions expired', ['plan'],
                ['Your {{ plan }} subscription has expired', "Hi {{ name }},\n\nYour {{ plan }} subscription expired and its features are off. Subscribe again from the panel at any time."],
                ['انتهى اشتراك {{ plan }}', "مرحباً {{ name }}،\n\nانتهى اشتراكك في {{ plan }} وتوقفت مزاياه. يمكنك الاشتراك مجدداً من اللوحة في أي وقت."],
                ['status' => 'existing', 'button' => $center]),
            'subscription.renewal_upcoming' => self::e(C::Reminders, 'Subscription renewal / expiring soon', 'Scheduler nawy:emails:renewal-reminders — 7 days before current_period_end', ['plan', 'renews_on', 'amount', 'auto_renew'],
                ['Your {{ plan }} subscription renews on {{ renews_on }}', "Hi {{ name }},\n\nYour {{ plan }} subscription period ends on {{ renews_on }}. {{ auto_renew }} Amount: {{ amount }}."],
                ['يتجدد اشتراك {{ plan }} في {{ renews_on }}', "مرحباً {{ name }}،\n\nتنتهي فترة اشتراكك في {{ plan }} في {{ renews_on }}. {{ auto_renew }} المبلغ: {{ amount }}."],
                ['button' => $center]),

            // Requests, reviews, ads, invites
            'seller.product_request_decision' => self::e(C::Transactional, 'Product request decision', 'StateMoved ProductRequest → approved / rejected', ['request_title', 'decision', 'note'],
                ['Your product request "{{ request_title }}" was {{ decision }}', "Hi {{ name }},\n\nYour product request \"{{ request_title }}\" was {{ decision }}.\n\n{{ note }}"],
                ['طلب المنتج "{{ request_title }}": {{ decision }}', "مرحباً {{ name }}،\n\nطلب المنتج \"{{ request_title }}\": {{ decision }}.\n\n{{ note }}"],
                ['recipients' => 'Requesting vendor owner or marketer', 'button' => ['Open request', 'فتح الطلب']]),
            'seller.change_request_decision' => self::e(C::Transactional, 'Profile change request decision', 'StateMoved ChangeRequest → approved / rejected', ['request_title', 'decision', 'note'],
                ['Your change request was {{ decision }}', "Hi {{ name }},\n\nYour request to change {{ request_title }} was {{ decision }}.\n\n{{ note }}"],
                ['طلب التعديل: {{ decision }}', "مرحباً {{ name }}،\n\nطلبك لتعديل {{ request_title }}: {{ decision }}.\n\n{{ note }}"],
                ['recipients' => 'Vendor / agency owner', 'button' => ['Open request', 'فتح الطلب']]),
            'seller.review_received' => self::e(C::Transactional, 'New product review', 'ProductReviews::create', ['product', 'rating'],
                ['New {{ rating }}-star review on {{ product }}', "Hi {{ name }},\n\nA customer left a {{ rating }}-star review on {{ product }}. You can reply publicly from the panel."],
                ['تقييم جديد ({{ rating }} نجوم) على {{ product }}', "مرحباً {{ name }}،\n\nترك عميل تقييماً بـ {{ rating }} نجوم على {{ product }}. يمكنك الرد علناً من اللوحة."],
                ['button' => ['Open reviews', 'فتح التقييمات']]),
            'seller.question_received' => self::e(C::Transactional, 'New product question', 'ProductQuestions::ask — to every store selling the product', ['product', 'question'],
                ['New question about {{ product }}', "Hi {{ name }},\n\nA customer asked about {{ product }}:\n\n{{ question }}"],
                ['سؤال جديد عن {{ product }}', "مرحباً {{ name }}،\n\nسأل عميل عن {{ product }}:\n\n{{ question }}"],
                ['recipients' => 'Owners of stores selling the product', 'button' => ['Answer', 'الإجابة']]),
            'ads.campaign_decision' => self::e(C::Transactional, 'Ad campaign review decision', 'StateMoved AdCampaign → active / rejected', ['campaign', 'decision', 'note'],
                ['Ad campaign "{{ campaign }}" {{ decision }}', "Hi {{ name }},\n\nYour ad campaign \"{{ campaign }}\" was {{ decision }}.\n\n{{ note }}"],
                ['الحملة الإعلانية "{{ campaign }}": {{ decision }}', "مرحباً {{ name }}،\n\nحملتك الإعلانية \"{{ campaign }}\": {{ decision }}.\n\n{{ note }}"],
                ['recipients' => 'Advertiser (vendor owner or marketer)', 'button' => ['Open campaign', 'فتح الحملة']]),
            'ads.booking_decision' => self::e(C::Transactional, 'Ad booking review decision', 'StateMoved AdBooking → approved / rejected', ['placement', 'decision', 'note'],
                ['Ad booking for {{ placement }} {{ decision }}', "Hi {{ name }},\n\nYour ad booking for {{ placement }} was {{ decision }}.\n\n{{ note }}"],
                ['حجز الإعلان في {{ placement }}: {{ decision }}', "مرحباً {{ name }}،\n\nحجزك الإعلاني في {{ placement }}: {{ decision }}.\n\n{{ note }}"],
                ['recipients' => 'Advertiser (vendor owner or marketer)', 'button' => ['Open booking', 'فتح الحجز']]),
            'flash_sale.invitation' => self::e(C::Opportunities, 'Flash-sale invitation', 'FlashSaleEventMachine → submission_open (all active stores of the country)', ['sale', 'starts', 'submit_by'],
                ['You are invited to {{ sale }}', "Hi {{ name }},\n\nSubmissions are open for the {{ sale }} flash sale starting {{ starts }}. Submit your best offers by {{ submit_by }}."],
                ['أنت مدعو إلى {{ sale }}', "مرحباً {{ name }}،\n\nباب المشاركة مفتوح في التخفيضات السريعة {{ sale }} التي تبدأ في {{ starts }}. قدّم أفضل عروضك قبل {{ submit_by }}."],
                ['recipients' => 'Store owners in the country', 'button' => ['Submit offers', 'تقديم العروض']]),
            'coupon.participation_invite' => self::e(C::Opportunities, 'Coupon participation invite', 'StateMoved CouponParticipationInvitation → open (stores / marketers in the audience)', ['coupon', 'discount', 'join_by'],
                ['Join the {{ coupon }} coupon programme', "Hi {{ name }},\n\nNawy is running the {{ coupon }} coupon ({{ discount }}). Join by {{ join_by }} to have your products included."],
                ['انضم إلى برنامج قسيمة {{ coupon }}', "مرحباً {{ name }}،\n\nتطلق ناوي قسيمة {{ coupon }} ({{ discount }}). انضم قبل {{ join_by }} لتُدرج منتجاتك."],
                ['recipients' => 'Store owners / marketers in the audience', 'button' => ['Join', 'انضم']]),

            // Documents (every business owner type)
            'document.expiring' => self::e(C::Reminders, 'Document expiring', 'Scheduler nawy:emails:document-expiry — approved document valid_until within 30 / 7 days', ['document', 'expires'],
                ['Your {{ document }} expires on {{ expires }}', "Hi {{ name }},\n\nYour {{ document }} on file expires on {{ expires }}. Upload a renewed copy to avoid interruptions to your account."],
                ['تنتهي صلاحية {{ document }} في {{ expires }}', "مرحباً {{ name }}،\n\nتنتهي صلاحية {{ document }} المسجّل لدينا في {{ expires }}. ارفع نسخة مجددة لتجنب توقف حسابك."],
                ['recipients' => 'Document owner (vendor, agency, marketer, delivery agent, carrier)', 'button' => ['Upload document', 'رفع المستند']]),
            'document.rejected' => self::e(C::Transactional, 'Document rejected', 'StateMoved Document → rejected', ['document', 'reason'],
                ['Your {{ document }} was rejected', "Hi {{ name }},\n\nWe could not accept your {{ document }}.\nReason: {{ reason }}\n\nPlease upload a new copy."],
                ['تم رفض {{ document }}', "مرحباً {{ name }}،\n\nلم نتمكن من قبول {{ document }}.\nالسبب: {{ reason }}\n\nيرجى رفع نسخة جديدة."],
                ['recipients' => 'Document owner (vendor, agency, marketer, delivery agent, carrier)', 'button' => ['Upload document', 'رفع المستند']]),
            'document.expired' => self::e(C::Transactional, 'Document expired', 'StateMoved Document → expired', ['document'],
                ['Your {{ document }} has expired', "Hi {{ name }},\n\nYour {{ document }} has expired. Upload a valid copy to keep your account active."],
                ['انتهت صلاحية {{ document }}', "مرحباً {{ name }}،\n\nانتهت صلاحية {{ document }}. ارفع نسخة سارية للحفاظ على نشاط حسابك."],
                ['recipients' => 'Document owner (vendor, agency, marketer, delivery agent, carrier)', 'button' => ['Upload document', 'رفع المستند']]),
            'advertise.inquiry_sent' => self::e(C::Transactional, 'Advertise inquiry received (prospect copy)', 'AdvertiseInquiries::submit', ['company'],
                ['We received your advertising inquiry', "Hi {{ name }},\n\nThanks for your interest in advertising on Nawy. Our partnerships team will contact {{ company }} within two business days."],
                ['استلمنا استفسارك الإعلاني', "مرحباً {{ name }}،\n\nشكراً لاهتمامك بالإعلان على ناوي. سيتواصل فريق الشراكات مع {{ company }} خلال يومي عمل."],
                ['status' => 'existing', 'recipients' => 'Prospect email']),
        ];
    }
}
