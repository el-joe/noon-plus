<?php

namespace App\Shared\Notifications\Email;

use App\Domains\Delivery\Models\Carrier;
use App\Domains\Delivery\Models\DeliveryAgent;
use App\Domains\Marketers\Models\MarketerProfile;
use App\Domains\Onboarding\Models\PartnerApplication;
use App\Domains\Vendors\Models\Vendor;
use App\Models\User;
use Closure;

/**
 * Partner-side recipients for catalogue emails: the owner of a store /
 * travel agency (Vendor.owner_user_id), a marketer (MarketerProfile.user_id),
 * a delivery agent (DeliveryAgent.user_id), a shipping company
 * (Carrier.owner_user_id) or an applicant (PartnerApplication.email) —
 * each with the panel its links open in. Sends through {@see Emails::send()}.
 */
class PartnerEmails
{
    public function __construct(private Emails $emails, private EmailLinks $links) {}

    /**
     * @param  array<string, mixed>|Closure(string): array<string, mixed>  $variables  a closure gets the recipient's language (for bilingual names)
     * @param  array<string, mixed>  $options
     */
    public function toVendor(?int $vendorId, string $event, array|Closure $variables, ?string $path = null, array $options = []): void
    {
        $this->toOwner('vendor', $vendorId, $event, $variables, $path, $options);
    }

    /**
     * @param  array<string, mixed>|Closure(string): array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function toMarketer(?int $marketerProfileId, string $event, array|Closure $variables, ?string $path = null, array $options = []): void
    {
        $this->toOwner('marketer', $marketerProfileId, $event, $variables, $path, $options);
    }

    /**
     * An owner by type: vendor / agency (store or travel agency), marketer,
     * agent / delivery_agent, carrier, application / applicant.
     *
     * @param  array<string, mixed>|Closure(string): array<string, mixed>  $variables
     * @param  array<string, mixed>  $options
     */
    public function toOwner(string $ownerType, ?int $ownerId, string $event, array|Closure $variables, ?string $path = null, array $options = []): void
    {
        $recipient = $ownerId === null ? null : $this->recipient($ownerType, $ownerId);

        if ($recipient === null) {
            return;
        }

        $options += ['panel' => $recipient['panel']] + array_filter(['locale' => $recipient['locale'], 'name' => $recipient['name']]);

        if ($path !== null) {
            $options['url'] ??= $this->links->panel($options['panel'], $path);
        }

        if ($variables instanceof Closure) {
            $locale = $recipient['to'] instanceof User ? $recipient['to']->preferredLocale() : ($recipient['locale'] ?? config('app.locale'));
            $variables = $variables($locale === 'ar' ? 'ar' : 'en');
        }

        $this->emails->send($event, $recipient['to'], $variables, $options);
    }

    /**
     * @return array{to: User|string, panel: string, locale: string|null, name: string|null}|null
     */
    public function recipient(string $ownerType, int $ownerId): ?array
    {
        if (in_array($ownerType, [PartnerApplication::DOCUMENT_OWNER, 'applicant'], true)) {
            $application = PartnerApplication::query()->find($ownerId);

            return $application === null ? null : ['to' => (string) $application->email, 'panel' => $application->type->panel(), 'locale' => $application->locale, 'name' => $application->name];
        }

        $vendor = in_array($ownerType, ['vendor', 'agency'], true) ? Vendor::query()->find($ownerId) : null;
        [$userId, $panel] = match ($ownerType) {
            'vendor', 'agency' => [$vendor?->owner_user_id, $vendor?->isTravelAgency() ? 'travel_agency' : 'partner'],
            'marketer' => [MarketerProfile::query()->whereKey($ownerId)->value('user_id'), 'marketer'],
            'agent', 'delivery_agent' => [DeliveryAgent::query()->whereKey($ownerId)->value('user_id'), 'delivery'],
            'carrier' => [Carrier::query()->whereKey($ownerId)->value('owner_user_id'), 'carrier'],
            default => [null, null],
        };
        $user = $userId === null ? null : User::query()->find($userId);

        return $user === null ? null : ['to' => $user, 'panel' => (string) $panel, 'locale' => null, 'name' => null];
    }
}
