import type { ProductCardData } from "@/components/product/product-card";
import type { ProductCard } from "./schemas";

export function cardFromApi(product: ProductCard): ProductCardData {
  return {
    slug: product.slug,
    listingId: product.listing_id ?? null,
    title: product.title,
    brand: product.brand,
    price: product.price,
    sponsored: product.sponsored,
    label: product.label,
    adToken: product.ad_token,
    image: product.image?.url ?? null,
    imageAlt: product.image?.alt ?? null,
    rating: product.rating ?? null,
  };
}
