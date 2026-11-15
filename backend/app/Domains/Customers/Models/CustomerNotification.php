<?php

namespace App\Domains\Customers\Models;

use App\Shared\Database\UsesCountryDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An in-app notification for a customer in one country (G-W29): order,
 * booking, return, request, ad and review moderation updates. `link` is a
 * storefront path without the market prefix, e.g. `/account/orders/NW-…`.
 */
#[Fillable(['user_id', 'type', 'title', 'body', 'link', 'data', 'read_at'])]
class CustomerNotification extends Model
{
    use UsesCountryDatabase;

    protected $table = 'customer_notifications';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array{id: int, type: string, title: string, body: string|null, link: string|null, data: array<string, mixed>, read: bool, read_at: string|null, created_at: string|null}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link,
            'data' => $this->data ?? [],
            'read' => $this->read_at !== null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
