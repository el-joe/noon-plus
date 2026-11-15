/// PLP filter state → `GET products` / `products/facets` query (pure).
class ProductFilters {
  ProductFilters({
    this.q,
    this.category,
    Set<String>? brands,
    Set<String>? sellers,
    this.minPrice,
    this.maxPrice,
    this.rating,
    Map<String, Set<String>>? attributes,
    this.sort = 'recommended',
  })  : brands = brands ?? <String>{},
        sellers = sellers ?? <String>{},
        attributes = attributes ?? <String, Set<String>>{};

  factory ProductFilters.fromArgs(Map<String, dynamic> args) => ProductFilters(
        q: args['q'] as String?,
        category: args['category'] as String?,
        brands: {if (args['brand'] is String) ...(args['brand'] as String).split(',').where((e) => e.isNotEmpty)},
        sellers: {if (args['seller'] is String) ...(args['seller'] as String).split(',').where((e) => e.isNotEmpty)},
        sort: args['sort'] as String? ?? 'recommended',
      );

  String? q;
  String? category;
  final Set<String> brands;
  final Set<String> sellers;
  String? minPrice;
  String? maxPrice;
  int? rating;
  final Map<String, Set<String>> attributes;
  String sort;

  static const sorts = ['recommended', 'price_asc', 'price_desc', 'newest', 'rating'];

  ProductFilters copy() => ProductFilters(
        q: q,
        category: category,
        brands: {...brands},
        sellers: {...sellers},
        minPrice: minPrice,
        maxPrice: maxPrice,
        rating: rating,
        attributes: {for (final e in attributes.entries) e.key: {...e.value}},
        sort: sort,
      );

  /// Number of active refinements (dot on the Filter chip).
  int get activeCount =>
      brands.length + sellers.length + (minPrice != null || maxPrice != null ? 1 : 0) + (rating != null ? 1 : 0) + attributes.values.fold(0, (s, v) => s + v.length);

  void clear() {
    brands.clear();
    sellers.clear();
    minPrice = null;
    maxPrice = null;
    rating = null;
    attributes.clear();
  }

  void toggle(Set<String> set, String value) => set.contains(value) ? set.remove(value) : set.add(value);

  void toggleAttribute(String key, String value) {
    final set = attributes.putIfAbsent(key, () => <String>{});
    toggle(set, value);
    if (set.isEmpty) attributes.remove(key);
  }

  Map<String, dynamic> query({int? page, int? perPage, bool forFacets = false}) {
    final sortedBrands = brands.toList()..sort();
    final sortedSellers = sellers.toList()..sort();
    return {
      if (q != null && q!.trim().isNotEmpty) 'q': q!.trim(),
      if (category != null) 'category': category,
      if (sortedBrands.isNotEmpty) 'brand': sortedBrands.join(','),
      if (sortedSellers.isNotEmpty) 'seller': sortedSellers.join(','),
      if (minPrice != null && minPrice!.isNotEmpty) 'min_price': minPrice,
      if (maxPrice != null && maxPrice!.isNotEmpty) 'max_price': maxPrice,
      if (rating != null) 'rating': rating,
      for (final e in attributes.entries)
        if (e.value.isNotEmpty) 'attr[${e.key}]': (e.value.toList()..sort()).join(','),
      if (!forFacets && sort != 'recommended') 'sort': sort,
      if (!forFacets && page != null) 'page': page,
      if (!forFacets && perPage != null) 'per_page': perPage,
    };
  }
}
