<x-layouts.panel :title="__('Post an ad')" :subtitle="__('Category → details → photos → location → price. The category contract is signed before review.')" :breadcrumbs="[__('My listings'), __('Post a listing')]">
    <form method="GET" action="{{ route('partner.marketplace.ads.create') }}" class="flex items-end gap-3 rounded-xl border border-line bg-surface p-4">
        <x-ui.select :label="__('Category')" name="category" :selected="$category?->id" :placeholder="__('Choose a category')" :options="$categories->mapWithKeys(fn ($option) => [$option->id => $option->name()])->all()" />
        <x-ui.button type="submit" variant="outline">{{ __('Continue') }}</x-ui.button>
    </form>

    @if ($category)
        <form method="POST" action="{{ route('partner.marketplace.ads.store') }}" enctype="multipart/form-data" class="grid gap-4 lg:grid-cols-2">
            @csrf
            <input type="hidden" name="category_id" value="{{ $category->id }}">
            <x-ui.card :title="__('Details')">
                <div class="grid gap-3">
                    <x-ui.select :label="__('Ad type')" name="kind" selected="offer" :options="['offer' => __('For sale / for rent'), 'wanted' => __('Wanted')]" />
                    <x-ui.input :label="__('Title')" name="title" :value="old('title')" required />
                    <x-ui.textarea :label="__('Description')" name="description" :value="old('description')" :rows="5" />
                    @foreach ($schema as $field)
                        @if (in_array($field['type'], ['select'], true))
                            <x-ui.select :label="$field['label'].($field['required'] ? ' *' : '')" :name="'specs['.$field['key'].']'" :placeholder="__('Choose')" :options="collect($field['options'])->mapWithKeys(fn ($option) => [is_array($option) ? $option['value'] : $option => is_array($option) ? ($option['label_en'] ?? $option['value']) : ucfirst($option)])->all()" />
                        @elseif ($field['type'] === 'multiselect')
                            <fieldset><legend class="text-sm font-medium">{{ $field['label'] }}</legend>
                                @foreach ($field['options'] as $option)
                                    @php($value = is_array($option) ? $option['value'] : $option)
                                    <label class="me-3 text-sm"><input type="checkbox" name="specs[{{ $field['key'] }}][]" value="{{ $value }}"> {{ ucfirst($value) }}</label>
                                @endforeach
                            </fieldset>
                        @elseif ($field['type'] === 'boolean')
                            <label class="text-sm"><input type="checkbox" name="specs[{{ $field['key'] }}]" value="1"> {{ $field['label'] }}</label>
                        @else
                            <x-ui.input :label="$field['label'].($field['unit'] ? ' ('.$field['unit'].')' : '').($field['required'] ? ' *' : '')" :name="'specs['.$field['key'].']'" :type="in_array($field['type'], ['number', 'year'], true) ? 'number' : 'text'" />
                        @endif
                    @endforeach
                </div>
            </x-ui.card>
            <x-ui.card :title="__('Photos, location & price')">
                <div class="grid gap-3">
                    <label class="text-sm font-medium" for="photos">{{ __('Photos (up to :n)', ['n' => $category->max_photos]) }}</label>
                    <input id="photos" type="file" name="photos[]" accept="image/*" multiple class="text-sm">
                    <x-ui.input :label="__('City')" name="city" :value="old('city')" />
                    <x-ui.input :label="__('Area')" name="area" :value="old('area')" />
                    <div class="grid grid-cols-2 gap-2">
                        <x-ui.input :label="__('Latitude')" name="latitude" type="number" />
                        <x-ui.input :label="__('Longitude')" name="longitude" type="number" />
                    </div>
                    <x-ui.input :label="__('Price')" name="price" type="number" :value="old('price')" />
                    <label class="text-sm"><input type="checkbox" name="is_negotiable" value="1"> {{ __('Negotiable') }}</label>
                    <x-ui.input :label="__('Contact phone (shown on reveal)')" name="contact_phone" :value="old('contact_phone')" />
                </div>
            </x-ui.card>
            <div class="flex gap-3 lg:col-span-2">
                <x-ui.button type="submit" name="submit" value="1">{{ __('Submit for review') }}</x-ui.button>
                <x-ui.button type="submit" variant="outline">{{ __('Save draft') }}</x-ui.button>
            </div>
        </form>
    @endif
</x-layouts.panel>
