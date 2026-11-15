{{-- Team & permissions (QC-020): owner-only page shared by Seller Center and Travel Partner. --}}
<x-layouts.panel :title="__('Team & permissions')" :subtitle="__('Staff of :team and what each role may do', ['team' => $tenant->store_name])" :breadcrumbs="[__('Account'), __('Team & permissions')]">
    <div class="grid gap-6 xl:grid-cols-3">
        <div class="flex flex-col gap-6 xl:col-span-2">
            <x-ui.card :title="__('Members')" flush>
                <x-ui.table :headers="[__('Name'), __('Email'), __('Role'), '']" :rows="$members->isNotEmpty()" :empty="__('No members yet.')">
                    @foreach ($members as $member)
                        <tr class="border-t border-line" data-member="{{ $member->id }}">
                            <td class="px-5 py-3 font-medium">{{ $member->user->name }}</td>
                            <td class="px-5 py-3">{{ $member->user->email }}</td>
                            <td class="px-5 py-3">
                                @if ($member->user_id === $tenant->owner_user_id)
                                    {{ __('Owner') }}
                                @else
                                    <form method="POST" action="{{ route($panel.'.team.role', $member->id) }}" class="flex items-end gap-2">
                                        @csrf
                                        @method('PUT')
                                        <x-ui.select :label="__('Role')" name="role" :id="'role-'.$member->id" :options="$roles" :selected="$member->tenant_role ?? \App\Domains\Panels\Support\TenantPermissions::DEFAULT_ROLE[$panel]" />
                                        <x-ui.button type="submit" variant="outline" size="sm">{{ __('Save') }}</x-ui.button>
                                    </form>
                                @endif
                            </td>
                            <td class="px-5 py-3">
                                @if ($member->user_id !== $tenant->owner_user_id)
                                    <form method="POST" action="{{ route($panel.'.team.destroy', $member->id) }}">@csrf @method('DELETE')<x-ui.button type="submit" variant="danger" size="sm">{{ __('Remove') }}</x-ui.button></form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-ui.table>
            </x-ui.card>

            @if ($invitations->isNotEmpty())
                <x-ui.card :title="__('Pending invitations')" flush>
                    <x-ui.table :headers="[__('Name'), __('Email'), __('Role'), '']">
                        @foreach ($invitations as $invitation)
                            <tr class="border-t border-line">
                                <td class="px-5 py-3">{{ $invitation->name }}</td>
                                <td class="px-5 py-3">{{ $invitation->email }}</td>
                                <td class="px-5 py-3">{{ $roles[$invitation->tenant_role] ?? '—' }}</td>
                                <td class="px-5 py-3"><form method="POST" action="{{ route($panel.'.team.invitations.revoke', $invitation->id) }}">@csrf<x-ui.button type="submit" variant="outline" size="sm">{{ __('Withdraw') }}</x-ui.button></form></td>
                            </tr>
                        @endforeach
                    </x-ui.table>
                </x-ui.card>
            @endif
        </div>

        <x-ui.card :title="__('Invite a member')" :subtitle="__('They join after accepting the emailed invitation.')">
            <form method="POST" action="{{ route($panel.'.team.invite') }}" class="flex flex-col gap-3">
                @csrf
                <x-ui.input :label="__('Name')" name="name" required />
                <x-ui.input :label="__('Email')" name="email" type="email" required />
                <x-ui.select :label="__('Role')" name="role" :options="$roles" />
                <div><x-ui.button type="submit">{{ __('Send invitation') }}</x-ui.button></div>
            </form>
        </x-ui.card>
    </div>
</x-layouts.panel>
