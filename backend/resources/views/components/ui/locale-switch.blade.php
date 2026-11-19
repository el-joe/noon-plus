{{-- QC-028: English / العربية switch for the panel sign-in pages. --}}
@php($switchPanel = \App\Domains\Panels\Support\Panel::fromRequest(request()))
@if ($switchPanel && Route::has($switchPanel->route('locale')))
    <form method="POST" action="{{ route($switchPanel->route('locale')) }}" class="flex justify-center gap-3 text-xs text-fg-secondary" data-locale-switcher>
        @csrf
        <button type="submit" name="locale" value="en" @class(['font-semibold text-fg' => app()->getLocale() === 'en'])>English</button>
        <span aria-hidden="true">·</span>
        <button type="submit" name="locale" value="ar" lang="ar" @class(['font-semibold text-fg' => app()->getLocale() === 'ar'])>العربية</button>
    </form>
@endif
