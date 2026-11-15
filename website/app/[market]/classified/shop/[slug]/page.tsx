import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ClassifiedShop } from "@/features/classifieds/shop";
import * as api from "@/lib/api/classifieds";
import { orNull } from "@/lib/api/client";
import { parseMarket } from "@/lib/markets";

type Props = PageProps<"/[market]/classified/shop/[slug]">;

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { market: slug, slug: shop } = await params;
  const market = parseMarket(slug);
  if (!market) return {};
  const data = await orNull(api.getShop(market.slug, shop).then((r) => r.data)).catch(() => null);
  return data ? { title: data.shop.name ?? data.shop.slug } : {};
}

/** `/classified/shop/[slug]` — Open Souq seller shop (Figma 57:2192). */
export default function ClassifiedShopPage({ params, searchParams }: Props) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <ShopContent params={params} searchParams={searchParams} />
      </Suspense>
    </Container>
  );
}

async function ShopContent({ params, searchParams }: Pick<Props, "params" | "searchParams">) {
  const { market: slug, slug: shop } = await params;
  const market = parseMarket(slug);
  if (!market) notFound();
  const raw = await searchParams;
  const page = Math.max(1, Number(Array.isArray(raw.page) ? raw.page[0] : raw.page) || 1);
  const result = await orNull(api.getShop(market.slug, shop, page));
  if (!result) notFound();
  return <ClassifiedShop market={market} data={result.data} page={page} lastPage={Number(result.meta.last_page ?? 1)} />;
}
