@php($P = \App\Domains\Content\Enums\PageStatus::class)
<x-layouts.panel :title="__('Blog')" :subtitle="__(':country · bilingual posts, nested categories, scheduled publishing', ['country' => $country->name_en])" :breadcrumbs="[__('Content'), __('Blog')]">
    @include('panels.admin.content._nav')

    <x-ui.card flush>
        <x-ui.table :headers="[__('Post'), __('Category'), __('Published / scheduled (Oman)'), __('Views'), __('Status'), '']" :rows="$posts->isNotEmpty()" :empty="__('No posts yet.')">
            @foreach ($posts as $post)
                <tr class="border-t border-line align-top" data-post="{{ $post->id }}">
                    <td class="px-5 py-3 font-medium">{{ $post->title_en }} @if ($post->is_featured)<x-ui.badge tone="brand">{{ __('Featured') }}</x-ui.badge>@endif<span class="block text-xs text-fg-secondary">/{{ $post->slug }} · {{ implode(', ', $post->tags ?? []) }}</span></td>
                    <td class="px-5 py-3">{{ $post->category?->name_en ?? '—' }}</td>
                    <td class="px-5 py-3 text-xs">{{ $post->published_at ? $clock->inAdminTime($post->published_at)->format('j M Y, H:i') : ($post->publish_at ? $clock->inAdminTime($post->publish_at)->format('j M Y, H:i') : '—') }}</td>
                    <td class="px-5 py-3">{{ $post->views }}</td>
                    <td class="px-5 py-3"><x-ui.status-badge :status="$post->status" /></td>
                    <td class="px-5 py-3">
                        <div class="flex flex-wrap gap-2">
                            @if (in_array($P::Published, $post->status->allowedNext(), true))
                                <form method="POST" action="{{ route('admin.cms.blog.posts.act', [$post->id, 'publish']) }}">@csrf<x-ui.button type="submit" size="sm">{{ __('Publish') }}</x-ui.button></form>
                            @endif
                            @if ($post->status === $P::Draft)
                                <form method="POST" action="{{ route('admin.cms.blog.posts.act', [$post->id, 'schedule']) }}" class="flex items-end gap-2">@csrf
                                    <x-ui.datetime-input :label="__('Publish at')" name="publish_at" :id="'publish-at-'.$post->id" :country="$country" />
                                    <x-ui.button type="submit" size="sm" variant="outline">{{ __('Schedule') }}</x-ui.button></form>
                            @endif
                            @if (in_array($P::Draft, $post->status->allowedNext(), true))
                                <form method="POST" action="{{ route('admin.cms.blog.posts.act', [$post->id, 'draft']) }}">@csrf<x-ui.button type="submit" size="sm" variant="ghost">{{ __('To draft') }}</x-ui.button></form>
                            @endif
                            @if (in_array($P::Archived, $post->status->allowedNext(), true))
                                <form method="POST" action="{{ route('admin.cms.blog.posts.act', [$post->id, 'archive']) }}">@csrf<x-ui.button type="submit" size="sm" variant="ghost">{{ __('Archive') }}</x-ui.button></form>
                            @endif
                        </div>
                    </td>
                </tr>
            @endforeach
        </x-ui.table>
    </x-ui.card>
    {{ $posts->links() }}

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card :title="__('New post')" class="lg:col-span-2">
            <form method="POST" action="{{ route('admin.cms.blog.posts.store') }}" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
                @csrf
                <x-ui.input :label="__('Title (English)')" name="title_en" required />
                <x-ui.input :label="__('Title (Arabic)')" name="title_ar" dir="rtl" required />
                <x-ui.input :label="__('Excerpt (English)')" name="excerpt_en" />
                <x-ui.input :label="__('Excerpt (Arabic)')" name="excerpt_ar" dir="rtl" />
                <x-ui.textarea :label="__('Body (English)')" name="body_en" rows="6" />
                <x-ui.textarea :label="__('Body (Arabic)')" name="body_ar" rows="6" dir="rtl" />
                <x-ui.select :label="__('Category')" name="category_id" :options="$categories->pluck('name_en', 'id')->all()" :placeholder="__('None')" />
                <x-ui.input :label="__('Tags (comma separated)')" name="tags" />
                <x-ui.input :label="__('Cover image')" name="cover" type="file" accept="image/*" />
                <div class="flex items-end gap-4"><x-ui.checkbox :label="__('Featured')" name="is_featured" /><x-ui.button type="submit">{{ __('Save draft') }}</x-ui.button></div>
            </form>
        </x-ui.card>
        <x-ui.card :title="__('Categories')">
            <ul class="mb-4 text-sm">@foreach ($categories as $category)<li>{{ $category->parent_id ? '— ' : '' }}{{ $category->name_en }} · {{ $category->name_ar }}</li>@endforeach</ul>
            <form method="POST" action="{{ route('admin.cms.blog.categories.store') }}" class="flex flex-col gap-3">
                @csrf
                <x-ui.input :label="__('Name (English)')" name="name_en" id="category_name_en" required />
                <x-ui.input :label="__('Name (Arabic)')" name="name_ar" id="category_name_ar" dir="rtl" required />
                <x-ui.select :label="__('Parent')" name="parent_id" id="category_parent" :options="$categories->pluck('name_en', 'id')->all()" :placeholder="__('Top level')" />
                <x-ui.button type="submit" size="sm">{{ __('Add category') }}</x-ui.button>
            </form>
        </x-ui.card>
    </div>
</x-layouts.panel>
