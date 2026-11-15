<x-layouts.panel :title="$member->name" :subtitle="$member->email" :breadcrumbs="[__('Platform'), __('Staff'), $member->name]">
    <section class="grid gap-4 lg:grid-cols-2">
        <x-ui.card :title="__('Admin access by country')" flush>
            <x-ui.table :headers="[__('Country'), __('Status')]" :rows="$memberships->isNotEmpty()">
                @foreach ($memberships as $membership)
                    <tr class="border-t border-line">
                        <td class="px-5 py-3">{{ $membership->country?->name_en ?? __('All countries (super admin)') }}</td>
                        <td class="px-5 py-3"><x-ui.status-badge :status="$membership->status" /></td>
                    </tr>
                @endforeach
            </x-ui.table>
            @can('admins.manage')
                @if (! $member->is(auth()->user()))
                    @php($here = $memberships->first(fn ($membership) => $membership->country_id === $country->id) ?? $memberships->first(fn ($membership) => $membership->country_id === null))
                    <form method="POST" action="{{ route('admin.staff.status', $member->id) }}" class="flex items-center gap-3 border-t border-line p-5">
                        @csrf
                        @if ($here?->status === 'suspended')
                            <input type="hidden" name="status" value="active">
                            <x-ui.button type="submit" variant="outline">{{ $isSuper ? __('Restore super admin access') : __('Restore access in :country', ['country' => $country->name_en]) }}</x-ui.button>
                        @else
                            <input type="hidden" name="status" value="suspended">
                            <x-ui.button type="submit" variant="danger">{{ $isSuper ? __('Suspend super admin') : __('Suspend in :country', ['country' => $country->name_en]) }}</x-ui.button>
                        @endif
                    </form>
                @endif
            @endcan
        </x-ui.card>

        <x-ui.card :title="__('Roles in :country', ['country' => $country->name_en])" :subtitle="$isSuper ? __('Super admins hold every permission in every country.') : null">
            @can('admins.manage')
                @if (! $member->is(auth()->user()) && ! $isSuper)
                    <form method="POST" action="{{ route('admin.staff.roles', $member->id) }}" class="flex flex-col gap-2">
                        @csrf @method('PUT')
                        @foreach ($roles as $role)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="roles[]" value="{{ $role->name }}" class="size-4 accent-brand" @checked(in_array($role->name, $rolesHere, true))> {{ str_replace('_', ' ', ucfirst($role->name)) }}</label>
                        @endforeach
                        <div class="mt-2"><x-ui.button type="submit">{{ __('Save roles') }}</x-ui.button></div>
                    </form>
                @else
                    <p class="text-sm">{{ implode(', ', $rolesHere) ?: '—' }}</p>
                @endif
            @else
                <p class="text-sm">{{ implode(', ', $rolesHere) ?: '—' }}</p>
            @endcan
        </x-ui.card>
    </section>

    <x-ui.card :title="__('Recent activity')" :subtitle="__('Times in Oman')" flush>
        <x-ui.table :headers="[__('When'), __('Log'), __('Event'), __('Description')]" :rows="$activity->isNotEmpty()" :empty="__('No recorded activity.')">
            @foreach ($activity as $entry)
                <tr class="border-t border-line">
                    <td class="px-5 py-3 text-xs">{{ $entry->created_at?->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                    <td class="px-5 py-3">{{ $entry->log_name }}</td>
                    <td class="px-5 py-3">{{ $entry->event }}</td>
                    <td class="px-5 py-3 text-xs">{{ $entry->description }}</td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
