<x-layouts.panel :title="__('App contexts')" :subtitle="__(':country · the customer app\'s super-app switcher: theme, home layout and tab bar per context (ADR 16)', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('App contexts')]">
    @include('panels.admin.content._nav')

    @foreach ($contexts as $context)
        <x-ui.card :title="$context->name_en.' · '.$context->name_ar" :subtitle="$context->key">
            <form method="POST" action="{{ route('admin.app-contexts.update', $context->id) }}" class="flex flex-col gap-4" data-context="{{ $context->key }}">
                @csrf @method('PUT')
                <div class="grid gap-4 sm:grid-cols-4">
                    <x-ui.input :label="__('Name (English)')" name="name_en" :id="$context->key.'-name_en'" :value="$context->name_en" required />
                    <x-ui.input :label="__('Name (Arabic)')" name="name_ar" :id="$context->key.'-name_ar'" :value="$context->name_ar" dir="rtl" required />
                    <x-ui.input :label="__('Icon')" name="icon" :id="$context->key.'-icon'" :value="$context->icon" />
                    <x-ui.input :label="__('Order')" name="sort_order" :id="$context->key.'-sort'" type="number" :value="$context->sort_order" />
                    <x-ui.input :label="__('Header background')" name="theme[header_bg]" :id="$context->key.'-bg'" :value="$context->theme['header_bg'] ?? ''" />
                    <x-ui.input :label="__('Header text')" name="theme[header_fg]" :id="$context->key.'-fg'" :value="$context->theme['header_fg'] ?? ''" />
                    <x-ui.input :label="__('Accent')" name="theme[accent]" :id="$context->key.'-accent'" :value="$context->theme['accent'] ?? ''" />
                    <x-ui.select :label="__('Home layout')" name="home_page_id" :id="$context->key.'-home'" :options="$homePages->all()" :selected="$context->home_page_id" :placeholder="__('None yet')" />
                </div>
                <div>
                    <p class="mb-2 text-sm font-medium">{{ __('Bottom tab bar (2–5 tabs; empty rows are dropped)') }}</p>
                    <div class="grid gap-2">
                        @foreach (array_pad($context->tabs ?? [], 5, []) as $index => $tab)
                            <div class="grid gap-2 sm:grid-cols-4">
                                <x-ui.input :label="__('Label EN')" :name="'tabs['.$index.'][label_en]'" :id="$context->key.'-tab-en-'.$index" :value="$tab['label_en'] ?? ''" />
                                <x-ui.input :label="__('Label AR')" :name="'tabs['.$index.'][label_ar]'" :id="$context->key.'-tab-ar-'.$index" :value="$tab['label_ar'] ?? ''" dir="rtl" />
                                <x-ui.input :label="__('Icon')" :name="'tabs['.$index.'][icon]'" :id="$context->key.'-tab-icon-'.$index" :value="$tab['icon'] ?? ''" />
                                <x-ui.select :label="__('Opens')" :name="'tabs['.$index.'][target]'" :id="$context->key.'-tab-target-'.$index" :options="collect($targets)->mapWithKeys(fn ($t) => [$t => ucfirst($t)])->all()" :selected="$tab['target'] ?? null" placeholder="—" />
                            </div>
                        @endforeach
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <input type="hidden" name="is_active" value="0">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($context->is_active) class="size-4 accent-brand"> {{ __('Shown in the app') }}</label>
                    <x-ui.button type="submit" size="sm">{{ __('Save') }}</x-ui.button>
                </div>
            </form>
        </x-ui.card>
    @endforeach
</x-layouts.panel>
