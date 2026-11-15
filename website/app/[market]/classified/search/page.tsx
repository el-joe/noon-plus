import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { ClassifiedSearch } from "@/features/classifieds/search-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/classified/search">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("classifieds.allAds"), robots: { index: false } };
}

/** `/classified/search?category=&q=&…` — Open Souq results (Figma 54:1139). */
export default async function ClassifiedSearchPage({ params, searchParams }: PageProps<"/[market]/classified/search">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => {
          const query: Record<string, string> = {};
          for (const [k, v] of Object.entries(raw)) {
            const value = Array.isArray(v) ? v[0] : v;
            if (value) query[k] = value;
          }
          return <ClassifiedSearch market={market} params={query} />;
        })}
      </Suspense>
    </Container>
  );
}
