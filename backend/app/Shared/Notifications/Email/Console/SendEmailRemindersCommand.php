<?php

namespace App\Shared\Notifications\Email\Console;

use App\Domains\Advertising\Enums\TravelPromotionStatus;
use App\Domains\Advertising\Models\TravelPromotion;
use App\Domains\Cart\Models\Cart;
use App\Domains\Countries\Support\CountryContext;
use App\Domains\Documents\Models\Document;
use App\Domains\Finance\Models\PromotionalCredit;
use App\Domains\Finance\Models\VendorSubscription;
use App\Domains\Finance\Models\VendorSubscriptionPlan;
use App\Domains\Membership\Models\Membership;
use App\Domains\Orders\Enums\SubOrderStatus;
use App\Domains\Orders\Models\SubOrder;
use App\Models\User;
use App\Shared\Http\StorefrontLinks;
use App\Shared\Notifications\Email\Emails;
use App\Shared\Notifications\Email\EmailValue;
use App\Shared\Notifications\Email\Models\EmailPreference;
use App\Shared\Notifications\Email\PartnerEmails;
use App\Shared\Scheduling\ForEachCountry;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Scheduled reminder emails (docs/project/email-catalogue.md), per active
 * country. Every reminder respects the recipient's preferences (category
 * `reminders` / `recommendations` / `cart_reminders`) and is idempotent
 * through its `key`, so a re-run never sends twice.
 *
 * Kinds: seller-sla, abandoned-carts, document-expiry, renewal-reminders,
 * credit-expiry, review-requests, promotion-expiry (ad and FBN inbound
 * reminders run in `nawy:classifieds:expire` / `nawy:fbn:inbound-reminders`).
 */
class SendEmailRemindersCommand extends Command
{
    public const KINDS = ['seller-sla', 'abandoned-carts', 'document-expiry', 'renewal-reminders', 'credit-expiry', 'review-requests', 'promotion-expiry'];

    protected $signature = 'nawy:emails:reminders {kind : One of seller-sla, abandoned-carts, document-expiry, renewal-reminders, credit-expiry, review-requests, promotion-expiry}';

    protected $description = 'Send scheduled reminder emails (respects preferences, idempotent)';

