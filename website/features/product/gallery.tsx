"use client";

import clsx from "clsx";
import { useTranslations } from "next-intl";
import { useState } from "react";
import { WishlistButton } from "@/components/product/product-card";
import { ProductImage } from "@/components/ui/misc";
import type { ProductImageData } from "@/lib/api/schemas";

/**
 * PDP gallery (Figma 24:2): vertical thumbnails + main image with the
 * wishlist heart. Images come from `images[]` (G-W1); with none, the
 * placeholder fills the frame.
 */
export function ProductGallery({ images, title, slug }: { images: ProductImageData[]; title: string; slug: string }) {
  const t = useTranslations("product");
  const [index, setIndex] = useState(0);
  const current = images[index] ?? null;

  return (
    <section className="flex flex-col-reverse gap-3 md:flex-row" aria-label={t("gallery")}>
      {images.length > 1 ? (
        <div className="scrollbar-none flex gap-2 overflow-x-auto md:max-h-[480px] md:flex-col md:overflow-y-auto">
          {images.map((image, i) => (
            <button
              key={image.id}
              type="button"
              onClick={() => setIndex(i)}
              onMouseEnter={() => setIndex(i)}
              aria-label={t("imageN", { n: i + 1 })}
              aria-current={i === index}
              className={clsx("size-14 shrink-0 overflow-hidden rounded-md border-2 bg-surface", i === index ? "border-brand" : "border-line")}
            >
              <ProductImage src={image.url} alt="" />
            </button>
          ))}
        </div>
      ) : null}
      <div className="relative aspect-square flex-1 overflow-hidden rounded-lg border border-line bg-surface">
        <ProductImage src={current?.url} alt={current?.alt || title} label={current ? undefined : t("imageSoon")} />
        <WishlistButton slug={slug} className="absolute end-3 top-3 size-9 rounded-full border border-line bg-surface shadow-xs hover:bg-subtle" />
      </div>
    </section>
  );
}
