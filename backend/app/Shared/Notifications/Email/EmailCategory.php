<?php

namespace App\Shared\Notifications\Email;

/**
 * Why an email is sent, which decides whether the recipient can turn it
 * off. Transactional and security mail always goes out; the others follow
 * the user's email preferences and carry a one-click unsubscribe link.
 */
enum EmailCategory: string
{
    case Transactional = 'transactional';
    case Security = 'security';
    case Reminders = 'reminders';
    case Opportunities = 'opportunities';
    case Recommendations = 'recommendations';
    case CartReminders = 'cart_reminders';
    case AdminAlerts = 'admin_alerts';

    public function isLocked(): bool
    {
        return in_array($this, [self::Transactional, self::Security], true);
    }

    /**
     * On unless the user turned it off; cart reminders and admin alerts are opt-in.
     */
    public function defaultEnabled(): bool
    {
        return ! in_array($this, [self::CartReminders, self::AdminAlerts], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Transactional => __('Orders, payments and account'),
            self::Security => __('Security'),
            self::Reminders => __('Reminders and deadlines'),
            self::Opportunities => __('Invitations and opportunities'),
            self::Recommendations => __('Wishlist alerts and review requests'),
            self::CartReminders => __('Cart reminders'),
            self::AdminAlerts => __('Operations alerts'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Transactional => __('Receipts, shipping updates, refunds and replies. Always sent.'),
            self::Security => __('Sign-in codes, new devices and password changes. Always sent.'),
            self::Reminders => __('Expiring ads, documents, subscriptions and pending tasks.'),
            self::Opportunities => __('Flash sales, coupon programmes and campaign invitations.'),
            self::Recommendations => __('Back in stock and price drops on your wishlist, and requests to review what you bought.'),
            self::CartReminders => __('A reminder when you leave items in your cart.'),
            self::AdminAlerts => __('Alerts for the queues you work on.'),
        };
    }
}