    public function handle(ForEachCountry $countries, Emails $emails, PartnerEmails $partners): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, self::KINDS, true)) {
            $this->error('Unknown kind. Use one of: '.implode(', ', self::KINDS));

            return self::FAILURE;
        }

        $countries->run('emails:'.$kind, function () use ($kind, $emails, $partners): void {
            match ($kind) {
                'seller-sla' => $this->sellerSla($partners),
                'abandoned-carts' => $this->abandonedCarts($emails),
                'document-expiry' => $this->documentExpiry($partners),
                'renewal-reminders' => $this->renewals($emails, $partners),
                'credit-expiry' => $this->creditExpiry($emails),
                'review-requests' => $this->reviewRequests($emails),
                'promotion-expiry' => $this->promotionExpiry($partners),
            };
        });

        $this->info("Reminders sent: {$kind}.");

        return self::SUCCESS;
    }

    private function sellerSla(PartnerEmails $partners): void
    {
        $hours = (int) config('nawy.mail.reminders.seller_unconfirmed_hours');

        SubOrder::query()->where('status', SubOrderStatus::Placed)->where('fulfilled_by', '!=', 'nawy')->where('created_at', '<=', now()->subHours($hours))
            ->where('created_at', '>=', now()->subDays(7))->limit(1000)->get()
            ->each(function (SubOrder $subOrder) use ($partners): void {
                $waited = (int) $subOrder->created_at->diffInHours(now());
                $partners->toVendor($subOrder->vendor_id, 'seller.sla_unconfirmed', ['shipment_number' => $subOrder->number, 'hours' => (string) $waited], '/orders/'.$subOrder->id,
                    ['entity' => $subOrder, 'key' => 'day'.intdiv($waited, 24)]);
            });

        SubOrder::query()->whereIn('status', [SubOrderStatus::Confirmed, SubOrderStatus::Processing, SubOrderStatus::Packed])->where('fulfilled_by', '!=', 'nawy')
            ->with(['statusHistory', 'items.listing'])->where('updated_at', '>=', now()->subDays(14))->limit(1000)->get()
            ->each(function (SubOrder $subOrder) use ($partners): void {
                $confirmedAt = $subOrder->statusHistory->firstWhere(fn ($change): bool => ($change->to_status->value ?? $change->to_status) === 'confirmed')?->created_at;
                $handling = max(1, (int) $subOrder->items->max(fn ($item) => $item->listing?->handling_days ?? 1));

                if ($confirmedAt === null || Carbon::parse($confirmedAt)->addDays($handling)->isFuture()) {
                    return;
                }

                $days = (int) Carbon::parse($confirmedAt)->diffInDays(now());
                $partners->toVendor($subOrder->vendor_id, 'seller.sla_not_shipped', ['shipment_number' => $subOrder->number, 'days' => (string) $days], '/orders/'.$subOrder->id,
                    ['entity' => $subOrder, 'key' => 'ship-day'.$days]);
            });
    }

    private function abandonedCarts(Emails $emails): void
    {
        $hours = (int) config('nawy.mail.reminders.abandoned_cart_hours');
        $optedIn = EmailPreference::query()->where('topic', 'cart_reminders')->where('enabled', true)->pluck('user_id');

        if ($optedIn->isEmpty()) {
            return;
        }

        Cart::query()->whereIn('customer_user_id', $optedIn)->with('items.listing.variant.product')->limit(1000)->get()
            ->each(function (Cart $cart) use ($emails, $hours): void {
                $last = $cart->items->max('updated_at');

                if ($cart->items->isEmpty() || $last === null || Carbon::parse($last)->isAfter(now()->subHours($hours)) || Carbon::parse($last)->isBefore(now()->subDays(7))) {
                    return;
                }

                $user = User::query()->find($cart->customer_user_id);
                $titles = $cart->items->take(3)->map(fn ($item): string => (string) ($item->listing?->variant?->product?->title_en ?? $item->listing?->vendor_sku ?? '—'))->implode(', ');

                $emails->send('cart.abandoned', $user, ['item_count' => (string) $cart->items->sum('quantity'), 'items' => $titles], [
                    'entity' => $cart, 'key' => 'week'.now()->isoWeek().'-'.now()->year,
                    'url' => app(StorefrontLinks::class)->to('cart', [], $user?->preferredLocale()),
                ]);
            });
    }

    private function documentExpiry(PartnerEmails $partners): void
    {
        foreach ((array) config('nawy.mail.reminders.document_expiry_days') as $days) {
            Document::query()->where('status', 'approved')->whereDate('valid_until', now()->addDays((int) $days)->toDateString())->limit(2000)->get()
                ->each(function (Document $document) use ($partners, $days): void {
                    $type = $document->type();
                    $partners->toOwner((string) $document->owner_type, (int) $document->owner_id, 'document.expiring', fn (string $locale): array => [
                        'document' => (string) ($locale === 'ar' ? ($type?->name_ar ?: $type?->name_en) : $type?->name_en) ?: EmailValue::t('document'),
                        'expires' => EmailValue::date($document->valid_until),
                    ], '/documents', ['entity' => $document, 'key' => $days.'d']);
                });
        }
    }

    private function renewals(Emails $emails, PartnerEmails $partners): void
    {
        $memberDays = (int) config('nawy.mail.reminders.membership_renewal_days');

        Membership::query()->where('status', 'active')->where('auto_renew', true)->whereDate('current_period_end', now()->addDays($memberDays)->toDateString())->with('plan')->limit(5000)->get()
            ->each(function (Membership $membership) use ($emails): void {
                $user = User::query()->find($membership->customer_user_id);
                $ar = $user?->preferredLocale() === 'ar';

                $emails->send('membership.renewal_upcoming', $user, [
                    'plan' => (string) ($ar ? $membership->plan?->name_ar : $membership->plan?->name_en),
                    'amount' => EmailValue::money($membership->plan?->price, app(CountryContext::class)->current()->currency),
                    'renews_on' => EmailValue::date($membership->current_period_end),
                ], ['entity' => $membership, 'key' => 'renew-'.Carbon::parse($membership->current_period_end)->toDateString(), 'url' => app(StorefrontLinks::class)->to('account/membership', [], $user?->preferredLocale())]);
            });

        $vendorDays = (int) config('nawy.mail.reminders.subscription_renewal_days');

        VendorSubscription::query()->whereIn('status', ['active', 'trialing'])->whereDate('current_period_end', now()->addDays($vendorDays)->toDateString())->limit(5000)->get()
            ->each(function (VendorSubscription $subscription) use ($partners): void {
                $plan = VendorSubscriptionPlan::query()->find($subscription->vendor_subscription_plan_id);
                $renews = (bool) $subscription->auto_renew && ! $subscription->cancel_at_period_end;

                $partners->toVendor($subscription->vendor_id, 'subscription.renewal_upcoming', fn (string $locale): array => [
                    'plan' => (string) ($locale === 'ar' ? $plan?->name_ar : $plan?->name_en),
                    'renews_on' => EmailValue::date($subscription->current_period_end),
                    'amount' => EmailValue::money($plan?->price, app(CountryContext::class)->current()->currency),
                    'auto_renew' => EmailValue::t($renews ? 'It renews automatically.' : 'Auto-renew is off, so your plan ends unless you renew it.'),
                ], '/subscription', ['entity' => $subscription, 'key' => 'renew-'.Carbon::parse($subscription->current_period_end)->toDateString()]);
            });
    }

    private function creditExpiry(Emails $emails): void
    {
        $days = (int) config('nawy.mail.reminders.credit_expiry_days');

        PromotionalCredit::query()->where('status', 'active')->where('remaining', '>', 0)->whereBetween('expires_at', [now(), now()->addDays($days)])->limit(5000)->get()
            ->groupBy('customer_user_id')
            ->each(function ($credits, $userId) use ($emails): void {
                $first = $credits->sortBy('expires_at')->first();
                $user = User::query()->find((int) $userId);

                $emails->send('wallet.credit_expiring', $user, [
                    'amount' => EmailValue::money((string) $credits->sum(fn ($credit) => (float) $credit->remaining), $first->currency),
                    'expires' => EmailValue::date($first->expires_at),
                ], ['entity' => $first, 'key' => 'expiring', 'url' => app(StorefrontLinks::class)->to('', [], $user?->preferredLocale())]);
            });
    }

    private function reviewRequests(Emails $emails): void
    {
        $days = (int) config('nawy.mail.reminders.review_request_days');

        SubOrder::query()->whereIn('status', [SubOrderStatus::Delivered, SubOrderStatus::Completed])
            ->whereBetween('delivered_at', [now()->subDays($days + 2), now()->subDays($days)])
            ->with(['order', 'items'])->limit(2000)->get()
            ->each(function (SubOrder $subOrder) use ($emails): void {
                $user = User::query()->find($subOrder->order?->customer_user_id);

                $emails->send('review.request', $user, [
                    'order_number' => (string) $subOrder->order?->number,
                    'items' => $subOrder->items->take(3)->pluck('title')->implode(', '),
                ], ['entity' => $subOrder, 'url' => app(StorefrontLinks::class)->to('account/orders/'.$subOrder->order?->number, [], $user?->preferredLocale())]);
            });
    }

    private function promotionExpiry(PartnerEmails $partners): void
    {
        $days = (int) config('nawy.mail.reminders.promotion_expiry_days');

        TravelPromotion::query()->where('status', TravelPromotionStatus::Active)->whereBetween('ends_at', [now(), now()->addDays($days)])->with('package')->limit(2000)->get()
            ->each(function (TravelPromotion $promotion) use ($partners): void {
                try {
                    $trip = (string) ($promotion->package?->title_en ?? $promotion->number);
                } catch (Throwable) {
                    $trip = (string) $promotion->number;
                }

                $partners->toVendor($promotion->vendor_id, 'travel.promotion_expiring', ['promotion' => (string) $promotion->number, 'trip' => $trip, 'ends' => EmailValue::date($promotion->ends_at)],
                    '/promotions', ['entity' => $promotion, 'key' => 'expiring', 'panel' => 'travel_agency']);
            });
    }
}
