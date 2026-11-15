import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { flatParams } from "@/features/content/pagination";
import { CreatorDirectory } from "@/features/creators/directory";
import { getT } from "@/lib/i18n";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/creators">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const t = getT(market.locale);
  return { title: t("creators.title"), description: t("creators.heroBody") };
}

/** `/creators` — creators directory (Figma 57:2752). */
export default async function CreatorsPage({ params, searchParams }: PageProps<"/[market]/creators">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {searchParams.then((raw) => (
          <CreatorDirectory market={market} query={flatParams(raw)} />
        ))}
      </Suspense>
    </Container>
  );
}
