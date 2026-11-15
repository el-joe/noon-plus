"use client";

import { Heart } from "lucide-react";
import { useTranslations } from "next-intl";
import { ProductCard } from "@/components/product/product-card";
import { ButtonLink } from "@/components/ui/button";
import { Alert, EmptyState, Skeleton } from "@/components/ui/misc";
import { cardFromApi } from "@/lib/api/mappers";
import { errorMessage, useWishlist } from "@/lib/hooks";
import { useHref } from "@/lib/market-context";

/** `/account/wishlist` (Figma 50:3374) — saved products (G-W6); the heart removes. */
export function WishlistView() {
  const t = useTranslations();
  const href = useHref();
  const { data, isLoading, error } = useWishlist();
  const items = data ?? [];

  return (
    <div className="flex flex-col gap-4">
      <div className="flex flex-wrap items-baseline gap-3">
        <h1 className="text-h2 font-bold">{t("wishlist.title")}</h1>
        {items.length > 0 ? <span className="text-body text-secondary">{t("wishlist.count", { count: items.length })}</span> : null}
      </div>
      {error ? <Alert tone="danger">{errorMessage(error, t("common.error"))}</Alert> : null}
      {isLoading ? <Skeleton className="h-72 w-full" /> : null}
      {!isLoading && items.length === 0 ? (
        <EmptyState icon={<Heart className="size-10" />} title={t("wishlist.empty")} body={t("wishlist.emptyHint")} action={<ButtonLink href={href("/")}>{t("wishlist.browse")}</ButtonLink>} />
      ) : null}
      <div className="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-4">
        {items.map((item) => (
          <div key={item.slug} className="flex flex-col gap-1">
            <ProductCard product={{ ...cardFromApi(item), soldOut: item.in_stock === false }} />
            {item.in_stock === false ? <span className="text-body-sm font-medium text-danger-text">{t("wishlist.outOfStock")}</span> : null}
          </div>
        ))}
      </div>
    </div>
  );
}
