import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { DealsHub } from "@/features/shop/deals";
import { getFlashSales } from "@/lib/api/shop";
import { getT } from "@/lib/i18n";
import type { Market } from "@/lib/markets";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/deals">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("shop.deals.metaTitle"), description: t("shop.deals.subtitle") };
}

/** `/deals` — deals hub (Figma 67:9288): running and upcoming flash sales. */
export default async function DealsPage({ params }: PageProps<"/[market]/deals">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <Deals market={market} />
      </Suspense>
    </Container>
  );
}

async function Deals({ market }: { market: Market }) {
  const sales = await getFlashSales(market.slug)
    .then((r) => r.data)
    .catch(() => []);
  return <DealsHub market={market} sales={sales} />;
}
