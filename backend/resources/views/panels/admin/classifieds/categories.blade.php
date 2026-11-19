<x-layouts.panel :title="__('Classified categories')" :subtitle="__('Categories in :country: contracts before posting and photo limits', ['country' => $country->name_en])" :breadcrumbs="[__('Verticals'), __('Classified categories')]">
    <x-ui.card flush>
        <x-ui.table :headers="[__('Category'), __('Section'), __('Parent'), __('Contract required'), __('Action'), __('Photo limits')]" :rows="$categories->isNotEmpty()" :empty="__('No classified categories yet.')">
            @foreach ($categories as $category)
                <tr class="border-t border-line align-top" data-category="{{ $category->id }}">
                    <td class="px-5 py-3"><span class="font-medium">{{ $category->name() }}</span><span class="block text-xs text-fg-secondary">{{ $category->slug }}</span></td>
                    <td class="px-5 py-3 text-sm">{{ \App\Shared\Localization\Label::of($category->section) }}</td>
                    <td class="px-5 py-3 text-sm">{{ $category->parent?->name() ?? '—' }}</td>
                    <td class="px-5 py-3 text-sm">
                        @if ($category->requires_contract)
                            {{ __('Yes') }}
                        @elseif ($category->needsContract())
                            {{ __('Yes (from parent)') }}
                        @else
                            {{ __('No') }}
                        @endif
                    </td>
                    <td class="px-5 py-3">
                        @can('categories.manage')
                            <form method="POST" action="{{ route('admin.classifieds.categories.contract', $category->id) }}">@csrf
                                <input type="hidden" name="requires_contract" value="{{ $category->requires_contract ? 0 : 1 }}">
                                <x-ui.button type="submit" size="sm" :variant="$category->requires_contract ? 'outline' : 'primary'">{{ $category->requires_contract ? __('Stop requiring a contract') : __('Require a contract') }}</x-ui.button>
                            </form>
                        @endcan
                    </td>
                    <td class="px-5 py-3 text-sm" data-photo-limits>
                        @php($limits = $category->photoLimits())
                        <p class="text-xs text-fg-secondary">{{ __(':count photos · :kb KB each', ['count' => $limits['max_photos'], 'kb' => $limits['photo_max_kb']]) }}</p>
                        @can('categories.manage')
                            <form method="POST" action="{{ route('admin.classifieds.categories.limits', $category->id) }}" class="mt-1 flex items-center gap-2">@csrf
                                <input type="number" name="max_photos" min="1" max="50" value="{{ $category->max_photos ?: '' }}" placeholder="{{ __('Photos') }}" aria-label="{{ __('Max photos') }}" class="h-8 w-20 rounded-lg border border-line bg-surface px-2 text-sm">
                                <input type="number" name="photo_max_kb" min="100" max="20480" value="{{ $category->photo_max_kb ?? '' }}" placeholder="{{ __('KB') }}" aria-label="{{ __('Max KB per photo (empty = inherit)') }}" class="h-8 w-24 rounded-lg border border-line bg-surface px-2 text-sm">
                                <x-ui.button type="submit" size="sm" variant="outline">{{ __('Save') }}</x-ui.button>
                            </form>
                        @endcan
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
</x-layouts.panel>
