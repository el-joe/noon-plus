<?php

namespace App\Shared\Validation;

use Illuminate\Http\Request;

/**
 * Contact details of a no-payment request (owner decision WP6): name,
 * email and a phone of the market's country are required from everyone; a
 * signed-in customer's own details fill whatever the request leaves out
 * (the apps prefill them, the customer may edit).
 */
class ContactDetails
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public static function rules(Request $request): array
    {
        $user = $request->user('sanctum');
        $request->mergeIfMissing(array_filter(['name' => $user?->name, 'email' => $user?->email, 'phone' => $user?->phone]));

        return [
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:24', new CountryPhone],
        ];
    }
}
