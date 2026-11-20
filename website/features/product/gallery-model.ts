import type { ProductImageData } from "@/lib/api/schemas";

/** One gallery slide: an image URL plus every variant SKU that points at it. */
export type GallerySlide = { key: string; url: string; alt: string | null; skus: string[] };

/**
 * Product media → gallery slides. The API returns product-level and
 * per-variant rows, and several rows often share one file (the demo
 * catalogue reuses a picture per variant). Same URL = one slide, so the
 * rail never shows duplicate thumbnails; variant SKUs are kept for swapping.
 */
export function gallerySlides(images: ProductImageData[], locale: "en" | "ar" = "en"): GallerySlide[] {
  const byUrl = new Map<string, GallerySlide>();
  for (const image of images) {
    if (!image.url) continue;
    const alt = (locale === "ar" ? image.alt_ar : image.alt_en) ?? image.alt ?? null;
    const slide = byUrl.get(image.url);
    if (slide) {
      if (image.variant_sku && !slide.skus.includes(image.variant_sku)) slide.skus.push(image.variant_sku);
      continue;
    }
    byUrl.set(image.url, { key: String(image.id), url: image.url, alt, skus: image.variant_sku ? [image.variant_sku] : [] });
  }
  return [...byUrl.values()];
}

/** First slide for a variant SKU, or null when the variant has no own picture. */
export function slideForSku(slides: GallerySlide[], sku: string | null | undefined): number | null {
  if (!sku) return null;
  const index = slides.findIndex((s) => s.skus.includes(sku));
  return index >= 0 ? index : null;
}

/** Keyboard step for the gallery: arrows follow the reading direction. */
export function keyStep(key: string, dir: "ltr" | "rtl"): -1 | 1 | null {
  if (key === "ArrowRight") return dir === "rtl" ? -1 : 1;
  if (key === "ArrowLeft") return dir === "rtl" ? 1 : -1;
  if (key === "ArrowDown") return 1;
  if (key === "ArrowUp") return -1;
  return null;
}
