import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { KNOWLEDGE_HUB, HelpSearch } from "@/features/content/help";
import { firstParam } from "@/features/content/pagination";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/adsupport/search">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.adsupport.title"), robots: { index: false } };
}

export default async function HubSearchPage({ params, searchParams }: PageProps<"/[market]/adsupport/search">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[480px] w-full" />}>
        {searchParams.then((raw) => (
          <HelpSearch market={market} config={KNOWLEDGE_HUB} q={firstParam(raw.q) ?? ""} />
        ))}
      </Suspense>
    </Container>
  );
}
