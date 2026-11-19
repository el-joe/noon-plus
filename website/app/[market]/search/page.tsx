import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, EmptyState, Skeleton } from "@/components/ui/misc";
import { ProductListing, toListingQuery } from "@/features/listing/product-listing";
import { SearchChips } from "@/features/search/search-chips";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/search">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("search.title"), robots: { index: false } };
}

/** `/search?q=` (Figma Search results 67:8168) — keyword listing with sponsored interleave. */
export default async function SearchPage({ params, searchParams }: PageProps<"/[market]/search">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => {
          const query = toListingQuery(raw);
          if (!query.q?.trim()) {
            const t = getT(market.locale);
            return (
              <div className="flex flex-col gap-6">
                <EmptyState title={t("search.title")} body={t("search.prompt")} />
                <SearchChips />
              </div>
            );
          }
          return <ProductListing market={market} query={query} basePath={`/${market.slug}/search`} />;
        })}
      </Suspense>
    </Container>
  );
}
