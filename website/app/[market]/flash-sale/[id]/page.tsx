import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { FlashSaleView } from "@/features/shop/deals";
import { orNull } from "@/lib/api/client";
import { getFlashSaleEvent } from "@/lib/api/shop";
import { getT } from "@/lib/i18n";
import { parseMarket } from "@/lib/markets";

type Params = PageProps<"/[market]/flash-sale/[id]">["params"];

async function load(params: Params) {
  const { market: slug, id } = await params;
  const market = parseMarket(slug);
  // `id` is the event slug (`{name}-{id}`) or, for older links, the numeric id.
  const key = decodeURIComponent(id);
  if (!market || !/^[A-Za-z0-9-]{1,160}$/.test(key)) notFound();
  const sale = await orNull(getFlashSaleEvent(market.slug, key).then((r) => r.data));
  return { market, sale };
}

export async function generateMetadata({ params }: PageProps<"/[market]/flash-sale/[id]">): Promise<Metadata> {
  const { market, sale } = await load(params).catch(() => ({ market: null, sale: null }));
  if (!market) return {};
  return { title: sale?.name ?? getT(market.locale)("shop.flash.notFound") };
}

/** `/flash-sale/[id]` — one flash sale event by slug or id (Figma 67:9994). */
export default function FlashSalePage({ params }: PageProps<"/[market]/flash-sale/[id]">) {
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <FlashSaleContent params={params} />
      </Suspense>
    </Container>
  );
}

async function FlashSaleContent({ params }: { params: Params }) {
  const { market, sale } = await load(params);
  if (!sale) notFound();
  return <FlashSaleView market={market} sale={sale} />;
}
