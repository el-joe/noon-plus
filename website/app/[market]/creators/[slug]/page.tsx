import type { Metadata } from "next";
import { Suspense } from "react";
import { Container, Skeleton } from "@/components/ui/misc";
import { CreatorBoutique, loadCreator } from "@/features/creators/boutique";
import { resolveMarket } from "@/lib/server-market";

export async function generateMetadata({ params }: PageProps<"/[market]/creators/[slug]">): Promise<Metadata> {
  const market = await resolveMarket(params);
  const { slug } = await params;
  const creator = await loadCreator(market, decodeURIComponent(slug)).catch(() => null);
  if (!creator) return {};
  return { title: creator.name ?? undefined, description: creator.bio?.slice(0, 160) ?? undefined };
}

/** `/creators/{slug}` — creator boutique (Figma 59:3228). */
async function CreatorPageContent({ params }: PageProps<"/[market]/creators/[slug]">) {
  const market = await resolveMarket(params);
  return (
    <Container className="py-6">
      <Suspense fallback={<Skeleton className="h-[640px] w-full" />}>
        {params.then(({ slug }) => (
          <CreatorBoutique market={market} slug={decodeURIComponent(slug)} />
        ))}
      </Suspense>
    </Container>
  );
}

/** Runtime params resolve inside Suspense (cacheComponents). */
export default function CreatorPage(props: PageProps<"/[market]/creators/[slug]">) {
  return (
    <Suspense fallback={<Container className="py-6"><Skeleton className="h-[480px] w-full" /></Container>}>
      <CreatorPageContent {...props} />
    </Suspense>
  );
}
