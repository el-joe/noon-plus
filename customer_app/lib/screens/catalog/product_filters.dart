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
    Set<String>? fulfilment,
    Set<String>? conditions,
    this.deals = false,
    this.sort = 'recommended',
  })  : brands = brands ?? <String>{},
        sellers = sellers ?? <String>{},
        attributes = attributes ?? <String, Set<String>>{},
        fulfilment = fulfilment ?? <String>{},
        conditions = conditions ?? <String>{};

  factory ProductFilters.fromArgs(Map<String, dynamic> args) => ProductFilters(
        q: args['q'] as String?,
        category: args['category'] as String?,
        brands: {if (args['brand'] is String) ...(args['brand'] as String).split(',').where((e) => e.isNotEmpty)},
        sellers: {if (args['seller'] is String) ...(args['seller'] as String).split(',').where((e) => e.isNotEmpty)},
        fulfilment: {if (args['fulfilment'] is String) ...(args['fulfilment'] as String).split(',').where((e) => e.isNotEmpty)},
        conditions: {if (args['condition'] is String) ...(args['condition'] as String).split(',').where((e) => e.isNotEmpty)},
        deals: args['deals'] == true || args['deals'] == '1' || args['deals'] == 1,
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

  /// QC-027: `fulfilment=fbm,fbn`, `condition=new,refurbished`, `deals=1`.
  final Set<String> fulfilment;
  final Set<String> conditions;
  bool deals;
  String sort;

  static const fulfilmentValues = ['fbn', 'fbm'];
  static const conditionValues = ['new', 'refurbished'];

  /// `bestselling`: weekly bestseller rank, unranked last (QC-015).
  static const sorts = ['recommended', 'bestselling', 'price_asc', 'price_desc', 'newest', 'rating'];

  ProductFilters copy() => ProductFilters(
        q: q,
        category: category,
        brands: {...brands},
        sellers: {...sellers},
        minPrice: minPrice,
        maxPrice: maxPrice,
        rating: rating,
        attributes: {for (final e in attributes.entries) e.key: {...e.value}},
        fulfilment: {...fulfilment},
        conditions: {...conditions},
        deals: deals,
        sort: sort,
      );

  /// Number of active refinements (dot on the Filter chip).
  int get activeCount =>
      brands.length + sellers.length + (minPrice != null || maxPrice != null ? 1 : 0) + (rating != null ? 1 : 0) + attributes.values.fold<int>(0, (s, v) => s + v.length) +
      fulfilment.length +
      conditions.length +
      (deals ? 1 : 0);

  void clear() {
    brands.clear();
    sellers.clear();
    minPrice = null;
    maxPrice = null;
    rating = null;
    attributes.clear();
    fulfilment.clear();
    conditions.clear();
    deals = false;
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
      if (fulfilment.isNotEmpty) 'fulfilment': (fulfilment.toList()..sort()).join(','),
      if (conditions.isNotEmpty) 'condition': (conditions.toList()..sort()).join(','),
      if (deals) 'deals': 1,
      if (!forFacets && sort != 'recommended') 'sort': sort,
      if (!forFacets && page != null) 'page': page,
      if (!forFacets && perPage != null) 'per_page': perPage,
    };
  }
}
