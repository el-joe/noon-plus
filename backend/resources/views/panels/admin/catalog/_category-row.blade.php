<tr class="border-t border-line" data-category="{{ $category->id }}">
    <td class="px-5 py-3 font-medium">
        <span style="padding-inline-start: {{ $depth * 1.25 }}rem">@if ($depth > 0)<span class="text-fg-muted">└ </span>@endif<a class="text-fg-link" href="{{ route('admin.catalog.categories.show', $category->id) }}">{{ $category->name_en }}</a></span>
        <span class="block text-xs text-fg-secondary" dir="rtl" style="padding-inline-start: {{ $depth * 1.25 }}rem">{{ $category->name_ar }}</span>
    </td>
    <td class="px-5 py-3 text-xs">{{ $category->slug }}</td>
    <td class="px-5 py-3">{{ $category->commission_rate !== null ? $category->commission_rate.'%' : '—' }}</td>
    <td class="px-5 py-3">{{ $category->products_count ?? $category->products()->count() }}</td>
    <td class="px-5 py-3"><x-ui.status-badge :status="$category->is_active ? 'active' : 'inactive'" /></td>
</tr>
@if ($nested && $depth < 3)
    @foreach ($category->children as $child)
        @include('panels.admin.catalog._category-row', ['category' => $child, 'depth' => $depth + 1, 'nested' => true])
    @endforeach
@endif
