<?php

namespace App\Shared\Notifications\Push;

use App\Domains\Identity\Models\PushDeviceToken;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Pushes an in-app notification to every registered device of the user in
 * the app that shows it (customer centre → customer app; a business panel
 * bell → that partner's app), in the device's country. Tokens the provider
 * reports as gone are deleted.
 */
class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Notification audience → the app whose devices receive it.
     *
     * @var array<string, string>
     */
    public const APPS = [
        'customer' => 'customer',
        'panel:partner' => 'vendor',
        'panel:marketer' => 'marketer',
        'panel:carrier' => 'supervisor',
        'panel:travel_agency' => 'travel_agency',
        'panel:delivery' => 'delivery',
    ];

    public int $tries = 3;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public int $userId,
        public string $audience,
        public ?string $countryCode,
        public string $title,
        public ?string $body,
        public ?string $link,
        public array $data,
    ) {}

    public function handle(PushSender $sender): void
    {
        $app = self::APPS[$this->audience] ?? null;

        if ($app === null) {
            return;
        }

        $tokens = PushDeviceToken::query()->where('user_id', $this->userId)->where('app', $app)
            ->when($this->countryCode !== null, fn ($query) => $query->where(fn ($where) => $where->whereNull('country_code')->orWhere('country_code', $this->countryCode)))
            ->get();
        $data = array_filter(['link' => $this->link] + array_map(fn ($value): ?string => is_scalar($value) ? (string) $value : null, $this->data), fn ($value): bool => $value !== null);

        foreach ($tokens as $token) {
            if (! $sender->send(new PushMessage((string) $token->token, (string) $token->platform, $this->title, $this->body, $data))) {
                $token->delete();
            }
        }
    }
}
