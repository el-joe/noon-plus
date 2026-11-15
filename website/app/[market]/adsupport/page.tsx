import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { KNOWLEDGE_HUB, HelpHome } from "@/features/content/help";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/adsupport">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("content.adsupport.title") };
}

export default async function HubHomePage({ params }: PageProps<"/[market]/adsupport">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        <HelpHome market={market} config={KNOWLEDGE_HUB} />
      </Suspense>
    </Container>
  );
}
