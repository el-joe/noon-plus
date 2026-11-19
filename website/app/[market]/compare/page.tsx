import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { CompareView } from "@/features/compare/compare-view";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/compare">): Promise<Metadata> {
  const market = await resolveMarket(params);
  return { title: getT(market.locale)("discovery.compare.title"), robots: { index: false } };
}

/** `/compare?slugs=a,b[,c,d]` — product comparison (FR-CAT-6, QC-043). */
export default async function ComparePage({ params, searchParams }: PageProps<"/[market]/compare">) {
  await resolveMarket(params);
  return (
    <Container className="py-4">
      <Suspense fallback={<Skeleton className="h-96 w-full" />}>
        {searchParams.then((raw) => {
          const value = Array.isArray(raw.slugs) ? raw.slugs.join(",") : (raw.slugs ?? "");
          const slugs = [...new Set(value.split(",").map((s) => s.trim()).filter(Boolean))].slice(0, 4);
          return <CompareView slugs={slugs} />;
        })}
      </Suspense>
    </Container>
  );
}
