<?php

namespace App\Shared\Notifications\Email\Catalogue;

use App\Shared\Notifications\Email\EmailCategory;

/**
 * One actor's part of the email catalogue. Each entry is built with
 * {@see self::e()} so every event has the same shape.
 */
abstract class CatalogueSection
{
    abstract public static function actor(): string;

    /**
     * @return array<string, array<string, mixed>>
     */
    abstract public static function events(): array;

    /**
     * @param  array<int, string>  $variables
     * @param  array{0: string, 1: string}  $en  subject, body
     * @param  array{0: string, 1: string}  $ar  subject, body
     * @param  array<string, mixed>  $extra  status (existing|added), channels, button [en, ar], recipients, attachment, permission, sensitive
     * @return array<string, mixed>
     */
    protected static function e(EmailCategory $category, string $label, string $trigger, array $variables, array $en, array $ar, array $extra = []): array
    {
        return $extra + [
            'actor' => static::actor(),
            'category' => $category,
            'label' => $label,
            'trigger' => $trigger,
            'recipients' => static::defaultRecipients(),
            'variables' => array_values(array_unique(['name', ...$variables])),
            'channels' => ['mail'],
            'status' => 'added',
            'button' => null,
            'attachment' => null,
            'permission' => null,
            'sensitive' => false,
            'defaults' => [
                'en' => ['subject' => $en[0], 'body' => $en[1]],
                'ar' => ['subject' => $ar[0], 'body' => $ar[1]],
            ],
        ];
    }

    protected static function defaultRecipients(): string
    {
        return 'User';
    }
}
