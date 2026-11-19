<x-layouts.panel :title="__('Admin staff')" :subtitle="__('Admins working in :country, and super admins', ['country' => $country->name_en])" :breadcrumbs="[__('Platform'), __('Staff')]">
    @include('panels.admin.staff._nav')

    <form method="GET" action="{{ route('admin.staff') }}" class="grid gap-3 rounded-xl border border-line bg-surface p-4 sm:grid-cols-4" role="search">
        <x-ui.input :label="__('Search')" name="q" :value="$filters['q'] ?? ''" :placeholder="__('Name or email')" />
        <x-ui.select :label="__('Role')" name="role" :selected="$filters['role'] ?? ''" :placeholder="__('All')" :options="$roles->mapWithKeys(fn ($role) => [$role->name => app(\App\Domains\Identity\Support\AdminRoleLabels::class)->label($role->name)])->all()" />
        <x-ui.select :label="__('Status')" name="status" :selected="$filters['status'] ?? ''" :placeholder="__('All')" :options="['active' => __('Active'), 'suspended' => __('Suspended')]" />
        <div class="flex items-end"><x-ui.button type="submit" variant="outline">{{ __('Filter') }}</x-ui.button></div>
    </form>

    <x-ui.card flush>
        <x-ui.table :headers="[__('Name'), __('Email'), __('Roles in :country', ['country' => $country->name_en]), __('Status')]" :rows="$staff->isNotEmpty()" :empty="__('No admin staff here.')">
            @foreach ($staff as $member)
                @php($super = $member->memberships->contains(fn ($membership) => $membership->country_id === null))
                <tr class="border-t border-line" data-staff="{{ $member->id }}">
                    <td class="px-5 py-3 font-medium"><a class="text-fg-link" href="{{ route('admin.staff.show', $member->id) }}">{{ $member->name }}</a></td>
                    <td class="px-5 py-3">{{ $member->email }}</td>
                    <td class="px-5 py-3">{{ $super ? __('Super admin (all countries)') : ($member->roles->pluck('name')->map(fn ($name) => app(\App\Domains\Identity\Support\AdminRoleLabels::class)->label($name))->implode(', ') ?: '—') }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$member->memberships->first()?->status ?? 'active'" /></td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>

    {{ $staff->links() }}

    @can('admins.manage')
        <x-ui.card :title="__('Invite an admin')" :subtitle="__('An email link; the person sets a password (or uses their existing account) and gets this role in :country.', ['country' => $country->name_en])">
            <form method="POST" action="{{ route('admin.staff.invite') }}" class="grid gap-3 sm:grid-cols-4">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Email')" name="email" type="email" required />
                <x-ui.select :label="__('Role')" name="role" :options="$roles->mapWithKeys(fn ($role) => [$role->name => app(\App\Domains\Identity\Support\AdminRoleLabels::class)->label($role->name)])->all()" />
                <div class="flex items-end"><x-ui.button type="submit">{{ __('Send invitation') }}</x-ui.button></div>
            </form>
        </x-ui.card>

        <x-ui.card :title="__('Pending invitations')" flush>
            <x-ui.table :headers="[__('Name'), __('Email'), __('Role'), __('Expires (Oman)'), '']" :rows="$invitations->isNotEmpty()" :empty="__('No pending invitations.')">
                @foreach ($invitations as $invitation)
                    <tr class="border-t border-line">
                        <td class="px-5 py-3">{{ $invitation->name }}</td>
                        <td class="px-5 py-3">{{ $invitation->email }}</td>
                        <td class="px-5 py-3">{{ app(\App\Domains\Identity\Support\AdminRoleLabels::class)->label($invitation->tenant_role) }}</td>
                        <td class="px-5 py-3 text-xs">{{ $invitation->expires_at->setTimezone(config('nawy.admin_timezone'))->format('j M Y, H:i') }}</td>
                        <td class="px-5 py-3 text-end"><form method="POST" action="{{ route('admin.staff.invitations.revoke', $invitation->id) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="ghost" size="sm">{{ __('Revoke') }}</x-ui.button></form></td>
                    </tr>
                @endforeach
            </x-ui.table>
        </x-ui.card>
    @endcan
</x-layouts.panel>
