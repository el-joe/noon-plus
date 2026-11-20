import { describe, expect, it } from "vitest";
import { gallerySlides, keyStep, slideForSku } from "@/features/product/gallery-model";

const img = (id: number, url: string, variant_sku: string | null = null) => ({ id, url, alt: `alt ${id}`, alt_en: `en ${id}`, alt_ar: `ar ${id}`, variant_sku });

describe("gallery model", () => {
  it("collapses rows sharing a file and keeps their variant SKUs", () => {
    const slides = gallerySlides([img(1, "a.png"), img(2, "a.png", "SKU-1"), img(3, "b.jpg"), img(4, "c.png", "SKU-2")]);
    expect(slides.map((s) => s.url)).toEqual(["a.png", "b.jpg", "c.png"]);
    expect(slides[0].skus).toEqual(["SKU-1"]);
    expect(slideForSku(slides, "SKU-2")).toBe(2);
    expect(slideForSku(slides, "SKU-1")).toBe(0);
    expect(slideForSku(slides, "nope")).toBeNull();
    expect(slideForSku(slides, null)).toBeNull();
  });

  it("uses the locale alt text", () => {
    expect(gallerySlides([img(1, "a.png")], "ar")[0].alt).toBe("ar 1");
  });

  it("maps arrow keys by reading direction", () => {
    expect(keyStep("ArrowRight", "ltr")).toBe(1);
    expect(keyStep("ArrowRight", "rtl")).toBe(-1);
    expect(keyStep("ArrowLeft", "rtl")).toBe(1);
    expect(keyStep("Enter", "ltr")).toBeNull();
  });
});
